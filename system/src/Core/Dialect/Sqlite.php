<?php

declare(strict_types=1);

namespace Talea\Core\Dialect;

use PDO;

/**
 * SQLite 3.38+ (one file, no server; the image's Debian has 3.40): a small site on a host without a database, a demo, a laptop. The SQL is the portable SQL the
 * MySQL and PostgreSQL code already writes; what SQLite lacks comes from PHP functions registered on every connection (connect()):
 * NOW() in PHP's own time zone, case- and accent-folding for LIKE, the full-text match. See docs/specs/sqlite.md.
 */
final class Sqlite extends Dialect
{
    /** The file of a site whose configuration names none, relative to the installation. */
    public const string DEFAULT_FILE = 'storage/database/talea.sqlite';

    /** @var array<string, resource> lock name => open lock file (flock is released when the file is closed) */
    private static array $locks = [];

    public function name(): string
    {
        return 'sqlite';
    }

    public function defaultPort(): int
    {
        return 0;
    }

    public function dsn(array $c): string
    {
        return 'sqlite:' . self::file($c['name']);
    }

    /** The database file: an absolute path as it is, a relative one inside the installation; '' = the default file. */
    public static function file(string $name): string
    {
        $name = $name === '' ? self::DEFAULT_FILE : $name;

        return $name === ':memory:' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1 ? $name : TALEA_ROOT . '/' . $name;
    }

    public function phinx(): array
    {
        return ['adapter' => 'sqlite', 'suffix' => ''];
    }

    public function quote(string $identifier): string
    {
        return '"' . $this->identifier($identifier) . '"';
    }

    /**
     * SQL written the MySQL way runs here too: backticks, `NOW() - INTERVAL 5 DAY` (SQLite's datetime() with a modifier), CURRENT_DATE
     * in PHP's time zone (SQLite's own is UTC), LIKE with the backslash as its escape character (as on MySQL and PostgreSQL), no `FOR UPDATE`
     * (a write transaction already holds the whole file).
     */
    public function rewrite(string $sql): string
    {
        $sql = str_replace('`', '"', $sql);
        // the left side: NOW(), CURRENT_DATE, a column, or what an earlier round made of `NOW() + INTERVAL 2 DAY` (+ INTERVAL 1 HOUR)
        $interval = '/(NOW\(\)|CURRENT_DATE|(?:date|datetime)(\((?:[^()]++|(?2))*\))|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)\s*([-+])\s*INTERVAL\s+(\d+|\?)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/i';
        do {
            $before = $sql;
            $sql = preg_replace_callback($interval, function (array $m): string {
                $date = strtoupper($m[1]) === 'CURRENT_DATE' || stripos($m[1], 'date(') === 0;

                return ($date ? 'date(' : 'datetime(') . (strtoupper($m[1]) === 'CURRENT_DATE' ? 'NOW()' : $m[1]) . ', ' . $this->modifier($m[3], $m[4], $m[5]) . ')';
            }, $sql) ?? $sql;
        } while ($sql !== $before);
        // a column default that is an expression needs parentheses (add-on migrations: `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`)
        $sql = preg_replace('/\bDEFAULT\s+CURRENT_(DATE|TIMESTAMP)\b/i', 'DEFAULT (CURRENT_$1)', $sql) ?? $sql;
        $sql = preg_replace('/\bCURRENT_DATE\b/i', 'date(NOW())', $sql) ?? $sql;
        $sql = preg_replace('/\bCURRENT_TIMESTAMP\b/i', 'NOW()', $sql) ?? $sql;
        $sql = preg_replace("/\\bLIKE\\s+(\\?|'(?:[^']|'')*'|:[a-z_]+)(?!\\s*ESCAPE)/i", "LIKE $1 ESCAPE '\\'", $sql) ?? $sql;

        return preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql) ?? $sql;
    }

    /** `'-5 day'` or, for a parameter, `('-' || (? * 1) || ' day')`; SQLite has no weeks, so a week is 7 days. */
    private function modifier(string $sign, string $amount, string $unit): string
    {
        $unit = strtolower($this->unit($unit));
        $factor = $unit === 'week' ? 7 : 1;
        $unit = $unit === 'week' ? 'day' : $unit;
        if ($amount === '?') {
            return "('" . $sign . "' || (CAST(? AS INTEGER) * " . $factor . ") || ' " . $unit . "')";
        }

        return "'" . $sign . ((int) $amount * $factor) . ' ' . $unit . "'";
    }

    /** Called by Db once per connection (a Pdo\Sqlite: its createFunction() replaces PDO's deprecated sqliteCreateFunction()): the pragmas a site needs and the functions SQLite does not have. */
    public function connect(PDO $pdo): void
    {
        if (!$pdo instanceof \Pdo\Sqlite) {
            throw new \LogicException('SQLite needs a Pdo\Sqlite connection (new Pdo\Sqlite(...) or PDO::connect()).');
        }
        // foreign keys are off in SQLite unless asked (the schema relies on ON DELETE CASCADE / SET NULL); WAL lets readers work while one process writes
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        if ($pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'memory') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::functions($pdo);
    }

    /**
     * The PHP functions every connection gets: they are what makes MySQL's SQL mean the same here. NOW() follows PHP's time zone
     * (App::applyTimezone), so there is no session time zone to set.
     */
    private static function functions(\Pdo\Sqlite $pdo): void
    {
        $deterministic = \Pdo\Sqlite::DETERMINISTIC;
        $define = fn (string $name, callable $fn, int $arguments, int $flags = 0): bool => $pdo->createFunction($name, $fn, $arguments, $flags);
        $define('NOW', fn (): string => date('Y-m-d H:i:s'), 0);
        $version = 'SQLite ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $define('VERSION', fn (): string => $version, 0, $deterministic); // System status shows the server's version
        $define('talea_offset_minutes', fn (): int => intdiv((int) date('Z'), 60), 0);
        $define('talea_unix', fn (mixed $date): ?int => $date === null || ($t = strtotime((string) $date)) === false ? null : $t, 1, $deterministic);
        $define('talea_fold', fn (mixed $text): ?string => $text === null ? null : remove_diacritics(mb_strtolower((string) $text)), 1, $deterministic);
        // MySQL's LOWER/UPPER know every alphabet, SQLite's only ASCII
        $define('LOWER', fn (mixed $text): ?string => $text === null ? null : mb_strtolower((string) $text), 1, $deterministic);
        $define('UPPER', fn (mixed $text): ?string => $text === null ? null : mb_strtoupper((string) $text), 1, $deterministic);
        $define('talea_match', self::match(...), -1, $deterministic);
        // CONCAT is NULL when an argument is (MySQL); SQLite only has them from 3.44 on, and its CONCAT skips NULL
        $define('CONCAT', fn (mixed ...$parts): ?string => in_array(null, $parts, true) ? null : implode('', array_map(fn (mixed $p): string => (string) $p, $parts)), -1, $deterministic);
        $define('CONCAT_WS', fn (mixed $separator, mixed ...$parts): ?string => $separator === null ? null : implode((string) $separator, array_map(fn (mixed $p): string => (string) $p, array_filter($parts, fn (mixed $p): bool => $p !== null))), -1, $deterministic);
        // group_concat() with ORDER BY needs 3.44, with DISTINCT and a separator does not exist: the values are collected, sorted and joined here
        $step = function (mixed $context, int $row, mixed $value, mixed $separator, mixed $distinct, mixed ...$order): array {
            $context ??= ['values' => [], 'separator' => (string) $separator, 'distinct' => (bool) $distinct];
            if ($value !== null) {
                $context['values'][] = [(string) $value, $order]; // the sort keys, each followed by 1 = descending
            }

            return $context;
        };
        $final = function (mixed $context): ?string {
            if ($context === null || $context['values'] === []) {
                return null;
            }
            $values = $context['values'];
            usort($values, function (array $a, array $b): int { // stable: rows the keys do not tell apart keep their order
                for ($i = 0; $i < count($a[1]); $i += 2) {
                    $order = $a[1][$i + 1] ? $b[1][$i] <=> $a[1][$i] : $a[1][$i] <=> $b[1][$i];
                    if ($order !== 0) {
                        return $order;
                    }
                }

                return 0;
            });
            $values = array_column($values, 0);

            return implode($context['separator'], $context['distinct'] ? array_values(array_unique($values)) : $values);
        };
        $pdo->createAggregate('talea_group_concat', $step, $final, -1);
    }

    /** talea_match(query, column, …): every word of the query (fulltextQuery()) begins a word of one of the columns, as MySQL's `+word*`. */
    public static function match(mixed $query, mixed ...$texts): int
    {
        $haystack = ' ' . preg_replace('/\s+/u', ' ', implode(' ', array_map(fn (mixed $t): string => (string) $t, $texts))) . ' ';
        foreach (preg_split('/\s+/u', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!str_contains($haystack, ' ' . $word)) {
                return 0;
            }
        }

        return 1;
    }

    public function setTimeZone(PDO $pdo, string $offset): void
    {
        // NOW() is PHP's date(): it already counts time the way PHP does
    }

    public function sessionOffsetMinutes(): string
    {
        return 'talea_offset_minutes()';
    }

    /** MySQL's form: rewrite() turns `expression - INTERVAL 5 DAY` into datetime(expression, '-5 day'). */
    public function interval(int $amount, string $unit): string
    {
        return 'INTERVAL ' . $amount . ' ' . $this->unit($unit);
    }

    public function unixTime(string $expression): string
    {
        return 'talea_unix(' . $expression . ')';
    }

    public function upsert(string $table, array $columns, array $keys, array $update): string
    {
        $action = $update === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $this->assignments($table, $update, fn (string $c): string => 'excluded.' . $this->quote($c)));

        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) ON CONFLICT (%s) %s', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')), $this->quoteAll($keys), $action);
    }

    public function insertIgnoreSelect(string $table, array $columns, string $select): string
    {
        return sprintf('INSERT OR IGNORE INTO {%s} (%s) %s', $this->identifier($table), $this->quoteAll($columns), $select);
    }

    public function insertIgnore(string $table, array $columns): string
    {
        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) ON CONFLICT DO NOTHING', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')));
    }

    /** As text, like MySQL's JSON_UNQUOTE: json_extract() hands numbers back as numbers (which never equal a string parameter) and true as 1. */
    public function jsonExtract(string $column, string $path): string
    {
        $sqlitePath = '$';
        foreach ($this->jsonPath($path) as $key) {
            $sqlitePath .= is_int($key) ? '[' . $key . ']' : '."' . $key . '"';
        }

        return "(CASE json_type({$column}, '{$sqlitePath}') WHEN 'true' THEN 'true' WHEN 'false' THEN 'false' ELSE CAST(json_extract({$column}, '{$sqlitePath}') AS TEXT) END)";
    }

    /** talea_group_concat(value, separator, distinct, key, descending, key, descending …), see functions(); $orderBy is `a, b DESC`. */
    public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string
    {
        $keys = '';
        foreach ($orderBy === null ? [] : $this->topLevelList($orderBy) as $key) {
            $keys .= ', ' . (preg_replace('/\s+(ASC|DESC)$/i', '', $key) ?? $key) . ', ' . (int) (preg_match('/\s+DESC$/i', $key) === 1);
        }

        return 'talea_group_concat(' . $expression . ", '" . $this->separator($separator) . "', " . (int) $distinct . $keys . ')';
    }

    /** `a, f(b, c) DESC` -> ['a', 'f(b, c) DESC']: the commas outside parentheses. @return list<string> */
    private function topLevelList(string $list): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        for ($i = 0, $n = strlen($list); $i < $n; $i++) {
            $depth += match ($list[$i]) { '(' => 1, ')' => -1, default => 0 };
            if ($list[$i] === ',' && $depth === 0) {
                $parts[] = trim(substr($list, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($list, $start));

        return $parts;
    }

    public function fulltextMatch(array $columns, string $placeholder = '?'): string
    {
        return '(talea_match(' . $placeholder . ', ' . $this->quoteAll($columns) . ') = 1)';
    }

    public function fulltextQuery(array $words): string
    {
        return implode(' ', $words);
    }

    /** No index: talea_match() reads the (already normalised) search column itself; a small site's news fit easily. */
    public function fulltextIndex(string $table, string $index, array $columns): string
    {
        return '';
    }

    public function likeInsensitive(string $column, string $placeholder = '?'): string
    {
        return 'talea_fold(' . $column . ') LIKE talea_fold(' . $placeholder . ") ESCAPE '\\'";
    }

    /** A file lock next to the database: SQLite has no named locks, and every process of the site sees the same folder. */
    public function lock(PDO $pdo, string $name, int $timeout): bool
    {
        if (isset(self::$locks[$name])) {
            return false;
        }
        $file = self::lockFile($pdo, $name);
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException('The lock file ' . $file . ' cannot be created - check the write permissions of the database folder.');
        }
        $until = microtime(true) + $timeout;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$locks[$name] = $handle;

                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $until);
        fclose($handle);

        return false;
    }

    public function unlock(PDO $pdo, string $name): void
    {
        if (isset(self::$locks[$name])) {
            flock(self::$locks[$name], LOCK_UN);
            fclose(self::$locks[$name]);
            unset(self::$locks[$name]);
        }
    }

    private static function lockFile(PDO $pdo, string $name): string
    {
        $database = (string) $pdo->query("SELECT file FROM pragma_database_list WHERE name = 'main'")->fetchColumn();

        return ($database === '' ? sys_get_temp_dir() . '/talea' : $database) . '.' . substr(hash('sha256', $name), 0, 16) . '.lock';
    }

    public function tableExistsSql(): string
    {
        return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    public function currentDatabaseSql(): string
    {
        return "SELECT file FROM pragma_database_list WHERE name = 'main'";
    }

    public function autoKeyColumn(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function columnsSql(): string
    {
        return 'SELECT name FROM pragma_table_info(?) ORDER BY cid';
    }

    /** Unique indexes, and the primary key: an INTEGER PRIMARY KEY is the rowid and has no index of its own. */
    public function uniqueKeysSql(): string
    {
        return 'WITH t(name) AS (SELECT ?)'
            . " SELECT il.name AS key_name, ii.name AS column_name, CASE WHEN il.origin = 'pk' THEN 1 ELSE 0 END AS is_primary, ii.seqno AS ord"
            . ' FROM t, pragma_index_list(t.name) il, pragma_index_info(il.name) ii WHERE il."unique" = 1'
            . " UNION ALL SELECT 'PRIMARY', ti.name, 1, ti.pk FROM t, pragma_table_info(t.name) ti WHERE ti.pk > 0"
            . " AND NOT EXISTS (SELECT 1 FROM pragma_index_list(t.name) p WHERE p.origin = 'pk')"
            . ' ORDER BY key_name, ord';
    }

    /** The whole file (one site per file): pages × page size. */
    public function sizeSql(): string
    {
        return 'SELECT c.page_count * s.page_size FROM pragma_page_count() c, pragma_page_size() s WHERE ? IS NOT NULL';
    }

    /** Inside a transaction foreign_keys cannot change, so the checks are deferred to the commit, when every table is empty. */
    public function emptyTablesSql(array $tables): array
    {
        return ['PRAGMA foreign_keys = OFF', 'PRAGMA defer_foreign_keys = ON', ...array_map(fn (string $t): string => 'DELETE FROM {' . $this->identifier($t) . '}', $tables), 'PRAGMA foreign_keys = ON'];
    }

    public function foreignKeysOff(): array
    {
        return [['PRAGMA foreign_keys = OFF', 'PRAGMA defer_foreign_keys = ON'], ['PRAGMA foreign_keys = ON']];
    }

    public function dumpTables(PDO $pdo, string $prefix): array
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '\\' ORDER BY name");
        $stmt->execute([addcslashes($prefix, '_%\\') . '%']);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The structure comes from the migrations (as on PostgreSQL): the file empties the tables and fills them again. */
    public function dumpPrologue(array $tables): string
    {
        return implode('', array_map(fn (string $t): string => 'DELETE FROM ' . $this->quote($t) . ";\n", $tables)) . "\n";
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
        return [];
    }

    /** SQLite strings know no escapes: a line break is concatenated as char(10), so the statement stays on one line. */
    public function dumpLiteral(PDO $pdo, string $value): string
    {
        $literal = "'" . str_replace("'", "''", $value) . "'";

        return preg_match('/[\r\n]/', $value) === 1 ? '(' . strtr($literal, ["\r" => "' || char(13) || '", "\n" => "' || char(10) || '"]) . ')' : $literal;
    }

    /** foreign_keys can only change outside a transaction; the restore then runs in one (a failed restore changes nothing). */
    public function restoreBegin(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
    }

    public function restoreEnd(PDO $pdo, bool $failed): void
    {
        if ($pdo->inTransaction()) {
            $failed ? $pdo->rollBack() : $pdo->commit();
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
