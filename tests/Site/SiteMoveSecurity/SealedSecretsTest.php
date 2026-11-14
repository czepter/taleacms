<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use PHPUnit\Framework\Attributes\Group;
use Talea\Core\Vault;
use Talea\Tests\Site\Support\SiteTestCase;

/** With TALEA_SECRET_KEY the passwords and keys in the settings and the app seeds are stored sealed, and everything that reads them still works (#335). */
#[Group('site')]
final class SealedSecretsTest extends SiteTestCase
{
    private const string SECRET = '7e1c0d2b3a4f5e6d7c8b9a0f1e2d3c4b5a69788796a5b4c3d2e1f0a9b8c7d6e5';
    private const string OTHER = '1f2e3d4c5b6a79880a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6071';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_SECRET_KEY' => self::SECRET]];
    }

    /** What a stored value holds, opened in the test process with the same key. */
    private function opened(string $stored): ?string
    {
        return str_starts_with($stored, Vault::PREFIX) ? Vault::open(Vault::derive(self::SECRET), substr($stored, strlen(Vault::PREFIX))) : $stored;
    }

    public function testASecretSavedInSettingsIsSealed(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'smtp_password' => 'typed-secret']);
        $stored = $site->settingValue('smtp_password');
        $this->assertStringStartsWith(Vault::PREFIX, $stored);
        $this->assertStringNotContainsString('typed-secret', $stored);
        $this->assertSame('typed-secret', $this->opened($stored));
        $this->assertStringContainsString('password is saved', $site->admin()->get('/admin.php?module=settings&tab=mail')->body, 'the form knows it has a password');
    }

    public function testATypedSecretThatStartsWithTheMarkerIsKept(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'smtp_password' => 'sealed:typed-secret']);
        $stored = $site->settingValue('smtp_password');
        $this->assertNotSame('sealed:typed-secret', $stored, 'it is sealed like any other value');
        $this->assertSame('sealed:typed-secret', $this->opened($stored), 'and opens to what was typed');
    }

    public function testPlainValuesAreSealedOnTheFirstAdminPage(): void
    {
        $site = $this->site();
        $site->setting('ai_key', 'plain-key');
        $this->assertSame('plain-key', $site->settingValue('ai_key'));
        $this->assertPage('/admin.php');
        $stored = $site->settingValue('ai_key');
        $this->assertStringStartsWith(Vault::PREFIX, $stored, 'the first administrator page seals it');
        $this->assertSame('plain-key', $this->opened($stored));
    }

    public function testTheWebhookTestCallIsSignedWithTheOpenedSecret(): void
    {
        $site = $this->site();
        $dir = $site->workDir('hook');
        file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$h = array_change_key_case(getallheaders());
file_put_contents(__DIR__ . '/calls.log', json_encode(['ts' => $h['x-talea-timestamp'] ?? '', 'sig' => $h['x-talea-signature'] ?? '', 'body' => file_get_contents('php://input')]) . "\n", FILE_APPEND);
http_response_code(204); return true;
PHP);
        $port = $site->startPhp($dir, 'router.php');
        file_put_contents($dir . '/calls.log', '');
        $site->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $site->setting('webhook_test_url', 'http://127.0.0.1:' . $port);
        $csrf = $site->admin()->get('/admin.php?module=settings&tab=webhooks')->csrf();
        $site->admin()->post('/admin.php?module=settings&action=test_webhook', ['_csrf' => $csrf, 'tab' => 'webhooks']);

        $stored = $site->settingValue('webhook_secret');
        $this->assertStringStartsWith(Vault::PREFIX, $stored, 'the signing secret is stored sealed');
        $call = json_decode(trim((string) file_get_contents($dir . '/calls.log')), true);
        $this->assertIsArray($call, 'the receiver got the test call');
        $this->assertSame('sha256=' . hash_hmac('sha256', $call['ts'] . '.' . $call['body'], (string) $this->opened($stored)), $call['sig'], 'signed with the opened secret');
        $this->assertStringContainsString((string) $this->opened($stored), $site->admin()->get('/admin.php?module=settings&tab=webhooks')->body, 'the page shows the plain secret to the administrator');
        $site->exec("UPDATE tl_settings SET value = '' WHERE name IN ('webhook_enquiries', 'webhook_test_url')");
    }

    public function testAnUnreadableSecretIsFlaggedAndNothingBreaks(): void
    {
        $site = $this->site();
        $site->setting('ai_key', Vault::PREFIX . Vault::seal(Vault::derive(self::OTHER), 'sk-foreign'));
        $this->assertPage('/admin.php?module=settings&tab=ai', 200, [], message: 'the AI tab opens with a key it cannot read');
        $this->assertPage('/admin.php?module=status', 200, 'cannot be read', message: 'System status says so');
        $this->assertStringStartsWith(Vault::PREFIX, $site->settingValue('ai_key'), 'the stored value is left alone');
    }

    public function testTwoFactorStillSignsIn(): void
    {
        $site = $this->site();
        $account = '/admin.php?action=account';
        $admin = $site->admin();
        $admin->post($account, ['_csrf' => $admin->get($account)->csrf(), 'op' => 'totp_start', 'current_password' => $site->password]);
        $setup = $admin->get($account . '&tab=sign-in');
        $this->assertSame(1, preg_match('/<code class="totp-key">([A-Z2-7 ]+)<\/code>/', $setup->body, $key));
        $seed = str_replace(' ', '', $key[1]);
        $code = trim($site->php('echo Talea\Core\Totp::code("' . $seed . '", intdiv(time(), 30));'));
        $admin->post($account, ['_csrf' => $setup->csrf(), 'op' => 'totp_confirm', 'code' => $code]);

        $stored = (string) $site->value("SELECT totp_secret FROM tl_users WHERE username = 'admin'");
        $this->assertStringStartsWith(Vault::PREFIX, $stored, 'the seed is stored sealed');
        $this->assertSame($seed, $this->opened($stored));

        $site->exec("UPDATE tl_users SET totp_last_step = NULL WHERE username = 'admin'");
        $browser = $site->client('second');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => $site->password]);
        $this->assertStringContainsString('name="code"', $first->body, 'the second step is asked for');
        $next = trim($site->php('echo Talea\Core\Totp::code("' . $seed . '", intdiv(time(), 30) + 1);'));
        $this->assertSame(302, $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => $next])->status, 'a code of the opened seed signs in');
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_backup_codes = NULL WHERE username = 'admin'");
    }
}
