<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Settings;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\ImportersBooking\BookingFixture;
use Talea\Tests\Site\Support\SiteTestCase;

/** Settings → Messages: the owner's own texts for courses and bookings, over the admin and over MCP. The methods build on each other. */
#[Group('site')]
final class MessagesTest extends SiteTestCase
{
    use BookingFixture;

    private const string TAB = '/admin.php?module=settings&tab=messages';

    private function mcpText(string $tool, array $args = []): string
    {
        return (string) ($this->site()->mcp($tool, $args)['result']['content'][0]['text'] ?? '');
    }

    private function register(string $email): string
    {
        $visitor = $this->site()->client('visitor');
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $visitor->get('/courses-test/salsa');
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)

        return $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/salsa',
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => 'Anna', 'p1' => $email, 'p3' => '1'])->redirect;
    }

    /** Saves the Messages form of the courses group in the default language. @param array<string, string> $fields */
    private function save(array $fields): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'messages', 'group' => 'courses', 'language' => ''] + $fields, self::TAB);
    }

    public function testTabOffersCoursesOnceACalendarExists(): void
    {
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $this->site()->setting($name, $value); // mail fails on purpose: the queue keeps the body
        }
        $this->assertStringNotContainsString('tab=messages', $this->site()->admin()->get('/admin.php?module=settings')->body, 'no calendar, no bookings: no tab');
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = trim($this->site()->php('echo date("Y-m-d", strtotime("+30 days"));'));
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Salsa', 'slug' => 'salsa', 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => '5', 'registration' => 'instant'], 'visible' => true]);
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->site()->value("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();

        $body = $this->assertPage(self::TAB, 200, ['Course cancelled, other courses offered', 'Thank you: registered', 'Back to the template', 'Send me a test'])->body;
        $this->assertStringNotContainsString('group=bookings', $body, 'bookings are off');
    }

    public function testCustomisedThankYouAndMailAreUsed(): void
    {
        $result = json_decode($this->mcpText('update_settings', ['settings' => [
            'message_course_thanks_registered_body' => 'Lovely, see you on the dance floor!',
            'message_course_registered_subject' => 'Seat for you: {course}',
        ]]), true);
        $this->assertSame([], (array) $result['errors']);
        $this->assertSame('Lovely, see you on the dance floor!', $result['settings']['messages']['message_course_thanks_registered_body'], 'the result lists the texts that are set');

        $redirect = $this->register('anna@example.com');
        $this->assertStringContainsString('result=registered', $redirect);
        $this->assertSame('Seat for you: Salsa', $this->site()->value("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringContainsString('Lovely, see you on the dance floor!', $this->site()->client('visitor')->get(preg_replace('/#.*/', '', $redirect))->body);
    }

    public function testUnknownPlaceholderIsRefused(): void
    {
        $result = json_decode($this->mcpText('update_settings', ['settings' => ['message_course_registered_body' => 'Pay {price} now']]), true);
        $this->assertStringContainsString('{price}', $result['errors']['message_course_registered_body']);
        $this->assertStringContainsString('{course}', $result['errors']['message_course_registered_body'], 'it names the placeholders that exist');
        $this->assertSame('', $this->site()->settingValue('message_course_registered_body'));

        $this->save(['message_course_registered_body' => 'Pay {price} now']);
        $this->assertSame('', $this->site()->settingValue('message_course_registered_body'), 'the admin refuses it too');
        $this->assertStringContainsString('Unknown placeholder {price}', $this->site()->admin()->get(self::TAB)->body);
    }

    public function testMcpRefusesUnknownLanguageAndNormalisesTemplate(): void
    {
        $result = json_decode($this->mcpText('update_settings', ['settings' => ['message_course_full_body_fr' => 'Complet', 'message_course_full_body' => 'This course is full. Join the waiting list and we will e-mail you when a seat is free.']]), true);
        $this->assertStringContainsString('no language version "fr"', $result['errors']['message_course_full_body_fr']);
        $this->assertArrayNotHasKey('message_course_full_body', (array) $result['settings']['messages'], 'the template itself is no override');
        $this->assertSame('', $this->site()->settingValue('message_course_full_body'));
    }

    public function testFormKeepsTemplatesEmptyAndSavesOwnText(): void
    {
        $this->save(['message_course_confirmed_subject' => 'Confirmed: {course}', 'message_course_confirmed_body' => 'We cannot wait, {name|there}!']);
        $this->assertSame('', $this->site()->settingValue('message_course_confirmed_subject'), 'a subject left as the template is no own text');
        $this->assertSame('We cannot wait, {name|there}!', $this->site()->settingValue('message_course_confirmed_body'));
        $this->assertStringContainsString('<span class="badge">Changed</span>', $this->site()->admin()->get(self::TAB . '&group=courses')->body);
    }

    public function testBackToTemplateEmptiesOverride(): void
    {
        $this->assertSame('Seat for you: {course}', $this->site()->settingValue('message_course_registered_subject'));
        $this->save(['reset_message' => 'course_registered', 'message_course_registered_subject' => 'Seat for you: {course}']);
        $this->assertSame('', $this->site()->settingValue('message_course_registered_subject'));
        $this->assertSame('', $this->site()->settingValue('message_course_registered_body'));
        $this->assertSame('We cannot wait, {name|there}!', $this->site()->settingValue('message_course_confirmed_body'), 'other messages are untouched');
    }

    public function testSendMeATest(): void
    {
        $to = 'owner@example.com';
        $this->adminPost('/admin.php?module=settings&action=message_test', ['id' => 'course_registered', 'language' => ''], self::TAB);
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_mail WHERE recipient = ?', [$to]), 'an account without an e-mail address gets nothing');
        $this->site()->exec("UPDATE tl_users SET email = ? WHERE username = 'admin'", [$to]);
        $this->adminPost('/admin.php?module=settings&action=message_test', ['id' => 'course_registered', 'language' => ''], self::TAB);
        $this->assertSame('You are registered: [Course]', $this->site()->value('SELECT subject FROM tl_mail WHERE recipient = ? ORDER BY mail_id DESC LIMIT 1', [$to]));
        $this->adminPost('/admin.php?module=settings&action=message_test', ['id' => 'course_thanks_registered', 'language' => ''], self::TAB);
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_mail WHERE recipient = ? AND subject = ?', [$to, 'You are registered: [Course]']), 'a page text is not a mail');
    }

    public function testEditedBookingConfirmationIsSent(): void
    {
        $this->bookingFixture();
        $this->assertStringContainsString('group=bookings', $this->site()->admin()->get(self::TAB)->body, 'bookings are on: the group is offered');
        $result = json_decode($this->mcpText('update_settings', ['settings' => [
            'message_booking_confirmation_subject' => 'Booked for {date}',
            'message_booking_confirmation_body' => "Hello {name}, your slot is booked.\n\n{appointment}",
            'message_booking_pending_thanks_body' => 'Request noted, {unknown} soon.',
        ]]), true);
        $this->assertArrayHasKey('message_booking_pending_thanks_body', (array) $result['errors']);

        $this->assertStringContainsString('result=ok', $this->book(['slot' => self::$day . ' 14:00', 'name' => 'Edit Booker', 'email' => 'edit-bk@example.cz', 'consent' => '1']));
        $mail = $this->site()->value("SELECT CONCAT(subject, '|', body) FROM tl_mail WHERE recipient = 'edit-bk@example.cz' ORDER BY mail_id LIMIT 1");
        $this->assertStringContainsString('Booked for ', (string) $mail);
        $this->assertStringContainsString('Hello Edit Booker, your slot is booked.', (string) $mail);
        $this->assertStringContainsString('_booking/cancel/', stripslashes((string) $mail), 'the cancel link is added at the end when the text lacks it');
    }
}
