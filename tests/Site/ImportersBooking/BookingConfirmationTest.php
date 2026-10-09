<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Old section 96, part 3 (3.3): a service that needs the provider's confirmation - a request holds the time, the provider accepts,
 * declines or proposes other times, the customer picks one, the hold runs out and the provider is reminded once.
 */
#[Group('site')]
final class BookingConfirmationTest extends SiteTestCase
{
    use BookingFixture;

    private static int $accepted = 0;
    private static int $declined = 0;
    private static int $proposed = 0;
    private static string $choose = '';

    private function booking(string $email): int
    {
        return (int) $this->q("SELECT id FROM ka_bookings WHERE email = '$email'");
    }

    public function testARequestHoldsTheTime(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $this->bookingText('save_booking_service', ['id' => self::$service, 'requires_confirmation' => true]);
        $this->assertSame('1', $this->q('SELECT requires_confirmation FROM ka_booking_services WHERE id = ' . self::$service), '3.3 booking: the service needs confirmation');
        $this->site()->setting('booking_pending_mail', 'Ahoj {name}, dostali jsme tvoji zprávu.');
        $this->assertStringContainsString('result=pending', $this->book(['slot' => "$day 15:00", 'name' => 'Pavla Žádost', 'email' => 'pavla-bk@example.cz', 'souhlas' => '1']), "3.3 booking: a visitor's request comes back as pending");
        $this->assertSame('pending|1', $this->q("SELECT CONCAT(status, '|', hold_until IS NOT NULL) FROM ka_bookings WHERE email = 'pavla-bk@example.cz'"), '3.3 booking: saved as pending with a hold');
        $this->assertSame('1|1|1', $this->q("SELECT CONCAT((SELECT COUNT(*) FROM ka_mail WHERE recipient = 'pavla-bk@example.cz' AND subject LIKE 'Přijali jsme vaši žádost%' AND body LIKE '%Ahoj Pavla Žádost, dostali jsme tvoji zprávu.%'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'Žádost čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'pavla-bk@example.cz'))"), "3.3 booking: the customer got the acknowledgement in the site's own words, the person the notification, nobody a confirmation");
        $slots = $this->slots()->body;
        $this->assertStringNotContainsString('"15:00"', $slots, '3.3 booking: a pending request holds its time');
        $this->assertStringContainsString('"16:00"', $slots, '3.3 booking: the time after the held one is free');
        $this->assertStringContainsString('result=taken', $this->book(['slot' => "$day 15:00", 'name' => 'Druha', 'email' => 'druha-bk@example.cz', 'souhlas' => '1']), '3.3 booking: nobody else can take the held time');
    }

    public function testAcceptAndDecline(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $this->assertPage('/admin.php?module=bookings&action=list', 200, message: 'the list opens');
        $this->assertPage('/admin.php?module=bookings', 200, 'Pavla Žádost', message: '3.3 booking: the list shows the request and what waits');
        self::$accepted = $this->booking('pavla-bk@example.cz');
        $detail = '/admin.php?module=bookings&action=detail&id=' . self::$accepted;
        $this->assertPage($detail, 200, 'id="propose-slots"', message: '3.3 booking: the detail offers accept, decline and other times');
        $this->adminPost('/admin.php?module=bookings&action=confirm', ['id' => self::$accepted], $detail);
        $this->assertSame('confirmed|1|1', $this->q("SELECT CONCAT(status, '|', hold_until IS NULL, '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'pavla-bk@example.cz' AND body LIKE '%_booking%cancel%')) FROM ka_bookings WHERE id = " . self::$accepted), '3.3 booking: accepted - confirmed, hold released, the customer got the confirmation with a cancel link');

        $this->book(['slot' => "$day 16:00", 'name' => 'Dana Odmítnutá', 'email' => 'dana-bk@example.cz', 'souhlas' => '1']);
        self::$declined = $this->booking('dana-bk@example.cz');
        $this->adminPost('/admin.php?module=bookings&action=decline', ['id' => self::$declined, 'message' => 'Ve čtvrtek bohužel nejsem na místě.'], '/admin.php?module=bookings&action=detail&id=' . self::$declined);
        $this->assertSame('declined|admin|1', $this->q("SELECT CONCAT(status, '|', cancelled_by, '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'dana-bk@example.cz' AND body LIKE '%Ve čtvrtek bohužel nejsem na místě.%')) FROM ka_bookings WHERE id = " . self::$declined), '3.3 booking: declined - the customer is told with the personal message');
        $this->assertStringContainsString('"16:00"', $this->slots()->body, '3.3 booking: a declined request frees the time');
    }

    public function testProposedTimes(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $site = $this->site();
        $this->book(['slot' => "$day 16:00", 'name' => 'Eva Návrh', 'email' => 'eva-bk@example.cz', 'souhlas' => '1']);
        self::$proposed = $this->booking('eva-bk@example.cz');
        $this->assertStringContainsString('confirm', $this->bookingRaw('propose_booking_times', ['id' => self::$proposed, 'times' => ["$day 12:30"]]), '3.3 booking: propose_booking_times needs an explicit confirmation');
        $this->assertStringContainsString('not free', $this->bookingRaw('propose_booking_times', ['id' => self::$proposed, 'times' => ["$day 15:00"], 'confirm' => true]), '3.3 booking: a time that is not free cannot be proposed');
        $site->mcp('propose_booking_times', ['id' => self::$proposed, 'times' => ["$day 12:30", "$day 16:00"], 'message' => 'Wie wäre es früher?', 'confirm' => true]);
        $this->assertSame('pending|2', $this->q("SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = " . self::$proposed . ')) FROM ka_bookings WHERE id = ' . self::$proposed), '3.3 booking: two times proposed - the own held time may be among them, the booking stays pending');
        $body = (string) $site->value("SELECT body FROM ka_mail WHERE recipient = 'eva-bk@example.cz' AND body LIKE '%_booking%choose%' ORDER BY mail_id DESC LIMIT 1");
        self::$choose = preg_match('#_booking/choose/([a-f0-9]{32})#', (string) (json_decode($body, true)['text'] ?? ''), $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', self::$choose, '3.3 booking: the e-mail carries the link to pick a time');
        $choose = '/_booking/choose/' . (self::$choose !== '' ? self::$choose : '0000000000000000000000000000000a');
        $this->assertPage($choose, 200, 'name="proposal"', $site->client(), '3.3 booking: the page lists the proposed times');
        $this->assertSame('pending', $this->q('SELECT status FROM ka_bookings WHERE id = ' . self::$proposed), '3.3 booking: opening the link chooses nothing');
        $proposal = (int) $this->q('SELECT id FROM ka_booking_proposals WHERE booking_id = ' . self::$proposed . " AND starts_at LIKE '% 12:30:00'");
        $site->client('visitor')->post($choose, ['proposal' => (string) $proposal]);
        $this->assertSame('confirmed|12:30:00|0|1', $this->q("SELECT CONCAT(status, '|', TIME(starts_at), '|', (SELECT COUNT(*) FROM ka_booking_proposals WHERE booking_id = " . self::$proposed . "), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'Zákazník přijal navržený termín%')) FROM ka_bookings WHERE id = " . self::$proposed), '3.3 booking: the customer picks a time - the booking moves there and is confirmed, the person is told');
    }

    public function testAnUnansweredRequestRunsOut(): void
    {
        $this->bookingFixture();
        $day = self::$day;
        $site = $this->site();
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'rezervace'"); // the limit of five bookings an hour from one address
        $this->book(['slot' => "$day 16:00", 'name' => 'Hana Čekající', 'email' => 'hana-bk@example.cz', 'souhlas' => '1']);
        $site->exec("UPDATE ka_bookings SET hold_until = NOW() - INTERVAL 1 HOUR WHERE email = 'hana-bk@example.cz'");
        $site->exec("UPDATE ka_jobs SET last_run = NOW() - INTERVAL 2 HOUR WHERE name = 'booking_reminders'");
        $site->runTasks();
        $this->assertSame('1|1|pending', $this->q("SELECT CONCAT((SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'Stále čeká na vaši odpověď%'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'hana-bk@example.cz'), '|', (SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'))"), '3.3 booking: the hold ran out - the provider is reminded, the customer got only the acknowledgement');
        $site->exec("UPDATE ka_jobs SET last_run = NOW() - INTERVAL 2 HOUR WHERE name = 'booking_reminders'");
        $site->runTasks();
        $this->assertSame('1', $this->q("SELECT COUNT(*) FROM ka_mail WHERE recipient = 'jana-bk@example.cz' AND subject LIKE 'Stále čeká na vaši odpověď%'"), '3.3 booking: the reminder goes out once');
        $this->assertStringContainsString('result=pending', $this->book(['slot' => "$day 16:00", 'name' => 'Iva', 'email' => 'iva-bk@example.cz', 'souhlas' => '1']), '3.3 booking: after the hold the time can be requested by someone else');
        $site->mcp('confirm_booking', ['id' => $this->booking('iva-bk@example.cz'), 'confirm' => true]);
        $this->assertSame('confirmed', $this->q("SELECT status FROM ka_bookings WHERE email = 'iva-bk@example.cz'"), '3.3 booking: Claude accepts the request that holds the time now');
        $this->assertStringContainsString('taken', $this->bookingRaw('confirm_booking', ['id' => $this->booking('hana-bk@example.cz'), 'confirm' => true]), '3.3 booking: a request whose held time ran out and was taken cannot be accepted');
        $this->assertSame('pending', $this->q("SELECT status FROM ka_bookings WHERE email = 'hana-bk@example.cz'"), '3.3 booking: the taken request stays pending');
        $pending = $this->bookingText('list_bookings', ['from' => $day, 'to' => $day, 'status' => 'pending']);
        $this->assertStringContainsString('hana-bk@example.cz', $pending, '3.3 booking: list_bookings filters the pending requests');
        $this->assertStringNotContainsString('iva-bk@example.cz', $pending, '3.3 booking: the confirmed request is not in the pending list');
    }
}
