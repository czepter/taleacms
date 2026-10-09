<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Search Console and Bing data in Statistics (was: section 82, 2.13, Core\SearchData). */
#[Group('site')]
final class SearchDataTest extends SiteTestCase
{
    use FakeServices;

    private const string CONNECTORS = '/admin.php?module=connectors';

    public function testGoogleOffersItsSearchConsolePropertiesOnce(): void
    {
        $this->connectFake('google');
        $screen = $this->assertPage(self::CONNECTORS, 200, 'action=properties', message: 'search: a connected Google offers to load the Search Console properties');
        $this->assertStringContainsString('Bing Webmaster Tools', $screen->body, 'search: Bing is listed');
        $this->assertStringContainsString('name="config[search_console_site]"', $screen->body, 'search: the property setting is on the screen');

        $this->adminPost('/admin.php?module=connectors&action=properties', [], self::CONNECTORS);
        $offered = $this->site()->admin()->get(self::CONNECTORS)->body;
        $this->assertStringContainsString('name="site" value="sc-domain:example.com"', $offered, 'search: the account\'s properties are offered (domain)');
        $this->assertStringContainsString('name="site" value="https://example.com/"', $offered, 'search: the account\'s properties are offered (URL prefix)');

        $this->adminPost('/admin.php?module=connectors&action=property', ['site' => 'sc-domain:example.com'], self::CONNECTORS);
        $this->adminPost('/admin.php?module=connectors&action=property', ['site' => 'javascript:alert(1)'], self::CONNECTORS);
        $this->assertSame('{"search_console_site":"sc-domain:example.com"}', $this->site()->value("SELECT config FROM ka_connectors WHERE service = 'google'"), 'search: the chosen property is kept in the connection\'s settings, a made-up one is refused');
        $this->assertStringNotContainsString('name="site" value=', $this->site()->admin()->get(self::CONNECTORS)->body, 'search: the loaded list is shown only once');
    }

    #[Depends('testGoogleOffersItsSearchConsolePropertiesOnce')]
    public function testTheDailyJobLoadsBothEnginesIntoOneSnapshot(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'bing', 'client_id' => '', 'account' => '', 'secret' => 'bing-test-key', 'config' => ['site_url' => 'https://example.com/']], self::CONNECTORS);
        $this->assertSame('1|0|https://example.com/', $site->value("SELECT CONCAT(connected_at IS NOT NULL, '|', secret LIKE '%bing-test-key%', '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.site_url'))) FROM ka_connectors WHERE service = 'bing'"), 'search: Bing is connected with its key stored encrypted and its site in the settings');

        $tasks = $this->runJob('search_data');
        $this->assertStringContainsString('search_data: google 5, bing 2', $tasks, 'search: the daily job loaded 2 queries, 2 pages and a sitemap from Google and 1 query and 1 page from Bing');

        $today = $this->siteDate('today');
        $this->assertSame('bing:page:https://example.com/kontakt:4:50:8.00:5.0 bing:query:kaleta bing:6:120:5.00:5.0 google:page:https://example.com/sluzby:31:640:4.84:6.2 google:page:https://example.com/:12:200:6.00:2.1 google:query:kaleta cms:42:900:4.67:3.4 google:query:firemní web zdarma:7:310:2.26:11.8 google:sitemap:https://example.com/sitemap.xml:10:15:66.67:0.0',
            $site->value("SELECT GROUP_CONCAT(CONCAT(engine, ':', kind, ':', `key`, ':', clicks, ':', impressions, ':', ctr, ':', position) ORDER BY engine, kind, clicks DESC SEPARATOR ' ') FROM ka_search_stats WHERE day = ?", [$today]),
            'search: the snapshot of today – Google\'s CTR in per cent, Bing\'s days summed with the position weighted by impressions, the stale day dropped, the sitemap counts');

        $log = $this->fakeLogContents('search.log');
        $this->assertStringContainsString('"site":"sc-domain:example.com","dimension":"query"', $log, 'search: Google was asked for the chosen property');
        $this->assertStringContainsString('"limit":250', $log, 'search: Google was asked for 250 rows per dimension');
        $this->assertStringContainsString('"bing":"GetPageStats","site":"https://example.com/","has_key":true', $log, 'search: Bing was asked for the registered site with the key');

        $this->assertSame('search.page,search.query,search.sitemaps,search.sites|0', $site->value("SELECT CONCAT(GROUP_CONCAT(DISTINCT action ORDER BY action), '|', SUM(action LIKE '%bing-test-key%' OR error LIKE '%bing-test-key%')) FROM ka_connector_log WHERE action LIKE 'search.%'"), 'search: the calls are logged by their action, and the Bing key is in no log row');
    }

    #[Depends('testTheDailyJobLoadsBothEnginesIntoOneSnapshot')]
    public function testStatisticsConnectionsAndMcpShowTheDataButNeverTheKey(): void
    {
        $site = $this->site();
        $stats = $this->assertPage('/admin.php?module=stats&days=7', 200, 'kaleta cms', message: 'search: Statistics show the queries and pages of both engines with the sitemap coverage');
        $this->assertStringContainsString('<td>kaleta bing</td><td class="number">6</td><td class="number">120</td><td class="number">5,0 %</td><td class="number">5,0</td>', $stats->body, 'search: the Bing query row');
        $this->assertStringContainsString('href="https://example.com/sluzby"', $stats->body, 'search: the page link');
        $this->assertStringContainsString('<td>https://example.com/sitemap.xml</td><td class="number">15</td><td class="number">10</td>', $stats->body, 'search: the sitemap row');
        $this->assertStringContainsString('Nejčastější dotazy (Google)', $stats->body, 'search: the Google heading');
        $this->assertStringNotContainsString('bing-test-key', $stats->body, 'search: no Bing key in Statistics');

        $this->assertStringNotContainsString('bing-test-key', $site->admin()->get(self::CONNECTORS)->body, 'search: the Bing key is not on the Connections screen');

        $text = $this->mcpText('get_stats', ['days' => 7]);
        $today = $this->siteDate('today');
        $this->assertStringContainsString('"search":{"google":{"day":"' . $today . '","covers_days":28,"queries":[{"query":"kaleta cms","clicks":42,"impressions":900,"ctr":4.67,"position":3.4}', $text, 'search: get_stats carries search.google');
        $this->assertStringContainsString('"sitemaps":[{"path":"https://example.com/sitemap.xml","submitted":15,"indexed":10}]', $text, 'search: get_stats carries the sitemaps');
        $this->assertStringContainsString('"bing":{"day":"' . $today . '","covers_days":28,"queries":[{"query":"kaleta bing","clicks":6', $text, 'search: get_stats carries search.bing');
        $this->assertStringNotContainsString('bing-test-key', $text, 'search: get_stats never carries the key');

        $connectors = $this->mcpText('list_connectors');
        $this->assertStringContainsString('"service":"bing","name":"Bing Webmaster Tools","auth":"token","connected":true', $connectors, 'search: Claude sees Bing connected');
        $this->assertStringNotContainsString('bing-test-key', $connectors, 'search: Claude never sees the key');
    }

    #[Depends('testStatisticsConnectionsAndMcpShowTheDataButNeverTheKey')]
    public function testTheMonthlyReportNamesTheTopQueriesOfTheMonth(): void
    {
        $last = trim($this->site()->php('echo (new DateTimeImmutable("last day of last month"))->format("Y-m-d");'));
        $this->site()->exec("INSERT INTO ka_search_stats (day, engine, kind, `key`, clicks, impressions, ctr, position) VALUES (?, 'google', 'query', 'kaleta minulý měsíc', 15, 300, 5, 4.0)", [$last]);
        $report = $this->assertPage('/admin.php?module=settings&action=report_preview', 200, 'kaleta minulý měsíc na Google', message: 'search: the monthly report mentions the top queries when the month has a snapshot');
        $this->assertStringContainsString('Hledání, která přivedla návštěvníky', $report->body, 'search: the report section heading');
        $this->assertStringNotContainsString('kaleta bing', $report->body, 'search: only the queries of that month');
    }

    #[Depends('testTheMonthlyReportNamesTheTopQueriesOfTheMonth')]
    public function testARefusedBingKeyIsReportedAndBothEnginesDisconnect(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'bing', 'client_id' => '', 'account' => '', 'secret' => 'wrong-key', 'config' => ['site_url' => 'https://example.com/']], self::CONNECTORS);
        $tasks = $this->runJob('search_data');
        $this->assertStringContainsString('search_data: google 5, bing: HTTP 401', $tasks, 'search: the job reports the refused Bing key and does not fail');
        $this->assertSame('1|2|0',$site->value("SELECT CONCAT(last_error LIKE '%401%', '|', (SELECT COUNT(*) FROM ka_search_stats WHERE engine = 'bing' AND day = ?), '|', (SELECT failures FROM ka_jobs WHERE name = 'search_data')) FROM ka_connectors WHERE service = 'bing'", [$this->siteDate('today')]),
            'search: a refused Bing key is reported on the connection, the day\'s Bing snapshot stays, the job has no failure');

        $screen = $site->admin()->get(self::CONNECTORS)->body;
        $this->assertStringNotContainsString('wrong-key', $screen, 'search: Connections never shows the key');
        $this->assertStringContainsString('HTTP 401', $screen, 'search: Connections shows the refused key as the connection\'s error');

        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'bing'], self::CONNECTORS);
        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'google'], self::CONNECTORS);
        $this->assertSame('bing:1:1,google:1:0', $site->value("SELECT GROUP_CONCAT(CONCAT(service, ':', connected_at IS NULL, ':', secret IS NULL) ORDER BY service) FROM ka_connectors"), 'search: both engines disconnected again, the stored key gone');
    }
}
