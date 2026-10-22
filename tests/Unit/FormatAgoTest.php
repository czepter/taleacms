<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormatAgoTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** docs/specs/admin-lists.md: dates younger than seven days are relative, older ones a plain date */
    public function testRelativeWithinAWeekThenDate(): void
    {
        $now = strtotime('2026-10-22 12:00:00');
        $this->assertSame(
            ['—', 'just now', '45 min ago', '5 h ago', 'yesterday', '3 days ago', format_date('2026-10-14 12:00:00')],
            array_map(fn (?string $value): string => format_ago($value, $now), [null, '2026-10-22 11:59:30', '2026-10-22 11:15:00', '2026-10-22 07:00:00', '2026-10-21 18:00:00', '2026-10-19 12:00:00', '2026-10-14 12:00:00'])
        );
    }

    public function testACallersDateTimeIsLeftAlone(): void
    {
        $value = new \DateTime('2026-10-20 15:30:00');
        format_ago($value, strtotime('2026-10-22 12:00:00'));
        $this->assertSame('2026-10-20 15:30:00', $value->format('Y-m-d H:i:s'), 'format_ago does not move the time of the object it was given');
    }

    public function testEarlierTodayIsHoursNotYesterday(): void
    {
        $this->assertSame('11 h ago', format_ago('2026-10-22 00:30:00', strtotime('2026-10-22 12:00:00')));
    }
}
