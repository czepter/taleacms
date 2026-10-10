<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 69 (2.12 testimonial requests with consent). */
#[Group('site')]
final class TestimonialRequestsTest extends SiteTestCase
{
    use Helpers;

    public function testRequestLinkConsentAndTheHiddenDraftReference(): void
    {
        $site = $this->site();
        $enquiry = $this->insertEnquiry('customer@example.com', '[["Message","Thank you"]]', 2);
        $noMail = $this->insertEnquiry('', '[]', 2);

        $this->assertStringContainsString('no e-mail address', $this->mcpText('request_testimonial', ['id' => $noMail]), 'testimonials: an enquiry without an e-mail cannot be asked');

        $answer = $site->mcpResult('request_testimonial', ['id' => $enquiry, 'send' => false]);
        $link = ltrim((string) parse_url((string) ($answer['link'] ?? ''), PHP_URL_PATH), '/');
        $this->assertNotSame('', $link, 'testimonials: request_testimonial returns the link');
        $token = substr($link, strlen('_testimonial/'));
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM ka_testimonial_requests WHERE enquiry_id = ? AND token_hash = SHA2(?, 256)', [$enquiry, $token]), 'testimonials: only a hash of the token is stored');

        $customer = $site->client();
        $page = $customer->get("/$link");
        $this->assertStringContainsString('name="consent_words"', $page->body, "testimonials: the customer's page asks for the words");
        $this->assertStringContainsString('name="consent_photo"', $page->body, 'testimonials: the customer page asks for a separate photo consent');
        $this->assertStringContainsString('noindex', $page->body, 'testimonials: the customer page is noindex');
        $signed = ['as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature')];
        sleep(4); // the anti-spam signature has a minimum age

        $refused = $customer->post("/$link", $signed + ['text' => 'Excellent cooperation, all on time.', 'name' => 'Eve Novak']);
        $this->assertMatchesRegularExpression('/only with your consent/', $refused->body, 'testimonials: nothing is saved without the consent');

        $answered = $customer->post("/$link", $signed + ['text' => 'Excellent cooperation, all <b>on time</b>.', 'name' => 'Eve Novak', 'role' => 'director, ACME', 'consent_words' => 1]);
        $item = $site->value('SELECT item_id FROM ka_testimonial_requests WHERE enquiry_id = ? AND used_at IS NOT NULL', [$enquiry]);
        $this->assertNotNull($item, 'testimonials: the answer was saved: ' . mb_substr($answered->text(), 0, 300));
        $this->assertSame('0|Eve Novak|Excellent cooperation, all on time.|Eve Novak, director, ACME|references', $site->value("SELECT CONCAT(p.visible, '|', p.name, '|', p.data->>'\$.quote', '|', p.data->>'\$.client', '|', k.preset) FROM ka_collection_items p JOIN ka_collections k ON k.collection_id = p.collection_id WHERE p.item_id = ?", [$item]),
            'testimonials: the answer is a hidden draft reference with the words, the name and the role');

        $kept = (string) $site->value("SELECT consent LIKE '%publish my words%' FROM ka_testimonial_requests WHERE item_id = ?", [$item]);
        $this->assertSame('1|404', $kept . '|' . $customer->get("/$link")->status, 'testimonials: the consent the customer saw is kept, the link works once');

        $this->assertPage("/admin.php?module=enquiries&action=detail&id=$enquiry", 200, "item=$item", message: 'testimonials: the enquiry detail shows the request and links the draft');
    }
}
