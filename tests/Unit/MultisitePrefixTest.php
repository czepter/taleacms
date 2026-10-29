<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\Db;
use Talea\Core\Sites;

/** Multisite (docs/specs/multisite.md): site tables carry the site prefix, owner tables the owner prefix. */
#[CoversClass(Db::class)]
#[CoversClass(Sites::class)]
final class MultisitePrefixTest extends TestCase
{
    public function testAFurtherSitesPrefixNeverStartsWithTheOwnerPrefix(): void
    {
        $this->assertSame('tl_', Sites::prefixFor('tl_', 1));
        $this->assertSame('tl2_', Sites::prefixFor('tl_', 2));
        $this->assertSame('web12_', Sites::prefixFor('web_', 12));
        $this->assertStringStartsNotWith('tl_', Sites::prefixFor('tl_', 3));
    }

    public function testAnOwnerPrefixWithoutUnderscoreCannotHaveFurtherSites(): void
    {
        $this->expectException(\RuntimeException::class);
        Sites::prefixFor('tl', 2);
    }

    public function testSiteTablesTakeTheSitePrefixAndOwnerTablesTheOwnerPrefix(): void
    {
        $db = new Db('mysql:host=x', '', '', 'tl2_', 'tl_');

        $this->assertSame('SELECT * FROM `tl2_pages` JOIN `tl_users` ON 1 = 1', $db->sql('SELECT * FROM {pages} JOIN {users} ON 1 = 1'));
        $this->assertSame('tl2_settings', $db->table('settings'));
        $this->assertSame('tl_sites', $db->table('sites'));
    }

    public function testSiteOneUsesOnePrefixForEverything(): void
    {
        $db = new Db('mysql:host=x', '', '', 'tl_');

        $this->assertSame('tl_', $db->ownerPrefix);
        $this->assertSame('SELECT * FROM `tl_pages` JOIN `tl_users` ON 1 = 1', $db->sql('SELECT * FROM {pages} JOIN {users} ON 1 = 1'));
    }

    public function testSiteRegistryIsAnOwnerTable(): void
    {
        $this->assertContains('sites', Db::OWNER_TABLES);
        $this->assertContains('site_domains', Db::OWNER_TABLES);
        $this->assertNotContains('settings', Db::OWNER_TABLES);
        $this->assertNotContains('media_usage', Db::OWNER_TABLES, 'media_usage references news, which exists once per site');
    }
}
