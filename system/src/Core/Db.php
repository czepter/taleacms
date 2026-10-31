<?php

declare(strict_types=1);

namespace Talea\Core;

use PDO;
use PDOStatement;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\MySql;
use Talea\Core\Dialect\Sqlite;

/**
 * A thin layer over PDO.
 *
 * Table names are written in SQL in curly braces without a prefix:
 *   SELECT * FROM {news} WHERE id = ?
 * and on execution the prefix from the configuration is filled in (default "tl_").
 *
 * Multisite (docs/specs/multisite.md): a site's tables carry the site prefix ($prefix: "tl_" for site 1, "tl2_" …), the owner's
 * shared tables (OWNER_TABLES) always the owner prefix. Queries do not change; {pages} and {users} land on the right table.
 */
final class Db
{
    /** Tables whose rows are addressed from outside by a public UUID v4 (column public_id); insert() fills it. The integer key never leaves the database layer. */
    /** table => primary key column of the same tables (the integer key that never leaves the database layer). */
    public const array PRIMARY_KEYS = ['users' => 'user_id', 'categories' => 'category_id', 'news' => 'news_id', 'tags' => 'tag_id', 'media' => 'media_id', 'media_folders' => 'folder_id', 'pages' => 'page_id', 'collections' => 'collection_id', 'collection_items' => 'item_id', 'popups' => 'popup_id', 'components' => 'component_id', 'forms' => 'form_id', 'sections' => 'section_id', 'enquiries' => 'enquiry_id', 'subscribers' => 'subscriber_id', 'newsletters' => 'id', 'redirects' => 'redirect_id', 'api_tokens' => 'token_id', 'user_passkeys' => 'passkey_id', 'bookings' => 'id', 'booking_services' => 'id', 'booking_staff' => 'id', 'requests' => 'id', 'fleet_sites' => 'id', 'experiments' => 'experiment_id', 'members' => 'member_id', 'member_groups' => 'group_id', 'monthly_reports' => 'report_id', 'sites' => 'site_id', 'owners' => 'owner_id'];

    public const array PUBLIC_ID_TABLES = ['users', 'categories', 'news', 'tags', 'media', 'media_folders', 'pages', 'collections', 'collection_items', 'popups', 'components', 'forms', 'sections', 'enquiries', 'subscribers', 'newsletters', 'redirects', 'api_tokens', 'user_passkeys', 'bookings', 'booking_services', 'booking_staff', 'requests', 'fleet_sites', 'experiments', 'members', 'member_groups', 'monthly_reports', 'sites', 'owners'];

    /** Tables shared by every site of the owner: always under the owner prefix. Every other table exists once per site prefix. */
    public const array OWNER_TABLES = ['users', 'user_passkeys', 'user_permissions', 'user_remember_tokens', 'user_devices', 'role', 'media', 'media_folders', 'components', 'facts', 'fact_history', 'api_tokens', 'oauth_clients', 'oauth_codes', 'agent_sessions', 'agent_journal', 'sites', 'site_domains', 'handoff_tokens', 'user_sites'];

    /** Tables of the install, shared by every owner (several owners, docs/specs/multisite.md): always under the install prefix, the configured base prefix. */
    public const array INSTALL_TABLES = ['owners'];

    /** Prefix of the owner tables; the same as $prefix for site 1. */
    public readonly string $ownerPrefix;

    /** Prefix of the install tables and of the first owner (and its site 1); the same as $ownerPrefix with one owner. */
    public readonly string $installPrefix;

    private ?PDO $pdo = null;
    private ?Dialect $dialect = null;
    /** @var array<string, ?string> memo of identityColumn() ('' = none, SQLite) */
    private array $identity = [];
    /** @var array<string, array<string, array{primary: bool, columns: list<string>}>> memo of keyRows(): table => key name => columns */
    private array $keyMemo = [];
    /** @var array<string, array<int, string>> memo of publicId() */
    private array $publicIds = [];

    /** @var array<string, mixed> the configuration fromConfig() was built from (the Migrator needs it to bring a site's tables back) */
    private array $config = [];

    public int $queryCount = 0;

    /** While a Claude connection runs a tool that changes the site: every content write is journaled for undo (2.17). */
    public ?AgentJournal $journal = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        public readonly string $prefix = 'tl_',
        string $ownerPrefix = '',
        string $installPrefix = '',
    ) {
        $this->ownerPrefix = $ownerPrefix !== '' ? $ownerPrefix : $prefix;
        $this->installPrefix = $installPrefix !== '' ? $installPrefix : $this->ownerPrefix;
    }

    /** @param array{driver?:string,host?:string,port?:int,socket?:string,name:string,username?:string,password?:string,prefix?:string,owner_prefix?:string,site_prefix?:string} $c driver: mysql (default) | pgsql | sqlite (name = the file); prefix = the install's (owner 1, its site 1), owner_prefix = a further owner's, site_prefix = another site's */
    public static function fromConfig(array $c): self
    {
        $dialect = Dialect::forDriver($c['driver'] ?? 'mysql');
        $c['port'] ??= $dialect->defaultPort();

        $install = $c['prefix'] ?? 'tl_';
        $owner = $c['owner_prefix'] ?? $install;
        $db = new self($dialect->dsn($c), $c['username'] ?? '', $c['password'] ?? '', $c['site_prefix'] ?? $owner, $owner, $install);
        unset($c['site_prefix']);
        $db->config = $c;

        return $db;
    }

    /** The configuration this connection was made from (without a site prefix; a further owner's connection carries owner_prefix); empty when it was not made by fromConfig(). @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** The tables of the site with this prefix, on the same connection. */
    public function forSite(string $prefix): self
    {
        $db = new self($this->dsn, $this->user, $this->password, $prefix, $this->ownerPrefix, $this->installPrefix);
        $db->pdo = $this->pdo();
        $db->dialect = $this->dialect;
        $db->config = $this->config;

        return $db;
    }

    /** The tables of another owner (its owner prefix, which is also its site 1) on the same connection; the install tables stay the install's. */
    public function forOwner(string $ownerPrefix): self
    {
        $db = new self($this->dsn, $this->user, $this->password, $ownerPrefix, $ownerPrefix, $this->installPrefix);
        $db->pdo = $this->pdo();
        $db->dialect = $this->dialect;
        $db->config = ($ownerPrefix === $this->installPrefix ? [] : ['owner_prefix' => $ownerPrefix]) + array_diff_key($this->config, ['owner_prefix' => 1]);

        return $db;
    }

    /** Real name of a table (name without prefix): the install prefix for INSTALL_TABLES, the owner prefix for OWNER_TABLES, the site prefix for the rest. */
    public function table(string $table): string
    {
        return $this->tablePrefix($table) . $table;
    }

    /** The prefix a table (name without prefix) lives under. */
    public function tablePrefix(string $table): string
    {
        return match (true) {
            in_array($table, self::INSTALL_TABLES, true) => $this->installPrefix,
            in_array($table, self::OWNER_TABLES, true) => $this->ownerPrefix,
            default => $this->prefix,
        };
    }

    /** The SQL differences of the engine this connection talks to (upsert, JSON, intervals, locks, full-text …). */
    public function dialect(): Dialect
    {
        return $this->dialect ??= Dialect::forDriver((string) strstr($this->dsn, ':', true));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $sqlite = $this->dialect() instanceof Sqlite;
            if ($sqlite && !is_dir($folder = dirname(substr($this->dsn, 7))) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new \RuntimeException('The folder of the database file cannot be created: ' . $folder);
            }
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ] + ($this->dialect()->name() === 'pgsql' ? [PDO::ATTR_STATEMENT_CLASS => [PgStatement::class]] : []);
            // SQLite: the driver's own class, which can register PHP functions (Dialect\Sqlite::connect)
            $this->pdo = $sqlite ? new \Pdo\Sqlite($this->dsn, null, null, $options) : new PDO($this->dsn, $this->user, $this->password, $options);
            $this->dialect()->connect($this->pdo);
            // The database must count time the same way as PHP: PHP writes article dates (date()), but queries compare them with NOW().
            // On a server with the database in a different zone (typically UTC) a just published article would show up hours later.
            // An offset instead of a zone name: named zones need loaded tables in MySQL, which are often missing on hosting.
            $this->dialect()->setTimeZone($this->pdo, date('P'));
            if ($this->dialect() instanceof MySql && str_contains((string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
                $this->dialect = new MySql(mariaDb: true);
            }
        }

        return $this->pdo;
    }

    /** Fills in the table prefix: {news} -> `tl_news` (quoted the way the engine wants). */
    public function sql(string $sql): string
    {
        $dialect = $this->dialect();

        return $dialect->rewrite(preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            fn (array $m): string => $dialect->quote($this->table($m[1])),
            $sql,
        ) ?? $sql);
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
            implode(', ', array_map($this->quoteName(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $pg = $this->dialect()->name() === 'pgsql'; // PostgreSQL has no LAST_INSERT_ID: the new key comes back with the INSERT
        $key = $pg ? (self::PRIMARY_KEYS[$table] ?? $this->identityColumn($table)) : null;
        $statement = $this->withoutJournal(fn (): PDOStatement => $this->run($sql . ($key !== null ? $this->dialect()->returning($key) : ''), array_values($data)));
        // SQLite numbers every row (rowid): a table without an auto-numbered key reports 0, as on MySQL
        $id = match (true) {
            $key !== null => (int) $statement->fetchColumn(),
            $pg, $this->dialect() instanceof Sqlite && !isset(self::PRIMARY_KEYS[$table]) && $this->identityColumn($table) === null => 0,
            default => (int) $this->pdo()->lastInsertId(),
        };
        if ($this->journal !== null && AgentJournal::journaled($table)) {
            $this->journal->inserted($table, $data, $id);
        }

        return $id;
    }

    /** Inserts a row, or updates $update (default: all other columns) of the row that has the same $keys (a unique key). $update: see Dialect::upsert() (`['count' => 'count + 1']`). */
    public function upsert(string $table, array $data, array $keys, ?array $update = null): void
    {
        $columns = array_keys($data);
        $this->pdo(); // connected first: the upsert differs on MariaDB
        $this->run($this->dialect()->upsert($table, $columns, $keys, $update ?? array_values(array_diff($columns, $keys))), array_values($data));
    }

    /** `INSERT ... SELECT` that skips rows violating a unique key; returns the number added. $select is a complete SELECT ({tables}, ? placeholders). @param list<string> $columns */
    public function insertIgnoreSelect(string $table, array $columns, string $select, array $params = []): int
    {
        return $this->run($this->dialect()->insertIgnoreSelect($table, $columns, $select), $params)->rowCount();
    }

    /** Inserts a row unless it violates a unique key (then nothing happens); returns whether a row was added. */
    public function insertIgnore(string $table, array $data): bool
    {
        return $this->run($this->dialect()->insertIgnore($table, array_keys($data)), array_values($data))->rowCount() > 0;
    }

    /** Takes a named lock (between processes) for at most $timeout seconds; false = somebody else holds it. Released with unlock() or when the connection ends. */
    public function lock(string $name, int $timeout = 0): bool
    {
        return $this->dialect()->lock($this->pdo(), $name, $timeout);
    }

    public function unlock(string $name): void
    {
        $this->dialect()->unlock($this->pdo(), $name);
    }

    /** Does the table (name without prefix) exist in the current database? */
    public function tableExists(string $table): bool
    {
        return (int) $this->value($this->dialect()->tableExistsSql(), [$this->table($table)]) > 0;
    }

    /** Empties tables (names without prefix) whatever the foreign keys say; see Dialect::emptyTablesSql(). @param list<string> $tables */
    public function emptyTables(array $tables): void
    {
        foreach ($this->dialect()->emptyTablesSql($tables) as $sql) {
            $this->run($sql);
        }
    }

    /** After rows were inserted with explicit auto-numbers (import, restore): PostgreSQL's counter must move past them, MySQL's does it itself. */
    public function syncSequences(string ...$tables): void
    {
        if ($this->dialect()->name() !== 'pgsql') {
            return;
        }
        foreach ($tables as $table) {
            $key = self::PRIMARY_KEYS[$table] ?? $this->identityColumn($table);
            $sql = $key === null ? '' : $this->dialect()->syncSequenceSql($table, $key);
            if ($sql !== '') {
                $this->run($sql);
            }
        }
    }

    /** Column names of a table (name without prefix) in their order. @return list<string> */
    public function columns(string $table): array
    {
        return array_map('strval', array_column($this->all($this->dialect()->columnsSql(), [$this->table($table)]), 'name'));
    }

    /** Every unique key of a table (name without prefix), the primary key included: key name => columns in order. @return array<string, list<string>> */
    public function uniqueKeys(string $table): array
    {
        return $this->keyRows($table, false);
    }

    /** '' for the install's own tables (owner 1, site 1), else the prefix of the table. */
    private function keyPrefix(string $table): string
    {
        $prefix = $this->tablePrefix($table);

        return $prefix !== $this->installPrefix ? $prefix : '';
    }

    /** The primary key columns of a table (name without prefix). @return list<string> */
    public function primaryKey(string $table): array
    {
        $keys = $this->keyRows($table, true);

        return $keys === [] ? [] : array_values(reset($keys));
    }

    /** @return array<string, list<string>> */
    private function keyRows(string $table, bool $primaryOnly): array
    {
        if (!isset($this->keyMemo[$table])) {
            foreach ($this->all($this->dialect()->uniqueKeysSql(), [$this->table($table)]) as $r) {
                $name = (string) $r['key_name'];
                if (($keyPrefix = $this->keyPrefix($table)) !== '' && str_starts_with($name, $keyPrefix)) {
                    $name = substr($name, strlen($keyPrefix)); // the name the migration gave it
                }
                $this->keyMemo[$table][$name] = ['primary' => (int) $r['is_primary'] === 1, 'columns' => [...($this->keyMemo[$table][$name]['columns'] ?? []), (string) $r['column_name']]];
            }
            $this->keyMemo[$table] ??= [];
        }
        $keys = [];
        foreach ($this->keyMemo[$table] as $name => $k) {
            if (!$primaryOnly || $k['primary']) {
                $keys[$name] = $k['columns'];
            }
        }

        return $keys;
    }

    /** Bytes of data and indexes of this site's tables (the prefix), 0 when the engine cannot tell. */
    public function tablesSize(): int
    {
        return (int) $this->value($this->dialect()->sizeSql(), [addcslashes($this->prefix, '_%') . '%']);
    }

    public function databaseName(): string
    {
        return (string) $this->value($this->dialect()->currentDatabaseSql());
    }

    /** PostgreSQL and SQLite: the auto-numbered column of a table (PostgreSQL has no LAST_INSERT_ID, SQLite numbers every row), null when there is none. */
    private function identityColumn(string $table): ?string
    {
        if ($this->dialect() instanceof Sqlite) {
            // the one INTEGER column of the primary key is the rowid; '' remembers that there is none
            return ($this->identity[$table] ??= (string) $this->value(
                "SELECT MAX(name) FROM pragma_table_info(?) WHERE pk > 0 HAVING COUNT(*) = 1 AND MAX(upper(type)) = 'INTEGER'",
                [$this->table($table)],
            )) ?: null;
        }

        return $this->identity[$table] ??= ($this->value(
            "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = ?::regclass AND a.attidentity <> '' AND NOT a.attisdropped",
            ['"' . $this->table($table) . '"'],
        ) ?: null);
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

        return (int) $this->value('SELECT ' . $this->dialect()->quote($pk) . ' FROM {' . $table . '} WHERE public_id = ?', [$uuid]);
    }

    /** The public id (UUID v4) of the row with this integer key, '' when there is none: what a link, a result or a payload carries instead of the number. */
    public function publicId(string $table, int $id): string
    {
        $pk = self::PRIMARY_KEYS[$table] ?? null;
        if ($pk === null || $id <= 0) {
            return '';
        }

        return (string) ($this->publicIds[$table][$id] ??= (string) $this->value('SELECT public_id FROM {' . $table . '} WHERE ' . $this->dialect()->quote($pk) . ' = ?', [$id]));
    }

    /**
     * @param array<string, scalar|null> $data
     * @param array<string, scalar|null> $where conditions joined with AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn (string $c): string => $this->quoteName($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $c): string => $this->quoteName($c) . ' = ?', array_keys($where)));
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
        $cond = implode(' AND ', array_map(fn (string $c): string => $this->quoteName($c) . ' = ?', array_keys($where)));
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

    private function quoteName(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Invalid column name: {$name}");
        }

        return $this->dialect()->quote($name);
    }
}
