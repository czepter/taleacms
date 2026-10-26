<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The Search engines → Performance screen (Core\SearchInsights over the daily snapshot). */
#[Group('site')]
final class SearchPerformanceTest extends SiteTestCase
{
    use FakeServices;

    private const string SCREEN = '/admin.php?module=search_performance';

    public function testNothingConnectedPointsToConnections(): void
    {
        $screen = $this->assertPage(self::SCREEN, 200, 'href="/admin/connectors"', message: 'performance: without a connection the screen points to Connections');
        $this->assertStringNotContainsString('<polyline', $screen->body, 'performance: no chart without data');
    }

    public function testConnectedButNoSnapshotYetSaysSo(): void
    {
        $this->connectFake('google');
        $this->assertPage(self::SCREEN, 200, 'The first data arrives after tonight', message: 'performance: connected, no snapshot');
    }

    public function testTheScreenShowsTheSnapshotAndNeverTheKey(): void
    {
        $site = $this->site();
        $this->connectFake('google');
        $this->adminPost('/admin.php?module=connectors&action=properties', [], self::SCREEN);
        $this->adminPost('/admin.php?module=connectors&action=property', ['site' => 'sc-domain:example.com'], self::SCREEN);
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'bing', 'client_id' => '', 'account' => '', 'secret' => 'bing-test-key', 'config' => ['site_url' => 'https://example.com/']], self::SCREEN);
        $this->runJob('search_data');
        $site->exec("UPDATE tl_pages SET visible = 1, focus_keywords = 'talea cms' WHERE slug = 'services'");
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'services'");
        $site->exec("UPDATE tl_settings SET value = 'https://example.com' WHERE name = 'site_url'");

        $screen = $this->assertPage(self::SCREEN, 200, ['Clicks', '<polyline', 'talea cms', 'company website free', 'Almost on the first page', 'In the search index'], message: 'performance: both engines');
        $this->assertStringContainsString('>59<', $screen->body, 'performance: 50 Google + 9 Bing clicks');
        $this->assertStringContainsString('/admin/pages/website/' . $uuid, $screen->body, 'performance: the page row links to the page');
        $this->assertStringContainsString('Focus keyword', $screen->body, 'performance: a query that is a page\'s focus keyword is tagged');
        $this->assertStringContainsString('Use for a page', $screen->body, 'performance: other queries offer a page');
        $this->assertStringNotContainsString('bing-test-key', $screen->body, 'performance: no Bing key');

        $bing = $this->assertPage(self::SCREEN . '&engine=bing', 200, '>9<', message: 'performance: Bing only');
        $this->assertStringNotContainsString('>59<', $bing->body, 'performance: Bing only shows no Google total');
        $this->assertStringContainsString('role="img"', $bing->body, 'performance: the chart has a text alternative');

        $site->exec("UPDATE tl_search_stats SET day = ?", [$this->siteDate('-10 days')]);
        $this->assertPage(self::SCREEN, 200, 'Last updated on', message: 'performance: an old snapshot is flagged');
    }
}
