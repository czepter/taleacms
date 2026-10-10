<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Forms and enquiries: spam protection, validation, webhook, admin list (was: section 12 "forms and enquiries" of tools/test.sh). */
#[Group('site')]
final class EnquiryFormsTest extends SiteTestCase
{
    use SiteHelpers;

    private static string $html = '';
    private static string $source = '';
    private static string $element = '';
    private static string $time = '';
    private static string $signature = '';
    private static float $loadedAt = 0.0;
    private static string $hookLog = '';

    public function testContactPageHasTheEnquiryFormAndTheWebhookReceiverIsReady(): void
    {
        $dir = $this->site()->workDir('hook');
        file_put_contents($dir . '/router.php', <<<'PHP'
            <?php
            $log = __DIR__ . '/calls.log';
            $h = array_change_key_case(getallheaders());
            file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'event' => $h['x-kaleta-event'] ?? '', 'delivery' => $h['x-kaleta-delivery'] ?? '', 'ts' => $h['x-kaleta-timestamp'] ?? '',
                'sig' => $h['x-kaleta-signature'] ?? '', 'body' => file_get_contents('php://input')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            http_response_code(str_contains($_SERVER['REQUEST_URI'], 'error') ? 500 : 204); return true;
            PHP);
        $port = $this->site()->startPhp($dir, 'router.php');
        self::$hookLog = $dir . '/calls.log';
        file_put_contents(self::$hookLog, '');
        $this->site()->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $this->site()->setting('webhook_test_url', 'http://127.0.0.1:' . $port);

        $page = $this->site()->client()->get('/contact');
        self::$loadedAt = microtime(true);
        self::$html = $page->body;
        $this->assertStringContainsString('class="ka-form"', self::$html, 'contact has the enquiry form');
        $this->assertStringContainsString('name="as_signature"', self::$html, 'the form carries the antispam signature');
        self::$source = $this->formField(self::$html, 'source');
        self::$element = $this->formField(self::$html, 'element');
        self::$time = $this->formField(self::$html, 'as_time');
        self::$signature = $this->formField(self::$html, 'as_signature');
        $this->assertNotSame('', self::$element, 'the form has an element id');
    }

    #[Depends('testContactPageHasTheEnquiryFormAndTheWebhookReceiverIsReady')]
    public function testASubmitThatIsTooFastHasItsOwnResultAndMessage(): void
    {
        $now = time();
        $secret = $this->site()->settingValue('secret_key');
        $signature = hash_hmac('sha256', 'form|' . self::$source . '|' . self::$element . '|' . $now, $secret);
        $response = $this->site()->client()->post('/form', [
            'source' => self::$source, 'element' => self::$element, 'back' => '/contact', 'as_time' => (string) $now, 'as_signature' => $signature,
            'p0' => 'A', 'p1' => 'a@example.org', 'p3' => 'x', 'p4' => '1',
        ]);
        $this->assertStringContainsString('result=too_fast', $response->redirect, 'too fast a submit has its own result');
        $this->assertPage('/contact?form=' . self::$element . '&result=too_fast', 200, 'Please wait a moment and send it again', message: 'the message tells to wait');
    }

    #[Depends('testContactPageHasTheEnquiryFormAndTheWebhookReceiverIsReady')]
    public function testFormMarkupHelpsBrowsersAndDelaysTheSubmit(): void
    {
        $this->assertStringContainsString('type="text" autocomplete="name"', self::$html, 'name with autofill');
        $this->assertStringContainsString('type="tel" autocomplete="tel" maxlength="30" pattern="', self::$html, 'phone with a browser-side check');
        $this->assertMatchesRegularExpression('/name="as_time" value="[0-9]*" data-wait="1"/', self::$html, 'the form carries the minimum time for delayed submit');
    }

    #[Depends('testASubmitThatIsTooFastHasItsOwnResultAndMessage')]
    #[Depends('testFormMarkupHelpsBrowsersAndDelaysTheSubmit')]
    public function testASubmittedEnquiryIsStoredWithCampaignAndSentToTheWebhook(): void
    {
        // the form asks for 1 second (KALETA_ANTISPAM_MIN) between loading and sending
        $wait = 1.2 - (microtime(true) - self::$loadedAt);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $location = $this->submit(['p0' => 'Jane', 'p1' => 'jane@example.org', 'p2' => '', 'p3' => 'I want a custom kitchen.', 'p4' => '1'],
            ['Referer: ' . $this->site()->base . '/contact?utm_source=newsletter&utm_medium=email&utm_campaign=spring']);
        $this->assertMatchesRegularExpression('~/contact\?form=' . self::$element . '&result=ok#.*' . self::$element . '$~', $location, 'form submit');
        $this->assertSame('1/jane@example.org/0', $this->sqlRow("SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(status)) FROM ka_enquiries"), 'enquiry stored');

        $this->assertSame('enquiry_received|signed|/crm|enquiry_received/204/1/1', $this->hookCheck(1) . '|' . $this->sql(
            "SELECT CONCAT(event, '/', status, '/', delivered IS NOT NULL, '/', body IS NULL) FROM ka_webhook_deliveries"), 'webhook: new enquiry delivered after the response, signed');
        $this->assertMatchesRegularExpression('/email.":."jane@example\.org/', (string) file_get_contents(self::$hookLog), 'webhook: the enquiry data are in the body');
        $this->assertSame('utm_source=newsletter&utm_medium=email&utm_campaign=spring', $this->sql('SELECT campaign FROM ka_enquiries'), 'enquiry carries the campaign from the utm_* page with the form');
    }

    #[Depends('testASubmittedEnquiryIsStoredWithCampaignAndSentToTheWebhook')]
    public function testInvalidSubmitsAreRejectedAndNothingIsStored(): void
    {
        $this->assertStringContainsString('result=field&field=1', $this->submit(['p0' => 'Jane', 'p1' => 'not-an-email', 'p3' => 'x', 'p4' => '1']), 'invalid e-mail rejected with the field number');
        $page = $this->site()->client()->get('/contact?form=' . self::$element . '&result=field&field=1')->body;
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="f-' . self::$element . '-1-error"', $page, 'the faulty field is marked');
        $this->assertStringContainsString('data-restore', $page, 'the filled values are restored');

        $this->assertStringContainsString('result=field', $this->submit(['p0' => 'Jane', 'p1' => 'jane@example.org', 'p3' => 'x']), 'missing consent rejected');

        $this->submit(['p0' => 'Robot', 'p1' => 'r@example.org', 'p3' => 'spam', 'p4' => '1', 'website' => 'http://spam.example']);

        $forged = $this->site()->client()->post('/form', [
            'source' => self::$source, 'element' => self::$element, 'back' => '/contact', 'as_time' => self::$time, 'as_signature' => 'forged',
            'p0' => 'A', 'p1' => 'a@example.org', 'p3' => 'x', 'p4' => '1',
        ]);
        $this->assertStringContainsString('result=verification', $forged->redirect, 'forged signature rejected');

        $this->assertStringNotContainsString('form=', $this->submit(['source' => 'page:999', 'p0' => 'A']), 'a form that does not exist is not accepted');
        $this->assertSame('1', $this->sql('SELECT COUNT(*) FROM ka_enquiries'), 'the robot and the errors added no enquiry');
    }

    #[Depends('testInvalidSubmitsAreRejectedAndNothingIsStored')]
    public function testEnquiryInTheAdminListDetailAndCsv(): void
    {
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => '0', 'name' => 'Author', 'username' => 'author', 'password' => $this->site()->password, 'admin' => '0'], '/admin.php?module=users');
        $id = $this->sql('SELECT enquiry_id FROM ka_enquiries');

        $this->assertPage('/admin.php?module=enquiries', 200, 'jane@example.org', message: 'enquiries in the admin');
        $detail = $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . $id, 200, 'I want a custom kitchen.', message: 'enquiry detail');
        $this->assertStringContainsString('>Tester</option>', $detail->body, 'the assignee list offers the administrator');
        $this->assertStringNotContainsString('>Author</option>', $detail->body, 'only someone with access to Enquiries can handle one');
        $this->assertSame('1', $this->sql('SELECT status FROM ka_enquiries'), 'an opened enquiry is read');

        $this->assertStringContainsString('I want a custom kitchen.', $this->site()->admin()->get('/admin.php?module=enquiries&action=csv')->body, 'enquiries export to CSV');
        $this->assertPage('/contact?form=' . self::$element . '&result=ok', 200, 'class="ka-form-done"', message: 'thank-you in place of the form');
    }

    /** The old submit_form: the redirect address of a POST to /form with the page's fields. @param array<string, string> $fields @param list<string> $headers */
    private function submit(array $fields, array $headers = []): string
    {
        $base = ['source' => self::$source, 'element' => self::$element, 'back' => '/contact', 'as_time' => self::$time, 'as_signature' => self::$signature];

        return $this->site()->client()->post('/form', $fields + $base, $headers)->redirect;
    }

    /** The old hook_check: event|signed|uri of the n-th logged call, "signed" when the signature matches the site's secret. */
    private function hookCheck(int $n): string
    {
        for ($i = 0; $i < 50; $i++) {
            $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents(self::$hookLog)))));
            if (isset($lines[$n - 1])) {
                break;
            }
            usleep(100_000);
        }
        $call = json_decode($lines[$n - 1] ?? 'null', true);
        if (!$call) {
            return 'none';
        }
        $secret = $this->site()->settingValue('webhook_secret');
        $good = hash_equals('sha256=' . hash_hmac('sha256', $call['ts'] . '.' . $call['body'], $secret), $call['sig']);

        return $call['event'] . '|' . ($good ? 'signed' : 'BAD') . '|' . $call['uri'];
    }
}
