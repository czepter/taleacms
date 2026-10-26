<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Depends;
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

    /** Creates a page over MCP, which answers over HTTPS only once site_url is the https address the search rows use. @param array<string, mixed> $page */
    private function createPage(array $page): void
    {
        $site = $this->site();
        $url = $site->settingValue('site_url');
        $site->setting('site_url', '');
        $this->assertArrayHasKey('result', $site->mcp('create_page', $page + ['content' => '<p>Text</p>', 'visible' => 1]), 'performance: the page is created');
        $site->setting('site_url', $url);
    }

    /** How many published pages the screen says have not appeared in search (0 for "every page"). */
    private function notInSearchCount(): int
    {
        $body = $this->assertPage(self::SCREEN . '&engine=google', 200, 'In the search index', message: 'performance: the index block')->body;

        return preg_match('/(\d+) published pages have not appeared in search yet/', $body, $m) === 1 ? (int) $m[1] : 0;
    }

    #[Depends('testTheScreenShowsTheSnapshotAndNeverTheKey')]
    public function testNotInSearchCountsOnlySitemapPages(): void
    {
        $site = $this->site();
        $before = $this->notInSearchCount();
        $this->createPage(['title' => 'Thank you', 'slug' => 'thank-you']);
        $this->createPage(['title' => 'Elsewhere', 'slug' => 'elsewhere']);
        $site->exec("UPDATE tl_pages SET noindex = 1 WHERE slug = 'thank-you'");
        $site->exec("UPDATE tl_pages SET canonical_url = 'https://other.test/elsewhere' WHERE slug = 'elsewhere'");
        $this->assertSame($before, $this->notInSearchCount(), 'performance: a noindex page and a page with a foreign canonical address are not counted as missing from search');
        $this->createPage(['title' => 'Indexable', 'slug' => 'indexable']);
        $this->assertSame($before + 1, $this->notInSearchCount(), 'performance: a new indexable page is counted');
    }

    #[Depends('testTheScreenShowsTheSnapshotAndNeverTheKey')]
    public function testHtmlAddressesMatchTheirPages(): void
    {
        $site = $this->site();
        $this->createPage(['title' => 'Prices', 'slug' => 'prices']);
        $site->setting('url_slash', 'html');
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'prices'");
        $day = (string) $site->value("SELECT MAX(day) FROM tl_search_stats WHERE engine = 'google'");
        $site->exec("DELETE FROM tl_search_stats WHERE engine = 'google' AND day = ? AND kind = 'page'", [$day]);
        $insert = 'INSERT INTO tl_search_stats (day, engine, kind, `key`, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $site->exec($insert, [$day, 'google', 'page', 'https://example.com/prices.html', '', 77, 700, 11, 2]);
        $site->exec($insert, [$day, 'google', 'page', 'https://other.test/prices.html', '', 1, 900, 0.1, 3]);
        $screen = $this->assertPage(self::SCREEN . '&engine=google', 200, message: 'performance: addresses ending in .html');
        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/pages/website/' . $uuid . '">Prices</a></td><td class="number">77<#', $screen->body, 'performance: a Top pages row ending in .html links its page');
        $this->assertStringContainsString('<code>https://other.test/prices.html</code>', $screen->body, 'performance: a page of another host links no page of this site');
        $site->setting('url_slash', '');
    }

    #[Depends('testTheScreenShowsTheSnapshotAndNeverTheKey')]
    public function testBingOnlyLanguageHomeEscapingAndAccess(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'google'], self::SCREEN);
        $screen = $this->assertPage(self::SCREEN, 200, 'talea bing', message: 'performance: with only Bing connected the lists come from Bing');
        $this->assertStringNotContainsString('talea cms', $screen->body, 'performance: no Google query without Google');
        $this->assertStringNotContainsString('>59<', $screen->body, 'performance: the disconnected Google snapshot is not added to the totals');
        $this->assertStringContainsString('<strong>9</strong>', $screen->body, 'performance: the totals are Bing\'s alone');
        $this->assertStringNotContainsString('addresses indexed', $screen->body, 'performance: no Google sitemaps without Google');

        $home = (int) $site->settingValue('home_page');
        $site->setting('additional_languages', 'de');
        $this->createPage(['title' => 'Startseite', 'slug' => 'start-de', 'language' => 'de', 'translation_of' => $site->publicId('pages', $home)]);
        $this->assertNotSame('', (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'start-de'"), 'performance: the German home page exists');
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'start-de'");
        $day = (string) $site->value("SELECT MAX(day) FROM tl_search_stats WHERE engine = 'bing'");
        $insert = 'INSERT INTO tl_search_stats (day, engine, kind, `key`, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $site->exec($insert, [$day, 'bing', 'page', 'https://example.com/de/', '', 40, 400, 10, 3]);
        $site->exec($insert, [$day, 'bing', 'query', '<script>alert(1)</script>', '', 90, 900, 10, 0]);
        $screen = $this->assertPage(self::SCREEN, 200, message: 'performance: language home');
        $this->assertStringContainsString('/admin/pages/website/' . $uuid, $screen->body, 'performance: /de/ maps to the German home page');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $screen->body, 'performance: a query is escaped');
        $this->assertStringNotContainsString('<script>alert(1)', $screen->body, 'performance: no raw script from a query');
        $this->assertStringContainsString('<td class="number">—</td>', $screen->body, 'performance: an unknown position is a dash');

        $site->exec("INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('perf-editor', ?, 'Perf editor', 1, NOW(), NOW())", [password_hash($site->password, PASSWORD_DEFAULT)]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = 'perf-editor'");
        $editor = $site->client('perf-editor');
        $site->signIn($editor, 'perf-editor');
        $this->assertPage(self::SCREEN, 403, as: $editor, message: 'performance: an editor without the Statistics right is refused');
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'stats' FROM tl_users WHERE username = 'perf-editor'");
        $this->assertPage(self::SCREEN, 200, as: $editor, message: 'performance: with the Statistics right it opens');
    }
}
