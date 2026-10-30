<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phinx\Db\Adapter\AdapterInterface;
use Talea\Core\Db;
use Talea\Core\MigrationSupport;
use Talea\Core\Owners;
use Talea\Core\Sites;

/** Several owners (docs/specs/multisite.md, "Several owners"): the prefix scheme, the three table classes and the migration naming rules. */
#[CoversClass(Owners::class)]
#[CoversClass(Db::class)]
#[CoversClass(MigrationSupport::class)]
final class OwnersTest extends TestCase
{
    public function testOwnerPrefixesAreTheInstallStemPlusLetters(): void
    {
        $this->assertSame('tl_', Owners::prefixFor('tl_', 1));
        $this->assertSame('tlb_', Owners::prefixFor('tl_', 2));
        $this->assertSame('tlc_', Owners::prefixFor('tl_', 3));
        $this->assertSame('tlz_', Owners::prefixFor('tl_', 26));
        $this->assertSame('tlba_', Owners::prefixFor('tl_', 27));
        $this->assertSame('tlbb_', Owners::prefixFor('tl_', 28));
        $this->assertSame('wpb_', Owners::prefixFor('wp_', 2));
    }

    public function testAnInstallPrefixWithDigitsCannotHaveFurtherOwners(): void
    {
        $this->expectException(\RuntimeException::class);
        Owners::prefixFor('web2_', 2);
    }

    public function testNoPrefixOfTheInstallIsTheStartOfAnother(): void
    {
        // a LIKE 'tlb\_%' (backup, table size, dropping a site) must catch the tables of that prefix and nothing else
        $all = [];
        for ($owner = 1; $owner <= 60; $owner++) {
            $ownerPrefix = Owners::prefixFor('tl_', $owner);
            $all[] = $ownerPrefix;
            for ($site = 2; $site <= 12; $site++) {
                $all[] = Sites::prefixFor($ownerPrefix, $site);
            }
        }
        $this->assertSame($all, array_unique($all), 'every prefix is unique');
        foreach ($all as $a) {
            foreach ($all as $b) {
                if ($a !== $b) {
                    $this->assertFalse(str_starts_with($a, $b), "{$a} starts with {$b}");
                }
            }
        }
        $this->assertSame(['tl_', 'tl2_', 'tlb_', 'tlb2_', 'tlba_', 'tlba2_'], [$all[0], $all[1], Owners::prefixFor('tl_', 2), Sites::prefixFor('tlb_', 2), Owners::prefixFor('tl_', 27), Sites::prefixFor('tlba_', 2)]);
    }

    public function testAPrefixKnowsItsOwner(): void
    {
        $this->assertSame('tl_', Owners::ownerOf('tl_', 'tl_'));
        $this->assertSame('tl_', Owners::ownerOf('tl_', 'tl3_'));
        $this->assertSame('tlb_', Owners::ownerOf('tl_', 'tlb_'));
        $this->assertSame('tlb_', Owners::ownerOf('tl_', 'tlb12_'));
        $this->assertSame('tlba_', Owners::ownerOf('tl_', 'tlba2_'));
        $this->assertNull(Owners::ownerOf('tl_', 'xx_'));
        $this->assertNull(Owners::ownerOf('tl_', 'tl_x'));
        $this->assertNull(Owners::ownerOf('tl_', 'tlB_'));
        $this->assertNull(Owners::ownerOf('tl_', 'tl2b_'), 'digits are a site number, they come after the letters');
    }

    public function testTablesLiveUnderTheirOwnClassOfPrefix(): void
    {
        $install = new Db('sqlite::memory:', '', '', 'tl_', 'tl_', 'tl_');
        $this->assertSame(['tl_owners', 'tl_users', 'tl_pages'], [$install->table('owners'), $install->table('users'), $install->table('pages')]);

        $siteOfOwnerOne = $install->forSite('tl2_');
        $this->assertSame(['tl_owners', 'tl_users', 'tl2_pages'], [$siteOfOwnerOne->table('owners'), $siteOfOwnerOne->table('users'), $siteOfOwnerOne->table('pages')]);

        $b = $install->forOwner('tlb_');
        $this->assertSame(['tl_owners', 'tlb_users', 'tlb_sites', 'tlb_pages'], [$b->table('owners'), $b->table('users'), $b->table('sites'), $b->table('pages')]);
        $this->assertSame('tl_', $b->installPrefix);

        $siteOfB = $b->forSite('tlb2_');
        $this->assertSame(['tl_owners', 'tlb_users', 'tlb2_pages', 'tlb_handoff_tokens'], [$siteOfB->table('owners'), $siteOfB->table('users'), $siteOfB->table('pages'), $siteOfB->table('handoff_tokens')]);
        $this->assertSame('tlb_', $siteOfB->ownerPrefix);
        $this->assertSame('tl_', $siteOfB->installPrefix);
    }

    public function testTheConfigurationSaysWhichOwnerAndSite(): void
    {
        $base = ['driver' => 'sqlite', 'name' => ':memory:', 'prefix' => 'tl_'];
        $first = Db::fromConfig($base);
        $this->assertSame(['tl_', 'tl_', 'tl_'], [$first->prefix, $first->ownerPrefix, $first->installPrefix]);

        $site = Db::fromConfig($base + ['site_prefix' => 'tl2_']);
        $this->assertSame(['tl2_', 'tl_', 'tl_'], [$site->prefix, $site->ownerPrefix, $site->installPrefix]);

        $owner = Db::fromConfig($base + ['owner_prefix' => 'tlb_', 'site_prefix' => 'tlb_']);
        $this->assertSame(['tlb_', 'tlb_', 'tl_'], [$owner->prefix, $owner->ownerPrefix, $owner->installPrefix]);
        $this->assertSame('tlb_', $owner->config()['owner_prefix'], 'a connection made from it remembers the owner');

        $ownerSite = Db::fromConfig($base + ['owner_prefix' => 'tlb_', 'site_prefix' => 'tlb2_']);
        $this->assertSame(['tlb2_', 'tlb_', 'tl_'], [$ownerSite->prefix, $ownerSite->ownerPrefix, $ownerSite->installPrefix]);
    }

    public function testOnlyTheFirstOwnerWorksOnTheInstallsFiles(): void
    {
        $first = new Db('sqlite::memory:', '', '', 'tl2_', 'tl_', 'tl_');
        $this->assertTrue(Owners::isFirst($first));
        $this->assertNull(Owners::sharedFilesRefusal($first));
        $b = (new Db('sqlite::memory:', '', '', 'tl_', 'tl_', 'tl_'))->forOwner('tlb_');
        $this->assertFalse(Owners::isFirst($b));
        $this->assertFalse(Owners::isFirst($b->forSite('tlb2_')));
        $this->assertStringContainsString('not available to this owner yet', (string) Owners::sharedFilesRefusal($b));
    }

    /** @param array<string, string> $options */
    private function adapter(array $options): AdapterInterface
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('getOption')->willReturnCallback(fn (string $name): mixed => $options[$name] ?? null);

        return $adapter;
    }

    public function testIndexNamesCarryThePrefixOfEveryTableOutsideTheInstalls(): void
    {
        $run = fn (string $table, string $owner): AdapterInterface => $this->adapter(['table_prefix' => $table, 'owner_prefix' => $owner, 'install_prefix' => 'tl_']);

        $this->assertSame('uq_pages_slug', MigrationSupport::indexName($run('tl_', 'tl_'), 'uq_pages_slug'), 'owner 1 and its site 1 keep the plain names');
        $this->assertSame('tl2_uq_pages_slug', MigrationSupport::indexName($run('tl2_', 'tl_'), 'uq_pages_slug'));
        $this->assertSame('tlb_uq_users_username', MigrationSupport::indexName($run('tlb_', 'tlb_'), 'uq_users_username'), 'a further owner\'s own run is not "the install"');
        $this->assertSame('tlb2_uq_pages_slug', MigrationSupport::indexName($run('tlb2_', 'tlb_'), 'uq_pages_slug'));
        $this->assertSame('uq_pages_slug', MigrationSupport::indexName($this->adapter(['table_prefix' => 'web_', 'owner_prefix' => 'web_']), 'uq_pages_slug'), 'older option sets without install_prefix');
    }

    public function testWhatAnEarlierRunMadeIsSkipped(): void
    {
        $run = fn (string $table, string $owner): AdapterInterface => $this->adapter(['table_prefix' => $table, 'owner_prefix' => $owner, 'install_prefix' => 'tl_']);

        // the install table: only in the install's own run
        $this->assertFalse(MigrationSupport::ownerTableInSiteRun($run('tl_', 'tl_'), 'owners'));
        $this->assertTrue(MigrationSupport::ownerTableInSiteRun($run('tl2_', 'tl_'), 'owners'));
        $this->assertTrue(MigrationSupport::ownerTableInSiteRun($run('tlb_', 'tlb_'), 'owners'), 'a further owner\'s own run');
        $this->assertTrue(MigrationSupport::ownerTableInSiteRun($run('tlb2_', 'tlb_'), 'owners'));
        // an owner table: in the owner run of every owner, not in the run of a site of it
        $this->assertFalse(MigrationSupport::ownerTableInSiteRun($run('tlb_', 'tlb_'), 'users'));
        $this->assertTrue(MigrationSupport::ownerTableInSiteRun($run('tlb2_', 'tlb_'), 'users'));
        $this->assertFalse(MigrationSupport::ownerTableInSiteRun($run('tlb2_', 'tlb_'), 'pages'));
    }
}
