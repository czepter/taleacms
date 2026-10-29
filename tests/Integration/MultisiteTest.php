<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Backup;
use Talea\Core\Migrator;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Multisite phase 0 (docs/specs/multisite.md): a second site in the class's database, migrated, isolated and backed up with the first. */
#[CoversClass(Sites::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(Backup::class)]
final class MultisiteTest extends DatabaseTestCase
{
    private static bool $secondSite = false;

    protected function setUp(): void
    {
        // the second site's tables are DDL, which ends a transaction on MySQL: made once, before the test's transaction starts
        if (!self::$secondSite) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null; // no database: parent::setUp() skips the test
            }
            if ($config !== null) {
                Sites::add($this->db(), 'Second', 'second');
                Migrator::migrate($config);
                self::$secondSite = true;
            }
        }
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        self::$secondSite = false;
        parent::tearDownAfterClass();
    }

    public function testSiteOneIsRegisteredWithTheOwnerPrefix(): void
    {
        $this->assertSame(['tl_', 'tl2_'], Sites::prefixes($this->db()));
        $this->assertSame('main', $this->db()->value('SELECT slug FROM {sites} WHERE site_id = 1'));
        $this->assertSame('paused', $this->db()->value('SELECT status FROM {sites} WHERE prefix = ?', ['tl2_']));
    }

    public function testAFurtherSiteGetsItsOwnSiteTablesButNoOwnerTables(): void
    {
        $site = $this->db()->forSite('tl2_');
        $exists = fn (string $real): bool => (int) $this->db()->value($this->db()->dialect()->tableExistsSql(), [$real]) > 0;

        $this->assertTrue($exists('tl2_pages'));
        $this->assertTrue($exists('tl2_settings'));
        $this->assertTrue($exists('tl2_media_usage'));
        $this->assertFalse($exists('tl2_users'), 'owner tables exist once, under the owner prefix');
        $this->assertFalse($exists('tl2_sites'));
        $this->assertSame([], Migrator::pending($site));
        $this->assertSame([], Migrator::migrate($this->dbConfig()), 'migrating again changes nothing on either site');
    }

    public function testASiteNeverSeesAnotherSitesRows(): void
    {
        $site = $this->db()->forSite('tl2_');
        $site->upsert('settings', ['name' => 'multisite_probe', 'value' => 'second'], ['name']);

        $this->assertSame('second', $site->value('SELECT value FROM {settings} WHERE name = ?', ['multisite_probe']));
        $this->assertNull($this->db()->value('SELECT value FROM {settings} WHERE name = ?', ['multisite_probe']));
        $this->assertSame(
            (int) $this->db()->value('SELECT COUNT(*) FROM {users}'),
            (int) $site->value('SELECT COUNT(*) FROM {users}'),
            'both sites read the same owner table',
        );
    }

    public function testASiteTablesForeignKeyPointsAtTheOwnerTable(): void
    {
        $referenced = match (true) {
            $this->isPostgres() => $this->db()->all("SELECT confrelid::regclass::text AS t FROM pg_constraint WHERE conrelid = 'tl2_news'::regclass AND contype = 'f'"),
            $this->isSqlite() => $this->db()->all("SELECT \"table\" AS t FROM pragma_foreign_key_list('tl2_news')"),
            default => $this->db()->all("SELECT referenced_table_name AS t FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'tl2_news'"),
        };

        $this->assertContains('tl_users', array_map(fn (array $r): string => trim((string) $r['t'], '"'), $referenced));
    }

    public function testAFurtherSitesUniqueKeysKeepTheirMigrationNames(): void
    {
        $this->db()->pdo()->rollBack(); // DDL ends a transaction on MySQL
        $site = $this->db()->forSite('tl2_');
        $this->assertArrayHasKey('uq_pages_slug', $site->uniqueKeys('pages'));
        $this->assertArrayHasKey('uq_pages_language_slug', $site->uniqueKeys('pages'));

        // Slug::switchPerLanguage() drops and restores the global key by its migration name
        $site->dropUniqueKey('pages', 'uq_pages_slug');
        $this->assertArrayNotHasKey('uq_pages_slug', $site->uniqueKeys('pages'));
        $this->assertArrayHasKey('uq_pages_slug', $this->db()->uniqueKeys('pages'), 'site 1 keeps its key');
        $site->addUniqueKey('pages', 'uq_pages_slug', ['slug']);
        $this->assertArrayHasKey('uq_pages_slug', $site->uniqueKeys('pages'));
    }

    public function testOneBackupRestoresEverySite(): void
    {
        $this->db()->pdo()->rollBack(); // a restore runs its own statements (and DDL on MySQL), outside the test's transaction
        $site = $this->db()->forSite('tl2_');
        $this->db()->upsert('settings', ['name' => 'multisite_backup', 'value' => 'one'], ['name']);
        $site->upsert('settings', ['name' => 'multisite_backup', 'value' => 'two'], ['name']);
        $file = Backup::create($this->db(), 'multisitetest');
        try {
            $this->db()->run('DELETE FROM {settings} WHERE name = ?', ['multisite_backup']);
            $site->run('DELETE FROM {settings} WHERE name = ?', ['multisite_backup']);

            Backup::restore($this->db(), $file);

            $this->assertSame('one', $this->db()->value('SELECT value FROM {settings} WHERE name = ?', ['multisite_backup']));
            $this->assertSame('two', $site->value('SELECT value FROM {settings} WHERE name = ?', ['multisite_backup']));
            $this->assertSame(['tl_', 'tl2_'], Sites::prefixes($this->db()));
        } finally {
            @unlink(Backup::FOLDER . '/' . $file);
        }
    }
}
