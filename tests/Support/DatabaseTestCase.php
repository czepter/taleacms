<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use Talea\Core\Db;
use Talea\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the database: one throw-away database per test class, built by the real migrations
 * (Core\Migrator), every test in a transaction that is rolled back. Skips (does not fail) when no MySQL is reachable, so
 * `vendor/bin/phpunit` still works on a machine without the dev stack.
 *
 * Connection: TALEA_TEST_DB_HOST / _PORT / _USER / _PASSWORD (defaults of phpunit.xml.dist = the db-test service of docker-compose-dev.yaml).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const string PREFIX = 'tl_';

    /** @var array<string, mixed>|null connection settings of the class's database; null = no MySQL reachable */
    private static ?array $config = null;
    private static ?string $database = null;
    private static ?Db $db = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $server = [
            'host' => (string) getenv('TALEA_TEST_DB_HOST'), 'port' => (int) getenv('TALEA_TEST_DB_PORT'),
            'username' => (string) getenv('TALEA_TEST_DB_USER'), 'password' => (string) getenv('TALEA_TEST_DB_PASSWORD'),
        ];
        try {
            $admin = new PDO("mysql:host={$server['host']};port={$server['port']}", $server['username'], $server['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            self::$config = null;

            return;
        }
        self::$database = 'talea_phpunit_' . getmypid() . '_' . substr(md5(static::class), 0, 6);
        // the charset and collation are the database's, exactly as the installer and docker-compose create it
        $admin->exec('DROP DATABASE IF EXISTS `' . self::$database . '`');
        $admin->exec('CREATE DATABASE `' . self::$database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        self::$config = ['host' => $server['host'], 'port' => $server['port'], 'name' => self::$database, 'username' => $server['username'], 'password' => $server['password'], 'prefix' => static::PREFIX];
        Migrator::migrate(self::$config);
        self::$db = Db::fromConfig(self::$config);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$config !== null) {
            self::$db = null;
            $admin = new PDO(sprintf('mysql:host=%s;port=%d', self::$config['host'], self::$config['port']), (string) self::$config['username'], (string) self::$config['password']);
            $admin->exec('DROP DATABASE IF EXISTS `' . self::$database . '`');
        }
        self::$config = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$config === null) {
            $this->markTestSkipped('No MySQL reachable (start the db-test service: docker compose -f docker-compose-dev.yaml up -d db-test).');
        }
        self::$db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$db !== null && self::$db->pdo()->inTransaction()) {
            self::$db->pdo()->rollBack();
        }
        parent::tearDown();
    }

    protected function db(): Db
    {
        return self::$db ?? throw new \LogicException('No database.');
    }

    /** @return array<string, mixed> */
    protected function dbConfig(): array
    {
        return self::$config ?? throw new \LogicException('No database.');
    }
}
