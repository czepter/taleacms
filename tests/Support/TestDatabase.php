<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use PDO;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\MySql;
use Talea\Core\Dialect\Sqlite;

/**
 * The database server the suites run against: MySQL 8 (default), PostgreSQL or SQLite, chosen with TALEA_TEST_DB_DRIVER=mysql|pgsql|sqlite.
 * Connection: TALEA_TEST_DB_HOST/_PORT/_USER/_PASSWORD for MySQL, TALEA_TEST_PG_HOST/_PORT/_USER/_PASSWORD for PostgreSQL
 * (defaults of phpunit.xml.dist = the db-test and db-test-pg services of docker-compose-dev.yaml); SQLite needs no server, its
 * databases are files in TALEA_TEST_SQLITE_DIR (default: a folder in the temp dir).
 * Creating and dropping throw-away databases is the one place the suites need engine-specific SQL.
 */
final class TestDatabase
{
    public static function driver(): string
    {
        return match (getenv('TALEA_TEST_DB_DRIVER')) {
            'pgsql' => 'pgsql',
            'sqlite' => 'sqlite',
            default => 'mysql',
        };
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    public static function isMySql(): bool
    {
        return self::driver() === 'mysql';
    }

    /** The folder of the SQLite test databases (one file per database). */
    public static function sqliteFolder(): string
    {
        $folder = (string) (getenv('TALEA_TEST_SQLITE_DIR') ?: sys_get_temp_dir() . '/talea-sqlite-tests');
        if (!is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }

        return $folder;
    }

    /** What a configuration names as the database: the name on a server, the absolute path of the file for SQLite. */
    public static function configName(string $database): string
    {
        return self::isSqlite() ? self::sqliteFolder() . '/' . $database . '.sqlite' : $database;
    }

    public static function dialect(): Dialect
    {
        static $mariaDb = null;
        if (!self::isMySql()) {
            return Dialect::forDriver(self::driver());
        }
        $mariaDb ??= str_contains((string) self::admin()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB');

        return new MySql($mariaDb);
    }

    /** @return array{driver: string, host: string, port: int, username: string, password: string} */
    public static function server(): array
    {
        if (self::isSqlite()) {
            return ['driver' => 'sqlite', 'host' => '', 'port' => 0, 'username' => '', 'password' => ''];
        }
        $env = self::isPostgres() ? 'TALEA_TEST_PG_' : 'TALEA_TEST_DB_';

        return [
            'driver' => self::driver(),
            'host' => (string) (getenv($env . 'HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv($env . 'PORT') ?: (self::isPostgres() ? 5433 : 33061)),
            'username' => (string) (getenv($env . 'USER') ?: (self::isPostgres() ? 'postgres' : 'root')),
            'password' => (string) (getenv($env . 'PASSWORD') !== false ? getenv($env . 'PASSWORD') : (self::isPostgres() ? 'postgres' : 'root')),
        ];
    }

    /** A connection to the server itself (PostgreSQL: its maintenance database), for creating and dropping databases. Throws PDOException when nothing listens. SQLite: a database in memory, the files are handled by name. */
    public static function admin(): PDO
    {
        if (self::isSqlite()) {
            return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        $s = self::server();
        $dsn = self::isPostgres() ? "pgsql:host={$s['host']};port={$s['port']};dbname=postgres" : "mysql:host={$s['host']};port={$s['port']}";

        return new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /** A direct connection to one database of the server (what a test reads and writes with raw SQL). */
    public static function connect(string $database): PDO
    {
        if (self::isSqlite()) {
            // the application's own pragmas and functions (NOW(), the folding of LIKE …), so the tests' SQL means what it means in the site
            $pdo = new \Pdo\Sqlite('sqlite:' . self::configName($database), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            (new Sqlite())->connect($pdo);
            self::testFunctions($pdo);

            return $pdo;
        }
        $s = self::server();
        $dsn = self::isPostgres() ? "pgsql:host={$s['host']};port={$s['port']};dbname={$database}" : "mysql:host={$s['host']};port={$s['port']};dbname={$database};charset=utf8mb4";

        // PostgreSQL: a boolean column comes back as 1 or 0 like MySQL's TINYINT(1), as it does in the application (Core\PgStatement)
        return new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC] + (self::isPostgres() ? [PDO::ATTR_STATEMENT_CLASS => [\Talea\Core\PgStatement::class]] : []));
    }

    /** A new empty database; $template (PostgreSQL only) = clone of an existing one, the native and fast way of copying a whole database. */
    public static function create(PDO $admin, string $name, ?string $template = null): void
    {
        if (self::isSqlite()) {
            $template !== null ? copy(self::configName($template), self::configName($name)) : touch(self::configName($name));

            return;
        }
        $quoted = self::dialect()->quote($name);
        if (self::isPostgres()) {
            $admin->exec("CREATE DATABASE {$quoted} " . ($template !== null ? 'TEMPLATE ' . self::dialect()->quote($template) : "ENCODING 'UTF8'"));

            return;
        }
        $admin->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"); // exactly as the installer and docker-compose create it
    }

    public static function drop(PDO $admin, string $name): void
    {
        if (self::isSqlite()) {
            foreach ([self::configName($name), ...glob(self::configName($name) . '[-.]*') ?: []] as $file) { // the file, its -wal and -shm, its lock files
                @unlink($file);
            }

            return;
        }
        $admin->exec('DROP DATABASE IF EXISTS ' . self::dialect()->quote($name) . (self::isPostgres() ? ' WITH (FORCE)' : ''));
    }

    /** @return list<string> names of databases matching a LIKE pattern (underscores are literal when escaped with a backslash) */
    public static function find(PDO $admin, string $like): array
    {
        if (self::isSqlite()) {
            $pattern = '/^' . strtr(preg_quote($like, '/'), ['\\\\_' => '_', '%' => '.*', '_' => '.']) . '$/';

            return array_values(array_filter(array_map(fn (string $f): string => basename($f, '.sqlite'), glob(self::sqliteFolder() . '/*.sqlite') ?: []), fn (string $n): bool => preg_match($pattern, $n) === 1));
        }
        $stmt = self::isPostgres() ? $admin->prepare('SELECT datname FROM pg_database WHERE datname LIKE ?') : $admin->prepare('SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE ?');
        $stmt->execute([$like]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Closes every other session on a database: PostgreSQL refuses to use a database as a template while somebody is connected to it. */
    public static function disconnectOthers(PDO $admin, string $name): void
    {
        if (self::isSqlite()) {
            // a copy of the file is the clone: everything in the write-ahead log goes into the file first
            $pdo = new PDO('sqlite:' . self::configName($name), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('PRAGMA journal_mode = DELETE');

            return;
        }
        if (self::isPostgres()) {
            $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?')->execute([$name]);
        }
    }

    /** MySQL functions the tests' own SQL uses that SQLite does not have (the product's SQL never needs them). */
    private static function testFunctions(\Pdo\Sqlite $pdo): void
    {
        $define = $pdo->createFunction(...);
        // the clock of the test's own SQL is the database server's on MySQL and PostgreSQL (UTC in the dev stack and CI), not the time zone a test set in PHP
        $define('NOW', fn (): string => gmdate('Y-m-d H:i:s'), 0);
        $define('CURDATE', fn (): string => gmdate('Y-m-d'), 0);
        $define('SHA2', fn (mixed $text, mixed $bits): ?string => $text === null ? null : hash('sha' . (int) $bits, (string) $text), 2);
        $define('MD5', fn (mixed $text): ?string => $text === null ? null : md5((string) $text), 1);
        $define('FIND_IN_SET', fn (mixed $needle, mixed $list): int => ($i = array_search((string) $needle, explode(',', (string) $list), true)) === false ? 0 : $i + 1, 2);
        $define('DAYOFWEEK', fn (mixed $date): ?int => $date === null ? null : (int) date('w', (int) strtotime((string) $date)) + 1, 1);
        $define('UNIX_TIMESTAMP', fn (mixed $date = null): ?int => func_num_args() === 0 ? time() : ($date === null ? null : (int) strtotime((string) $date)), -1);
        // MySQL's JSON_EXTRACT gives JSON text ('true', '"text"'), SQLite's json_extract the SQL value (1, text); TestSql renames the tests' calls
        $define('mysql_json_extract', fn (mixed $json, mixed $path): ?string => $json === null || ($v = self::jsonAt($json, $path, $found)) === null && !$found ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 2);
        $define('JSON_UNQUOTE', fn (mixed $value): mixed => is_string($value) && str_starts_with($value, '"') ? json_decode($value) : $value, 1);
        $define('JSON_LENGTH', fn (mixed $json, mixed $path = null): ?int => ($v = self::jsonAt($json, $path)) === null ? null : (is_array($v) ? count($v) : 1), -1);
    }

    /** The value at a path (`$.a.b[0]`, `$."a b"`); $found tells a JSON null from a missing key. */
    private static function jsonAt(mixed $json, mixed $path, ?bool &$found = null): mixed
    {
        $value = json_decode((string) $json, true);
        $found = true;
        foreach ($path === null ? [] : array_filter(preg_split('/[.\[\]]+/', (string) preg_replace('/^\$\.?/', '', (string) $path)) ?: [], 'strlen') as $key) {
            $key = trim($key, '"');
            $found = is_array($value) && array_key_exists($key, $value);
            $value = $found ? $value[$key] : null;
        }

        return $value;
    }
}
