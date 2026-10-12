<?php

declare(strict_types=1);

namespace Talea\Core\Dialect;

use PDO;

/**
 * The few SQL constructs that differ between MySQL 8 and PostgreSQL. Feature code writes plain SQL and asks the dialect
 * (Db::dialect()) for these; every method returns a piece of SQL (with ? placeholders and {table} names, expanded by Db)
 * except where the name says it acts on the connection (lock, unlock, setTimeZone).
 * Identifiers and paths are validated here, values never are inlined: they stay ? parameters.
 */
abstract class Dialect
{
    public const array UNITS = ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR'];

    public static function forDriver(string $driver): self
    {
        return match ($driver) {
            'mysql', '' => new MySql(),
            'pgsql' => new Postgres(),
            default => throw new \InvalidArgumentException("Unknown database driver: {$driver} (mysql or pgsql)"),
        };
    }

    /** 'mysql' | 'pgsql': the PDO driver and the Phinx adapter name. */
    abstract public function name(): string;

    abstract public function defaultPort(): int;

    /** @param array{host?:string,port?:int,socket?:string,name:string} $c */
    abstract public function dsn(array $c): string;

    /** Phinx environment options of this engine (adapter, charset, collation). @return array<string, mixed> */
    abstract public function phinx(): array;

    /** One quoted identifier: `name` / "name". */
    abstract public function quote(string $identifier): string;

    /** Last chance to adapt SQL written for MySQL (placeholders are already expanded); PostgreSQL turns backticks into double quotes. */
    public function rewrite(string $sql): string
    {
        return $sql;
    }

    /** Session time zone as an offset ("+01:00"): PHP writes dates, queries compare them with NOW(). */
    abstract public function setTimeZone(PDO $pdo, string $offset): void;

    /** Current time of the session; the database column types are plain DATETIME, so this compares with them directly. */
    public function now(): string
    {
        return 'NOW()';
    }

    /** An interval literal to add to or subtract from a date: `NOW() - INTERVAL 5 DAY` (MySQL), `NOW() - INTERVAL '5 day'` (PostgreSQL). */
    abstract public function interval(int $amount, string $unit): string;

    /** Seconds since 1970 of a date expression. */
    abstract public function unixTime(string $expression): string;

    /**
     * INSERT of one row, or an update when a row with the same $keys exists. Without $update an existing row stays as it is.
     * $update: a column name (takes the inserted value) or `column => expression` (`'count' => '{old.count} + 1'`; `{old.col}` in the expression is the
     * value of col in the existing row, `{new.col}` the inserted one; a bare column name would be ambiguous in PostgreSQL).
     *
     * @param list<string> $columns @param list<string> $keys @param array<int|string, string> $update
     */
    abstract public function upsert(string $table, array $columns, array $keys, array $update): string;

    /** INSERT ... SELECT that skips rows violating a unique key; $select is a complete SELECT statement with ? placeholders. @param list<string> $columns */
    abstract public function insertIgnoreSelect(string $table, array $columns, string $select): string;

    /** INSERT that silently skips a row violating a unique key (MySQL INSERT IGNORE, PostgreSQL ON CONFLICT DO NOTHING). @param list<string> $columns */
    abstract public function insertIgnore(string $table, array $columns): string;

    /**
     * The text at a path of a JSON column as a string (NULL when it is not there). Path: `$.a.b` or `a.b`, with [0] for list items.
     * The column is an expression written as it is (`data`, `c.data`); columns holding JSON are TEXT, so PostgreSQL casts them.
     */
    abstract public function jsonExtract(string $column, string $path): string;

    /** Values of a group joined into one string. $orderBy is an expression; with $distinct it must be the expression itself. */
    abstract public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string;

    /** ORDER BY expression that sorts by the position in a list (MySQL FIELD()): `CASE expr WHEN ? THEN 0 WHEN ? THEN 1 … ELSE n END`, one ? per value, in this order. */
    public function listPosition(string $expression, int $count): string
    {
        $whens = '';
        for ($i = 0; $i < $count; $i++) {
            $whens .= ' WHEN ? THEN ' . $i;
        }

        return 'CASE ' . $expression . $whens . ' ELSE ' . $count . ' END';
    }

    /** A text expression as a number for sorting (MySQL: `expr + 0`, text without a number is 0). */
    public function toNumber(string $expression): string
    {
        return '(' . $expression . ' + 0)';
    }

    public function limit(int $count, int $offset = 0): string
    {
        return 'LIMIT ' . max(0, $count) . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    /** Predicate: all words of the search string (made by fulltextQuery()) are in the columns. @param list<string> $columns */
    abstract public function fulltextMatch(array $columns, string $placeholder = '?'): string;

    /** The parameter of fulltextMatch() for words made of letters and digits (Search::normalize): every word is a prefix, all are required. @param list<string> $words */
    abstract public function fulltextQuery(array $words): string;

    /** CREATE statement of a full-text index; $table is the real table name (with prefix). @param list<string> $columns */
    abstract public function fulltextIndex(string $table, string $index, array $columns): string;

    /** `column LIKE ?` that ignores case and accents, as MySQL's utf8mb4_0900_ai_ci does. */
    abstract public function likeInsensitive(string $column, string $placeholder = '?'): string;

    /** Name lock between processes (migrations). True when taken within $timeout seconds. */
    abstract public function lock(PDO $pdo, string $name, int $timeout): bool;

    abstract public function unlock(PDO $pdo, string $name): void;

    /** SQL (? = table name without prefix, not expanded) counting the table of the current database. */
    abstract public function tableExistsSql(): string;

    abstract public function currentDatabaseSql(): string;

    // ---- backups (Core\Backup): a plain SQL file the application writes and reads itself, without mysqldump or pg_dump

    /** Real names of the tables of this installation (prefix), in the order a restore can insert them (referenced tables first). @return list<string> */
    abstract public function dumpTables(PDO $pdo, string $prefix): array;

    /** Text after the file header: MySQL switches the foreign key checks off, PostgreSQL empties all the tables in one statement (the structure comes from the migrations, not from the file). @param list<string> $tables */
    abstract public function dumpPrologue(array $tables): string;

    abstract public function dumpEpilogue(): string;

    /** Statements that recreate one table: MySQL its DROP and CREATE, PostgreSQL nothing. */
    abstract public function dumpStructure(PDO $pdo, string $table): string;

    /** Start of an INSERT of several rows of $table (real name), `VALUES` and a line break included; PostgreSQL names the columns, MySQL takes the table's own order. @param list<string> $columns */
    abstract public function dumpInsert(string $table, array $columns): string;

    /** Columns of $table (real name) PostgreSQL keeps as boolean: their 1 and 0 are written as TRUE and FALSE. @return list<string> */
    abstract public function dumpBooleanColumns(PDO $pdo, string $table): array;

    /** One value as an SQL literal that stays on one line (the restore reads statement by statement, a statement ends with a semicolon at the end of a line). */
    abstract public function dumpLiteral(PDO $pdo, string $value): string;

    /** Called before the first statement of a restore and, with $failed, after the last one. */
    abstract public function restoreBegin(PDO $pdo): void;

    abstract public function restoreEnd(PDO $pdo, bool $failed): void;

    /** Statements ({table} names) that empty these tables whatever the foreign keys say: MySQL switches the checks off around DELETE, PostgreSQL TRUNCATEs them together (CASCADE: rows that only exist for them go too). @param list<string> $tables @return list<string> */
    abstract public function emptyTablesSql(array $tables): array;

    /** Statement ({table}, no placeholders) that moves the auto-number of a table past its highest key after rows were inserted with explicit keys; '' when the engine does it by itself (MySQL). */
    public function syncSequenceSql(string $table, string $keyColumn): string
    {
        return '';
    }

    /** Statement ({table}, no placeholders) that drops a unique key (an index on PostgreSQL, where index names are schema-wide). */
    abstract public function dropUniqueKeySql(string $table, string $key): string;

    /**
     * Statement ({table}, no placeholders) that adds a unique key; it fails when rows already break it.
     *
     * @param list<string> $columns
     */
    abstract public function addUniqueKeySql(string $table, string $key, array $columns): string;

    /** The column definition `{pk}` stands for in add-on migrations: an auto-numbered BIGINT primary key. */
    abstract public function autoKeyColumn(): string;

    /** SQL (? = real table name) listing the column names of a table in their order, one column `name`. */
    abstract public function columnsSql(): string;

    /** SQL (? = real table name) listing every unique key (the primary key too) of a table: `key_name`, `column_name`, `is_primary` (0|1), in key and column order. */
    abstract public function uniqueKeysSql(): string;

    /** SQL (? = LIKE pattern for the table names) giving the sum of data and index bytes of the matching tables of the current database. */
    abstract public function sizeSql(): string;

    /** Text after an INSERT that returns the new row's key, '' when the engine reports it through PDO::lastInsertId(). */
    public function returning(string $keyColumn): string
    {
        return '';
    }

    /** `$.a.b[0]` or `a.b` -> ['a', 'b', 0] */
    protected function jsonPath(string $path): array
    {
        $path = preg_replace('/^\$\.?/', '', $path) ?? '';
        $keys = [];
        foreach (explode('.', $path) as $part) {
            if (!preg_match('/^([A-Za-z0-9_-]+)((?:\[\d+\])*)$/', $part, $m)) {
                throw new \InvalidArgumentException("Invalid JSON path: {$path}");
            }
            $keys[] = $m[1];
            preg_match_all('/\[(\d+)\]/', $m[2], $indexes);
            foreach ($indexes[1] as $i) {
                $keys[] = (int) $i;
            }
        }

        return $keys;
    }

    /**
     * The SET list of an upsert. @param array<int|string, string> $update
     * @param \Closure(string): string $newValue the inserted value of a column (MySQL new_row.col, PostgreSQL EXCLUDED.col)
     * @return list<string>
     */
    protected function assignments(string $table, array $update, \Closure $newValue): array
    {
        $sets = [];
        foreach ($update as $column => $expression) {
            if (is_int($column)) {
                $sets[] = $this->quote($expression) . ' = ' . $newValue($expression);
            } else {
                $sets[] = $this->quote($column) . ' = ' . preg_replace_callback('/\{(new|old)\.([a-z_][a-z0-9_]*)\}/i', fn (array $m): string => $m[1] === 'new' ? $newValue($m[2]) : '{' . $this->identifier($table) . '}.' . $this->quote($m[2]), $expression);
            }
        }

        return $sets;
    }

    protected function unit(string $unit): string
    {
        $unit = strtoupper($unit);

        return in_array($unit, self::UNITS, true) ? $unit : throw new \InvalidArgumentException("Invalid interval unit: {$unit}");
    }

    protected function separator(string $separator): string
    {
        return preg_match("/^[^'\\\\]*$/", $separator) === 1 ? $separator : throw new \InvalidArgumentException('Invalid separator.');
    }

    /** @param list<string> $names */
    protected function quoteAll(array $names): string
    {
        return implode(', ', array_map($this->quote(...), $names));
    }

    protected function identifier(string $name): string
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/i', $name) === 1 ? $name : throw new \InvalidArgumentException("Invalid name: {$name}");
    }
}
