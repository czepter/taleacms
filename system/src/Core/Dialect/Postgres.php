<?php

declare(strict_types=1);

namespace Talea\Core\Dialect;

use PDO;

final class Postgres extends Dialect
{
    /** Case- and accent-insensitive ICU collation (primary strength), the counterpart of utf8mb4_0900_ai_ci; created by the baseline migration. */
    public const string COLLATION = 'talea_ci';

    public function name(): string
    {
        return 'pgsql';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function dsn(array $c): string
    {
        // a socket is the directory of PostgreSQL's socket, which libpq takes as the host
        return sprintf('pgsql:host=%s;port=%d;dbname=%s', !empty($c['socket']) ? $c['socket'] : ($c['host'] ?? 'localhost'), $c['port'] ?? 5432, $c['name']);
    }

    public function phinx(): array
    {
        return ['adapter' => 'pgsql', 'charset' => 'utf8'];
    }

    public function quote(string $identifier): string
    {
        return '"' . $this->identifier($identifier) . '"';
    }

    /** SQL written the MySQL way runs here too: backticks, and `INTERVAL 5 DAY` / `INTERVAL ? HOUR` (the interval syntax both engines can be given, see docs/specs/postgres.md). */
    public function rewrite(string $sql): string
    {
        $sql = preg_replace_callback(
            '/\bINTERVAL\s+(\d+|\?)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/i',
            fn (array $m): string => $m[1] === '?' ? "(CAST(? AS integer) * INTERVAL '1 " . strtolower($m[2]) . "')" : "INTERVAL '" . $m[1] . ' ' . strtolower($m[2]) . "'",
            $sql,
        ) ?? $sql;

        return str_replace('`', '"', $sql);
    }

    public function setTimeZone(PDO $pdo, string $offset): void
    {
        $pdo->exec("SET TIME ZONE INTERVAL '" . $offset . "' HOUR TO MINUTE");
    }

    public function interval(int $amount, string $unit): string
    {
        return "INTERVAL '" . $amount . ' ' . strtolower($this->unit($unit)) . "'";
    }

    public function unixTime(string $expression): string
    {
        return 'EXTRACT(EPOCH FROM ' . $expression . ')::bigint';
    }

    public function upsert(string $table, array $columns, array $keys, array $update): string
    {
        $action = $update === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $this->assignments($table, $update, fn (string $c): string => 'EXCLUDED.' . $this->quote($c)));

        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) ON CONFLICT (%s) %s', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')), $this->quoteAll($keys), $action);
    }

    public function insertIgnoreSelect(string $table, array $columns, string $select): string
    {
        return sprintf('INSERT INTO {%s} (%s) %s ON CONFLICT DO NOTHING', $this->identifier($table), $this->quoteAll($columns), $select);
    }

    public function insertIgnore(string $table, array $columns): string
    {
        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) ON CONFLICT DO NOTHING', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')));
    }

    public function jsonExtract(string $column, string $path): string
    {
        // an ARRAY[...] and not the '{a,b}' literal: a single key in braces would be taken for a {table} placeholder
        return '((' . $column . ')::jsonb #>> ARRAY[' . implode(', ', array_map(fn (int|string $k): string => "'" . $k . "'", $this->jsonPath($path))) . '])';
    }

    public function toNumber(string $expression): string
    {
        // no ? and no {} in the pattern: the placeholders of Db::sql() would take them
        $text = '(' . $expression . ')::text';

        return 'COALESCE(CASE WHEN ' . $text . " ~ '^[-]*[0-9]+([.][0-9]+)*$' AND " . $text . " !~ '^[-][-]' THEN " . $text . '::numeric END, 0)';
    }

    public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string
    {
        $text = '(' . $expression . ')::text';
        if ($distinct && $orderBy === $expression) {
            $orderBy = $text; // PostgreSQL wants the ORDER BY of a DISTINCT aggregate to be the argument itself
        }

        return 'string_agg(' . ($distinct ? 'DISTINCT ' : '') . $text . ', \'' . $this->separator($separator) . '\'' . ($orderBy !== null ? ' ORDER BY ' . $orderBy : '') . ')';
    }

    /** The same expression is in the index and in the query, so the GIN index serves the predicate. @param list<string> $columns */
    private function vector(array $columns): string
    {
        return "to_tsvector('simple', " . implode(" || ' ' || ", array_map(fn (string $c): string => "coalesce({$this->quote($c)}, '')", $columns)) . ')';
    }

    public function fulltextMatch(array $columns, string $placeholder = '?'): string
    {
        return '(' . $this->vector($columns) . ' @@ to_tsquery(\'simple\', ' . $placeholder . '))';
    }

    public function fulltextQuery(array $words): string
    {
        return implode(' & ', array_map(fn (string $w): string => $w . ':*', $words));
    }

    public function fulltextIndex(string $table, string $index, array $columns): string
    {
        return sprintf('CREATE INDEX %s ON %s USING GIN (%s)', $this->quote($index), $this->quote($table), $this->vector($columns));
    }

    public function likeInsensitive(string $column, string $placeholder = '?'): string
    {
        return $column . ' COLLATE ' . $this->quote(self::COLLATION) . ' LIKE ' . $placeholder; // nondeterministic collations support LIKE since PostgreSQL 18
    }

    /** pg_advisory_lock has no timeout, so the try-version is repeated until the time is up. */
    public function lock(PDO $pdo, string $name, int $timeout): bool
    {
        $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(?)');
        $until = microtime(true) + $timeout;
        do {
            $stmt->execute([self::key($name)]);
            if ($stmt->fetchColumn()) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $until);

        return false;
    }

    public function unlock(PDO $pdo, string $name): void
    {
        $pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::key($name)]);
    }

    /** The lock name as the bigint PostgreSQL wants. */
    public static function key(string $name): int
    {
        return (int) unpack('q', substr(hash('sha256', $name, true), 0, 8))[1];
    }

    public function tableExistsSql(): string
    {
        return 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }

    public function autoKeyColumn(): string
    {
        return 'BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
    }

    public function columnsSql(): string
    {
        return 'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position';
    }

    public function uniqueKeysSql(): string
    {
        return 'SELECT i.relname AS key_name, a.attname AS column_name, ix.indisprimary::int AS is_primary FROM pg_index ix JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' JOIN pg_namespace n ON n.oid = t.relnamespace CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum'
            . ' WHERE n.nspname = current_schema() AND t.relname = ? AND ix.indisunique ORDER BY i.relname, k.ord';
    }

    public function dropUniqueKeySql(string $table, string $key): string
    {
        $this->identifier($table);

        return 'DROP INDEX ' . $this->quote($key);
    }

    public function addUniqueKeySql(string $table, string $key, array $columns): string
    {
        return 'CREATE UNIQUE INDEX ' . $this->quote($key) . ' ON {' . $this->identifier($table) . '} (' . $this->quoteAll($columns) . ')';
    }

    public function sizeSql(): string
    {
        return "SELECT COALESCE(SUM(pg_total_relation_size(c.oid)), 0)::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname LIKE ?";
    }

    public function emptyTablesSql(array $tables): array
    {
        return ['TRUNCATE ' . implode(', ', array_map(fn (string $t): string => '{' . $this->identifier($t) . '}', $tables)) . ' CASCADE'];
    }

    public function syncSequenceSql(string $table, string $keyColumn): string
    {
        $t = '{' . $this->identifier($table) . '}';
        $k = $this->quote($keyColumn);

        return "SELECT setval(pg_get_serial_sequence('{$t}', '{$keyColumn}'), COALESCE(MAX({$k}), 1), MAX({$k}) IS NOT NULL) FROM {$t}";
    }

    public function dumpTables(PDO $pdo, string $prefix): array
    {
        $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name LIKE ? ORDER BY table_name");
        $stmt->execute([addcslashes($prefix, '_%') . '%']);
        $tables = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // referenced tables first (a foreign key is checked when the row is inserted): repeatedly take the tables whose parents are all in
        $parents = array_fill_keys($tables, []);
        foreach ($pdo->query("SELECT c.relname, p.relname FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid JOIN pg_class p ON p.oid = k.confrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE k.contype = 'f' AND n.nspname = current_schema()")->fetchAll(PDO::FETCH_NUM) as [$child, $parent]) {
            if (isset($parents[$child]) && $child !== $parent) {
                $parents[$child][] = $parent;
            }
        }
        $ordered = [];
        while ($parents !== []) {
            $ready = array_keys(array_filter($parents, fn (array $p): bool => array_diff($p, $ordered) === []));
            $ready = $ready === [] ? [array_key_first($parents)] : $ready; // a cycle: take any
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($parents[$table]);
            }
        }

        return $ordered;
    }

    public function dumpPrologue(array $tables): string
    {
        return $tables === [] ? '' : 'TRUNCATE ' . $this->quoteAll($tables) . ";\n\n";
    }

    public function dumpEpilogue(): string
    {
        return '';
    }

    public function dumpStructure(PDO $pdo, string $table): string
    {
        return '';
    }

    public function dumpInsert(string $table, array $columns): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (' . $this->quoteAll($columns) . ") VALUES\n";
    }

    public function dumpBooleanColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND data_type = 'boolean'");
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function dumpLiteral(PDO $pdo, string $value): string
    {
        if (!preg_match('/[\\\r\n]/', $value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return "E'" . strtr($value, ["'" => "''", '\\' => '\\\\', "\n" => '\\n', "\r" => '\\r']) . "'"; // an escape string: the literal stays on one line
    }

    public function restoreBegin(PDO $pdo): void
    {
        $pdo->beginTransaction(); // TRUNCATE and INSERT are transactional here: a failed restore changes nothing
    }

    public function restoreEnd(PDO $pdo, bool $failed): void
    {
        if ($pdo->inTransaction()) {
            $failed ? $pdo->rollBack() : $pdo->commit();
        }
    }

    public function currentDatabaseSql(): string
    {
        return 'SELECT current_database()';
    }

    public function returning(string $keyColumn): string
    {
        return ' RETURNING ' . $this->quote($keyColumn);
    }
}
