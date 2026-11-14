<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Visit counting confirmed by the browser (Front\Stats::view, the beacon of image/web.js): the request only renders, the beacon counts. */
#[Group('site')]
final class VisitBeaconTest extends SiteTestCase
{
    private function views(): int
    {
        return (int) $this->site()->value('SELECT COALESCE(SUM(views), 0) FROM tl_stats_days');
    }

    public function testARequestAloneCountsNothingTheBeaconDoes(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_stats_days');
        $site->exec('DELETE FROM tl_stats_visitors');
        $site->clearPageCache();

        $visitor = $site->client('beacon-visitor');
        $page = $visitor->get('/');
        $visitor->get('/'); // answered from the page cache
        $this->assertSame(0, $this->views(), 'rendering the page (also from the cache) counts nothing');
        $this->assertMatchesRegularExpression('# data-view="/_view" data-view-path="/" data-view-news="" data-view-sig="[0-9a-f]{32}"#', $visitor->get('/')->body, 'the cached page carries what the beacon needs');
        $this->assertStringNotContainsString('data-view=', $site->admin()->get('/')->body, 'no beacon for signed-in people');
        $this->assertStringNotContainsString('data-view=', $visitor->get('/?preview=1')->body, 'no beacon in a preview');

        $this->assertSame(204, $this->countView($visitor, '/')->status, 'the beacon answers 204');
        $this->countView($visitor, '/');
        $this->assertSame('1|2', $site->value("SELECT CONCAT(SUM(visits), '|', SUM(views)) FROM tl_stats_days"), 'one visit, two page views for the same visitor and day');
        $this->assertSame('2', (string) $site->value('SELECT views FROM tl_stats_visitors'), 'the visitor of the day remembers its views');
        $this->assertNotSame('', $page->body);
    }

    public function testWhatTheBrowserDidNotShowIsNotCounted(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_stats_days');
        $client = $site->client('beacon-other');
        $page = $client->get('/');
        $fields = static function (string $name) use ($page): string {
            preg_match('/ ' . $name . '="([^"]*)"/', $page->body, $m);

            return html_entity_decode($m[1] ?? '');
        };
        $beacon = fn (array $override = [], array $headers = ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Dest: empty'], string $browser = 'Mozilla/5.0 other') => $client->post('/_view',
            $override + ['path' => $fields('data-view-path'), 'news' => '', 'sig' => $fields('data-view-sig')], $headers, $browser);

        $beacon(['sig' => 'forged'], browser: 'Mozilla/5.0 forged');                       // a made-up signature
        $beacon(['path' => '/other'], browser: 'Mozilla/5.0 other path');                    // a signature for another page
        $beacon([], ['Sec-Purpose: prefetch'], 'Mozilla/5.0 prefetch');                     // prefetch
        $beacon([], ['Sec-Purpose: prefetch;prerender'], 'Mozilla/5.0 prerender');
        $beacon([], ['Purpose: prefetch'], 'Mozilla/5.0 old prefetch');
        $beacon([], ['Sec-Fetch-Site: cross-site'], 'Mozilla/5.0 cross site');              // not made by the page itself
        $beacon([], ['Sec-Fetch-Dest: document'], 'Mozilla/5.0 document');
        $beacon([], browser: 'Googlebot/2.1');                                               // a crawler
        $beacon([], browser: 'curl/8.0');
        $site->admin()->post('/_view', ['path' => $fields('data-view-path'), 'news' => '', 'sig' => $fields('data-view-sig')], ['Sec-Fetch-Site: same-origin']);
        $this->assertSame(0, $this->views(), 'forged, advance, cross-site, crawler and signed-in requests count nothing');

        $beacon([], browser: 'Mozilla/5.0 real');
        $this->assertSame(1, $this->views(), 'a real browser counts');
    }

    public function testMachinesAndTheDailyCap(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_stats_days');
        $site->exec('DELETE FROM tl_stats_visitors');
        $machine = $site->client('machine');
        for ($i = 0; $i < 14; $i++) {
            $this->countView($machine, '/', 'Mozilla/5.0 machine');
        }
        $this->assertLessThan(10, $this->views(), '10 views within 10 seconds are a machine: it counts no further');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_stats_visitors WHERE machine = TRUE'), 'the visitor is marked as a machine');

        $site->exec('DELETE FROM tl_stats_days');
        $site->exec('DELETE FROM tl_stats_visitors');
        $reader = $site->client('reader');
        $this->countView($reader, '/', 'Mozilla/5.0 reader');
        $this->assertSame(1, $this->views());
        $site->exec('UPDATE tl_stats_visitors SET views = 300');
        $this->countView($reader, '/', 'Mozilla/5.0 reader');
        $this->assertSame(1, $this->views(), 'past 300 page views a day nothing is added');
    }

    public function testTheDayCountingChangedIsMarked(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_stats_days');
        $today = date('Y-m-d');
        $site->setting('stats_counting_since', $today);
        $this->assertStringNotContainsString('stats-counting', $site->admin()->get('/admin.php?module=stats&days=7')->body, 'a site without older figures has no break to mark');
        $site->exec('INSERT INTO tl_stats_days (day, visits, views) VALUES (CURRENT_DATE - INTERVAL 3 DAY, 10, 30)');

        $stats = $site->admin()->get('/admin.php?module=stats&days=7');
        $this->assertStringContainsString('class="chart" role="group"', $stats->body, '#338: the chart is a group of bars');
        $this->assertStringContainsString('aria-describedby="stats-chart-summary"', $stats->body);
        $this->assertStringContainsString('id="stats-chart-summary"', $stats->body, 'the group is described by a text summary');
        $this->assertMatchesRegularExpression('/class="chart-column"[^>]* role="img"/', $stats->body, 'every bar is an image with its own label');
        $this->assertStringNotContainsString('class="chart" role="img"', $stats->body, 'no image around focusable bars');
        $this->assertStringContainsString('class="small-text stats-counting"', $stats->body, 'Statistics name the day counting changed');
        $this->assertStringContainsString('Figures before ' . $today . ' counted every request', $stats->body, 'the note carries the day');
        $this->assertStringContainsString('Figures before ' . $today, $site->admin()->get('/admin.php?days=7')->body, 'the dashboard marks it too');
        $text = $this->site()->mcpText('get_stats', ['days' => 7]);
        $this->assertStringContainsString('"counting":{"method":"A visit is counted when', $text, 'get_stats explains how visits are counted');
        $this->assertStringContainsString('"changed_on":"' . $today . '"', $text, 'get_stats names the day counting changed');
    }
}
