<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A drafts-only connection must not move people from the waiting list into seats; a connection that may publish does. */
#[Group('site')]
final class CourseDraftsRefillTest extends SiteTestCase
{
    use CollectionsHelpers;

    private function register(string $email, string $name): void
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $visitor->get('/courses-test/salsa');
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)
        $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/salsa',
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => $name, 'p1' => $email, 'p3' => '1']);
    }

    private function state(string $email): string
    {
        return (string) $this->sq('SELECT seat_state FROM tl_enquiries WHERE email = ?', [$email]);
    }

    public function testRaisedCapacityRefillsOnlyForAConnectionThatMayPublish(): void
    {
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = $this->siteDate('+30 days');
        $values = ['start' => "$start 18:00", 'end' => "$start 19:30", 'registration' => 'confirm', 'seats_per_registration' => '1'];
        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => 'Salsa', 'slug' => 'salsa', 'values' => $values + ['capacity' => '1'], 'visible' => true]);
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();
        $this->register('anna@example.com', 'Anna');
        $this->register('bob@example.com', 'Bob'); // the course is full: the waiting list
        $this->assertSame('waitlisted', $this->state('bob@example.com'));

        $itemId = (int) $this->sq("SELECT item_id FROM tl_collection_items WHERE slug = 'salsa'");
        $pid = $this->site()->publicId('collection_items', $itemId);
        $this->site()->exec('UPDATE tl_collection_items SET visible = 0 WHERE item_id = ?', [$itemId]); // a drafts-only connection changes hidden items only
        $this->site()->exec("UPDATE tl_api_tokens SET access = 'drafts'");
        try {
            $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'id' => $pid, 'values' => $values + ['capacity' => '5']]);
        } finally {
            $this->site()->exec("UPDATE tl_api_tokens SET access = 'full'");
        }
        $this->assertSame('waitlisted', $this->state('bob@example.com'), 'a drafts-only write does not move anyone into a seat');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'bob@example.com'"), 'and sends no mail');

        $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'id' => $pid, 'values' => $values + ['capacity' => '5']]);
        $this->assertNotSame('waitlisted', $this->state('bob@example.com'), 'a connection that may publish frees the seat');
    }
}
