<?php

declare(strict_types=1);

namespace Talea\Core\Dialect;

use PDO;

final class MySql extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function dsn(array $c): string
    {
        return !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);
    }

    public function phinx(): array
    {
        // charset and collation are set here once; the database is created with the same ones and tables inherit them
        return ['adapter' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'];
    }

    public function quote(string $identifier): string
    {
        return '`' . $this->identifier($identifier) . '`';
    }

    public function setTimeZone(PDO $pdo, string $offset): void
    {
        // an offset instead of a zone name: named zones need loaded tables in MySQL, which are often missing on hosting
        $pdo->exec("SET time_zone = '" . $offset . "'");
    }

    public function interval(int $amount, string $unit): string
    {
        return 'INTERVAL ' . $amount . ' ' . $this->unit($unit);
    }

    public function unixTime(string $expression): string
    {
        return 'UNIX_TIMESTAMP(' . $expression . ')';
    }

    public function upsert(string $table, array $columns, array $keys, array $update): string
    {
        $sets = array_map(fn (string $c): string => $this->quote($c) . ' = new_row.' . $this->quote($c), $update);
        $own = '{' . $this->identifier($table) . '}.' . $this->quote($keys[0]);
        $sets = $sets === [] ? [$own . ' = ' . $own] : $sets; // nothing to update: keep the row

        return sprintf('INSERT INTO {%s} (%s) VALUES (%s) AS new_row ON DUPLICATE KEY UPDATE %s', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')), implode(', ', $sets));
    }

    public function insertIgnore(string $table, array $columns): string
    {
        return sprintf('INSERT IGNORE INTO {%s} (%s) VALUES (%s)', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')));
    }

    public function jsonExtract(string $column, string $path): string
    {
        $mysqlPath = '$';
        foreach ($this->jsonPath($path) as $key) {
            $mysqlPath .= is_int($key) ? '[' . $key . ']' : '."' . $key . '"';
        }

        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$mysqlPath}'))";
    }

    public function groupConcat(string $expression, string $separator = ',', ?string $orderBy = null, bool $distinct = false): string
    {
        return 'GROUP_CONCAT(' . ($distinct ? 'DISTINCT ' : '') . $expression . ($orderBy !== null ? ' ORDER BY ' . $orderBy : '') . " SEPARATOR '" . $this->separator($separator) . "')";
    }

    public function fulltextMatch(array $columns, string $placeholder = '?'): string
    {
        return 'MATCH(' . $this->quoteAll($columns) . ') AGAINST (' . $placeholder . ' IN BOOLEAN MODE)';
    }

    public function fulltextQuery(array $words): string
    {
        return implode(' ', array_map(fn (string $w): string => '+' . $w . '*', $words));
    }

    public function fulltextIndex(string $table, string $index, array $columns): string
    {
        return sprintf('CREATE FULLTEXT INDEX %s ON %s (%s)', $this->quote($index), $this->quote($table), $this->quoteAll($columns));
    }

    public function likeInsensitive(string $column, string $placeholder = '?'): string
    {
        return $column . ' LIKE ' . $placeholder; // the default collation is accent- and case-insensitive
    }

    public function lock(PDO $pdo, string $name, int $timeout): bool
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([substr($name, 0, 64), $timeout]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function unlock(PDO $pdo, string $name): void
    {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([substr($name, 0, 64)]);
    }

    public function tableExistsSql(): string
    {
        return 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    }

    public function currentDatabaseSql(): string
    {
        return 'SELECT DATABASE()';
    }
}
