<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Cancelling a course with two offered courses, and the take-over link of a cancelled participant. The methods build on each other. */
#[Group('site')]
final class CourseCancelTest extends SiteTestCase
{
    use CollectionsHelpers;

    private function register(string $slug, string $email, string $name = 'Anna', string $seats = '1'): void
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $visitor->get('/courses-test/' . $slug);
        sleep(1); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)
        $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/' . $slug,
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => $name, 'p1' => $email, 'p3' => '1', 'seats' => $seats]);
    }

    private function collectionId(): string
    {
        return $this->site()->publicId('collections', (int) $this->sq("SELECT collection_id FROM tl_collections WHERE slug = 'courses-test'"));
    }

    private function itemPid(string $slug): string
    {
        return $this->site()->publicId('collection_items', (int) $this->sq('SELECT item_id FROM tl_collection_items WHERE slug = ?', [$slug]));
    }

    /** The take-over path /_course/<token>/<n> in the newest mail to this address. */
    private function link(string $email, int $n): string
    {
        $body = (string) $this->sq('SELECT body FROM tl_mail WHERE recipient = ? ORDER BY mail_id DESC LIMIT 1', [$email]);
        $this->assertSame(1, preg_match('~/_course/[a-f0-9]{64}/' . $n . '~', (string) (json_decode($body, true)['text'] ?? ''), $m), "a link $n in the mail to $email");

        return $m[0];
    }

    /** A cancelled registration of $email (from Salsa) with a live token for the course $slug; returns the take-over path. */
    private function offerRow(string $email, string $slug): string
    {
        $token = hash('sha256', $email . $slug);
        $this->site()->exec("INSERT INTO tl_enquiries (created_at, source, element, form, page, data, email, item_id, seat_state, seats, offer_token_hash, offer_items, offer_until) SELECT NOW(), a.source, a.element, a.form, '/x', '[[\"Name\",\"Zed\"]]', ?, i.item_id, 'cancelled', 1, ?, ?, NOW() + INTERVAL 5 DAY FROM tl_collection_items i JOIN tl_enquiries a ON a.enquiry_id = (SELECT MIN(enquiry_id) FROM tl_enquiries WHERE email = 'anna@example.com') WHERE i.slug = 'salsa'",
            [$email, hash('sha256', $token), '[' . (int) $this->sq('SELECT item_id FROM tl_collection_items WHERE slug = ?', [$slug]) . ']']);

        return '/_course/' . $token . '/1';
    }

    private function holderMails(): int
    {
        return (int) $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'holder@example.org'");
    }

    private function enquiries(string $slug, string $email): string
    {
        return $this->sq("SELECT COUNT(*) FROM tl_enquiries e JOIN tl_collection_items i ON i.item_id = e.item_id WHERE i.slug = ? AND e.email = ?", [$slug, $email]);
    }

    public function testCancelCourseAndSendLinks(): void
    {
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $this->site()->setting($name, $value); // mail fails on purpose: the queue keeps the body
        }
        $this->site()->setting('site_email', 'holder@example.org'); // the form's recipient
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = $this->siteDate('+30 days');
        foreach ([['Salsa', 'salsa', '5'], ['Tango', 'tango', '5'], ['Samba', 'samba', '1']] as [$name, $slug, $capacity]) {
            $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => $name, 'slug' => $slug, 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => $capacity,
                'registration' => 'instant', 'seats_per_registration' => '2', 'schedule' => 'Thursdays'], 'visible' => true]);
        }
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();
        $this->register('salsa', 'anna@example.com', 'Anna', '2');
        $this->register('salsa', 'bob@example.com', 'Bob');
        $this->register('samba', 'zed@example.com', 'Zed'); // the only seat of Samba
        $this->assertSame('confirmed', $this->sq("SELECT seat_state FROM tl_enquiries WHERE email = 'anna@example.com'"));

        $screen = '&id=' . $this->collectionId() . '&item=' . $this->itemPid('salsa');
        $this->assertPage('/admin.php?module=collections&action=registrations' . $screen, 200, ['Cancel the course']);
        $form = $this->assertPage('/admin.php?module=collections&action=cancel_course' . $screen, 200, ['2 registrations are cancelled', 'Tango', 'Samba', 'name="offer[]"', 'data-confirm', 'Cancel the course and send 2 e-mails']);
        $this->assertStringNotContainsString('value="' . $this->itemPid('salsa') . '" data', $form->body, 'the course itself is not offered');

        $response = $this->adminPost('/admin.php?module=collections&action=cancel_course', ['id' => $this->collectionId(), 'item' => $this->itemPid('salsa'), 'offer' => [$this->itemPid('tango'), $this->itemPid('samba')],
            'message' => 'The teacher is ill.'], '/admin.php?module=collections&action=cancel_course' . $screen);
        $this->assertSame(302, $response->status);
        $this->assertSame('2', $this->sq("SELECT COUNT(*) FROM tl_enquiries WHERE seat_state = 'cancelled'"));
        $this->assertSame('Cancelled: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringContainsString('The teacher is ill.', $this->sq("SELECT body FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->site()->clearPageCache();
        $old = $this->visitor()->get('/courses-test/salsa');
        $this->assertSame(301, $old->status, 'the address of the cancelled course redirects');
        $this->assertStringContainsString('/courses-test/tango', $old->redirect);
    }

    public function testTakeOverLinkIsSingleUseAndGetNeverActs(): void
    {
        $link = $this->link('anna@example.com', 1);
        $other = $this->link('anna@example.com', 2);
        $visitor = $this->visitor();
        foreach ([1, 2] as $_) {
            $page = $visitor->get($link);
            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('Tango', $page->body);
            $this->assertStringContainsString('Take 2 seats', $page->body);
            $this->assertStringContainsString('noindex', $page->body);
        }
        $this->assertSame('0', $this->enquiries('tango', 'anna@example.com'), 'a GET registers nobody');

        $holders = $this->holderMails();
        $done = $visitor->post($link);
        $this->assertSame($holders + 1, $this->holderMails(), 'the form recipients hear of it as of any registration');
        $this->assertSame(200, $done->status);
        $this->assertStringContainsString('You are registered', $done->body);
        $this->assertSame('confirmed|2', $this->sq("SELECT CONCAT(e.seat_state, '|', e.seats) FROM tl_enquiries e JOIN tl_collection_items i ON i.item_id = e.item_id WHERE i.slug = 'tango' AND e.email = 'anna@example.com'"));
        $this->assertSame('You are registered: Tango', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringContainsString('Anna', $this->sq("SELECT data FROM tl_enquiries WHERE email = 'anna@example.com' ORDER BY enquiry_id DESC LIMIT 1"), 'the details are copied');

        foreach ([$link, $other] as $dead) {
            foreach ([$visitor->get($dead), $visitor->post($dead)] as $page) {
                $this->assertSame(200, $page->status, 'a link from a mail answers 200');
                $this->assertStringContainsString('This link no longer works', $page->body);
                $this->assertStringContainsString('href="/courses-test"', $page->body);
            }
        }
        $this->assertSame('1', $this->enquiries('tango', 'anna@example.com'));
        $this->assertSame('0', $this->enquiries('samba', 'anna@example.com'), 'one used link kills the others');
        $this->assertStringContainsString('This link no longer works', $visitor->get('/_course/' . str_repeat('a', 64) . '/1')->body);
    }

    public function testFullOfferedCourseWaitlists(): void
    {
        $link = $this->link('bob@example.com', 2); // Samba holds one seat, taken by Zed
        $page = $this->visitor()->post($link);
        $this->assertStringContainsString('You are on the waiting list', $page->body);
        $this->assertSame('waitlisted', $this->sq("SELECT e.seat_state FROM tl_enquiries e JOIN tl_collection_items i ON i.item_id = e.item_id WHERE i.slug = 'samba' AND e.email = 'bob@example.com'"));
        $this->assertSame('Waiting list: Samba', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'bob@example.com' ORDER BY mail_id DESC LIMIT 1"));
    }

    public function testClosedOrHiddenOfferKillsTheLink(): void
    {
        $tango = (int) $this->sq("SELECT item_id FROM tl_collection_items WHERE slug = 'tango'");
        $token = str_repeat('a', 64);
        $this->site()->exec("INSERT INTO tl_enquiries (created_at, source, page, data, email, item_id, seat_state, seats, offer_token_hash, offer_items, offer_until) SELECT NOW(), 'x', '/x', '[]', 'eve@example.com', item_id, 'cancelled', 1, ?, ?, NOW() + INTERVAL 5 DAY FROM tl_collection_items WHERE slug = 'salsa'", [hash('sha256', $token), '[' . $tango . ']']);
        $this->assertStringContainsString('Take a seat', $this->visitor()->get('/_course/' . $token . '/1')->body);
        $this->assertStringNotContainsString('This link no longer works', $this->visitor()->get('/_course/' . $token . '/1')->body);
        $this->site()->exec('UPDATE tl_collection_items SET visible = FALSE WHERE item_id = ?', [$tango]);
        $this->assertStringContainsString('This link no longer works', $this->visitor()->post('/_course/' . $token . '/1')->body, 'hidden');
        $this->assertSame('0', $this->enquiries('tango', 'eve@example.com'));
        $this->site()->exec('UPDATE tl_collection_items SET visible = TRUE WHERE item_id = ?', [$tango]);
        $this->site()->exec('UPDATE tl_enquiries SET offer_until = NOW() - INTERVAL 1 DAY WHERE email = ?', ['eve@example.com']);
        $this->assertStringContainsString('This link no longer works', $this->visitor()->post('/_course/' . $token . '/1')->body, 'expired');
    }

    public function testHolderIsToldOfARequestAndADuplicateKeepsItsLinkWhenFull(): void
    {
        $start = $this->siteDate('+30 days');
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Rumba', 'slug' => 'rumba', 'values' => ['start' => "$start 18:00", 'capacity' => '5', 'registration' => 'confirm'], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Mambo', 'slug' => 'mambo', 'values' => ['start' => "$start 18:00", 'capacity' => '1', 'waiting_list' => 'off'], 'visible' => true]);
        $this->site()->clearPageCache();

        $before = $this->holderMails();
        $page = $this->visitor()->post($this->offerRow('fay@example.com', 'rumba'));
        $this->assertStringContainsString('We have your request', $page->body);
        $this->assertSame($before + 1, $this->holderMails());
        $this->assertStringStartsWith('Request:', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'holder@example.org' ORDER BY mail_id DESC LIMIT 1"), 'a waiting request says so in the subject');

        $this->register('mambo', 'zed@example.com', 'Zed'); // the only seat of Mambo
        $mails = (int) $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'zed@example.com'");
        $path = $this->offerRow('zed@example.com', 'mambo'); // the same address presses the link of the full course
        $page = $this->visitor()->post($path);
        $this->assertStringContainsString('This course is full', $page->body);
        $this->assertStringNotContainsString('no longer works', $page->body);
        $this->assertSame('1', $this->enquiries('mambo', 'zed@example.com'), 'nothing is stored');
        $this->assertSame((string) $mails, $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'zed@example.com'"), 'no already-registered mail either');
        $this->assertNotNull($this->sq("SELECT offer_until FROM tl_enquiries WHERE offer_token_hash = ?", [hash('sha256', hash('sha256', 'zed@example.commambo'))]), 'the token is not used up');

        // a duplicate where the course still takes people: the same page a new address gets, the address is told by mail, nothing is stored
        $this->site()->exec("UPDATE tl_collection_items SET data = REPLACE(data, '\"capacity\":\"1\"', '\"capacity\":\"5\"') WHERE slug = 'mambo'");
        $page = $this->visitor()->post($path);
        $this->assertStringContainsString('You are registered', $page->body);
        $this->assertSame('1', $this->enquiries('mambo', 'zed@example.com'));
        $this->assertSame('Already registered: Mambo', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'zed@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringContainsString('This link no longer works', $this->visitor()->post($path)->body, 'now the token is used');
    }
}
