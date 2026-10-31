<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Mails about a course never build a link on the Host header: without the setting site_url there is no link. */
#[Group('site')]
final class CourseMailLinksTest extends SiteTestCase
{
    use CollectionsHelpers;

    public function testWithoutSiteUrlNoLinkIsBuiltFromTheHostHeader(): void
    {
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $this->site()->setting($name, $value); // mail fails on purpose: the queue keeps the body
        }
        $this->site()->setting('site_email', 'holder@example.org');
        $this->mcpText('create_collection', ['name' => 'Courses test', 'preset' => 'courses']);
        $start = $this->siteDate('+30 days');
        foreach ([['Salsa', 'salsa'], ['Tango', 'tango']] as [$name, $slug]) {
            $this->mcpText('save_collection_item', ['collection' => 'courses-test', 'name' => $name, 'slug' => $slug, 'values' => ['start' => "$start 18:00", 'end' => "$start 19:30", 'capacity' => '5',
                'registration' => 'instant', 'seats_per_registration' => '1'], 'visible' => true]);
        }
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'courses-test'")), 'visible' => true]);
        $this->site()->clearPageCache();
        $visitor = $this->visitor();
        $page = $visitor->get('/courses-test/salsa');
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam)
        $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/courses-test/salsa',
            'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => 'Anna', 'p1' => 'anna@example.com', 'p3' => '1']);

        $this->site()->setting('site_url', '');
        $this->mcpText('cancel_course', ['item' => $this->site()->publicId('collection_items', (int) $this->sq("SELECT item_id FROM tl_collection_items WHERE slug = 'salsa'")),
            'offer' => [$this->site()->publicId('collection_items', (int) $this->sq("SELECT item_id FROM tl_collection_items WHERE slug = 'tango'"))]]);

        $body = (string) $this->sq("SELECT body FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1");
        $this->assertSame('Cancelled: Salsa', $this->sq("SELECT subject FROM tl_mail WHERE recipient = 'anna@example.com' ORDER BY mail_id DESC LIMIT 1"));
        $this->assertStringNotContainsString('_course/', $body, 'no take-over link without a trusted site address');
    }
}
