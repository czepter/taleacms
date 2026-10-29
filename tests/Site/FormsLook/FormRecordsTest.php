<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Forms as records (docs/specs/form-builder.md): a page's form becomes a record when the page is published, the page places it,
 * the form's own build changes it everywhere, the placement sets widths and button text, the thank-you e-mail speaks in the
 * owner's words without repeating what the visitor wrote freely, and Enquiries → Forms creates, opens and deletes forms.
 */
#[Group('site')]
final class FormRecordsTest extends SiteTestCase
{
    use SiteHelpers;

    private static string $page = '';
    private static string $element = '';
    private static string $form = '';
    private static string $definition = '';

    public function testPublishingThePageMakesItsFormARecordAndPlacesIt(): void
    {
        self::$page = $this->sql("SELECT public_id FROM tl_pages WHERE slug = 'contact'");
        $written = $this->ofType($this->call('get_build', ['id' => self::$page])['build']['children'] ?? [], 'form');
        $this->assertNotNull($written, 'the installed contact page has a form written inside it');
        self::$element = (string) $written['id'];
        $this->assertSame('0', $this->sql('SELECT COUNT(*) FROM tl_forms'), 'no record before publishing');

        $this->call('publish_build', ['id' => self::$page]);
        $this->assertSame('1|page:' . $this->sql("SELECT page_id FROM tl_pages WHERE slug = 'contact'") . '|' . self::$element, $this->sql('SELECT COUNT(*) FROM tl_forms') . '|' . $this->sql('SELECT origin FROM tl_forms'),
            'publishing made it a record that remembers where it came from');
        self::$form = $this->sql('SELECT public_id FROM tl_forms');
        $placement = $this->ofType($this->call('get_build', ['id' => self::$page])['build']['children'] ?? [], 'form_embed');
        $this->assertSame([self::$element, self::$form], [$placement['id'] ?? '', $placement['content']['form'] ?? ''], 'the page now places the form, under the same id');
        $this->call('publish_build', ['id' => self::$page]);
        $this->assertSame('1', $this->sql('SELECT COUNT(*) FROM tl_forms'), 'publishing again makes no second record');

        $forms = $this->call('list_forms');
        $this->assertSame([self::$form, true, ['Contact']], [$forms[0]['id'] ?? '', $forms[0]['published'] ?? null, $forms[0]['placed_on'] ?? null], 'list_forms names the form and where it is placed');
        $html = $this->site()->client()->get('/contact')->body;
        $this->assertStringContainsString('name="element" value="' . self::$element . '"', $html, 'visitors get the same form under the placement’s id');
        $this->assertStringNotContainsString('class="tl-form tl-form-layout"', $html, 'no grid without widths');
    }

    #[Depends('testPublishingThePageMakesItsFormARecordAndPlacesIt')]
    public function testAnAnswerToAPlacedFormNamesTheForm(): void
    {
        $this->assertStringContainsString('result=ok', $this->submit(['p0' => 'Jane', 'p1' => 'jane@example.org', 'p3' => 'I want a custom kitchen.', 'p4' => '1']), 'the placed form sends');
        $this->assertSame($this->sql('SELECT form_id FROM tl_forms'), $this->sql('SELECT form_id FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), 'the enquiry names the form record');
        $this->assertPage('/admin.php?module=forms', 200, ['Contact', 'On the site', 'New form'], message: 'Enquiries → Forms lists the record');
        $this->assertPage('/admin.php?module=forms&action=detail&id=' . self::$form, 200, ['Jane', 'I want a custom kitchen.'], message: 'its answers');
    }

    #[Depends('testPublishingThePageMakesItsFormARecordAndPlacesIt')]
    public function testThePlacementSetsWidthsAndButtonText(): void
    {
        $definition = $this->call('get_build', ['form' => self::$form])['build']['children'][0] ?? [];
        self::$definition = (string) ($definition['id'] ?? '');
        $keys = array_column($definition['content']['fields'] ?? [], 'key');
        $this->call('edit_build', ['id' => self::$page, 'publish' => true, 'operations' => [['op' => 'update', 'id' => self::$element,
            'content' => ['widths' => [$keys[0] => 3, $keys[1] => 3, $keys[2] => 5], 'button_text' => 'Ask us']]]]);
        $html = $this->site()->client()->get('/contact')->body;
        $this->assertStringContainsString('class="tl-form tl-form-layout"', $html, 'a placement with widths gets the grid');
        $this->assertSame(2, substr_count($html, 'class="tl-span-3 tl-field"'), 'name and e-mail take half a line each; a width that is not 2, 3 or 4 is dropped');
        $this->assertStringContainsString('type="submit">Ask us</button>', $html, 'the button text of this page');
    }

    #[Depends('testThePlacementSetsWidthsAndButtonText')]
    public function testTheFormsBuildChangesItWhereverItIsPlacedOncePublished(): void
    {
        $this->call('edit_build', ['form' => self::$form, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['fields' => array_merge(
            $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'], [['label' => 'Wedding date', 'type' => 'date']])]]]]);
        $this->assertStringNotContainsString('Wedding date', $this->site()->client()->get('/contact')->body, 'a draft of the form is not on the site');
        $this->assertPage('/_form/' . self::$form . '?build=draft', 200, 'Wedding date', message: 'the form builder’s canvas shows the draft');
        $this->call('publish_build', ['form' => self::$form]);
        $this->assertStringContainsString('Wedding date', $this->site()->client()->get('/contact')->body, 'published, it shows on the page that places it');
        $this->assertSame('1', $this->sql("SELECT COUNT(*) FROM tl_build_revisions WHERE part = 'form:" . $this->sql('SELECT form_id FROM tl_forms') . "'"), 'the previous version is kept');
    }

    #[Depends('testTheFormsBuildChangesItWhereverItIsPlacedOncePublished')]
    public function testTheThankYouEmailSpeaksInTheOwnersWordsWithoutTheVisitorsText(): void
    {
        $site = $this->site();
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none', 'smtp_user' => ''] as $key => $value) {
            $site->setting($key, $value); // nothing listens: the message stays in the queue with its text, which the test reads
        }
        $this->call('edit_build', ['form' => self::$form, 'publish' => true, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['confirmation' => true,
            'confirmation_subject' => 'Thanks, {Your name}', 'confirmation_sender' => 'Jana from the studio',
            'confirmation_text' => "Hello {Your name}, we read: {How can we help you?|your message}. Date: {Wedding date|to be agreed}. {site name}"]]]]);
        $site->exec('DELETE FROM tl_mail');
        $this->assertStringContainsString('result=ok', $this->submit(['p0' => 'Jane', 'p1' => 'jane@example.org', 'p3' => 'Click https://spam.example', 'p4' => '1']));
        $body = json_decode((string) $this->sql("SELECT body FROM tl_mail WHERE recipient = 'jane@example.org'"), true);
        $this->assertSame('Thanks, Jane', $this->sql("SELECT subject FROM tl_mail WHERE recipient = 'jane@example.org'"), 'the subject in the owner’s words');
        $this->assertStringContainsString('Hello Jane, we read: your message. Date: to be agreed.', (string) ($body['text'] ?? ''), 'free text is never repeated to the visitor; empty answers take their fallback');
        $this->assertStringNotContainsString('spam.example', (string) ($body['text'] ?? ''));
        $this->assertSame('Jana from the studio', $body['headers']['From-Name'] ?? '', 'the sender name of this form');
    }

    #[Depends('testPublishingThePageMakesItsFormARecordAndPlacesIt')]
    public function testFormsAreCreatedOpenedAndDeletedInEnquiries(): void
    {
        $created = $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Voucher order', 'starter' => 'download'], '/admin.php?module=forms&action=new');
        $id = $this->sql("SELECT public_id FROM tl_forms WHERE name = 'Voucher order'");
        $this->assertStringContainsString('/forms/builder/' . $id, (string) $created->redirect, 'a new form opens in the form builder');
        $this->assertSame('1|0', $this->sql("SELECT CONCAT(build_draft IS NOT NULL, '|', build IS NOT NULL) FROM tl_forms WHERE public_id = ?", [$id]), 'it starts as a draft');
        $this->assertPage('/admin.php?module=forms&action=builder&id=' . $id, 200, 'Form: Voucher order', message: 'the form builder');
        $this->assertPage('/admin.php?module=forms&status=draft', 200, ['Voucher order', 'Not published yet'], message: 'listed as not published');

        $refused = $this->site()->mcp('delete_form', ['id' => self::$form]);
        $this->assertStringContainsString('The form is placed on: Contact', (string) json_encode($refused, JSON_UNESCAPED_UNICODE), 'a placed form is not deleted');
        $this->adminPost('/admin.php?module=forms&action=delete', ['id' => $id], '/admin.php?module=forms');
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM tl_forms WHERE name = 'Voucher order'"), 'a form placed nowhere is deleted');
    }

    /** The first element of a type in a tree. @param list<array<string, mixed>> $children @return array<string, mixed>|null */
    private function ofType(array $children, string $type): ?array
    {
        foreach ($children as $node) {
            if (($node['type'] ?? '') === $type) {
                return $node;
            }
            if (($found = $this->ofType($node['children'] ?? [], $type)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Sends the contact form as a visitor: loads the page for a fresh signature, waits the minimum time, posts. @param array<string, string> $fields */
    private function submit(array $fields): string
    {
        $html = $this->site()->client()->get('/contact')->body;
        usleep(1_200_000); // the form asks for 1 second (TALEA_ANTISPAM_MIN) between loading and sending
        $base = ['back' => '/contact'] + array_map(fn (string $name): string => $this->formField($html, $name), ['source' => 'source', 'element' => 'element', 'as_time' => 'as_time', 'as_signature' => 'as_signature']);

        return $this->site()->client()->post('/form', $fields + $base)->redirect;
    }
}
