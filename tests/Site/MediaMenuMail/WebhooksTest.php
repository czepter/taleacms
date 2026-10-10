<?php

declare(strict_types=1);

namespace Talea\Tests\Site\MediaMenuMail;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Webhooks: signature, delivery log and retries (was: section 34 of tools/test.sh; the receiver and the enquiry come from section 12). */
#[Group('site')]
final class WebhooksTest extends SiteTestCase
{
    private function callsLog(): string
    {
        return $this->site()->workDir('hook') . '/calls.log';
    }

    /** The old hook_check: is the n-th logged call signed with the site's secret? "event|signed|uri". */
    private function hookCheck(int $n): string
    {
        $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($this->callsLog())))));
        $call = json_decode($lines[$n - 1] ?? 'null', true);
        if (!$call) {
            return 'none';
        }
        $expected = 'sha256=' . hash_hmac('sha256', $call['ts'] . '.' . $call['body'], $this->site()->settingValue('webhook_secret'));

        return $call['event'] . '|' . (hash_equals($expected, $call['sig']) ? 'signed' : 'BAD') . '|' . $call['uri'];
    }

    /** @param array<string, mixed> $fields */
    private function webhookAction(string $action, array $fields = []): void
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=settings&tab=webhooks')->csrf();
        $this->site()->admin()->post('/admin.php?module=settings&action=' . $action, ['_csrf' => $csrf, 'tab' => 'webhooks'] + $fields);
    }

    /** The receiver (logs every call with its signature headers; an address containing "error" answers 500) and one real enquiry. */
    public function testTheDeliveryLogListsTheEnquiryCall(): void
    {
        $dir = $this->site()->workDir('hook');
        file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$log = __DIR__ . '/calls.log';
$h = array_change_key_case(getallheaders());
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'event' => $h['x-talea-event'] ?? '', 'delivery' => $h['x-talea-delivery'] ?? '', 'ts' => $h['x-talea-timestamp'] ?? '',
    'sig' => $h['x-talea-signature'] ?? '', 'body' => file_get_contents('php://input')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
http_response_code(str_contains($_SERVER['REQUEST_URI'], 'error') ? 500 : 204); return true;
PHP);
        $port = $this->site()->startPhp($dir, 'router.php');
        file_put_contents($this->callsLog(), '');
        $this->site()->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $this->site()->setting('webhook_test_url', 'http://127.0.0.1:' . $port);

        // a real enquiry: the form's time stamp is signed, so it is dated back instead of waiting the minimum seconds
        $visitor = $this->site()->client();
        $form = $visitor->get('/contact');
        [$source, $element] = [$form->field('source'), $form->field('element')];
        $time = time() - 10;
        $signature = hash_hmac('sha256', "form|$source|$element|$time", $this->site()->settingValue('secret_key'));
        $visitor->post('/form', ['source' => $source, 'element' => $element, 'back' => '/contact', 'as_time' => $time, 'as_signature' => $signature,
            'p0' => 'Jana', 'p1' => 'jana@example.cz', 'p2' => '', 'p3' => 'I want a custom kitchen.', 'p4' => 1]);
        for ($i = 0; $i < 100 && (int) $this->site()->value('SELECT COUNT(*) FROM tl_webhook_deliveries WHERE delivered IS NOT NULL') === 0; $i++) {
            usleep(100_000);
        }

        $secret = $this->site()->settingValue('webhook_secret');
        $page = $this->assertPage('/admin.php?module=settings&tab=webhooks', 200, $secret, message: 'Settings → Webhooks shows the secret and the log');
        $this->assertStringContainsString('<code>enquiry_received</code>', $page->body, 'the log lists the enquiry call');
    }

    #[Depends('testTheDeliveryLogListsTheEnquiryCall')]
    public function testTestCallIsSignedAndLogged(): void
    {
        file_put_contents($this->callsLog(), '');
        $this->webhookAction('test_webhook');

        $this->assertSame('test|signed|/crm', $this->hookCheck(1), 'test call signed and logged');
    }

    #[Depends('testTestCallIsSignedAndLogged')]
    public function testFailedCallIsRetriedThenGivenUpAndCanBeSentAgain(): void
    {
        $site = $this->site();
        $site->setting('webhook_enquiries', 'https://hooks.example.com/error');
        file_put_contents($this->callsLog(), '');
        $this->webhookAction('test_webhook');
        $failed = (int) $site->value('SELECT MAX(id) FROM tl_webhook_deliveries');
        $this->assertSame('1|500|HTTP 500|1|1', $site->value("SELECT CONCAT(attempts, '|', status, '|', error, '|', next_attempt > NOW(), '|', body IS NOT NULL) FROM tl_webhook_deliveries WHERE id = ?", [$failed]), 'a failed call waits for the next attempt with the reason');

        for ($i = 2; $i <= 6; $i++) {
            $site->exec('UPDATE tl_webhook_deliveries SET next_attempt = NOW() WHERE id = ?', [$failed]);
            $site->runTasks();
        }
        $calls = count(array_filter(explode("\n", (string) file_get_contents($this->callsLog()))));
        $this->assertSame('6|1|1|1|6', $site->value("SELECT CONCAT(attempts, '|', next_attempt IS NULL, '|', delivered IS NULL, '|', body IS NOT NULL) FROM tl_webhook_deliveries WHERE id = ?", [$failed]) . '|' . $calls, 'after six attempts the call is given up but kept for sending again');
        $this->assertPage('/admin.php?module=settings&tab=webhooks', 200, "name=\"id\" value=\"$failed\"", message: 'the given-up call has Send again');

        $site->exec("UPDATE tl_webhook_deliveries SET url = 'https://hooks.example.com/crm' WHERE id = ?", [$failed]);
        $this->webhookAction('retry_webhook', ['id' => $failed]);
        $this->assertSame('7|204|1|1', $site->value("SELECT CONCAT(attempts, '|', status, '|', delivered IS NOT NULL, '|', body IS NULL) FROM tl_webhook_deliveries WHERE id = ?", [$failed]), 'Send again delivers it');
    }

    #[Depends('testFailedCallIsRetriedThenGivenUpAndCanBeSentAgain')]
    public function testNewSecretAndTheSecretStaysOutOfMcp(): void
    {
        $site = $this->site();
        $old = $site->settingValue('webhook_secret');
        $this->webhookAction('new_webhook_secret');

        $new = $site->settingValue('webhook_secret');
        $this->assertNotSame($old, $new, 'a new secret replaces the old one');
        $this->assertStringStartsWith('whsec_', $new, 'the new secret has its prefix');
        $this->assertStringNotContainsString('whsec_', json_encode($site->mcp('site_info', [])), 'the webhook secret stays out of MCP');

        $site->exec("UPDATE tl_settings SET value = '' WHERE name IN ('webhook_enquiries', 'webhook_test_url')");
    }
}
