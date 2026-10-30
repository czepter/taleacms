<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Backup;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Several owners (docs/specs/multisite.md, "Several owners"): owner B and a site of it in the class's database, migrated, routed and backed up with owner 1. */
#[CoversClass(Owners::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(Backup::class)]
#[CoversClass(Sites::class)]
final class OwnersTest extends DatabaseTestCase
{
    private static bool $made = false;

    protected function setUp(): void
    {
        // the tables are DDL, which ends a transaction on MySQL: made once, before the test's transaction starts
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                $db = $this->db();
                $b = Owners::add($db, 'Beta', 'organisation');
                $lines = Owners::provisionPending($db, $config);
                if ($lines !== ['owner tlb_: ready']) {
                    throw new \RuntimeException(implode("\n", $lines));
                }
                Sites::add($db->forOwner($b), 'Beta shop', 'beta-shop');
                Migrator::migrate($config); // the shop of B
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    private function exists(string $real): bool
    {
        return (int) $this->db()->value($this->db()->dialect()->tableExistsSql(), [$real]) > 0;
    }

    public function testOwnerOneIsRegisteredWithTheInstallPrefix(): void
    {
        $this->assertSame(['tl_', 'tlb_'], Owners::prefixes($this->db()));
        $this->assertSame(['tl_', 'tlb_'], Owners::readyPrefixes($this->db()));
        $this->assertSame(['tl_', 'tl_'], [$this->db()->value('SELECT prefix FROM {owners} WHERE owner_id = 1'), $this->db()->installPrefix]);
        $this->assertSame(['tl_', 'tlb_', 'tlb2_'], array_map(fn ($c) => $c->prefix, array_filter(Owners::connections($this->db()), fn ($c) => $c->prefix !== 'tl2_')));
    }

    public function testAFurtherOwnerGetsOwnerAndSiteTablesButNotTheInstallTables(): void
    {
        foreach (['tlb_users', 'tlb_sites', 'tlb_site_domains', 'tlb_handoff_tokens', 'tlb_media', 'tlb_pages', 'tlb_settings', 'tlb_migrations', 'tlb2_pages', 'tlb2_settings'] as $table) {
            $this->assertTrue($this->exists($table), $table);
        }
        $this->assertTrue($this->exists('tl_owners'));
        $this->assertFalse($this->exists('tlb_owners'), 'the install table exists once');
        $this->assertFalse($this->exists('tlb2_owners'));
        $this->assertFalse($this->exists('tlb2_users'), 'owner tables exist once per owner');
        $this->assertSame([], Migrator::pending($this->db()->forOwner('tlb_')));
        $this->assertSame([], Migrator::pending($this->db()->forOwner('tlb_')->forSite('tlb2_')));
        $this->assertSame([], Migrator::migrate($this->dbConfig()), 'migrating again changes nothing');
        $this->assertFalse(Migrator::anyPending($this->db()));
    }

    public function testEachOwnerHasItsOwnRegistryWithItsOwnSiteOne(): void
    {
        $b = $this->db()->forOwner('tlb_');
        $this->assertSame(['tlb_', 'tlb2_'], Sites::prefixes($b));
        $this->assertSame(['tl_'], Sites::prefixes($this->db()), 'owner 1 does not see the sites of B');
        $this->assertSame('tlb_', $b->value('SELECT prefix FROM {sites} WHERE site_id = 1'));
        $this->assertNotNull($b->value('SELECT ready_at FROM {sites} WHERE site_id = 1'));
        $this->assertSame(1, Sites::currentId($b));
        $this->assertSame(2, Sites::currentId($b->forSite('tlb2_')));
    }

    public function testForeignKeysStayInsideTheOwner(): void
    {
        $referenced = match (true) {
            $this->isPostgres() => $this->db()->all("SELECT confrelid::regclass::text AS t FROM pg_constraint WHERE conrelid = 'tlb2_news'::regclass AND contype = 'f'"),
            $this->isSqlite() => $this->db()->all("SELECT \"table\" AS t FROM pragma_foreign_key_list('tlb2_news')"),
            default => $this->db()->all("SELECT referenced_table_name AS t FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'tlb2_news'"),
        };
        $tables = array_map(fn (array $r): string => trim((string) $r['t'], '"'), $referenced);
        $this->assertContains('tlb_users', $tables);
        $this->assertNotContains('tl_users', $tables);
    }

    public function testUniqueKeysOfAFurtherOwnerKeepTheirMigrationNames(): void
    {
        $this->db()->pdo()->rollBack(); // DDL ends a transaction on MySQL
        $b = $this->db()->forOwner('tlb_');
        $this->assertArrayHasKey('uq_users_username', $b->uniqueKeys('users'), 'an owner table of B');
        $this->assertArrayHasKey('uq_pages_slug', $b->uniqueKeys('pages'));
        $this->assertArrayHasKey('uq_pages_slug', $b->forSite('tlb2_')->uniqueKeys('pages'));
        $this->assertArrayHasKey('uq_owners_prefix', $b->uniqueKeys('owners'), 'the install table keeps the plain name');
        $b->dropUniqueKey('pages', 'uq_pages_slug');
        $this->assertArrayNotHasKey('uq_pages_slug', $b->uniqueKeys('pages'));
        $this->assertArrayHasKey('uq_pages_slug', $this->db()->uniqueKeys('pages'), 'owner 1 keeps its key');
        $b->addUniqueKey('pages', 'uq_pages_slug', ['slug']);
        $this->assertArrayHasKey('uq_pages_slug', $b->uniqueKeys('pages'));
    }

    public function testAHostRoutesToItsOwnerAndIsUniqueInTheInstall(): void
    {
        $db = $this->db();
        $b = $db->forOwner('tlb_');
        $db->insert('site_domains', ['site_id' => 1, 'host' => 'alpha.example', 'is_primary' => true, 'redirect_to_primary' => false, 'verified_at' => date('Y-m-d H:i:s')]);
        $b->insert('site_domains', ['site_id' => 1, 'host' => 'beta.example', 'is_primary' => true, 'redirect_to_primary' => false, 'verified_at' => date('Y-m-d H:i:s')]);
        $b->insert('site_domains', ['site_id' => 2, 'host' => 'shop.beta.example', 'is_primary' => true, 'redirect_to_primary' => false, 'verified_at' => date('Y-m-d H:i:s')]);

        $alpha = Sites::resolve($db, 'alpha.example');
        $this->assertSame(['tl_', 'tl_', 1, 'live'], [$alpha['prefix'], $alpha['owner_prefix'], $alpha['site_id'], $alpha['owner_status']]);
        $beta = Sites::resolve($db, 'beta.example');
        $this->assertSame(['tlb_', 'tlb_', 1], [$beta['prefix'], $beta['owner_prefix'], $beta['site_id']]);
        $shop = Sites::resolve($db, 'shop.beta.example');
        $this->assertSame(['tlb2_', 'tlb_', 2, 'shop.beta.example'], [$shop['prefix'], $shop['owner_prefix'], $shop['site_id'], $shop['primary_host']]);
        $this->assertNull(Sites::resolve($db, 'nobody.example'));

        $this->assertStringContainsString('already used', (string) Sites::addDomain($db, 1, 'beta.example'), 'owner 1 cannot take B\'s host');
        $this->assertStringContainsString('already used', (string) Sites::addDomain($b, 1, 'alpha.example'), 'B cannot take owner 1\'s host');
        $this->assertNull(Sites::addDomain($b, 1, 'www.beta.example'));
    }

    public function testWithoutTheFlagOnlyTheFirstOwnerIsRouted(): void
    {
        $this->db()->forOwner('tlb_')->insert('site_domains', ['site_id' => 1, 'host' => 'beta.example', 'is_primary' => true, 'redirect_to_primary' => false, 'verified_at' => date('Y-m-d H:i:s')]);
        putenv('TALEA_OWNERS');
        $this->assertNull(Sites::resolve($this->db(), 'beta.example'));
    }

    public function testAPausedOrArchivedOwnerStillOwnsItsHosts(): void
    {
        $db = $this->db();
        $db->forOwner('tlb_')->insert('site_domains', ['site_id' => 1, 'host' => 'beta.example', 'is_primary' => true, 'redirect_to_primary' => false, 'verified_at' => date('Y-m-d H:i:s')]);

        $this->assertNull(Owners::setStatus($db, 'tlb_', 'paused'));
        $this->assertSame('paused', Sites::resolve($db, 'beta.example')['owner_status']);
        $this->assertStringContainsString('already used', (string) Sites::addDomain($db, 1, 'beta.example'));
        $this->assertTrue(Owners::hostTaken($db, 'beta.example'));

        $this->assertNull(Owners::setStatus($db, 'tlb_', 'archived'));
        $this->assertSame('archived', Sites::resolve($db, 'beta.example')['owner_status']);
        $this->assertStringContainsString('already used', (string) Sites::addDomain($db, 1, 'beta.example'), 'an archived client\'s domain cannot be taken over');
        $this->assertNull(Owners::setStatus($db, 'tlb_', 'live'));
    }

    public function testTheFirstOwnerCanNeverBePausedOrArchived(): void
    {
        $this->assertStringContainsString('cannot be paused', (string) Owners::setStatus($this->db(), 'tl_', 'paused'));
        $this->assertStringContainsString('cannot be paused', (string) Owners::setStatus($this->db(), 'tl_', 'archived'));
        $this->assertSame('live', $this->db()->value('SELECT status FROM {owners} WHERE owner_id = 1'));
        $this->assertNotNull(Owners::setStatus($this->db(), 'tlb_', 'gone'));
    }

    public function testOnlyTheFirstOwnerMakesBackups(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available to this owner');
        Backup::create($this->db()->forOwner('tlb_'), 'ownerstest');
    }

    private function headerOf(string $file): string
    {
        $path = Backup::FOLDER . '/' . $file;
        $text = str_ends_with($file, '.gz') ? (string) gzdecode((string) file_get_contents($path)) : (string) file_get_contents($path);
        preg_match('/^-- prefixes: (\S+)/m', $text, $m);

        return $m[1] ?? '';
    }

    public function testOneBackupRestoresEveryOwner(): void
    {
        $this->db()->pdo()->rollBack(); // a restore runs its own statements, outside the test's transaction
        $a = $this->db();
        $b = $a->forOwner('tlb_');
        $shop = $b->forSite('tlb2_');
        $a->upsert('settings', ['name' => 'owners_backup', 'value' => 'a'], ['name']);
        $b->upsert('settings', ['name' => 'owners_backup', 'value' => 'b'], ['name']);
        $shop->upsert('settings', ['name' => 'owners_backup', 'value' => 'shop'], ['name']);
        $file = Backup::create($a, 'ownerstest');
        try {
            $this->assertSame('tl_,tlb_,tlb2_', $this->headerOf($file), 'the backup names every owner\'s prefixes, each owner followed by its sites');
            foreach ([$a, $b, $shop] as $db) {
                $db->run('DELETE FROM {settings} WHERE name = ?', ['owners_backup']);
            }

            Backup::restore($a, $file);

            $this->assertSame(['a', 'b', 'shop'], [$a->value('SELECT value FROM {settings} WHERE name = ?', ['owners_backup']), $b->value('SELECT value FROM {settings} WHERE name = ?', ['owners_backup']), $shop->value('SELECT value FROM {settings} WHERE name = ?', ['owners_backup'])]);
            $this->assertSame(['tl_', 'tlb_'], Owners::prefixes($a));
        } finally {
            set_time_limit(0); // Backup::restore() set a 300 s limit on this process
            @unlink(Backup::FOLDER . '/' . $file);
        }
    }

    public function testAnOlderBackupDropsAnOwnerMadeAfterItAndABackupBringsARemovedOneBack(): void
    {
        $this->db()->pdo()->rollBack();
        $a = $this->db();
        $file = Backup::create($a, 'ownerstest');
        $c = null;
        try {
            $c = Owners::add($a, 'Gamma');
            $this->assertSame('tlc_', $c);
            Migrator::migrate($this->dbConfig());
            $this->assertNotSame([], $a->dialect()->dumpTables($a->pdo(), 'tlc_'));

            Backup::restore($a, $file);

            $this->assertSame([], $a->dialect()->dumpTables($a->pdo(), 'tlc_'), 'the tables of the newer owner are gone');
            $this->assertSame(['tl_', 'tlb_'], Owners::prefixes($a), 'the registry is the backup\'s');

            // the other way: an owner removed since the backup comes back with its tables
            $c = Owners::add($a, 'Gamma');
            Migrator::migrate($this->dbConfig());
            $withC = Backup::create($a, 'ownerstest');
            Sites::dropTables($a, 'tlc_');
            $a->delete('owners', ['prefix' => 'tlc_']);
            $this->assertSame(['tl_', 'tlb_'], Owners::prefixes($a));

            Backup::restore($a, $withC);

            $this->assertSame(['tl_', 'tlb_', 'tlc_'], Owners::prefixes($a));
            $this->assertNotSame([], $a->dialect()->dumpTables($a->pdo(), 'tlc_'), 'its tables are back');
            @unlink(Backup::FOLDER . '/' . $withC);
        } finally {
            set_time_limit(0);
            @unlink(Backup::FOLDER . '/' . $file);
            if ($c !== null) {
                Sites::dropTables($a, 'tlc_');
                $a->delete('owners', ['prefix' => 'tlc_']);
            }
        }
    }

    public function testABackupWithAForeignPrefixShapeIsRefused(): void
    {
        $file = 'talea-20261103-000001-ownerstest-prefixes.sql';
        @mkdir(Backup::FOLDER, 0775, true);
        file_put_contents(Backup::FOLDER . '/' . $file, "-- Talea test\n-- engine: " . $this->db()->dialect()->name() . "\n-- prefixes: tl_,tlB_\nDELETE FROM \"tl_pages\";\n");
        try {
            Backup::restore($this->db(), $file);
            $this->fail('A prefix of the wrong shape must stop the restore.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('other table prefixes', $e->getMessage());
        } finally {
            @unlink(Backup::FOLDER . '/' . $file);
        }
    }
}
