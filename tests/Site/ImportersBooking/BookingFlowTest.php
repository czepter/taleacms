<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Old section 96, part 2 (3.0): a visitor books a time, confirmation e-mails and tokens, cancelling by the customer, the reminder job,
 * what Claude and the administrator see, a booking by phone, personal data requests and the retention. The tests build on each other.
 */
#[Group('site')]
final class BookingFlowTest extends SiteTestCase
{
    use BookingFixture;

    private static string $token = '';

    public function testVisitorBooksATime(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $this->assertStringContainsString('result=ok', $this->book(['slot' => "$day 10:00", 'name' => 'Peter Booker', 'email' => 'petr-bk@example.cz', 'phone' => '+420777000111', 'note' => 'Test', 'souhlas' => '1']), 'booking: a visitor books a time');
        $staff = self::$staff;
        $this->assertSame("1|$staff|10:30:00", $this->q("SELECT CONCAT(COUNT(*), '|', MAX(staff_id), '|', MAX(TIME(ends_at))) FROM ka_bookings WHERE email = 'petr-bk@example.cz' AND status = 'confirmed'"), 'booking: saved as confirmed for the person, with the end time by the duration');
        $this->assertSame('1|1', $this->q("SELECT CONCAT((SELECT COUNT(*) FROM ka_mail WHERE recipient = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'New booking%'))"), 'booking: the confirmation went to the customer and the notification to the person');
        $this->assertStringContainsString('result=taken', $this->book(['slot' => "$day 10:00", 'name' => 'Druhy', 'email' => 'druhy-bk@example.cz', 'souhlas' => '1']), 'booking: the same time cannot be booked twice');
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM ka_bookings WHERE starts_at = '$day 10:00:00'"), 'booking: the second attempt saved nothing');
        $slots = $this->slots()->body;
        $this->assertStringNotContainsString('"10:00"', $slots, 'booking: the booked time is gone from the free times');
        $this->assertStringNotContainsString('"09:30"', $slots, 'booking: the buffer before the booking is gone');
        $this->assertStringNotContainsString('"10:30"', $slots, 'booking: the buffer after the booking is gone');
        $this->assertStringContainsString('"11:00"', $slots, 'booking: the time after the buffer is free');
        $this->assertStringContainsString('result=consent', $this->book(['slot' => "$day 11:00", 'name' => 'Petr', 'email' => 'petr-bk@example.cz', 'souhlas' => '']), 'booking: without the consent nothing is saved');
    }

    public function testConfirmationTokenAndCancel(): void
    {
        $this->bookingFixture();
        self::$token = $this->cancelToken('ASC');
        $this->assertNotSame('', self::$token, 'booking: the confirmation carries the cancel link');
        $token = self::$token !== '' ? self::$token : '0000000000000000000000000000000a';
        $this->assertPage("/_booking/ics/$token", 200, 'BEGIN:VEVENT', message: "booking: the .ics file for the customer's calendar");
        $this->assertPage("/_booking/cancel/$token", 200, 'Cancel the appointment?', message: 'booking: the cancel page asks before it cancels');
        $this->assertSame('confirmed', $this->q("SELECT status FROM ka_bookings WHERE email = 'petr-bk@example.cz'"), 'booking: opening the link cancels nothing');
        $answer = $this->cancelBooking(self::$token);
        $this->assertSame(200, $answer->status, 'booking: the cancel request answers');
        $this->assertStringContainsString('Your appointment is cancelled', $answer->body, 'booking: the customer cancels before the deadline');
        $this->assertSame('cancelled|customer|1', $this->q("SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'Booking cancelled%')) FROM ka_bookings WHERE email = 'petr-bk@example.cz'"), 'booking: cancelled by the customer, the person was told');
    }

    public function testCancelDeadlineAndReminders(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $site = $this->site();
        $this->assertStringContainsString('result=ok', $this->book(['slot' => "$day 11:00", 'name' => 'Peter Booker', 'email' => 'petr-bk@example.cz', 'souhlas' => '1']), 'booking: a second appointment');
        $second = $this->cancelToken('DESC');
        $site->setting('booking_cancel_hours', '200');
        $answer = $this->cancelBooking($second);
        $this->assertSame(200, $answer->status, 'booking: the late cancel request answers');
        $this->assertStringContainsString('can no longer be cancelled online', $answer->body, 'booking: after the deadline the link refuses to cancel');
        $this->assertSame('confirmed', $this->q("SELECT status FROM ka_bookings WHERE starts_at = '$day 11:00:00'"), 'booking: the appointment stays confirmed');
        $site->setting('booking_cancel_hours', '24');
        $site->setting('booking_reminder_hours', '100');
        $site->exec("UPDATE ka_bookings SET created_at = NOW() - INTERVAL 10 DAY WHERE starts_at = '$day 11:00:00'");
        $site->exec("DELETE FROM ka_jobs WHERE name = 'booking_reminders'");
        $site->runTasks();
        $this->assertSame('1|1', $this->q("SELECT CONCAT((SELECT reminded_at IS NOT NULL FROM ka_bookings WHERE starts_at = '$day 11:00:00'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'petr-bk@example.cz' AND subject LIKE 'Reminder%'))"), 'booking: the reminder job sends the reminder once and marks it');
        $site->exec("UPDATE ka_jobs SET last_run = NOW() - INTERVAL 2 HOUR WHERE name = 'booking_reminders'");
        $site->runTasks();
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM ka_mail WHERE recipient = 'petr-bk@example.cz' AND subject LIKE 'Reminder%'"), 'booking: the next run sends no second reminder');
        $site->setting('booking_reminder_hours', '24');
    }

    public function testClaudeAndTheAdministratorSeeTheBookings(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $site = $this->site();
        $list = $this->bookingText('list_bookings', ['from' => $day, 'to' => $day, 'status' => 'all']);
        $this->assertStringContainsString('petr-bk@example.cz', $list, 'booking: Claude lists the bookings of the day');
        $this->assertStringContainsString('"count":2', $list, 'booking: the list counts both bookings');
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM ka_change_log WHERE action = 'list_bookings' AND description LIKE '%2%'"), 'booking: every read of bookings by Claude is in the change log');
        $availability = $this->bookingText('booking_availability', ['service' => self::$service, 'day' => $day]);
        $this->assertStringContainsString('"slots"', $availability, 'booking: booking_availability returns slots');
        $this->assertStringContainsString('"09:00"', $availability, 'booking: booking_availability shows the free times');
        $this->assertStringNotContainsString('petr', $availability, 'booking: booking_availability shows no personal data');
        $this->assertPage('/admin.php?module=bookings', 200, 'Peter Booker', message: 'booking: the admin list shows the booking by day');
        $id = (int) $this->q("SELECT id FROM ka_bookings WHERE starts_at = '$day 11:00:00'");
        $this->assertPage("/admin.php?module=bookings&action=detail&id=$id", 200, 'petr-bk@example.cz', message: 'booking: the admin detail with the customer');
        $this->assertPage('/admin.php?module=bookings&action=services&id=' . self::$service, 200, 'Haircut test', message: 'booking: the services screen');
        $this->assertPage('/admin.php?module=bookings&action=staff_edit&id=' . self::$staff, 200, 'name="hours_1"', message: "booking: the person's form with the weekly hours");
        $this->assertPage('/admin.php?module=bookings&action=new', 200, 'name="day"', message: 'booking: the manual booking form');

        $form = '/admin.php?module=bookings&action=new';
        $this->adminPost('/admin.php?module=bookings&action=create', ['service' => self::$service, 'staff_member' => 0, 'day' => $day, 'time' => '14:00', 'name' => 'Phone Customer', 'email' => '', 'phone' => '777000222'], $form);
        $this->assertSame('1|admin', $this->q("SELECT CONCAT(COUNT(*), '|', MAX(source)) FROM ka_bookings WHERE name = 'Phone Customer' AND status = 'confirmed'"), 'booking: a booking taken by phone, without an e-mail');
        $this->adminPost('/admin.php?module=bookings&action=create', ['service' => self::$service, 'staff_member' => self::$staff, 'day' => $day, 'time' => '14:00', 'name' => 'Collision', 'email' => ''], $form);
        $this->assertSame('0', $this->q("SELECT COUNT(*) FROM ka_bookings WHERE name = 'Collision'"), 'booking: the admin cannot double-book either');

        $phone = (int) $this->q("SELECT id FROM ka_bookings WHERE name = 'Phone Customer'");
        $this->assertStringContainsString('confirm', $this->bookingRaw('cancel_booking', ['id' => $phone]), 'booking: cancel_booking needs an explicit confirmation');
        $site->mcp('cancel_booking', ['id' => $phone, 'confirm' => true]);
        $this->assertSame('cancelled|claude|1', $this->q("SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_change_log WHERE module = 'bookings' AND action = 'cancel' AND description = CONCAT('#', $phone))) FROM ka_bookings WHERE id = $phone"), 'booking: Claude cancels a booking, the change is logged');
    }

    public function testPersonalDataAndRetention(): void
    {
        $this->bookingFixture();
        $site = $this->site();
        $this->assertStringContainsString('"bookings":2', $this->bookingText('find_personal_data', ['email' => 'petr-bk@example.cz']), 'booking: a personal data request finds the bookings');
        $this->assertPage('/admin.php?module=enquiries&action=personal', 200, 'personal-email', message: "booking: the admin's personal data screen links the bookings");
        $site->mcp('erase_personal_data', ['email' => 'petr-bk@example.cz', 'confirm' => true]);
        $this->assertSame('0|1', $this->q("SELECT CONCAT((SELECT COUNT(*) FROM ka_bookings WHERE email = 'petr-bk@example.cz'), '|', (SELECT COUNT(*) FROM ka_bookings WHERE name = 'Phone Customer'))"), "booking: erased on request, the other person's booking stays");
        $site->exec("UPDATE ka_bookings SET ends_at = NOW() - INTERVAL 30 MONTH, starts_at = NOW() - INTERVAL 30 MONTH WHERE name = 'Phone Customer'");
        $site->setting('enquiries_expiry', 'anonymise');
        $site->admin()->get('/admin.php?module=bookings');
        $phone = (int) $this->q("SELECT id FROM ka_bookings WHERE source = 'admin' LIMIT 1");
        $this->assertSame('1|1|1', $this->q("SELECT CONCAT(COUNT(*), '|', MAX(name = ''), '|', MAX(anonymised_at IS NOT NULL)) FROM ka_bookings WHERE id = $phone"), 'booking: past the enquiry retention the booking is anonymised, the row stays');
    }
}
