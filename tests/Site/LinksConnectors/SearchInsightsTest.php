<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;
use Talea\Core\SearchData;
use Talea\Tests\Site\Support\SiteTestCase;

/** Core\SearchInsights reading tl_search_stats. */
#[Group('site')]
final class SearchInsightsTest extends SiteTestCase
{
    private const string ORIGIN = 'https://example.com';

    private function insights(string $call): mixed
    {
        $out = $this->site()->php('echo json_encode(Talea\\Core\\SearchInsights::' . $call . ');');

        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }

    private function row(string $day, string $engine, string $kind, string $key, int $clicks, int $impressions, float $position, string $page = ''): void
    {
        $this->site()->exec('INSERT INTO tl_search_stats (day, engine, kind, `key`, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$day, $engine, $kind, $key, $page, $clicks, $impressions, $impressions > 0 ? round(100 * $clicks / $impressions, 2) : 0, $position]);
    }

    public function testWithoutDataEverythingIsEmpty(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->assertNull($this->insights("latestDay($db, 'google')"));
        $this->assertNull($this->insights("summary($db, 'both')"));
        $this->assertSame([], $this->insights("byPath($db, 'google', '" . self::ORIGIN . "')"));
        $this->assertSame(['totals' => null, 'queries' => []], $this->insights("forPath($db, 'a', '" . self::ORIGIN . "')"));
        $this->assertNull($this->insights("notInSearch($db, '" . self::ORIGIN . "', ['a'])"));
    }

    public function testSummaryPreviousAndBothEngines(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->site()->exec("DELETE FROM tl_search_stats");
        $today = date('Y-m-d', strtotime('-5 days'));
        $this->row($today, 'google', 'total', '', 50, 1000, 5.0);
        $this->row($today, 'google', 'day', $today, 50, 1000, 5.0);
        $this->row($today, 'bing', 'total', '', 10, 500, 0.0);
        $this->row($today, 'bing', 'day', $today, 10, 500, 0.0);
        $this->assertNull($this->insights("summary($db, 'google')")['previous'], 'one snapshot has no previous');
        $this->assertSame($today, $this->insights("summary($db, 'google')")['day']);

        $this->row(date('Y-m-d', strtotime($today . ' -30 days')), 'google', 'total', '', 40, 800, 6.0);
        $google = $this->insights("summary($db, 'google')");
        $this->assertSame(40, $google['previous']['clicks'], 'a snapshot 30 days earlier is within the 3 day tolerance');
        $both = $this->insights("summary($db, 'both')");
        $this->assertSame(60, $both['totals']['clicks']);
        $this->assertSame(1500, $both['totals']['impressions']);
        $this->assertEquals(5.0, $both['totals']['position'], 'Bing has no position, so only Google counts');
        $this->assertNull($this->insights("summary($db, 'bing')")['totals']['position']);
        $this->assertSame([['day' => $today, 'clicks' => 60, 'impressions' => 1500]], $both['days']);
    }

    public function testPathsAndMissingPages(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->site()->exec("DELETE FROM tl_search_stats");
        $o = self::ORIGIN;
        $day = date('Y-m-d');
        $this->row($day, 'google', 'page', 'https://www.example.com/services/', 30, 600, 4.0);
        $this->row($day, 'google', 'page', 'https://example.com/services?x=1', 10, 200, 8.0);
        $this->row($day, 'google', 'page', 'https://example.com/', 5, 100, 2.0);
        $this->row($day, 'google', 'page', 'https://other.test/services', 99, 999, 1.0);
        $this->row($day, 'google', 'page_query', 'talea', 20, 300, 4.0, 'https://example.com/services');
        $this->row($day, 'google', 'page_query', 'cms', 2, 400, 9.0, 'https://example.com/services');
        $this->row($day, 'google', 'page_query', 'elsewhere', 2, 400, 9.0, 'https://example.com/');

        $by = $this->insights("byPath($db, 'google', '$o')");
        $this->assertSame(['services', ''], array_keys($by));
        $this->assertSame(40, $by['services']['clicks']);
        $this->assertSame(800, $by['services']['impressions']);
        $this->assertSame(['talea', 'cms'], array_column($this->insights("forPath($db, '/services', '$o')")['queries'], 'query'));
        $this->assertSame(40, $this->insights("forPath($db, 'services', '$o')")['totals']['clicks']);
        $this->assertSame(['about'], $this->insights("notInSearch($db, '$o', ['/services', '', 'about'])"));
    }

    public function testEncodedPathsMatchRawOnes(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->site()->exec("DELETE FROM tl_search_stats");
        $o = self::ORIGIN;
        $day = date('Y-m-d');
        $this->row($day, 'google', 'page', 'https://example.com/caf%C3%A9', 3, 50, 4.0);
        $this->row($day, 'google', 'page_query', 'kaffee', 1, 20, 4.0, 'https://example.com/caf%C3%A9');
        $this->assertSame(['café'], array_keys($this->insights("byPath($db, 'google', '$o')")));
        $this->assertSame(3, $this->insights("forPath($db, 'café', '$o')")['totals']['clicks']);
        $this->assertSame(['kaffee'], array_column($this->insights("forPath($db, '/café', '$o')")['queries'], 'query'));
        $this->assertSame(['other'], $this->insights("notInSearch($db, '$o', ['café', 'other'])"));
    }

    public function testPreviousNeedsEveryEngineOfTheTotals(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->site()->exec("DELETE FROM tl_search_stats");
        $day = date('Y-m-d', strtotime('-5 days'));
        $this->row($day, 'google', 'total', '', 50, 1000, 5.0);
        $this->row($day, 'bing', 'total', '', 10, 500, 0.0);
        $this->row(date('Y-m-d', strtotime($day . ' -28 days')), 'google', 'total', '', 40, 800, 6.0);
        $this->assertNull($this->insights("summary($db, 'both')")['previous']);
        $this->assertSame(40, $this->insights("summary($db, 'google')")['previous']['clicks']);
    }

    public function testFullPageListCannotSayWhichPagesAreMissing(): void
    {
        $db = '(Talea\\Core\\App::boot())->db()';
        $this->site()->exec("DELETE FROM tl_search_stats");
        $day = date('Y-m-d');
        for ($i = 0; $i < SearchData::ROW_LIMIT; $i++) {
            $this->row($day, 'google', 'page', self::ORIGIN . '/p' . $i, 1, 10, 5.0);
        }
        $this->assertNull($this->insights("notInSearch($db, '" . self::ORIGIN . "', ['a'])"));
    }
}
