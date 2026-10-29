<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Backup;
use Talea\Core\MediaStaging;
use Talea\Core\AgentJournal;
use Talea\Core\App;
use Talea\Core\Migrator;
use Talea\Core\Request;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Multisite phase 0 (docs/specs/multisite.md): a second site in the class's database, migrated, isolated and backed up with the first. */
#[CoversClass(Sites::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(Backup::class)]
#[CoversClass(MediaStaging::class)]
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

    public function testARestoreRefusesTablesOfAnUnregisteredPrefix(): void
    {
        // tl9_ looks like a site of this install, but no site has it: it may belong to another installation in the same database
        $file = 'talea-20261029-000000-multisitetest-foreign.sql';
        @mkdir(Backup::FOLDER, 0775, true);
        file_put_contents(Backup::FOLDER . '/' . $file, "-- Talea test\n-- engine: " . $this->db()->dialect()->name() . "\nDELETE FROM \"tl9_pages\";\n");
        try {
            Backup::restore($this->db(), $file);
            $this->fail('A table of an unregistered prefix must stop the restore.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('foreign table tl9_pages', $e->getMessage());
        } finally {
            @unlink(Backup::FOLDER . '/' . $file);
        }
    }

    public function testAWaitingFileGoesPublicWhenAFurtherSitePublishesIt(): void
    {
        $path = 'media/2026/10/multisite-probe-' . bin2hex(random_bytes(3)) . '.jpg';
        $staged = MediaStaging::root(true) . '/' . $path;
        @mkdir(dirname($staged), 0775, true);
        file_put_contents($staged, 'jpeg');
        $id = $this->db()->insert('media', ['image_path' => $path, 'staged_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        $this->db()->forSite('tl2_')->insert('site_parts', ['type' => 'footer', 'build' => '{"v":1,"children":[{"type":"image","content":{"src":"' . $path . '"}}]}']);
        try {
            MediaStaging::cleanup($this->db()); // the job runs with site 1 only, but site 2's content counts

            $this->assertNull($this->db()->value('SELECT staged_at FROM {media} WHERE media_id = ?', [$id]));
            $this->assertFileExists(TALEA_ROOT . '/' . $path);
        } finally {
            @unlink($staged);
            @unlink(TALEA_ROOT . '/' . $path);
            // remove the folders the test created, bottom-up; rmdir leaves non-empty ones alone
            $folder = dirname($staged);
            for ($i = 0; $i < 3; $i++, $folder = dirname($folder)) {
                @rmdir($folder);
            }
            @rmdir(MediaStaging::root(true));
            $folder = dirname(TALEA_ROOT . '/' . $path);
            @rmdir($folder);
            @rmdir(dirname($folder));
        }
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
            set_time_limit(0); // Backup::restore() set a 300 s limit on this process; the worker runs more test classes after this one
            @unlink(Backup::FOLDER . '/' . $file);
        }
    }

    public function testResolveFindsTheSiteOfAHost(): void
    {
        $db = $this->db();
        $db->insert('site_domains', ['site_id' => 2, 'host' => 'second.example', 'is_primary' => true, 'redirect_to_primary' => false]);
        $db->insert('site_domains', ['site_id' => 2, 'host' => 'www.second.example', 'is_primary' => false, 'redirect_to_primary' => true]);

        $primary = Sites::resolve($db, 'second.example');
        $this->assertSame(['tl2_', 2, 'paused', true, false, 'second.example'], [$primary['prefix'], $primary['site_id'], $primary['status'], $primary['is_primary'], $primary['redirect_to_primary'], $primary['primary_host']]);
        $alias = Sites::resolve($db, 'www.second.example');
        $this->assertSame([false, true, 'second.example'], [$alias['is_primary'], $alias['redirect_to_primary'], $alias['primary_host']]);
        $this->assertNull(Sites::resolve($db, 'nobody.example'));
        $this->assertSame('second.example', Sites::primaryHost($db, 2));
        $this->assertNull(Sites::primaryHost($db, 1));
    }

    public function testTheFirstDomainComesFromTheSiteAddress(): void
    {
        $this->assertNull(Sites::addFirstDomain($this->db(), ''));
        $this->assertSame('main.example', Sites::addFirstDomain($this->db(), 'https://Main.Example/'));
        $this->assertSame(1, Sites::resolve($this->db(), 'main.example')['site_id']);
    }

    public function testAnArchiveImportIsRefusedWhileSeveralSitesExist(): void
    {
        $this->assertNull(Sites::importRefusal($this->db()), 'the flag is off');
        putenv('TALEA_MULTISITE=1');
        try {
            $this->assertStringContainsString('only possible while the install has one site', (string) Sites::importRefusal($this->db()));
            $this->db()->update('sites', ['status' => 'trashed'], ['prefix' => 'tl2_']);
            $this->assertNull(Sites::importRefusal($this->db()), 'a trashed site does not count');
        } finally {
            putenv('TALEA_MULTISITE');
        }
    }

    public function testTheJournalOfASiblingCallRecordsItsPrefixAndUndoWritesBackThere(): void
    {
        $this->db()->pdo()->rollBack(); // undo has a transaction of its own
        $site = $this->db()->forSite('tl2_');
        $own = $this->db();
        $site->journal = AgentJournal::start($site, 'probe', 'update_settings');
        $site->upsert('settings', ['name' => 'journal_probe', 'value' => 'second'], ['name']);
        $site->journal = null;
        $own->journal = AgentJournal::start($own, 'probe', 'update_settings');
        $own->upsert('settings', ['name' => 'journal_probe', 'value' => 'first'], ['name']);
        $own->journal = null;

        $this->assertSame(['tl2_', ''], array_column($own->all("SELECT site_prefix FROM {agent_journal} WHERE tbl = 'settings' ORDER BY id"), 'site_prefix'));

        // undone from the first site's connection, the row of the second site goes from the second site's table
        $app = new App(['db' => $this->dbConfig()], Request::fromGlobals());
        (new \ReflectionProperty(App::class, 'db'))->setValue($app, $own);
        $result = AgentJournal::undo($app, (int) $own->value('SELECT MAX(id) FROM {agent_sessions}'));

        $this->assertSame(2, $result['removed'] + $result['restored']);
        $this->assertNull($site->value("SELECT value FROM {settings} WHERE name = 'journal_probe'"));
        $this->assertNull($own->value("SELECT value FROM {settings} WHERE name = 'journal_probe'"));
        $own->delete('agent_sessions', ['connection' => 'probe']);
    }
}
