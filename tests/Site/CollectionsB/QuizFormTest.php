<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/** A lead quiz (the form shows its result after the contact details): the server computes, stores and shows the result. */
#[Group('site')]
final class QuizFormTest extends SiteTestCase
{
    use Helpers;

    /** @var array<string, string> the hidden fields of the form page */
    private array $fields = [];
    private Http $visitor;
    private static int $pages = 0;
    private string $path = '';

    /** @param array<string, mixed> $extra content of the form that differs from the default quiz */
    private function quiz(array $extra = []): void
    {
        $site = $this->site();
        $this->path = '/quiz-5-' . ++self::$pages; // the site is shared by the tests of the class
        $page = $site->rowId($site->mcpResult('create_page', ['title' => 'Quiz 5 ' . self::$pages, 'visible' => true])['id']);
        $site->mcp('save_build', ['id' => $site->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => $extra + [
            'name' => 'Wedding quiz', 'no_captcha' => true, 'calculation' => 'after_contact', 'button_text' => 'Get my estimate', 'thank_you' => 'Your estimate is ready.',
            'fields' => [
                ['key' => 'kloc0', 'label' => 'Where is the wedding?', 'type' => 'radio', 'required' => true, 'choices' => "Local | 500\nAbroad | 900"],
                ['key' => 'khrs0', 'label' => 'Coverage hours', 'type' => 'timerange'],
                ['key' => 'kcnt0', 'label' => 'Which country?', 'type' => 'text', 'required' => true],
                ['key' => 'kext0', 'label' => 'Extras', 'type' => 'checkboxes', 'checkbox_options' => "Album | 150\nVideo | 400"],
                ['key' => 'knot0', 'label' => 'Note', 'type' => 'textarea'],
                ['key' => 'knam0', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'kmai0', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['key' => 'kest0', 'label' => 'Your estimate', 'type' => 'estimate', 'base_price' => '1000', 'range_below' => 10, 'range_above' => 15, 'round_to' => 50, 'unit_suffix' => '€'],
            ],
            'logic' => [
                'rules' => [
                    ['label' => 'Ask for the country', 'match' => 'all', 'when' => [['field' => 'kloc0', 'op' => 'is', 'value' => 'Abroad']], 'then' => [['do' => 'show_when', 'field' => 'kcnt0']]],
                    ['label' => 'Abroad surcharge', 'match' => 'all', 'when' => [['field' => 'kloc0', 'op' => 'is', 'value' => 'Abroad']], 'then' => [['do' => 'multiply', 'by' => 1.3], ['do' => 'result', 'variant' => 'vabrd']]],
                ],
                'results' => [['key' => 'vabrd', 'heading' => 'We plan this one personally', 'text' => 'Abroad weddings need a call first.', 'show_amount' => false, 'button_text' => 'Book a call', 'button_link' => '/contact']],
            ],
        ]]]]]]]);
        $site->clearPageCache();
        $this->visitor = $site->client();
        $form = $this->visitor->get($this->path);
        $this->fields = ['source' => $form->field('source'), 'element' => $form->field('element'), 'back' => $this->path, 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature')];
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        sleep(1); // the anti-spam signature has a minimum age
    }

    /** @param array<string, mixed> $answers */
    private function send(array $answers): string
    {
        // the redirect as a path with its query (the test client wants no host)
        return (string) preg_replace('#^https?://[^/]+#', '', $this->visitor->post('/form', $this->fields + $answers)->redirect);
    }

    private const LOCAL = ['p0' => 'Local', 'p1' => ['10:00', '12:00'], 'p3' => ['Album'], 'p6' => 'o@example.com'];

    public function testAfterContactShowsTheServerResult(): void
    {
        $this->quiz();
        $this->assertStringContainsString('/image/forms.js?v=', $this->visitor->get($this->path)->body, 'a page with a form loads the form script');
        $redirect = $this->send(self::LOCAL);
        $this->assertStringContainsString('result=ok&enquiry=', $redirect);
        $this->assertStringContainsString('&key=', $redirect);

        $page = $this->visitor->get($redirect);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('1,450 € – 1,900 €', str_replace("\u{a0}", ' ', $page->body), 'the range');
        foreach (['Base price', 'Local', 'Album', 'Your estimate is ready.'] as $needle) {
            $this->assertStringContainsString($needle, $page->body);
        }
        $this->assertStringNotContainsString('o@example.com', $page->body, 'no contact data on the result screen');
        $this->assertStringContainsString('data-sent="Wedding quiz"', $page->body, 'the conversion is reported');

        $stored = json_decode((string) $this->site()->value('SELECT data FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);
        $labels = array_column($stored, 0);
        $this->assertSame('Breakdown', $labels[array_search('Your estimate', $labels, true) + 1], 'the breakdown pair follows the estimate');
        $this->assertStringContainsString('Album: +150', str_replace("\u{a0}", ' ', implode("\n", array_column($stored, 1))));
    }

    public function testVariantWins(): void
    {
        $this->quiz();
        $page = $this->visitor->get($this->send(['p0' => 'Abroad', 'p2' => 'Spain', 'p6' => 'o@example.com']));
        $this->assertStringContainsString('We plan this one personally', $page->body);
        $this->assertStringContainsString('Book a call', $page->body);
        $this->assertStringNotContainsString('€', $page->body, 'show_amount is off: no amount');
        $this->assertStringNotContainsString('Abroad surcharge', $page->body, 'and no breakdown');
        $stored = json_decode((string) $this->site()->value('SELECT data FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);
        $this->assertStringContainsString('We plan this one personally', json_encode($stored));
    }

    public function testHiddenRequiredQuestionDoesNotBlock(): void
    {
        $this->quiz();
        $this->assertStringContainsString('result=ok&enquiry=', $this->send(self::LOCAL), 'the country is required but the rules hide it');
    }

    public function testMalformedTimeRangeIsAFieldError(): void
    {
        $this->quiz();
        $this->assertMatchesRegularExpression('/result=field&field=1/', $this->send(['p0' => 'Local', 'p1' => ['25:00', '02:00'], 'p6' => 'o@example.com']));
    }

    public function testResultLinkFailsClosed(): void
    {
        $this->quiz();
        $redirect = $this->send(self::LOCAL);
        $this->assertStringContainsString('1,450', str_replace("\u{a0}", ' ', $this->visitor->get($redirect)->body));
        $gone = 'This result is no longer available.';

        preg_match('/key=([^#&]+)/', $redirect, $m);
        $tampered = str_replace($m[1], substr($m[1], 0, -1) . (str_ends_with($m[1], '0') ? '1' : '0'), $redirect);
        $page = $this->visitor->get($tampered);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString($gone, $page->body);
        $this->assertStringContainsString('Get my estimate', $page->body, 'the form button leads back');
        $this->assertStringNotContainsString('1,450', str_replace("\u{a0}", ' ', $page->body));

        $this->site()->exec('UPDATE tl_enquiries SET deleted_at = NOW()');
        $page = $this->visitor->get($redirect);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString($gone, $page->body, 'a deleted enquiry');

        $this->site()->exec('UPDATE tl_enquiries SET deleted_at = NULL, anonymised_at = NOW()');
        $this->assertStringContainsString($gone, $this->visitor->get($redirect)->body, 'an anonymised enquiry');
    }

    public function testThankYouPageIgnoredAfterContact(): void
    {
        $this->quiz(['thank_you_page' => '/contact']);
        $redirect = $this->send(self::LOCAL);
        $this->assertStringStartsWith($this->path . '?form=', $redirect);
        $this->assertStringNotContainsString('sent=', $redirect);
    }

    public function testConfirmationMailHasResultBlockWithoutFreeText(): void
    {
        $this->quiz(['confirmation' => true]);
        $this->send(self::LOCAL + ['p4' => 'SECRET-TEXT', 'p5' => 'Jane']);
        $body = (string) $this->site()->value("SELECT body FROM tl_mail WHERE recipient = 'o@example.com' ORDER BY mail_id DESC LIMIT 1");
        $text = str_replace("\u{a0}", ' ', (string) (json_decode($body, true)['text'] ?? ''));
        $this->assertStringContainsString('1,450 € – 1,900 €', $text);
        $this->assertStringContainsString('Where is the wedding?: Local', $text, 'the option line');
        $this->assertStringContainsString('Extras: Album', $text);
        $this->assertStringContainsString('Album', $text);
        $this->assertStringNotContainsString('SECRET-TEXT', $text);
        $this->assertStringNotContainsString('Jane', $text);
    }

    public function testResultStaysAvailableWhenARuleHidesTheEstimate(): void
    {
        $this->quiz(['logic' => [
            'rules' => [['label' => 'Hide it', 'match' => 'all', 'when' => [['field' => 'kloc0', 'op' => 'is', 'value' => 'Local']], 'then' => [['do' => 'skip', 'field' => 'kest0']]]],
            'results' => [],
        ]]);
        $page = $this->visitor->get($this->send(self::LOCAL + ['p2' => 'Spain'])); // no rule shows the country here, so it is answered
        $this->assertStringNotContainsString('This result is no longer available.', $page->body);
        $this->assertStringContainsString('Your estimate is ready.', $page->body);
    }

    public function testChangedVariantOrForeignEnquiryFailsClosed(): void
    {
        $this->quiz();
        $redirect = $this->send(self::LOCAL);
        $gone = 'This result is no longer available.';
        $this->assertStringContainsString($gone, $this->visitor->get(str_replace('&variant=&', '&variant=vabrd&', $redirect))->body, 'a changed variant');

        preg_match('/enquiry=([0-9a-f-]{36})/', $redirect, $m);
        $other = '11111111-2222-4333-8444-555555555555';
        $this->site()->exec("INSERT INTO tl_enquiries (created_at, form, source, element, page, email, data, status, public_id) VALUES (NOW(), 'Other', 'page:1', 'other', '/x', '', '[]', 0, ?)", [$other]);
        $this->assertStringContainsString($gone, $this->visitor->get(str_replace($m[1], $other, $redirect))->body, 'another enquiry with the key of this one');
    }
}
