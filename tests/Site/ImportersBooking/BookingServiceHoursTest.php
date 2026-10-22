<?php

declare(strict_types=1);

namespace Talea\Tests\Site\ImportersBooking;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Weekly hours per service (upstream #24): one person keeps the general hours for one service and own hours for another; the hours
 * of a service replace the general ones for it only, all services share the person's one calendar, so times never overlap.
 * The tests build on each other.
 */
#[Group('site')]
final class BookingServiceHoursTest extends SiteTestCase
{
    use BookingFixture;

    private static int $shoot = 0;

    public function testClaudeKeepsHoursForOneService(): void
    {
        $this->bookingFixture();
        $site = $this->site();
        $this->bookingText('save_booking_service', ['name' => 'Family shoot', 'duration_min' => 60, 'buffer_min' => 0]);
        self::$shoot = (int) $site->value("SELECT id FROM tl_booking_services WHERE name = 'Family shoot'");
        $shoot = $site->publicId('booking_services', self::$shoot);
        $text = $this->bookingText('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'services' => [$site->publicId('booking_services', self::$service), $shoot],
            'service_hours' => [['service' => $shoot, 'hours' => [$this->weekday() => '13:00-15:00']]]]);
        $this->assertStringContainsString('"service_hours":[{"service":"' . $shoot . '","hours":{"' . $this->weekdayNumber() . '":"13:00-15:00"}}]', $text, 'service hours: save_booking_staff returns them by the public id of the service');
        $this->assertSame('1|7', $this->hourRows(), 'service hours: one row for the service, the general week stays');
        $this->assertStringContainsString('"service":"' . $shoot . '"', $this->bookingText('booking_availability', []), 'service hours: booking_availability lists them');
        $this->assertStringContainsString('not valid', $this->bookingRaw('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'service_hours' => [['service' => '00000000-0000-4000-8000-000000000000', 'hours' => []]]]), 'service hours: an unknown service is refused');
        $this->assertStringContainsString('ranges', $this->bookingRaw('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'service_hours' => [['service' => $shoot, 'hours' => ['monday' => '17-9']]]]), 'service hours: wrong hours are refused');
    }

    public function testTheServiceIsOfferedOnlyInItsHours(): void
    {
        $slots = $this->shootSlots(self::$day);
        foreach (['"13:00"', '"14:00"'] as $time) {
            $this->assertStringContainsString($time, $slots, "service hours: $time is offered for the service");
        }
        foreach (['"09:00"', '"12:00"', '"14:30"', '"15:00"'] as $time) {
            $this->assertStringNotContainsString($time, $slots, "service hours: $time is outside the service's hours");
        }
        $this->assertDoesNotMatchRegularExpression('/"\d\d:\d\d"/', $this->shootSlots(date('Y-m-d', strtotime(self::$day . ' +1 day'))), "service hours: no time on a day without the service's hours, although the person works then");
        $this->assertStringContainsString('"09:00"', $this->slots()->body, 'service hours: the other service keeps the general weekly hours');
    }

    public function testServicesShareOneCalendar(): void
    {
        $day = self::$day;
        $this->assertStringContainsString('result=ok', $this->book(['slot' => "$day 13:00", 'name' => 'Haircut Customer', 'email' => 'cut-bk@example.cz', 'consent' => '1']), 'service hours: the haircut is booked at 13:00');
        $slots = $this->shootSlots($day);
        $this->assertStringNotContainsString('"13:00"', $slots, 'service hours: the shoot cannot start while the person cuts hair');
        $this->assertStringContainsString('"14:00"', $slots, 'service hours: the shoot is still offered after the haircut');
        $this->assertStringNotContainsString('"13:00"', $this->slots()->body, 'service hours: the haircut time is taken for the haircut too');
    }

    public function testTheAdministratorEditsTheHoursPerService(): void
    {
        $site = $this->site();
        $staff = $site->publicId('booking_staff', self::$staff);
        $shoot = $site->publicId('booking_services', self::$shoot);
        $form = '/admin.php?module=bookings&action=services&id=' . $shoot;
        $page = $this->assertPage($form, 200, 'value="own" checked', message: 'service hours: the service has its own page, with "Only at certain times" chosen');
        $this->assertMatchesRegularExpression('/name="hours_' . $staff . '_' . $this->weekdayNumber() . '" maxlength="100" value="13:00.15:00"/u', $page->body, "service hours: the service's page shows the person's times for it");
        $this->assertStringNotContainsString('name="hours_' . $shoot . '_', $this->assertPage('/admin.php?module=bookings&action=staff_edit&id=' . $staff, 200, 'Family shoot')->body, "service hours: the person's form only links to the service");
        $fields = ['id' => $shoot, 'name' => 'Family shoot', 'duration_min' => '60', 'buffer_min' => '0', 'active' => '1', 'staff' => [$staff], 'hours_mode' => 'own'];
        foreach (range(1, 7) as $d) {
            $fields['hours_' . $staff . '_' . $d] = $d === 6 ? '10:00-12:00' : '';
        }
        $this->adminPost('/admin.php?module=bookings&action=service_save', $fields, $form);
        $this->assertSame('6|10:00|12:00', $this->q('SELECT CONCAT(weekday, \'|\', time_from, \'|\', time_to) FROM tl_booking_hours WHERE service_id = ' . self::$shoot), "service hours: the service's form replaces its times");
        $person = ['id' => $staff, 'name' => 'Jane Booking', 'email' => 'jana-bk@example.cz', 'active' => '1', 'services' => [$site->publicId('booking_services', self::$service), $shoot]];
        foreach (range(1, 7) as $d) {
            $person['hours_' . $d] = '9:00-17:00';
        }
        $this->adminPost('/admin.php?module=bookings&action=staff_save', $person, '/admin.php?module=bookings&action=staff_edit&id=' . $staff);
        $this->assertSame('1|7', $this->hourRows(), "service hours: saving the person keeps the service's times");
        $fields['hours_' . $staff . '_6'] = '';
        $this->adminPost('/admin.php?module=bookings&action=service_save', $fields, $form);
        $this->assertSame('1|7', $this->hourRows(), 'service hours: "Only at certain times" without any time is refused');
        $fields['hours_mode'] = 'person';
        $this->adminPost('/admin.php?module=bookings&action=service_save', $fields, $form);
        $this->assertSame('0|7', $this->hourRows(), 'service hours: "When the person works" takes the service back to the weekly hours');
        $this->assertStringContainsString('"09:00"', $this->shootSlots(self::$day), 'service hours: ... and the service is offered in the general hours again');
    }

    public function testRemovingTheServiceRemovesItsHours(): void
    {
        $site = $this->site();
        $site->mcp('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'service_hours' => [['service' => $site->publicId('booking_services', self::$shoot), 'hours' => ['saturday' => '13:00-15:00']]]]);
        $this->assertSame('1', $this->q('SELECT COUNT(*) FROM tl_booking_hours WHERE service_id IS NOT NULL'), 'service hours: saved again');
        $site->exec('DELETE FROM tl_bookings WHERE service_id = ?', [self::$shoot]);
        $site->exec('DELETE FROM tl_booking_services WHERE id = ?', [self::$shoot]);
        $this->assertSame('0|7', $this->hourRows(), 'service hours: the foreign key removes them with the service, the general week stays');
    }

    /** The person's rows of hours: "kept for a service|general". */
    private function hourRows(): string
    {
        return $this->q("SELECT CONCAT(COUNT(service_id), '|', COUNT(*) - COUNT(service_id)) FROM tl_booking_hours WHERE staff_id = " . self::$staff);
    }

    private function shootSlots(string $day): string
    {
        return $this->site()->client()->get('/_booking/slots?service=' . $this->site()->publicId('booking_services', self::$shoot) . '&staff=0&day=' . $day)->body;
    }

    /** The weekday of the fixture's day as MCP names it (monday … sunday). */
    private function weekday(): string
    {
        return strtolower(date('l', (int) strtotime(self::$day)));
    }

    private function weekdayNumber(): int
    {
        return (int) date('N', (int) strtotime(self::$day));
    }
}
