<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\WebVitals;

final class WebVitalsTest extends TestCase
{
    /** 2.8: real-user speed (Core\WebVitals) – histogram buckets, p75, Google's ratings, the audit rule */
    public function testHistogram(): void
    {
        $this->assertSame([4, 5, 0, 11, 4, 5, 3, 4], [WebVitals::bucket('lcp', 2500), WebVitals::bucket('lcp', 2500.1), WebVitals::bucket('lcp', 0), WebVitals::bucket('lcp', 9000), WebVitals::bucket('cls', 0.1), WebVitals::bucket('cls', 0.11), WebVitals::bucket('inp', 200), WebVitals::bucket('inp', 201)], '2.8: WebVitals::bucket – an edge value belongs to its bucket, the next value to the next one, above the last edge to the open bucket');
        $this->assertSame([true, true, true], array_map(fn (string $m): bool => in_array(WebVitals::THRESHOLDS[$m][0], WebVitals::BUCKETS[$m], true) && in_array(WebVitals::THRESHOLDS[$m][1], WebVitals::BUCKETS[$m], true), array_keys(WebVitals::BUCKETS)), '2.8: WebVitals::THRESHOLDS are bucket edges, so a rating from a bucket edge is exact');
        // 100 samples: 70 in (1500, 2000], 10 in (2000, 2500], 20 in (4000, 5000] – the 75th sample is in the (2000, 2500] bucket
        $this->assertSame(2500.0, WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20]), '2.8: WebVitals::percentile – p75 is the upper edge of the bucket with the 75th sample');
        $this->assertSame(2000.0, WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20], 0.5), '2.8: WebVitals::percentile – p50 of the same samples');
        $this->assertSame(150.0, WebVitals::percentile('inp', [2 => 1]), '2.8: WebVitals::percentile – a single sample is its own p75');
        $this->assertSame([8000.0, null], [WebVitals::percentile('lcp', [11 => 4]), WebVitals::percentile('cls', [])], '2.8: WebVitals::percentile – the open bucket reports its lower edge (at least that), no samples give null');
        $this->assertSame(['good', 'needs_improvement', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor'], [WebVitals::rating('lcp', 2500), WebVitals::rating('lcp', 2501), WebVitals::rating('lcp', 4000), WebVitals::rating('lcp', 4001),
            WebVitals::rating('cls', 0.1), WebVitals::rating('cls', 0.25), WebVitals::rating('cls', 0.3), WebVitals::rating('inp', 200), WebVitals::rating('inp', 500), WebVitals::rating('inp', 501)], '2.8: WebVitals::rating – Google\'s thresholds');
        $this->assertSame([true, false, true, false, false, false, false], [WebVitals::isRegression(3000.0, 2000.0, 30, 30), WebVitals::isRegression(2500.0, 2000.0, 100, 100),
            WebVitals::isRegression(2501.0, 2000.0, 100, 100), WebVitals::isRegression(3000.0, 2000.0, 29, 30), WebVitals::isRegression(3000.0, 2000.0, 30, 29), WebVitals::isRegression(3000.0, null, 30, 30), WebVitals::isRegression(null, 2000.0, 30, 30)], '2.8: WebVitals::isRegression – more than 25 % worse with at least 30 measurements in both periods');
        $this->assertSame([true, true, 0], [str_contains((string) file_get_contents(TALEA_ROOT . '/image/vitals.js'), "getAttribute('data-vitals')"),
            str_contains((string) file_get_contents(TALEA_ROOT . '/image/vitals.js'), 'navigator.sendBeacon('), preg_match('/document\.cookie|localStorage|sessionStorage/', (string) file_get_contents(TALEA_ROOT . '/image/vitals.js'))], '2.8: the beacon script looks for nothing but its own endpoint and sends with sendBeacon');
        $script = (string) file_get_contents(TALEA_ROOT . '/image/vitals.js');
        $this->assertSame([true, true], [str_contains($script, 'e.startTime < firstHidden'), str_contains($script, 'navigation.activationStart')],
            'the beacon leaves out paints after the page was first hidden (a background tab) and counts a prerendered page from its activation');
        $this->assertTrue(in_array('vitals', \Talea\Front\Kernel::reservedSlugs(), true), '2.8: /vitals is a reserved address');
    }
}
