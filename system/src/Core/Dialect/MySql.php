<?php

declare(strict_types=1);

namespace Talea\Core\Dialect;

use PDO;

final class MySql extends Dialect
{
    /** @param bool $mariaDb the server is MariaDB (Db::pdo() tells after connecting): the same dialect except the upsert */
    public function __construct(public readonly bool $mariaDb = false)
    {
    }

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

    /** Session modes that change how SQL text is read: Talea's statements (and the ones in a backup) assume neither. */
    private const array UNSAFE_MODES = ['ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES'];

    /**
     * The sql_mode without ANSI_QUOTES (a "…" is then an identifier, not a string) and NO_BACKSLASH_ESCAPES (a backslash is then an
     * ordinary character, so a dump written by another session reads wrongly); null when the mode has neither.
     */
    public static function safeSqlMode(string $mode): ?string
    {
        $modes = array_values(array_filter(array_map('trim', explode(',', $mode)), fn (string $m): bool => $m !== ''));
        $kept = array_values(array_filter($modes, fn (string $m): bool => !in_array(strtoupper($m), self::UNSAFE_MODES, true)));

        return count($kept) === count($modes) ? null : implode(',', $kept);
    }

    /**
     * Takes the two modes out of this session. A host that does not allow changing sql_mode (or a server that refuses the
     * value) is left as it is: the site keeps running, only a backup made in such a session may not read back elsewhere.
     */
    public function useSafeSqlMode(PDO $pdo): void
    {
        try {
            $safe = self::safeSqlMode((string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
            if ($safe !== null) {
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($safe));
            }
        } catch (\Throwable) {
            // not allowed here
        }
    }

    public function connect(PDO $pdo): void
    {
        $this->useSafeSqlMode($pdo);
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

    public function sessionOffsetMinutes(): string
    {
        return 'TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())';
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
        // MySQL 8 deprecates VALUES(col) in favour of a row alias, which MariaDB does not have
        $sets = $this->assignments($table, $update, fn (string $c): string => $this->mariaDb ? 'VALUES(' . $this->quote($c) . ')' : 'new_row.' . $this->quote($c));
        $own = '{' . $this->identifier($table) . '}.' . $this->quote($keys[0]);
        $sets = $sets === [] ? [$own . ' = ' . $own] : $sets; // nothing to update: keep the row

        return sprintf('INSERT INTO {%s} (%s) VALUES (%s)%s ON DUPLICATE KEY UPDATE %s', $this->identifier($table), $this->quoteAll($columns), implode(', ', array_fill(0, count($columns), '?')), $this->mariaDb ? '' : ' AS new_row', implode(', ', $sets));
    }

    public function insertIgnoreSelect(string $table, array $columns, string $select): string
    {
        return sprintf('INSERT IGNORE INTO {%s} (%s) %s', $this->identifier($table), $this->quoteAll($columns), $select);
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

    public function autoKeyColumn(): string
    {
        return 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    public function columnsSql(): string
    {
        return 'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position';
    }

    public function uniqueKeysSql(): string
    {
        return "SELECT index_name AS key_name, column_name AS column_name, (index_name = 'PRIMARY') AS is_primary FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 ORDER BY index_name, seq_in_index";
    }

    public function sizeSql(): string
    {
        return 'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?';
    }

    public function emptyTablesSql(array $tables): array
    {
        return ['SET FOREIGN_KEY_CHECKS = 0', ...array_map(fn (string $t): string => 'DELETE FROM {' . $this->identifier($t) . '}', $tables), 'SET FOREIGN_KEY_CHECKS = 1'];
    }

    public function foreignKeysOff(): array
    {
        return [['SET FOREIGN_KEY_CHECKS = 0'], ['SET FOREIGN_KEY_CHECKS = 1']];
    }

    public function dumpTables(PDO $pdo, string $prefix): array
    {
        $this->useSafeSqlMode($pdo); // first call of a dump and of a restore: SHOW CREATE TABLE and the literals follow the session's mode
        $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name');
        $stmt->execute([addcslashes($prefix, '_%') . '%']);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function dumpPrologue(array $tables): string
    {
        return "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    }

    public function dumpEpilogue(): string
    {
        return "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    public function dumpStructure(PDO $pdo, string $table): string
    {
        $create = $pdo->query('SHOW CREATE TABLE ' . $this->quote($table))->fetch(PDO::FETCH_NUM)[1];

        return 'DROP TABLE IF EXISTS ' . $this->quote($table) . ";\n{$create};\n\n";
    }

    public function dumpInsert(string $table, array $columns): string
    {
        return 'INSERT INTO ' . $this->quote($table) . " VALUES\n";
    }

    public function dumpBooleanColumns(PDO $pdo, string $table): array
    {
        return [];
    }

    public function dumpLiteral(PDO $pdo, string $value): string
    {
        // escaped here, not by PDO::quote(): that follows the session's sql_mode (NO_BACKSLASH_ESCAPES would write a line break as it is,
        // and the file is read line by line), and the file must read the same in any session that restores it
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\n" => '\\n', "\r" => '\\r', "\0" => '\\0', "\x1a" => '\\Z']) . "'";
    }

    public function restoreBegin(PDO $pdo): void
    {
        $this->useSafeSqlMode($pdo);
        if (preg_match('/NO_BACKSLASH_ESCAPES/i', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()) === 1) {
            throw new \RuntimeException('The database session reads backslashes literally (sql_mode NO_BACKSLASH_ESCAPES) and the host does not allow changing it, so a backup cannot be restored here.');
        }
    }

    public function restoreEnd(PDO $pdo, bool $failed): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function currentDatabaseSql(): string
    {
        return 'SELECT DATABASE()';
    }
}
