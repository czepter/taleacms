<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use PDO;
use Talea\Core\Dialect\Dialect;

/**
 * The database server the suites run against: MySQL 8 (default) or PostgreSQL, chosen with TALEA_TEST_DB_DRIVER=mysql|pgsql.
 * Connection: TALEA_TEST_DB_HOST/_PORT/_USER/_PASSWORD for MySQL, TALEA_TEST_PG_HOST/_PORT/_USER/_PASSWORD for PostgreSQL
 * (defaults of phpunit.xml.dist = the db-test and db-test-pg services of docker-compose-dev.yaml).
 * Creating and dropping throw-away databases is the one place the suites need engine-specific SQL.
 */
final class TestDatabase
{
    public static function driver(): string
    {
        return getenv('TALEA_TEST_DB_DRIVER') === 'pgsql' ? 'pgsql' : 'mysql';
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function dialect(): Dialect
    {
        return Dialect::forDriver(self::driver());
    }

    /** @return array{driver: string, host: string, port: int, username: string, password: string} */
    public static function server(): array
    {
        $env = self::isPostgres() ? 'TALEA_TEST_PG_' : 'TALEA_TEST_DB_';

        return [
            'driver' => self::driver(),
            'host' => (string) (getenv($env . 'HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv($env . 'PORT') ?: (self::isPostgres() ? 5433 : 33061)),
            'username' => (string) (getenv($env . 'USER') ?: (self::isPostgres() ? 'postgres' : 'root')),
            'password' => (string) (getenv($env . 'PASSWORD') !== false ? getenv($env . 'PASSWORD') : (self::isPostgres() ? 'postgres' : 'root')),
        ];
    }

    /** A connection to the server itself (PostgreSQL: its maintenance database), for creating and dropping databases. Throws PDOException when nothing listens. */
    public static function admin(): PDO
    {
        $s = self::server();
        $dsn = self::isPostgres() ? "pgsql:host={$s['host']};port={$s['port']};dbname=postgres" : "mysql:host={$s['host']};port={$s['port']}";

        return new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /** A direct connection to one database of the server (what a test reads and writes with raw SQL). */
    public static function connect(string $database): PDO
    {
        $s = self::server();
        $dsn = self::isPostgres() ? "pgsql:host={$s['host']};port={$s['port']};dbname={$database}" : "mysql:host={$s['host']};port={$s['port']};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    /** A new empty database; $template (PostgreSQL only) = clone of an existing one, the native and fast way of copying a whole database. */
    public static function create(PDO $admin, string $name, ?string $template = null): void
    {
        $quoted = self::dialect()->quote($name);
        if (self::isPostgres()) {
            $admin->exec("CREATE DATABASE {$quoted} " . ($template !== null ? 'TEMPLATE ' . self::dialect()->quote($template) : "ENCODING 'UTF8'"));

            return;
        }
        $admin->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"); // exactly as the installer and docker-compose create it
    }

    public static function drop(PDO $admin, string $name): void
    {
        $admin->exec('DROP DATABASE IF EXISTS ' . self::dialect()->quote($name) . (self::isPostgres() ? ' WITH (FORCE)' : ''));
    }

    /** @return list<string> names of databases matching a LIKE pattern (underscores are literal when escaped with a backslash) */
    public static function find(PDO $admin, string $like): array
    {
        $stmt = self::isPostgres() ? $admin->prepare('SELECT datname FROM pg_database WHERE datname LIKE ?') : $admin->prepare('SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE ?');
        $stmt->execute([$like]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Closes every other session on a database: PostgreSQL refuses to use a database as a template while somebody is connected to it. */
    public static function disconnectOthers(PDO $admin, string $name): void
    {
        if (self::isPostgres()) {
            $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?')->execute([$name]);
        }
    }
}
