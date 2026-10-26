<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\SearchData;

final class SearchDataRowsTest extends TestCase
{
    public function testGooglePageQueriesCarryTheQueryAsKeyAndThePage(): void
    {
        $rows = SearchData::googlePageQueries(['rows' => [
            ['keys' => ['https://example.com/a', 'q'], 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.26],
            ['keys' => ['https://example.com/b'], 'clicks' => 1],
        ]]);
        $this->assertSame([['kind' => 'page_query', 'key' => 'q', 'clicks' => 3, 'impressions' => 40, 'ctr' => 7.5, 'position' => 4.3, 'page' => 'https://example.com/a']], $rows, 'a row without a query is left out');
    }

    public function testGoogleTotalIsOneRowWithoutKey(): void
    {
        $this->assertSame([['kind' => 'total', 'key' => '', 'clicks' => 10, 'impressions' => 100, 'ctr' => 10.0, 'position' => 7.0, 'page' => '']],
            [SearchData::googleTotal(['rows' => [['clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 7.0]]])]);
        $this->assertSame([], SearchData::googleTotal([]));
    }

    public function testGoogleDaysKeyIsTheDate(): void
    {
        $days = SearchData::googleDays(['rows' => [['keys' => ['2026-10-01'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 3.0], ['keys' => [], 'clicks' => 1]]]);
        $this->assertSame([['kind' => 'day', 'key' => '2026-10-01', 'clicks' => 2, 'impressions' => 20, 'ctr' => 10.0, 'position' => 3.0, 'page' => '']], $days);
    }

    public function testBingDaysDropOldRowsAndParseTheDate(): void
    {
        $since = strtotime('-28 days');
        $recent = (time() - 5 * 86400) * 1000;
        $days = SearchData::bingDays(['d' => [
            ['Date' => '/Date(' . $recent . '-0700)/', 'Clicks' => 4, 'Impressions' => 80],
            ['Date' => '/Date(' . ((time() - 60 * 86400) * 1000) . ')/', 'Clicks' => 9, 'Impressions' => 90],
            ['Clicks' => 1], 'junk',
        ]], $since);
        $this->assertSame([['kind' => 'day', 'key' => date('Y-m-d', intdiv($recent, 1000)), 'clicks' => 4, 'impressions' => 80, 'ctr' => 5.0, 'position' => 0.0, 'page' => '']], $days);
    }
}
