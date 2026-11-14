<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DaysAgoTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    public function testSpringForwardKeepsTheCalendarCount(): void
    {
        $from = $this->at('2026-03-28 23:30');
        $now = $this->at('2026-03-30 23:10');
        $this->assertSame(2, days_ago($from, $now));
        $this->assertSame(1, intdiv($now - $from, 86400), 'seconds / 86400 is a day short across the change');
    }

    public function testFallBackKeepsTheCalendarCount(): void
    {
        $from = $this->at('2026-10-24 00:10');
        $now = $this->at('2026-10-25 23:50');
        $this->assertSame(1, days_ago($from, $now));
        $this->assertSame(2, intdiv($now - $from, 86400), 'seconds / 86400 is a day over across the change');
    }

    public function testTodayAndYesterday(): void
    {
        $this->assertSame(0, days_ago($this->at('2026-06-10 00:01'), $this->at('2026-06-10 23:59')));
        $this->assertSame(1, days_ago($this->at('2026-06-09 23:59'), $this->at('2026-06-10 00:01')));
    }
}
