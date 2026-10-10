<?php

declare(strict_types=1);

namespace Talea\Tests\Site\MediaMenuMail;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Subscribers passed on to a mailing service, against a fake service (was: section 33 of tools/test.sh). */
#[Group('site')]
final class SubscribersServiceTest extends SiteTestCase
{
    use Helpers;

    private static int $servicePort = 0;

    private function serviceLog(): string
    {
        return $this->site()->workDir('service') . '/pozadavky.log';
    }

    /** Starts the fake mailing service once per class. */
    private function startService(): void
    {
        if (self::$servicePort !== 0) {
            return;
        }
        $dir = $this->site()->workDir('service');
        file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$log = __DIR__ . '/pozadavky.log';
if ($_SERVER['REQUEST_URI'] === '/_log') { header('Content-Type: text/plain'); @readfile($log); return true; }
$h = array_change_key_case(getallheaders());
file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . ($h['api-key'] ?? $h['authorization'] ?? $h['key'] ?? '-') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
if (str_contains($_SERVER['REQUEST_URI'], 'error')) { http_response_code(500); echo '{"message":"Invalid list"}'; return true; }
http_response_code(201); header('Content-Type: application/json'); echo '{}'; return true;
PHP);
        self::$servicePort = $this->site()->startPhp($dir, 'router.php');
    }

    /** old set_service: the service settings and one confirmed subscriber; the request log is emptied */
    private function setService(string $service, string $key, string $list, string $webhook): void
    {
        $this->startService();
        $site = $this->site();
        foreach (['newsletter_service' => $service, 'newsletter_key' => $key, 'newsletter_list' => $list, 'newsletter_webhook' => $webhook, 'newsletter_test_url' => 'http://127.0.0.1:' . self::$servicePort] as $name => $value) {
            $site->setting($name, $value);
        }
        $site->exec('DELETE FROM tl_subscription_queue');
        $site->exec('DELETE FROM tl_subscribers');
        $site->exec("INSERT INTO tl_subscribers (email, status, token, created_at, confirmed_at) VALUES ('sluzba@example.cz', 1, ?, NOW(), NOW())", [bin2hex(random_bytes(16))]);
        file_put_contents($this->serviceLog(), '');
    }

    /** @param array<string, mixed> $fields */
    private function subscriberAction(string $action, array $fields = []): void
    {
        $this->adminPost('/admin.php?module=subscribers&action=' . $action, $fields, '/admin.php?module=subscribers');
    }

    private function lastRequest(): string
    {
        $lines = file($this->serviceLog(), FILE_IGNORE_NEW_LINES) ?: [];

        return (string) end($lines);
    }

    public function testBrevoAddsAndRemovesTheSubscriber(): void
    {
        $this->setService('brevo', 'brevo-klic', '7', '');
        $this->subscriberAction('sync');
        $this->assertSame('POST /brevo/v3/contacts brevo-klic {"email":"sluzba@example.cz","listIds":[7],"updateEnabled":true}', $this->lastRequest(), 'Brevo: adding to the list');
        $this->assertSame('ok/0', $this->site()->value("SELECT CONCAT(sync, '/', (SELECT COUNT(*) FROM tl_subscription_queue)) FROM tl_subscribers"), 'the subscriber is in the service');
        $this->assertPage('/admin.php?module=subscribers', 200, 'sent', message: 'service state at the subscribers');

        $this->subscriberAction('delete', ['subscriber_id' => $this->site()->value('SELECT public_id FROM tl_subscribers')]);
        $this->subscriberAction('retry');
        $this->assertSame('POST /brevo/v3/contacts/lists/7/contacts/remove brevo-klic {"emails":["sluzba@example.cz"]}', $this->lastRequest(), 'Brevo: the deleted subscriber is removed from the list');
    }

    public function testMailchimpMailerLiteSmartEmailingAndWebhook(): void
    {
        $this->setService('mailchimp', 'abc123-us21', 'aud1', '');
        $this->subscriberAction('sync');
        $line = $this->lastRequest();
        $this->assertStringStartsWith('PUT /mailchimp/3.0/lists/aud1/members/' . md5('sluzba@example.cz') . ' Basic ' . base64_encode('talea:abc123-us21') . ' ', $line, 'Mailchimp: audience member address and login');
        $this->assertStringContainsString('"status":"subscribed"', $line, 'Mailchimp: audience member');

        $this->setService('mailerlite', 'ml-klic', '99', '');
        $this->subscriberAction('sync');
        $this->assertSame('POST /mailerlite/api/subscribers Bearer ml-klic {"email":"sluzba@example.cz","groups":["99"],"status":"active"}', $this->lastRequest(), 'MailerLite: subscriber in the group');

        $this->setService('smartemailing', 'jmeno:klic', '5', '');
        $this->subscriberAction('sync');
        $line = $this->lastRequest();
        $this->assertStringStartsWith('POST /smartemailing/api/v3/import Basic ' . base64_encode('jmeno:klic') . ' ', $line, 'SmartEmailing: import address and login');
        $this->assertStringContainsString('"contactlists":[{"id":5,"status":"confirmed"}]', $line, 'SmartEmailing: import into the list');

        $this->setService('webhook', '', '', 'https://hook.example.com/odber');
        $this->subscriberAction('sync');
        $line = $this->lastRequest();
        $this->assertMatchesRegularExpression('#^POST /webhook/odber - \{"event":"subscribed",#', $line, 'webhook: new subscriber');
        $this->assertStringContainsString('"email":"sluzba@example.cz"', $line, 'webhook: the subscriber e-mail');
    }

    public function testEcomailFailureWaitsForTheNextAttemptAndTheKeyStaysOutOfMcp(): void
    {
        $this->setService('ecomail', 'eco-klic', 'error', '');
        $this->subscriberAction('sync');

        $this->assertSame('1|1|1', $this->site()->value("SELECT CONCAT(attempts, '|', error LIKE 'HTTP 500%', '|', next_attempt_at > NOW()) FROM tl_subscription_queue"), 'a failed transfer waits for the next attempt with the error');
        $line = $this->lastRequest();
        $this->assertStringStartsWith('POST /ecomail/lists/error/subscribe eco-klic ', $line, 'Ecomail: signing in to the list');
        $this->assertStringContainsString('"skip_confirmation":true', $line, 'Ecomail: signing in to the list');

        $settings = $this->mcpText('update_settings', []);
        $this->assertStringNotContainsString('newsletter_klic', $settings, 'MCP does not show the mailing service key (name)');
        $this->assertStringNotContainsString('eco-klic', $settings, 'MCP does not show the mailing service key (value)');
    }
}
