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

    public function testPathKeepsToTheSiteHostAndFolder(): void
    {
        $root = 'https://example.com/magazin';
        $this->assertSame('services.html', SearchInsights::path('https://www.example.com/magazin/services.html?x=1', $root));
        $this->assertSame('', SearchInsights::path('https://example.com/magazin/', $root), 'the folder itself is the home page');
        $this->assertSame('de', SearchInsights::path('http://example.com/magazin/de/', $root));
        $this->assertSame('café', SearchInsights::path('https://example.com/magazin/caf%C3%A9', $root));
        $this->assertNull(SearchInsights::path('https://shop.example.com/magazin/services', $root), 'another host');
        $this->assertNull(SearchInsights::path('https://example.com/services', $root), 'outside the folder');
        $this->assertNull(SearchInsights::path('https://example.com/magazinx/services', $root), 'a folder that only starts like it');
        $this->assertSame('services', SearchInsights::path('https://example.com/services/', 'https://example.com'));
        $this->assertSame('de/kontakt.html', SearchInsights::key('de/kontakt.html', $root));
        $this->assertSame('café', SearchInsights::key('/café', $root));
        $this->assertSame('', SearchInsights::key('', $root));
    }
}
