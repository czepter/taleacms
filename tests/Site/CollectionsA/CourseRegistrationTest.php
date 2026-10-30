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
        $redirect = $this->register('salsa', 'Anna@Example.com');
        $this->assertStringContainsString('result=already', $redirect);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_enquiries WHERE LOWER(email) = 'anna@example.com'"), 'nothing was stored');
        $this->assertStringContainsString('You are already registered for this course.', $this->visitor()->get(preg_replace('/#.*/', '', $redirect))->body);
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
}
