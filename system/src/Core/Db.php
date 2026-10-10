<?php

declare(strict_types=1);

namespace Kaleta\Core;

use PDO;
use PDOStatement;

/**
 * A thin layer over PDO.
 *
 * Table names are written in SQL in curly braces without a prefix:
 *   SELECT * FROM {news} WHERE id = ?
 * and on execution the prefix from the configuration is filled in (default "ka_").
 */
final class Db
{
    /** Tables whose rows are addressed from outside by a public UUID v4 (column public_id); insert() fills it. The integer key never leaves the database layer. */
    /** table => primary key column of the same tables (the integer key that never leaves the database layer). */
    public const array PRIMARY_KEYS = ['users' => 'user_id', 'categories' => 'category_id', 'news' => 'news_id', 'tags' => 'tag_id', 'media' => 'media_id', 'media_folders' => 'folder_id', 'pages' => 'page_id', 'collections' => 'collection_id', 'collection_items' => 'item_id', 'popups' => 'popup_id', 'components' => 'component_id', 'sections' => 'section_id', 'enquiries' => 'enquiry_id', 'subscribers' => 'subscriber_id', 'newsletters' => 'id', 'redirects' => 'redirect_id', 'api_tokens' => 'token_id', 'user_passkeys' => 'passkey_id', 'bookings' => 'id', 'booking_services' => 'id', 'booking_staff' => 'id', 'requests' => 'id', 'fleet_sites' => 'id'];

    public const array PUBLIC_ID_TABLES = ['users', 'categories', 'news', 'tags', 'media', 'media_folders', 'pages', 'collections', 'collection_items', 'popups', 'components', 'sections', 'enquiries', 'subscribers', 'newsletters', 'redirects', 'api_tokens', 'user_passkeys', 'bookings', 'booking_services', 'booking_staff', 'requests', 'fleet_sites'];

    private ?PDO $pdo = null;
    /** @var array<string, array<int, string>> memo of publicId() */
    private array $publicIds = [];

    public int $queryCount = 0;

    /** While a Claude connection runs a tool that changes the site: every content write is journaled for undo (2.17). */
    public ?AgentJournal $journal = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        public readonly string $prefix = 'ka_',
    ) {
    }

    /** @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $c */
    public static function fromConfig(array $c): self
    {
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);

        return new self($dsn, $c['username'], $c['password'], $c['prefix'] ?? 'ka_');
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // The database must count time the same way as PHP: PHP writes article dates (date()), but queries compare them with NOW().
            // On a server with the database in a different zone (typically UTC) a just published article would show up hours later.
            // An offset instead of a zone name: named zones need loaded tables in MySQL, which are often missing on hosting.
            $this->pdo->exec("SET time_zone = '" . date('P') . "'");
        }

        return $this->pdo;
    }

    /** Fills in the table prefix: {news} -> `ka_news`. */
    public function sql(string $sql): string
    {
        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            fn (array $m): string => '`' . $this->prefix . $m[1] . '`',
            $sql,
        ) ?? $sql;
    }

    /** @param array<int|string, scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $journaled = $this->journal?->aroundRaw($sql, $params);
        $stmt = $this->pdo()->prepare($this->sql($sql));
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        $this->queryCount++;
        if ($journaled !== null) {
            $journaled();
        }

        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** First column as key, second as value - handy for <select>. */
    public function pairs(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, array $data): int
    {
        if (in_array($table, self::PUBLIC_ID_TABLES, true) && !isset($data['public_id'])) {
            $data['public_id'] = Uuid::v4();
        }
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO {%s} (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(self::quoteName(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->withoutJournal(fn (): PDOStatement => $this->run($sql, array_values($data)));
        $id = (int) $this->pdo()->lastInsertId();
        if ($this->journal !== null && AgentJournal::journaled($table)) {
            $this->journal->inserted($table, $data, $id);
        }

        return $id;
    }

    /**
     * The row with this public identifier (UUID v4), or null. The pattern is checked before any SQL, so a malformed value never reaches the database.
     *
     * @return array<string, mixed>|null
     */
    public function byPublicId(string $table, mixed $uuid): ?array
    {
        if (!in_array($table, self::PUBLIC_ID_TABLES, true) || !Uuid::valid($uuid)) {
            return null;
        }

        return $this->one('SELECT * FROM {' . $table . '} WHERE public_id = ?', [$uuid]);
    }

    /** The integer key of the row with this public id (UUID v4), or 0: unknown table, malformed or unknown id. Integer ids from outside are never accepted. */
    public function internalId(string $table, mixed $uuid): int
    {
        $pk = self::PRIMARY_KEYS[$table] ?? null;
        if ($pk === null || !Uuid::valid($uuid)) {
            return 0;
        }

        return (int) $this->value('SELECT `' . $pk . '` FROM {' . $table . '} WHERE public_id = ?', [$uuid]);
    }

    /** The public id (UUID v4) of the row with this integer key, '' when there is none: what a link, a result or a payload carries instead of the number. */
    public function publicId(string $table, int $id): string
    {
        $pk = self::PRIMARY_KEYS[$table] ?? null;
        if ($pk === null || $id <= 0) {
            return '';
        }

        return (string) ($this->publicIds[$table][$id] ??= (string) $this->value('SELECT public_id FROM {' . $table . '} WHERE `' . $pk . '` = ?', [$id]));
    }

    /**
     * @param array<string, scalar|null> $data
     * @param array<string, scalar|null> $where conditions joined with AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE {%s} SET %s WHERE %s', $table, $set, $cond);
        $before = $this->journal !== null && AgentJournal::journaled($table) ? $this->journal->rowsWhere($table, $cond, array_values($where)) : null;
        $count = $this->withoutJournal(fn (): int => $this->run($sql, [...array_values($data), ...array_values($where)])->rowCount());
        if ($before !== null) {
            $this->journal?->record($table, $before);
        }

        return $count;
    }

    /** @param array<string, scalar|null> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \LogicException('Deleting without a condition is not allowed.');
        }
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $before = $this->journal !== null && AgentJournal::journaled($table) ? $this->journal->rowsWhere($table, $cond, array_values($where)) : null;
        $count = $this->withoutJournal(fn (): int => $this->run(sprintf('DELETE FROM {%s} WHERE %s', $table, $cond), array_values($where))->rowCount());
        if ($before !== null) {
            $this->journal?->record($table, $before);
        }

        return $count;
    }

    /** A helper's own statement: the helper journals it with the rows it knows, run() must not journal it again. */
    private function withoutJournal(\Closure $fn): mixed
    {
        $journal = $this->journal;
        $this->journal = null;
        try {
            return $fn();
        } finally {
            $this->journal = $journal;
        }
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo()->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo()->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    private static function quoteName(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Invalid column name: {$name}");
        }

        return '`' . $name . '`';
    }
}
