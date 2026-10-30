<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The registration tools over MCP and the registration block of a course item. The methods build on each other. */
#[Group('site')]
final class CourseMcpTest extends SiteTestCase
{
    use CollectionsHelpers;

    private function register(string $slug, string $email, string $name): void
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $visitor->get('/courses-test/' . $slug);
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)
        $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/' . $slug,
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => $name, 'p1' => $email, 'p3' => '1']);
    }

    private function itemPid(string $slug): string
    {
        return $this->site()->publicId('collection_items', (int) $this->sq('SELECT item_id FROM tl_collection_items WHERE slug = ?', [$slug]));
    }

    private function pidOf(string $email): string
    {
        return $this->site()->publicId('enquiries', (int) $this->sq('SELECT enquiry_id FROM tl_enquiries WHERE email = ?', [$email]));
    }

    public function testListRegistrationsReturnsPublicIds(): void
    {
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $this->site()->setting($name, $value); // mail fails on purpose: the queue keeps the body
        }
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = $this->siteDate('+30 days');
        foreach ([['Salsa', 'salsa', '1'], ['Tango', 'tango', '5']] as [$name, $slug, $capacity]) {
            $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => $name, 'slug' => $slug, 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => $capacity,
                'registration' => 'confirm', 'seats_per_registration' => '1'], 'visible' => true]);
        }
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();
        $this->register('salsa', 'anna@example.com', 'Anna');
        $this->register('salsa', 'bob@example.com', 'Bob'); // the course is full: the waiting list

        $result = $this->site()->mcpResult('list_registrations', ['item' => $this->itemPid('salsa')]);
        $rows = $result['registrations'];
        $this->assertSame(['waiting', 'waitlisted'], array_column($rows, 'state'));
        $this->assertSame($this->pidOf('anna@example.com'), $rows[0]['id']);
        $this->assertSame(['Anna', 'anna@example.com', 1], [$rows[0]['name'], $rows[0]['email'], $rows[0]['seats']]);
        $this->assertSame(1, $rows[1]['position']);
        $this->assertArrayNotHasKey('position', $rows[0]);
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_change_log WHERE action = 'list_registrations'"), 'a personal-data read is logged');

        $this->mcpText('create_collection', ['name' => 'Questions', 'preset' => 'faq']);
        $this->mcpText('save_collection_item', ['collection' => 'questions', 'name' => 'Why', 'slug' => 'why', 'values' => [], 'visible' => true]);
        foreach (['list_registrations', 'cancel_course'] as $tool) {
            $this->assertStringContainsString('not a course', $this->mcpText($tool, ['item' => $this->itemPid('why')]));
        }
    }

    public function testItemResultHasTheCounts(): void
    {
        $items = $this->site()->mcpResult('list_collection_items', ['collection' => 'courses-test'])['items'];
        $salsa = $items[array_search('salsa', array_column($items, 'slug'), true)];
        $this->assertSame(['confirm', true, 1, 1, 1, 1], array_map(fn (string $k): mixed => $salsa['registration'][$k],
            ['mode', 'waiting_list', 'max_seats', 'taken', 'waiting', 'waitlisted']));
    }

    public function testUpdateRegistrationConfirms(): void
    {
        $text = $this->mcpText('update_registration', ['registration' => $this->pidOf('anna@example.com'), 'action' => 'confirm', 'message' => 'See you']);
        $this->assertStringNotContainsString('rror', $text);
        $this->assertSame('confirmed', $this->sq("SELECT seat_state FROM tl_enquiries WHERE email = 'anna@example.com'"));
        $this->assertGreaterThan(1, (int) $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'anna@example.com'"), 'the person is told, as with the button');
        $this->assertNotSame('', $this->mcpText('update_registration', ['registration' => $this->pidOf('anna@example.com'), 'action' => 'dance']));
        $this->assertSame('confirmed', $this->sq("SELECT seat_state FROM tl_enquiries WHERE email = 'anna@example.com'"), 'an unknown action changes nothing');
    }

    public function testCancelCourseOverMcp(): void
    {
        $this->mcpText('cancel_course', ['item' => $this->itemPid('salsa'), 'offer' => [$this->itemPid('tango')], 'message' => 'The teacher is ill.']);
        $this->assertSame('2', $this->sq("SELECT COUNT(*) FROM tl_enquiries WHERE seat_state = 'cancelled'"));
        $this->assertSame('Cancelled: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
    }

    public function testDraftsOnlyConnectionCannotUpdate(): void
    {
        foreach (['update_registration', 'cancel_course'] as $tool) {
            $this->assertFalse(\Talea\Mcp\Catalog::allows('drafts', $tool));
            $this->assertContains($tool, \Talea\Mcp\Catalog::SENDS);
        }
        $this->assertTrue(\Talea\Mcp\Catalog::allows('read', 'list_registrations'));
    }
}
