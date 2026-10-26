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

    #[Depends('testTheScreenShowsTheSnapshotAndNeverTheKey')]
    public function testBingOnlyLanguageHomeEscapingAndAccess(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'google'], self::SCREEN);
        $screen = $this->assertPage(self::SCREEN, 200, 'talea bing', message: 'performance: with only Bing connected the lists come from Bing');
        $this->assertStringNotContainsString('talea cms', $screen->body, 'performance: no Google query without Google');

        $home = (int) $site->settingValue('home_page');
        $site->setting('additional_languages', 'de');
        $site->mcp('create_page', ['title' => 'Startseite', 'slug' => 'start-de', 'language' => 'de', 'translation_of' => $site->publicId('pages', $home), 'content' => '<p>Hallo</p>', 'visible' => 1]);
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
