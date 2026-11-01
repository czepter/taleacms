<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use Talea\Tests\Site\Support\SiteTestCase;
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

        $this->assertStringContainsString('no e-mail address', $this->mcpText('create_form_link', ['id' => $this->site()->publicId('enquiries', $noMail), 'send' => true]), 'testimonials: an enquiry without an e-mail cannot be mailed');
        $nameOnly = $site->mcpResult('create_form_link', ['label' => 'Anna Weber, wedding 2019']);
        $this->assertStringContainsString('/_f/', (string) ($nameOnly['link'] ?? ''), 'testimonials: a link needs neither an enquiry nor an e-mail address');
        $this->assertSame('Anna Weber, wedding 2019', (string) $site->value("SELECT label FROM tl_form_links WHERE label <> '' ORDER BY id DESC LIMIT 1"), 'testimonials: the label says who the link is for');
        $this->assertStringContainsString('Only a link for an enquiry', $this->mcpText('create_form_link', ['label' => 'Stranger', 'email' => 'stranger@example.com', 'send' => true]), 'testimonials: an address the site did not get from the person is never mailed');
        $this->assertStringContainsString('label', $this->mcpText('create_form_link', []), 'testimonials: without enquiry and label there is nobody to name the link after');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_forms WHERE origin = 'system:testimonial'"), 'testimonials: the system form is one record in Enquiries → Forms');

        $answer = $site->mcpResult('create_form_link', ['id' => $site->publicId('enquiries', $enquiry), 'send' => false]);
        $link = ltrim((string) parse_url((string) ($answer['link'] ?? ''), PHP_URL_PATH), '/');
        $this->assertNotSame('', $link, 'testimonials: create_form_link returns the link');
        $token = substr($link, strlen('_f/'));
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_form_links WHERE enquiry_id = ? AND token_hash = SHA2(?, 256)', [$enquiry, $token]), 'testimonials: only a hash of the token is stored');

        $customer = $site->client();
        $page = $customer->get("/$link");
        $this->assertStringContainsString('Your words', $page->body, "testimonials: the customer's page asks for the words");
        $this->assertStringContainsString('I agree that', $page->body, 'testimonials: the customer page asks for the consent');
        $this->assertStringContainsString('noindex', $page->body, 'testimonials: the customer page is noindex');
        $signed = ['source' => $page->field('source'), 'element' => $page->field('element'), 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'back' => "/$link", 'link' => $token];
        sleep(1); // the anti-spam signature has a minimum age

        $refused = $customer->post('/form', $signed + ['p0' => 'Excellent cooperation, all on time.', 'p1' => 'Eve Novak']);
        $this->assertStringContainsString('result=field', $refused->redirect, 'testimonials: nothing is saved without the consent');

        $answered = $customer->post('/form', $signed + ['p0' => 'Excellent cooperation, all <b>on time</b>.', 'p1' => 'Eve Novak', 'p2' => 'director, ACME', 'p4' => '1']);
        $item = $site->value('SELECT item_id FROM tl_form_links WHERE enquiry_id = ? AND used_at IS NOT NULL', [$enquiry]);
        $this->assertNotNull($item, 'testimonials: the answer was saved: ' . $answered->redirect);
        $this->assertSame('0|Eve Novak|Excellent cooperation, all on time.|Eve Novak, director, ACME|references', $site->value("SELECT CONCAT(p.visible, '|', p.name, '|', p.data->>'\$.quote', '|', p.data->>'\$.client', '|', k.preset) FROM tl_collection_items p JOIN tl_collections k ON k.collection_id = p.collection_id WHERE p.item_id = ?", [$item]),
            'testimonials: the answer is a hidden draft reference with the words, the name and the role');

        $kept = (string) $site->value("SELECT consent LIKE '%publish my words%' FROM tl_form_links WHERE item_id = ?", [$item]);
        $this->assertSame('1|404', $kept . '|' . $customer->get("/$link")->status, 'testimonials: the consent the customer saw is kept, the link works once');

        $this->assertPage("/admin.php?module=enquiries&action=detail&id=" . $site->publicId('enquiries', (int) $enquiry), 200, 'item=' . $site->publicId('collection_items', (int) $item), message: 'testimonials: the enquiry detail shows the request and links the draft');
    }
}
