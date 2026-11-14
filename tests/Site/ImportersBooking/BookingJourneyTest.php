<?php

declare(strict_types=1);

namespace Talea\Tests\Site\ImportersBooking;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Bookings walked to the end of the journey: a visitor's request, the provider's answer (admin or Claude), the customer's mail and
 * link, the customer's own step (picking a proposed time, cancelling) and the time free or taken again afterwards. The older
 * classes stop at the first answer; the bugs found by this class lay behind it (the element's fixed service or person switched
 * off, a confirmation that ignored the buffer, Claude's free times with row numbers, a status filter Claude mistyped).
 */
#[Group('site')]
final class BookingJourneyTest extends SiteTestCase
{
    use BookingFixture;

    private static int $consultation = 0;

    /** A second service of the same person that needs the provider's confirmation (30 minutes, 10 minutes buffer). */
    private function consultation(): int
    {
        $this->bookingFixture();
        if (self::$consultation === 0) {
            $this->site()->mcp('save_booking_service', ['name' => 'Consultation test', 'duration_min' => 30, 'buffer_min' => 10, 'requires_confirmation' => true,
                'staff' => [$this->site()->publicId('booking_staff', self::$staff)]]);
            self::$consultation = (int) $this->q("SELECT id FROM tl_booking_services WHERE name = 'Consultation test'");
        }

        return self::$consultation;
    }

    /** A visitor's POST to /_booking for a given service on the fixture's page (the fixture's book() always sends the haircut). @param array<string, string> $fields */
    private function bookService(int $service, array $fields): string
    {
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'booking'"); // five bookings an hour from one address
        $this->site()->clearPageCache();

        return $this->site()->client('visitor')->post('/_booking', $fields + [
            'source' => self::$source, 'element' => 'bk1', 'back' => '/booking-test', 'as_time' => self::$formTime, 'as_signature' => self::$signature,
            'service' => $this->site()->publicId('booking_services', $service), 'staff' => '0', 'consent' => '1',
        ])->redirect;
    }

    /** The free times of a service on the fixture's day, as the element's script asks for them. @return list<string> */
    private function freeTimes(int $service): array
    {
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'booking_query'");
        $json = $this->site()->client()->get('/_booking/slots?service=' . $this->site()->publicId('booking_services', $service) . '&staff=0&day=' . self::$day)->json();

        return array_values(array_map('strval', (array) ($json['slots'] ?? [])));
    }

    /** The text of the newest mail to a recipient (the queue keeps it, the mail server of the fixture fails). */
    private function lastMail(string $to, string $subjectLike = '%'): string
    {
        $body = (string) $this->site()->value('SELECT body FROM tl_mail WHERE recipient = ? AND subject LIKE ? ORDER BY mail_id DESC LIMIT 1', [$to, $subjectLike]);

        return (string) (json_decode($body, true)['text'] ?? '');
    }

    private function token(string $text, string $what): string
    {
        return preg_match('#_booking/' . $what . '/([a-f0-9]{32})#', $text, $m) === 1 ? $m[1] : '';
    }

    public function testTheFreeTimesHelperReadsTheEndpoint(): void
    {
        $this->bookingFixture();
        $this->assertContains('09:00', $this->freeTimes(self::$service), 'the helper reads the free times of the day');
    }

    /** Request → other times proposed by Claude → the customer picks one → confirmation with links → the customer cancels → the time is free again. */
    public function testAProposedTimeCarriesThroughToTheCancel(): void
    {
        $service = $this->consultation();
        $site = $this->site();
        $day = self::$day;
        $this->assertStringContainsString('result=pending', $this->bookService($service, ['slot' => "$day 10:00", 'name' => 'Olga Journey', 'email' => 'olga-bk@example.cz']), 'the request is pending');
        $id = (int) $this->q("SELECT id FROM tl_bookings WHERE email = 'olga-bk@example.cz'");
        $this->assertNotContains('10:00', $this->freeTimes($service), 'the request holds its time');

        $site->mcpConfirmed('propose_booking_times', ['id' => $site->publicId('bookings', $id), 'times' => ["$day 13:00"], 'message' => 'After lunch?', 'confirm' => true]);
        $choose = $this->token($this->lastMail('olga-bk@example.cz', 'Other times%'), 'choose');
        $this->assertNotSame('', $choose, 'the proposal mail carries the choose link');
        $proposal = (int) $this->q("SELECT id FROM tl_booking_proposals WHERE booking_id = $id");
        $picked = $site->client('visitor')->post("/_booking/choose/$choose", ['proposal' => (string) $proposal]);
        $this->assertStringContainsString('Your appointment is confirmed', $picked->body, 'the customer picks the proposed time');
        $this->assertSame("confirmed|$day 13:00:00|$day 13:30:00", $this->q("SELECT CONCAT(status, '|', starts_at, '|', ends_at) FROM tl_bookings WHERE id = $id"), 'the booking moved to the picked time and is confirmed');

        $times = $this->freeTimes($service);
        $this->assertContains('10:00', $times, 'the time of the original request is free again');
        $this->assertNotContains('13:00', $times, 'the picked time is taken');
        $this->assertSame(404, $site->client('visitor')->get("/_booking/choose/$choose")->status, 'the choose link is spent once the time is picked (the token was rotated)');

        $confirmation = $this->lastMail('olga-bk@example.cz', 'Your appointment on%');
        $this->assertStringContainsString('13:00', $confirmation, 'the confirmation names the picked time');
        $cancel = $this->token($confirmation, 'cancel');
        $this->assertNotSame('', $cancel, 'the confirmation carries the cancel link');
        $ics = $site->client('visitor')->get('/_booking/ics/' . $this->token($confirmation, 'ics'));
        $this->assertSame(200, $ics->status, 'the calendar link of the confirmation works');
        $utc = (new \DateTimeImmutable("$day 13:00", new \DateTimeZone($site->settingValue('time_zone') ?: 'Europe/Prague')))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
        $this->assertStringContainsString('DTSTART:' . $utc, $ics->body, 'the calendar file has the picked time in UTC');

        $site->client('visitor')->post("/_booking/cancel/$cancel", ['zrusit' => '1']);
        $this->assertSame('cancelled|customer', $this->q("SELECT CONCAT(status, '|', cancelled_by) FROM tl_bookings WHERE id = $id"), 'the customer cancels with the link of the confirmation');
        $this->assertContains('13:00', $this->freeTimes($service), 'the cancelled time is free again');
    }

    /** A proposed time somebody else booked meanwhile cannot be picked: the request stays pending and the other booking stays as it is. */
    public function testAProposedTimeTakenMeanwhileIsRefused(): void
    {
        $service = $this->consultation();
        $site = $this->site();
        $day = self::$day;
        $this->bookService($service, ['slot' => "$day 11:00", 'name' => 'Petra Late', 'email' => 'petra-late-bk@example.cz']);
        $id = (int) $this->q("SELECT id FROM tl_bookings WHERE email = 'petra-late-bk@example.cz'");
        $site->mcpConfirmed('propose_booking_times', ['id' => $site->publicId('bookings', $id), 'times' => ["$day 14:00"], 'confirm' => true]);
        $choose = $this->token($this->lastMail('petra-late-bk@example.cz', 'Other times%'), 'choose');
        $this->assertStringContainsString('result=ok', $this->bookService(self::$service, ['slot' => "$day 14:00", 'name' => 'Quick Visitor', 'email' => 'quick-bk@example.cz']), 'another visitor books the proposed time first');
        $proposal = (int) $this->q("SELECT id FROM tl_booking_proposals WHERE booking_id = $id");
        $answer = $site->client('visitor')->post("/_booking/choose/$choose", ['proposal' => (string) $proposal]);
        $this->assertStringContainsString('just been taken', $answer->body, 'the customer is told that the time is gone');
        $this->assertSame("pending|$day 11:00:00", $this->q("SELECT CONCAT(status, '|', starts_at) FROM tl_bookings WHERE id = $id"), 'the request stays pending at its own time');
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM tl_bookings WHERE starts_at = '$day 14:00:00' AND status = 'confirmed'"), 'the time is booked once');
        $this->assertSame('0', $this->q("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'petra-late-bk@example.cz' AND subject LIKE 'Your appointment on%'"), 'no confirmation went out');
    }

    /** The administrator cancels: the customer is told, the customer's link says so and the time is free again. */
    public function testTheAdministratorCancels(): void
    {
        $this->bookingFixture();
        $site = $this->site();
        $day = self::$day;
        $this->assertStringContainsString('result=ok', $this->bookService(self::$service, ['slot' => "$day 15:30", 'name' => 'Rita Cancelled', 'email' => 'rita-bk@example.cz']));
        $id = (int) $this->q("SELECT id FROM tl_bookings WHERE email = 'rita-bk@example.cz'");
        $cancel = $this->token($this->lastMail('rita-bk@example.cz'), 'cancel');
        $detail = '/admin.php?module=bookings&action=detail&id=' . $site->publicId('bookings', $id);
        $this->adminPost('/admin.php?module=bookings&action=status', ['id' => $site->publicId('bookings', $id), 'status' => 'cancelled'], $detail);
        $this->assertSame('cancelled|admin', $this->q("SELECT CONCAT(status, '|', cancelled_by) FROM tl_bookings WHERE id = $id"), 'the administrator cancels the booking');
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'rita-bk@example.cz' AND subject LIKE '%was cancelled%'"), 'the customer is told');
        $this->assertStringContainsString('This appointment is cancelled.', $site->client('visitor')->get("/_booking/cancel/$cancel")->body, "the customer's link shows the cancelled appointment");
        $this->assertContains('15:30', $this->freeTimes(self::$service), 'the time is free again');
    }

    /**
     * A request whose hold ran out may lose its neighbourhood: a visitor books right after it. Accepting it then would put two
     * appointments together without the buffer the service keeps – book() never offers that time, so confirm() must not either.
     */
    public function testAcceptingKeepsTheBuffer(): void
    {
        $service = $this->consultation();
        $site = $this->site();
        $day = self::$day;
        $this->bookService($service, ['slot' => "$day 09:00", 'name' => 'Sara Buffer', 'email' => 'sara-bk@example.cz']);
        $id = (int) $this->q("SELECT id FROM tl_bookings WHERE email = 'sara-bk@example.cz'");
        $this->assertNotContains('09:30', $this->freeTimes(self::$service), 'while the request holds its time, the buffer after it is kept free');
        $site->exec("UPDATE tl_bookings SET hold_until = NOW() - INTERVAL 1 HOUR WHERE id = $id");
        $this->assertStringContainsString('result=ok', $this->bookService(self::$service, ['slot' => "$day 09:30", 'name' => 'Next Door', 'email' => 'next-bk@example.cz']), 'after the hold, a visitor books right after the request');
        $answer = $this->bookingRaw('confirm_booking', ['id' => $site->publicId('bookings', $id), 'confirm' => true]);
        $this->assertStringContainsString('taken', $answer, 'accepting the request now is refused – the buffer before the next appointment would be gone');
        $this->assertSame('pending', $this->q("SELECT status FROM tl_bookings WHERE id = $id"), 'the request stays pending');
    }

    /** The element fixes a service (or a person) that was switched off later: the form must still book what it offers. */
    public function testAnElementWhoseFixedServiceOrPersonWasSwitchedOff(): void
    {
        $service = $this->consultation();
        $site = $this->site();
        $day = self::$day;
        $site->mcp('save_booking_staff', ['name' => 'Tom Away', 'services' => [$site->publicId('booking_services', self::$service)], 'hours' => ['monday' => '9:00-17:00']]);
        $tom = (int) $this->q("SELECT id FROM tl_booking_staff WHERE name = 'Tom Away'");
        $site->mcp('create_page', ['title' => 'Fixed booking', 'slug' => 'fixed-booking', 'visible' => true]);
        $site->mcp('save_build', ['id' => $site->publicId('pages', (int) $this->q("SELECT page_id FROM tl_pages WHERE slug = 'fixed-booking'")), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['id' => 'bkservice', 'type' => 'booking', 'content' => ['service' => $site->publicId('booking_services', $service)]],
            ['id' => 'bkperson', 'type' => 'booking', 'content' => ['staff_member' => $site->publicId('booking_staff', $tom)]],
        ]]]]]);
        $site->mcp('save_booking_service', ['id' => $site->publicId('booking_services', $service), 'active' => false]);
        $site->mcp('save_booking_staff', ['id' => $site->publicId('booking_staff', $tom), 'active' => false]);
        $site->clearPageCache();
        $page = $site->client()->get('/fixed-booking');
        $this->assertStringContainsString('Haircut test', $page->body, 'the element shows the services still on offer');
        $source = $page->field('source');
        $post = function (string $element, string $email, string $time) use ($site, $source, $day): string {
            $site->exec("DELETE FROM tl_ip_checks WHERE type = 'booking'");
            $at = time() - 10;

            return $site->client('visitor')->post('/_booking', ['source' => $source, 'element' => $element, 'back' => '/fixed-booking', 'as_time' => $at,
                'as_signature' => hash_hmac('sha256', 'booking|' . $source . '|' . $element . '|' . $at, $site->settingValue('secret_key')),
                'service' => $site->publicId('booking_services', self::$service), 'staff' => '0', 'slot' => "$day $time", 'name' => 'Fixed Visitor', 'email' => $email, 'consent' => '1'])->redirect;
        };
        $this->assertStringContainsString('result=ok', $post('bkservice', 'fixed-service-bk@example.cz', '12:00'), 'the visitor books the service the form offered, not the switched-off one');
        $this->assertStringContainsString('result=ok', $post('bkperson', 'fixed-person-bk@example.cz', '15:00'), 'the visitor books with the people the form offered, not the switched-off person');
        $this->assertSame('2', $this->q("SELECT COUNT(*) FROM tl_bookings WHERE email IN ('fixed-service-bk@example.cz', 'fixed-person-bk@example.cz') AND status = 'confirmed' AND staff_id = " . self::$staff), 'both bookings are saved');
    }

    /** Claude's free times name the people by their public ids, like every other id it gets. */
    public function testClaudeSeesWhoIsFreeByPublicId(): void
    {
        $this->bookingFixture();
        $site = $this->site();
        $result = $site->mcpResult('booking_availability', ['service' => $site->publicId('booking_services', self::$service), 'day' => self::$day]);
        $this->assertIsArray($result);
        $at = $result['free_staff_at']['16:30'] ?? null;
        $this->assertSame([$site->publicId('booking_staff', self::$staff)], $at, 'free_staff_at names the person by the public id that staff[].id carries');
        $staff = $site->mcpResult('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'days_off' => [['from' => '2031-01-02', 'note' => 'Holiday']]]);
        $this->assertIsArray($staff);
        $this->assertSame([['from' => '2031-01-02 00:00:00', 'to' => '2031-01-03 00:00:00', 'note' => 'Holiday']], $staff['staff']['days_off'] ?? null, 'the days off come back without row numbers');
        $site->mcp('save_booking_staff', ['id' => $site->publicId('booking_staff', self::$staff), 'days_off' => []]);
    }

    /** A status list_bookings does not know is refused instead of returning every booking. */
    public function testListBookingsRefusesAnUnknownStatus(): void
    {
        $this->bookingFixture();
        $answer = $this->bookingRaw('list_bookings', ['status' => 'requested']);
        $this->assertStringContainsString('pending', $answer, 'the answer names the statuses there are');
        $this->assertStringNotContainsString('@example.cz', $answer, 'no booking is listed for a status that does not exist');
    }

    /** The owner's own thank-you text (Settings → Messages) is what the visitor reads after booking. */
    public function testTheOwnThankYouTextIsShown(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $this->site()->setting('message_booking_thanks_body', 'See you soon at the salon.');
        $back = $this->bookService(self::$service, ['slot' => "$day 16:00", 'name' => 'Thanks Own', 'email' => 'thanks-own-bk@example.cz']);
        $this->assertStringContainsString('result=ok', $back);
        $path = (string) preg_replace('#^https?://[^/]+#', '', $back);
        $this->assertStringContainsString('See you soon at the salon.', $this->site()->client('visitor')->get($path)->body, "the visitor reads the owner's own text");
    }
}
