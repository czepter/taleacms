<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Front\Stats;

final class StatsBeaconTest extends TestCase
{
    public function testAdvanceRequestsAreRecognised(): void
    {
        $this->assertFalse(Stats::isAdvance([]), 'no headers: an ordinary beacon');
        $this->assertFalse(Stats::isAdvance(['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'empty']), 'a beacon the page sent');
        $this->assertTrue(Stats::isAdvance(['HTTP_SEC_PURPOSE' => 'prefetch']));
        $this->assertTrue(Stats::isAdvance(['HTTP_SEC_PURPOSE' => 'prefetch;prerender']));
        $this->assertTrue(Stats::isAdvance(['HTTP_PURPOSE' => 'Prefetch']));
        $this->assertTrue(Stats::isAdvance(['HTTP_X_MOZ' => 'prefetch']));
        $this->assertTrue(Stats::isAdvance(['HTTP_SEC_FETCH_SITE' => 'cross-site']));
        $this->assertTrue(Stats::isAdvance(['HTTP_SEC_FETCH_DEST' => 'document']));
    }

    public function testTheMethodIsDescribedWithItsLimits(): void
    {
        $this->assertSame([300, 10, 10], [Stats::DAILY_CAP, Stats::MACHINE_VIEWS, Stats::MACHINE_SECONDS]);
        $this->assertStringContainsString('confirms that the page was shown', Stats::method());
        $this->assertStringContainsString('300 page views a day', Stats::method());
    }
}
