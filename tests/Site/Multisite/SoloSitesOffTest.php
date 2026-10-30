<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The solopreneur additions with TALEA_MULTISITE off: no report box, no Projects element, no overview, no site selects. */
#[Group('site')]
final class SoloSitesOffTest extends SiteTestCase
{
    public function testTheReportSettingIsHidden(): void
    {
        $this->site()->signIn($this->site()->admin());
        $body = $this->site()->admin()->get('/admin.php?module=settings&tab=reports')->body;
        $this->assertStringContainsString('name="report_monthly"', $body);
        $this->assertStringNotContainsString('report_all_sites', $body);
    }

    public function testTheProjectsElementIsNotOfferedAndPrintsNothing(): void
    {
        $site = $this->site();
        $this->assertNotContains('site_cards', array_column((array) ($site->mcpResult('builder_schema', ['full' => true])['elements'] ?? []), 'type'));

        $site->mcp('create_page', ['title' => 'Projects', 'slug' => 'projects', 'visible' => true]);
        $build = '{"v":1,"children":[{"id":"sc1","type":"site_cards","tag":"div","content":{"count":6,"link_text":"Visit"},"style":{},"classes":[],"children":[]}]}';
        $site->exec('UPDATE tl_pages SET build = ?, build_draft = ?, visible = TRUE WHERE slug = ?', [$build, $build, 'projects']);
        $this->assertStringNotContainsString('class="tl-site-card"', $site->client('visitor')->get('/projects')->body);
    }

    public function testNoOverviewAndNoSitesSelectOnTheSubscribers(): void
    {
        $site = $this->site();
        $this->assertNotSame(200, $site->admin()->get('/admin.php?module=sites&action=overview')->status);
        $this->assertStringNotContainsString('name="sites"', $site->admin()->get('/admin.php?module=subscribers')->body);
    }
}
