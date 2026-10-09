<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Forms and enquiries: spam protection, validation, webhook, admin list (was: section 12 "formuláře a poptávky" of tools/test.sh). */
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
            http_response_code(str_contains($_SERVER['REQUEST_URI'], 'chyba') ? 500 : 204); return true;
            PHP);
        $port = $this->site()->startPhp($dir, 'router.php');
        self::$hookLog = $dir . '/calls.log';
        file_put_contents(self::$hookLog, '');
        $this->site()->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $this->site()->setting('webhook_test_url', 'http://127.0.0.1:' . $port);

        $page = $this->site()->client()->get('/kontakt');
        self::$loadedAt = microtime(true);
        self::$html = $page->body;
        $this->assertStringContainsString('class="ka-formular"', self::$html, 'kontakt has the enquiry form');
        $this->assertStringContainsString('name="as_podpis"', self::$html, 'the form carries the antispam signature');
        self::$source = $this->formField(self::$html, 'source');
        self::$element = $this->formField(self::$html, 'element');
        self::$time = $this->formField(self::$html, 'as_cas');
        self::$signature = $this->formField(self::$html, 'as_podpis');
        $this->assertNotSame('', self::$element, 'the form has an element id');
    }

    #[Depends('testContactPageHasTheEnquiryFormAndTheWebhookReceiverIsReady')]
    public function testASubmitThatIsTooFastHasItsOwnResultAndMessage(): void
    {
        $now = time();
        $secret = $this->site()->settingValue('secret_key');
        $signature = hash_hmac('sha256', 'formular|' . self::$source . '|' . self::$element . '|' . $now, $secret);
        $response = $this->site()->client()->post('/formular', [
            'source' => self::$source, 'element' => self::$element, 'zpet' => '/kontakt', 'as_cas' => (string) $now, 'as_podpis' => $signature,
            'p0' => 'A', 'p1' => 'a@example.cz', 'p3' => 'x', 'p4' => '1',
        ]);
        $this->assertStringContainsString('result=rychle', $response->redirect, 'too fast a submit has its own result');
        $this->assertPage('/kontakt?form=' . self::$element . '&result=rychle', 200, 'Počkejte prosím chvilku a odešlete ho znovu', message: 'the message tells to wait');
    }

    #[Depends('testContactPageHasTheEnquiryFormAndTheWebhookReceiverIsReady')]
    public function testFormMarkupHelpsBrowsersAndDelaysTheSubmit(): void
    {
        $this->assertStringContainsString('type="text" autocomplete="name"', self::$html, 'name with autofill');
        $this->assertStringContainsString('type="tel" autocomplete="tel" maxlength="30" pattern="', self::$html, 'phone with a browser-side check');
        $this->assertMatchesRegularExpression('/name="as_cas" value="[0-9]*" data-cekat="1"/', self::$html, 'the form carries the minimum time for delayed submit');
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
        $location = $this->submit(['p0' => 'Jana', 'p1' => 'jana@example.cz', 'p2' => '', 'p3' => 'Chci kuchyň na míru.', 'p4' => '1'],
            ['Referer: ' . $this->site()->base . '/kontakt?utm_source=newsletter&utm_medium=email&utm_campaign=jaro']);
        $this->assertMatchesRegularExpression('~/kontakt\?form=' . self::$element . '&result=ok#.*' . self::$element . '$~', $location, 'form submit');
        $this->assertSame('1/jana@example.cz/0', $this->sqlRow("SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(status)) FROM ka_enquiries"), 'enquiry stored');

        $this->assertSame('nova_poptavka|signed|/crm|nova_poptavka/204/1/1', $this->hookCheck(1) . '|' . $this->sql(
            "SELECT CONCAT(event, '/', status, '/', delivered IS NOT NULL, '/', body IS NULL) FROM ka_webhook_deliveries"), 'webhook: new enquiry delivered after the response, signed');
        $this->assertMatchesRegularExpression('/email.":."jana@example\.cz/', (string) file_get_contents(self::$hookLog), 'webhook: the enquiry data are in the body');
        $this->assertSame('utm_source=newsletter&utm_medium=email&utm_campaign=jaro', $this->sql('SELECT campaign FROM ka_enquiries'), 'enquiry carries the campaign from the utm_* page with the form');
    }

    #[Depends('testASubmittedEnquiryIsStoredWithCampaignAndSentToTheWebhook')]
    public function testInvalidSubmitsAreRejectedAndNothingIsStored(): void
    {
        $this->assertStringContainsString('result=pole&field=1', $this->submit(['p0' => 'Jana', 'p1' => 'neni-email', 'p3' => 'x', 'p4' => '1']), 'invalid e-mail rejected with the field number');
        $page = $this->site()->client()->get('/kontakt?form=' . self::$element . '&result=pole&field=1')->body;
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="f-' . self::$element . '-1-chyba"', $page, 'the faulty field is marked');
        $this->assertStringContainsString('data-obnovit', $page, 'the filled values are restored');

        $this->assertStringContainsString('result=pole', $this->submit(['p0' => 'Jana', 'p1' => 'jana@example.cz', 'p3' => 'x']), 'missing consent rejected');

        $this->submit(['p0' => 'Robot', 'p1' => 'r@example.cz', 'p3' => 'spam', 'p4' => '1', 'web_adresa' => 'http://spam.example']);

        $forged = $this->site()->client()->post('/formular', [
            'source' => self::$source, 'element' => self::$element, 'zpet' => '/kontakt', 'as_cas' => self::$time, 'as_podpis' => 'podvrh',
            'p0' => 'A', 'p1' => 'a@example.cz', 'p3' => 'x', 'p4' => '1',
        ]);
        $this->assertStringContainsString('result=overeni', $forged->redirect, 'forged signature rejected');

        $this->assertStringNotContainsString('form=', $this->submit(['source' => 'stranka:999', 'p0' => 'A']), 'a form that does not exist is not accepted');
        $this->assertSame('1', $this->sql('SELECT COUNT(*) FROM ka_enquiries'), 'the robot and the errors added no enquiry');
    }

    #[Depends('testInvalidSubmitsAreRejectedAndNothingIsStored')]
    public function testEnquiryInTheAdminListDetailAndCsv(): void
    {
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => '0', 'jmeno' => 'Autor', 'username' => 'autor', 'password' => $this->site()->password, 'admin' => '0'], '/admin.php?module=users');
        $id = $this->sql('SELECT enquiry_id FROM ka_enquiries');

        $this->assertPage('/admin.php?module=enquiries', 200, 'jana@example.cz', message: 'enquiries in the admin');
        $detail = $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . $id, 200, 'Chci kuchyň na míru.', message: 'enquiry detail');
        $this->assertStringContainsString('>Tester</option>', $detail->body, 'the assignee list offers the administrator');
        $this->assertStringNotContainsString('>Autor</option>', $detail->body, 'only someone with access to Enquiries can handle one');
        $this->assertSame('1', $this->sql('SELECT status FROM ka_enquiries'), 'an opened enquiry is read');

        $this->assertStringContainsString('Chci kuchyň na míru.', $this->site()->admin()->get('/admin.php?module=enquiries&action=csv')->body, 'enquiries export to CSV');
        $this->assertPage('/kontakt?form=' . self::$element . '&result=ok', 200, 'class="ka-formular-hotovo"', message: 'thank-you in place of the form');
    }

    /** The old submit_form: the redirect address of a POST to /formular with the page's fields. @param array<string, string> $fields @param list<string> $headers */
    private function submit(array $fields, array $headers = []): string
    {
        $base = ['source' => self::$source, 'element' => self::$element, 'zpet' => '/kontakt', 'as_cas' => self::$time, 'as_podpis' => self::$signature];

        return $this->site()->client()->post('/formular', $fields + $base, $headers)->redirect;
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
