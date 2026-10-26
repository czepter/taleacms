<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\SearchInsights;

final class SearchInsightsTest extends TestCase
{
    public function testOpportunitiesKeepAlmostRankingQueriesWithPage(): void
    {
        $rows = [
            ['key' => 'near', 'position' => 11.4, 'impressions' => 2140],
            ['key' => 'top', 'position' => 3.0, 'impressions' => 5000],
            ['key' => 'rare', 'position' => 12.0, 'impressions' => 40],
        ];
        $pq = [
            ['key' => 'near', 'page' => 'https://x.test/a', 'impressions' => 100],
            ['key' => 'near', 'page' => 'https://x.test/b', 'impressions' => 900],
        ];
        $this->assertSame([['query' => 'near', 'position' => 11.4, 'impressions' => 2140, 'page' => 'https://x.test/b']], SearchInsights::opportunities($rows, $pq));
        $this->assertNull(SearchInsights::opportunities($rows, [])[0]['page']);
    }

    public function testLowCtrFlagsPagesClickedLessThanHalfOfExpected(): void
    {
        $rows = [
            ['key' => 'low', 'position' => 6.1, 'impressions' => 500, 'ctr' => 0.9],
            ['key' => 'good', 'position' => 1.2, 'impressions' => 500, 'ctr' => 20.0],
            ['key' => 'few', 'position' => 6.1, 'impressions' => 100, 'ctr' => 0.1],
            ['key' => 'unknown', 'position' => 0.0, 'impressions' => 500, 'ctr' => 0.0],
        ];
        $this->assertSame([['page' => 'low', 'impressions' => 500, 'ctr' => 0.9, 'position' => 6.1]], SearchInsights::lowCtr($rows));
    }
}
