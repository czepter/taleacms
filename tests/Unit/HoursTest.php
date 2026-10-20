<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HoursTest extends TestCase
{
    /** 2.10: opening hours with exceptions */
    public function testOpeningHoursWithExceptions(): void
    {
        $hWeek = array_fill_keys(\Talea\Core\Hours::DAYS, []);
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $hDay) { $hWeek[$hDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
        $hXmas = ['id' => 1, 'from' => '2026-12-24', 'to' => '2026-12-26', 'closed' => true, 'hours' => '', 'note' => 'Christmas', 'notice_days' => 7];
        $hShort = ['id' => 2, 'from' => '2026-12-31', 'to' => '2026-12-31', 'closed' => false, 'hours' => '9-12', 'note' => '', 'notice_days' => 0];
        $hAt = fn (string $when): \DateTimeImmutable => new \DateTimeImmutable($when);
        $hStatus = fn (string $when, array $ex = []): array => (fn (array $st): array => [$st['open'], $st['until'], $st['next']?->format('Y-m-d H:i')])(\Talea\Core\Hours::status($hWeek, $ex, $hAt($when)));
        $this->assertSame([[['09:00', '12:00'], ['13:30', '17:00']], null], [\Talea\Core\Hours::parseRanges('9-12, 13:30–17'), \Talea\Core\Hours::parseRanges('morning')], '2.10: Hours::parseRanges – 9-12, 9:00–12:00 and more ranges; nonsense is refused');
        $this->assertSame([[true, '12:00', null], [false, null, '2026-10-05 13:00'], [false, null, '2026-10-05 08:00'], [false, null, '2026-12-28 08:00'], [true, '12:00', null], [false, null, '2027-01-01 08:00']], [
            $hStatus('2026-10-05 10:00'), $hStatus('2026-10-05 12:30'), $hStatus('2026-10-03 11:00'), $hStatus('2026-12-23 18:00', [$hXmas]), $hStatus('2026-12-31 10:00', [$hShort]), $hStatus('2026-12-31 12:30', [$hShort])], '2.10: Hours::status – open until, lunch break, closed for the weekend, a holiday, shorter hours');
        $this->assertSame([0, 1, 0, 0, [['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => '2026-12-24', 'validThrough' => '2026-12-26'],
                ['@type' => 'OpeningHoursSpecification', 'opens' => '09:00', 'closes' => '12:00', 'validFrom' => '2026-12-31', 'validThrough' => '2026-12-31']]], [
            count(\Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-16 10:00'))), count(\Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-17 10:00'))),
            count(\Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-27 10:00'))), count(\Talea\Core\Hours::noticed([$hShort], $hAt('2026-12-31 10:00'))),
            \Talea\Core\Hours::schema([$hXmas, $hShort])], '2.10: Hours – the notice bar a week ahead until the end, not with notice_days 0; exceptions in schema.org');
    }
}
