<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Registering for a course from its page: seats, the same address twice, the waiting list and a list that is off. The methods build on each other. */
#[Group('site')]
final class CourseRegistrationTest extends SiteTestCase
{
    use CollectionsHelpers;

    /** Posts the registration form of a course page (drawn now, or earlier: a stale page); returns the redirect. @param array<string, string> $extra */
    private function register(string $slug, string $email, array $extra = [], ?\Talea\Tests\Site\Support\Response $drawn = null): string
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $drawn ?? $visitor->get('/courses-test/' . $slug);
        sleep($drawn === null ? 4 : 0); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)

        return $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/' . $slug,
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => 'Anna', 'p1' => $email, 'p3' => '1'] + $extra)->redirect;
    }

    private function state(string $email): string
    {
        return $this->sq('SELECT seat_state FROM tl_enquiries WHERE email = ? ORDER BY enquiry_id DESC LIMIT 1', [$email]);
    }

    public function testRegisterTwoSeatsFillsCourse(): void
    {
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $this->site()->setting($name, $value); // mail fails on purpose: the queue keeps the body
        }
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = $this->siteDate('+30 days');
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Salsa', 'slug' => 'salsa', 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => '2',
            'registration' => 'instant', 'seats_per_registration' => '2'], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Tango', 'slug' => 'tango', 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => '1',
            'waiting_list' => 'off'], 'visible' => true]);
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();

        $page = $this->visitor()->get('/courses-test/salsa');
        $this->assertStringContainsString('How many people are coming?', $page->body);
        $this->assertStringContainsString('Up to 2 per registration.', $page->body);
        $this->assertStringContainsString('<option value="2">2 people</option>', $page->body);
        $this->assertStringNotContainsString('name="seats"', $this->visitor()->get('/courses-test/tango')->body, 'one seat per registration: no select');

        $this->assertStringContainsString('result=registered', $this->register('salsa', 'anna@example.com', ['seats' => '2']));
        $this->assertSame('confirmed|2|', $this->sq("SELECT CONCAT(seat_state, '|', seats, '|', language) FROM tl_enquiries WHERE email = 'anna@example.com'"));
        $this->assertStringNotContainsString('How many', $this->sq("SELECT data FROM tl_enquiries WHERE email = 'anna@example.com'"), 'the participants count is not a form answer');
        $this->assertSame('You are registered: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'anna@example.com' AND subject LIKE 'Confirmation%'"), 'the form auto-reply is replaced');
    }

    public function testSameEmailIsRefused(): void
    {
        $before = (int) $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'Anna@Example.com'");
        $redirect = $this->register('salsa', 'Anna@Example.com');
        $this->assertStringContainsString('result=waitlisted', $redirect, 'the page answers as it would a new registration (the course is full)');
        $this->assertSame('Already registered: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'Anna@Example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertSame((string) ($before + 1), $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'Anna@Example.com'"));
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_enquiries WHERE LOWER(email) = 'anna@example.com'"), 'nothing was stored');
        $this->assertStringContainsString('You are on the waiting list.', $this->visitor()->get(preg_replace('/#.*/', '', $redirect))->body);
    }

    public function testFullCourseOffersWaitingList(): void
    {
        $this->site()->clearPageCache();
        $this->assertStringContainsString('Join the waiting list', $this->visitor()->get('/courses-test/salsa')->body);
        $redirect = $this->register('salsa', 'bob@example.com', ['seats' => '1']);
        $this->assertStringContainsString('result=waitlisted', $redirect, 'capacity holds for new registrations: the next one waits');
        $this->assertSame('waitlisted', $this->state('bob@example.com'));
        $this->assertSame('Waiting list: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'bob@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringContainsString('You are on the waiting list.', $this->visitor()->get(preg_replace('/#.*/', '', $redirect))->body);
    }

    public function testListOffShowsFull(): void
    {
        $stale = $this->visitor()->get('/courses-test/tango'); // drawn while there is a seat; the second visitor sends it after the seat went
        sleep(4);
        $this->assertStringContainsString('result=registered', $this->register('tango', 'cara@example.com', ['seats' => '5'], $stale));
        $this->assertSame('1', $this->sq("SELECT seats FROM tl_enquiries WHERE email = 'cara@example.com'"), 'the count is clamped to the capacity');
        $this->assertStringContainsString('result=full', $this->register('tango', 'dan@example.com', [], $stale));
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_enquiries WHERE email = 'dan@example.com'"));
        $page = $this->visitor()->get('/courses-test/tango');
        $this->assertStringContainsString('This event is fully booked.', $page->body);
        $this->assertStringNotContainsString('class="tl-form"', $page->body);
    }

    private function collectionId(): string
    {
        return $this->site()->publicId('collections', (int) $this->sq("SELECT collection_id FROM tl_collections WHERE slug = 'courses-test'"));
    }

    private function itemPid(string $slug): string
    {
        return $this->site()->publicId('collection_items', (int) $this->sq('SELECT item_id FROM tl_collection_items WHERE slug = ?', [$slug]));
    }

    private function screen(string $slug, string $extra = ''): string
    {
        return '/admin.php?module=collections&action=registrations&id=' . $this->collectionId() . '&item=' . $this->itemPid($slug) . $extra;
    }

    /** The registration buttons of the screen: POST do=… for the enquiry of this address. */
    private function act(string $slug, string $email, string $do, string $message = ''): void
    {
        $pid = $this->site()->publicId('enquiries', (int) $this->sq('SELECT enquiry_id FROM tl_enquiries WHERE email = ? ORDER BY enquiry_id DESC LIMIT 1', [$email]));
        $this->adminPost('/admin.php?module=collections&action=registration', ['id' => $this->collectionId(), 'item' => $this->itemPid($slug), 'registration' => $pid, 'do' => $do, 'message' => $message], $this->screen($slug));
    }

    public function testItemsListShowsSeats(): void
    {
        $page = $this->assertPage('/admin.php?module=collections&action=items&id=' . $this->collectionId(), 200, ['Seats', '2 of 2']);
        $this->assertStringContainsString('/admin/collections/registrations', $page->body);
        $this->assertStringContainsString('1 waiting', $page->body, 'Bob waits on the list');
    }

    public function testMoveUpNeedsSeat(): void
    {
        $screen = $this->assertPage($this->screen('salsa'), 200, ['Registrations', 'Waiting list', 'bob@example.com', 'anna@example.com', 'No free seat.']);
        $this->assertStringContainsString('disabled', $screen->body);
        $this->act('salsa', 'bob@example.com', 'promote');
        $this->assertSame('waitlisted', $this->state('bob@example.com'), 'the course is full: nobody moves up by hand');
    }

    public function testRaisingCapacityMovesWaitlistUp(): void
    {
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'id' => $this->itemPid('salsa'), 'values' => ['capacity' => '3']]);
        $this->assertSame('confirmed', $this->state('bob@example.com'), 'the seat that capacity added goes to the first on the list');
        $this->assertSame('A seat for you: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'bob@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertPage('/admin.php?module=collections&action=items&id=' . $this->collectionId(), 200, '3 of 3');
    }

    public function testAdminConfirmsAndCancels(): void
    {
        $start = $this->siteDate('+30 days');
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Rumba', 'slug' => 'rumba', 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => '5',
            'registration' => 'confirm'], 'visible' => true]);
        $this->site()->clearPageCache();
        $this->register('rumba', 'dora@example.com');
        $this->assertSame('waiting', $this->state('dora@example.com'));
        $this->assertPage($this->screen('rumba'), 200, ['Waiting for confirmation', 'dora@example.com', 'Confirm']);
        $this->assertPage('/admin.php?module=enquiries', 200, 'Waiting');

        $this->act('rumba', 'dora@example.com', 'confirm');
        $this->assertSame('confirmed', $this->state('dora@example.com'));
        $this->assertSame('Confirmed: Rumba', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'dora@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertPage($this->screen('rumba'), 200, ['Confirmed', '1 of 5']);
        $this->assertPage('/admin.php?module=enquiries', 200, 'Confirmed');

        $this->act('rumba', 'dora@example.com', 'cancel', 'See you next term');
        $this->assertSame('cancelled', $this->state('dora@example.com'));
        $this->assertStringContainsString('See you next term', $this->sq("SELECT body FROM tl_mail WHERE recipient = 'dora@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->act('rumba', 'dora@example.com', 'confirm'); // does not apply any more: an error, nothing changes
        $this->assertSame('cancelled', $this->state('dora@example.com'));
    }
}
