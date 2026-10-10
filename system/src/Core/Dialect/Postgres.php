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

    public function rewrite(string $sql): string
    {
        return str_replace('`', '"', $sql); // SQL written with MySQL backticks
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
        $action = $update === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', array_map(fn (string $c): string => $this->quote($c) . ' = EXCLUDED.' . $this->quote($c), $update));

        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) ON CONFLICT (%s) %s', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')), $this->quoteAll($keys), $action);
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

    public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string
    {
        return 'string_agg(' . ($distinct ? 'DISTINCT ' : '') . '(' . $expression . ')::text, \'' . $this->separator($separator) . '\'' . ($orderBy !== null ? ' ORDER BY ' . $orderBy : '') . ')';
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

    public function currentDatabaseSql(): string
    {
        return 'SELECT current_database()';
    }

    public function returning(string $keyColumn): string
    {
        return ' RETURNING ' . $this->quote($keyColumn);
    }
}
