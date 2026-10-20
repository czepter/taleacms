<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    /** 3.0: online booking of appointments (Core\Booking) */
    public function testBooking(): void
    {
        $bk = \Talea\Core\Booking::class;
        $bkTz = new \DateTimeZone('Europe/Prague');
        $bkNow = new \DateTimeImmutable('2026-11-09 08:00', $bkTz); // the day before
        $bkDay = '2026-11-10';
        $bkRanges = [['09:00', '12:00'], ['13:00', '17:00']];
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'], $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, $bkNow, 0, 60), '3.0 Booking::free – slots across a lunch break step by the duration');
        $this->assertSame(['09:00', '09:45'], $bk::free([['09:00', '11:00']], [], [], 45, 0, 45, $bkDay, $bkNow, 0, 60), '3.0 Booking::free – a 45-minute service fits only where 45 minutes are left');
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '15:00', '15:30', '16:00', '16:30'], $bk::free($bkRanges, [], [['2026-11-10 13:00:00', '2026-11-10 15:00:00']], 30, 0, 30, $bkDay, $bkNow, 0, 60), '3.0 Booking::free – a day off (an exception) takes its hours out');
        $this->assertSame(['09:00', '11:00', '11:30'], $bk::free([['09:00', '12:00']], [['2026-11-10 10:00:00', '2026-11-10 10:30:00'], ['2026-11-11 09:00:00', '2026-11-11 12:00:00']], [], 30, 15, 30, $bkDay, $bkNow, 0, 60), '3.0 Booking::free – an existing booking blocks its time and the buffer around it; a booking of another day does not');
        $this->assertSame([['10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'], [], []], [
            $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new \DateTimeImmutable('2026-11-10 08:30', $bkTz), 2, 60),
            $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new \DateTimeImmutable('2026-11-10 16:45', $bkTz), 0, 60),
            $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new \DateTimeImmutable('2026-11-11 08:00', $bkTz), 0, 60)], '3.0 Booking::free – the lead time: nothing sooner than two hours from now, never in the past');
        $this->assertSame([[], ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'], []], [
            $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-20', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-19', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, 'nonsense', $bkNow, 0, 10)], '3.0 Booking::free – the horizon: a day beyond it has no times, the last day in it has');
        // the clocks go forward on 2026-03-29 at 02:00 in Prague: the slots are stepped on the real timeline and shown as wall-clock times
        $this->assertSame([['09:00', '10:00', '11:00'], ['01:00', '03:00'], ['09:00', '11:00']], [
            $bk::free([['09:00', '12:00']], [], [], 60, 0, 60, '2026-03-29', new \DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
            $bk::free([['01:00', '04:00']], [], [], 60, 0, 60, '2026-03-29', new \DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
            $bk::free([['09:00', '12:00']], [['2026-03-29 10:00:00', '2026-03-29 11:00:00']], [], 60, 0, 60, '2026-03-29', new \DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60)], '3.0 Booking::free – a DST day gives the same wall-clock times as any other, and a range across the switch skips the hour that does not exist');
        $this->assertSame([2, 2, 3, null], [
            $bk::leastBooked([1, 2, 3], [1 => 2, 2 => 0, 3 => 1], [1, 2, 3]), $bk::leastBooked([1, 2], [], [2, 1]), $bk::leastBooked([3], [1 => 0, 3 => 5], [1, 2, 3]), $bk::leastBooked([], [], [1, 2])], '3.0 Booking::leastBooked – "anyone" goes to the least-booked free person that day, ties to the first in the order, nobody free = null');
        $this->assertSame([30, 45, 60, 15, 15], [$bk::step(30), $bk::step(45), $bk::step(60), $bk::step(90), $bk::step(0)], '3.0 Booking::step – the duration up to an hour, otherwise a quarter');
        $this->assertSame([[1 => [['09:00', '12:00'], ['13:00', '17:00']], 7 => [['10:00', '14:00']]], null, null, null], [
            $bk::parseHours(['monday' => '9-12, 13:00-17:00', 2 => '', '7' => '10-14']), $bk::parseHours(['funday' => '9-12']), $bk::parseHours([1 => '12-9']), $bk::parseHours([3 => 'whenever'])], '3.0 Booking::parseHours – weekday names or numbers with ranges, empty days left out, a wrong range or day refused');
        $this->assertSame([['2026-12-24 00:00:00', '2026-12-25 00:00:00'], ['2026-12-24 08:00:00', '2026-12-24 12:00:00'], ['2026-12-24 00:00:00', '2026-12-27 00:00:00'], null, null], [
            $bk::offRange('2026-12-24', ''), $bk::offRange('2026-12-24 08:00', '2026-12-24 12:00'), $bk::offRange('2026-12-24', '2026-12-26'), $bk::offRange('2026-12-26', '2026-12-24'), $bk::offRange('christmas', '')], '3.0 Booking::offRange – a day, a part of a day, a span; an end before the start is refused');
        $bkSite = ['Tuesday' => [['08:00', '16:00']]] + array_fill_keys(\Talea\Core\Hours::DAYS, []);
        $bkClosed = [['id' => 1, 'from' => '2026-11-10', 'to' => '2026-11-10', 'closed' => true, 'hours' => '', 'note' => 'Inventory', 'notice_days' => 0]];
        $this->assertSame([[['10:00', '18:00']], [['08:00', '16:00']], [], [], []], [
            $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, [], new \DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new \DateTimeImmutable('2026-11-10', $bkTz)),
            $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, $bkClosed, new \DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, $bkClosed, new \DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new \DateTimeImmutable('2026-11-11', $bkTz))], '3.0 Booking::dayRanges – own hours, else the site\'s; a closed day of the site is a day off for everyone');
        $this->assertSame([['read', ''], ['read', ''], ['write', ''], ['destructive', ''], true, false, 3600, true, true, 'booking', \Talea\Builder\Elements\Booking::class, false, true, 'bookings', '2', '60', true], [
            \Talea\Mcp\Catalog::TOOLS['list_bookings'], \Talea\Mcp\Catalog::TOOLS['booking_availability'], \Talea\Mcp\Catalog::TOOLS['save_booking_staff'], \Talea\Mcp\Catalog::TOOLS['cancel_booking'], \Talea\Mcp\Catalog::allows('drafts', 'list_bookings'), \Talea\Mcp\Catalog::allows('drafts', 'cancel_booking'),
            \Talea\Core\Scheduler::JOBS['booking_reminders'][0], isset(\Talea\Core\Events::TYPES['booking.created']), isset(\Talea\Core\Events::TYPES['booking.cancelled']), 'booking',
            \Talea\Builder\Build::className('booking'), (bool) preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-booking'), (bool) preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-booking-x'), \Talea\Admin\Guide::MODULES['bookings'] ?? null,
            \Talea\Core\Settings::DEFAULTS['booking_lead_hours'], \Talea\Core\Settings::DEFAULTS['booking_horizon_days'], in_array('booking_services', \Talea\Core\SiteImport::TABLES, true)], '3.0 Booking: the tools are in the catalog with the right access, the job and the events exist, the element has an English name and its hook attribute is reserved');
    }
}
