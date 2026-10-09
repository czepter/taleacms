<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 96, part 1 (3.0/3.2): the feature is off on a new site, Claude sets up services, people and hours, the element and the free times. */
#[Group('site')]
final class BookingSetupTest extends SiteTestCase
{
    use BookingFixture;

    public function testNothingAnswersWhileTheFeatureIsOff(): void
    {
        $this->bookingMail();
        $this->assertPage('/admin.php?module=bookings', 403, message: '3.2 bookings off: no admin module');
        $raw = $this->bookingRaw('save_booking_service', ['name' => 'Off test']);
        $this->assertStringContainsString('switched off on this site', $raw, '3.2 bookings off: the MCP tools say the feature is off');
        $this->assertSame('0', $this->q('SELECT COUNT(*) FROM ka_booking_services'), '3.2 bookings off: the MCP tool saves nothing');
        $this->assertPage('/_booking/days?service=1&staff=0&month=2026-01', 404, message: '3.2 bookings off: the public booking addresses are a 404');
    }

    public function testClaudeSetsUpServiceAndPerson(): void
    {
        $this->bookingSwitchOn();
        $this->assertSame('1', $this->q("SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'"), '3.2 bookings: switched on over MCP');
        $text = $this->bookingStaffAndService();
        $this->assertGreaterThan(0, self::$service, 'booking: Claude saves a service');
        $this->assertGreaterThan(0, self::$staff, 'booking: Claude saves a person');
        $this->assertMatchesRegularExpression('/"7":\[\["09:00","17:00"\]\]/', $text, 'booking: the person has weekly hours (Sunday = 7)');
        $this->assertStringContainsString('ranges', $this->bookingRaw('save_booking_staff', ['name' => 'Nikdo', 'hours' => ['monday' => '17-9']]), 'booking: wrong hours are refused');
        // the rest of the fixture (the page with the element), so that the next tests do not set everything up twice
        $this->bookingPage();
        $this->bookingForm();
        self::$bookingReady = true;
    }

    public function testTheElementRendersTheForm(): void
    {
        $this->bookingFixture();
        $html = $this->bookingForm()->body;
        foreach (['class="ka-rezervace"', 'data-rezervace="bk1"', 'Střih test', 'name="as_podpis"', 'name="slot" required', 'image/web.js'] as $needle) {
            $this->assertStringContainsString($needle, $html, "booking: the element renders $needle (service, plain select of free times, spam protection, web.js)");
        }
    }

    public function testFreeTimesAndDays(): void
    {
        $this->bookingFixture();
        $slots = $this->slots()->body;
        $this->assertStringContainsString('"09:00"', $slots, 'booking: /_booking/slots returns the first free time');
        $this->assertStringContainsString('"16:30"', $slots, 'booking: /_booking/slots returns the last free time');
        $this->assertStringNotContainsString('"17:00"', $slots, 'booking: no time starts when the day ends');
        $days = $this->site()->client()->get('/_booking/days?service=' . self::$service . '&staff=0&month=' . substr(self::$day, 0, 7))->body;
        $this->assertStringContainsString('"' . self::$day . '"', $days, 'booking: /_booking/days lists the day among the days with free times');
    }
}
