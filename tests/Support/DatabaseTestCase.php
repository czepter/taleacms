<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

use Talea\Core\Db;
use Talea\Core\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the database: one throw-away database per test class, built by the real migrations
 * (Core\Migrator), every test in a transaction that is rolled back. Skips (does not fail) when no database is reachable, so
 * `vendor/bin/phpunit` still works on a machine without the dev stack.
 *
 * The engine (MySQL 8 or PostgreSQL) and the connection come from Support\TestDatabase (TALEA_TEST_DB_DRIVER, TALEA_TEST_DB_* / TALEA_TEST_PG_*).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const string PREFIX = 'tl_';

    /** @var array<string, mixed>|null connection settings of the class's database; null = no database reachable */
    private static ?array $config = null;
    private static ?string $database = null;
    private static ?Db $db = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $server = TestDatabase::server();
        try {
            $admin = TestDatabase::admin();
        } catch (\PDOException) {
            self::$config = null;

            return;
        }
        self::$database = 'talea_phpunit_' . getmypid() . '_' . substr(md5(static::class), 0, 6);
        TestDatabase::drop($admin, self::$database);
        TestDatabase::create($admin, self::$database);
        self::$config = ['driver' => $server['driver'], 'host' => $server['host'], 'port' => $server['port'], 'name' => self::$database, 'username' => $server['username'], 'password' => $server['password'], 'prefix' => static::PREFIX];
        Migrator::migrate(self::$config);
        self::$db = Db::fromConfig(self::$config);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$config !== null) {
            self::$db = null;
            TestDatabase::drop(TestDatabase::admin(), (string) self::$database);
        }
        self::$config = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$config === null) {
            $this->markTestSkipped('No database reachable (start the db-test or db-test-pg service: docker compose -f docker-compose-dev.yaml up -d db-test db-test-pg).');
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

    protected function isPostgres(): bool
    {
        return TestDatabase::isPostgres();
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
