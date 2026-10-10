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

    /** INSERT of one row, or an update of the columns in $update when a row with the same $keys exists. Without $update an existing row stays as it is. @param list<string> $columns @param list<string> $keys @param list<string> $update */
    abstract public function upsert(string $table, array $columns, array $keys, array $update): string;

    /** INSERT that silently skips a row violating a unique key (MySQL INSERT IGNORE, PostgreSQL ON CONFLICT DO NOTHING). @param list<string> $columns */
    abstract public function insertIgnore(string $table, array $columns): string;

    /**
     * The text at a path of a JSON column as a string (NULL when it is not there). Path: `$.a.b` or `a.b`, with [0] for list items.
     * The column is an expression written as it is (`data`, `c.data`); columns holding JSON are TEXT, so PostgreSQL casts them.
     */
    abstract public function jsonExtract(string $column, string $path): string;

    /** Values of a group joined into one string. $orderBy is an expression; with $distinct it must be the expression itself. */
    abstract public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string;

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
