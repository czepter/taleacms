<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 96, part 4 (3.2, 3.2.3): the feature switched off again - the element, public addresses, admin module and tools are gone, the data stays, old customer links keep working. */
#[Group('site')]
final class BookingSwitchOffTest extends SiteTestCase
{
    use BookingFixture;

    public function testSwitchedOffAgain(): void
    {
        $this->bookingFixture();
        $site = $this->site();
        $this->assertStringContainsString('class="ka-booking"', $site->client()->get('/booking-test')->body, 'the element is on the page while the feature is on');
        $site->exec("UPDATE ka_settings SET value = ? WHERE name = 'extensions'", [self::$extensionsBefore]);
        $site->clearPageCache();
        $this->assertStringNotContainsString('class="ka-booking"', $site->client()->get('/booking-test')->body, '3.2 bookings off: the Booking element is not on the page');

        // an appointment booked before the switch-off - its cancel and .ics links keep working
        $token = 'cafe0000cafe0000cafe0000cafe0003';
        $site->exec("INSERT INTO ka_bookings (service_id, staff_id, starts_at, ends_at, name, email, token_hash, created_at) VALUES (?, ?, NOW() + INTERVAL 10 DAY, NOW() + INTERVAL 10 DAY + INTERVAL 30 MINUTE, 'Off Customer', 'off-bk@example.cz', SHA2(?, 256), NOW())", [self::$service, self::$staff, $token]);
        $this->assertPage("/_booking/ics/$token", 200, 'BEGIN:VEVENT', $site->client(), "3.2.3 bookings off: the customer's .ics link still works");
        $this->assertPage("/_booking/cancel/$token", 200, 'zrusit', $site->client(), "3.2.3 bookings off: the customer's cancel page still works");
        $site->client()->post("/_booking/cancel/$token", ['zrusit' => '1']);
        $this->assertSame('cancelled|customer', $this->q("SELECT CONCAT(status, '|', cancelled_by) FROM ka_bookings WHERE email = 'off-bk@example.cz'"), '3.2.3 bookings off: the customer can still cancel');
        $this->assertPage('/_booking/slots?service=' . self::$service . '&staff=0&day=2026-01-05', 404, as: $site->client(), message: '3.2.3 bookings off: new bookings are not taken');
        $this->assertPage('/admin.php?module=bookings', 403, message: '3.2 bookings off: no admin module');
        $this->assertStringContainsString('switched off on this site', $this->bookingRaw('list_bookings', []), '3.2 bookings off: list_bookings says so');
        $this->assertSame('1|1', $this->q('SELECT CONCAT((SELECT COUNT(*) > 0 FROM ka_booking_services), \'|\', (SELECT COUNT(*) > 0 FROM ka_bookings))'), '3.2 bookings off: the services and bookings stay');
    }
}
