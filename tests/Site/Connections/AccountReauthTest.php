<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A stolen session alone must not mint a personal token or enrol a second-factor secret (3.3.3, N56). */
#[Group('site')]
final class AccountReauthTest extends SiteTestCase
{
    private const string URL = '/admin.php?action=account';

    public function testPersonalTokenNeedsTheCurrentPassword(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $csrf = $admin->get(self::URL)->csrf();

        $refused = $admin->post(self::URL, ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Stolen', 'access' => 'full', 'lifetime' => '0']);
        $this->assertDoesNotMatchRegularExpression('/talea_[a-f0-9]{48}/', $refused->body, 'no token is shown without the password');
        $admin->post(self::URL, ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Stolen', 'current_password' => 'wrong']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_api_tokens WHERE name = 'Stolen'"), 'no token is stored');

        $created = $admin->post(self::URL, ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Mine', 'current_password' => $site->password]);
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $created->body, 'the right password creates the token');
    }

    public function testTwoFactorEnrolmentNeedsTheCurrentPassword(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $csrf = $admin->get(self::URL)->csrf();

        $admin->post(self::URL, ['_csrf' => $csrf, 'op' => 'totp_start']);
        $this->assertStringNotContainsString('class="totp-key"', $admin->get(self::URL . '&tab=sign-in')->body, 'no secret is shown without the password');

        $admin->post(self::URL, ['_csrf' => $csrf, 'op' => 'totp_start', 'current_password' => $site->password]);
        $setup = $admin->get(self::URL . '&tab=sign-in');
        $this->assertSame(1, preg_match('/<code class="totp-key">([A-Z2-7 ]+)<\/code>/', $setup->body, $key), 'the right password shows the key');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . str_replace(' ', '', $key[1]) . '", intdiv(time(), 30));'));
        $admin->post(self::URL, ['_csrf' => $setup->csrf(), 'op' => 'totp_confirm', 'code' => $code]);
        $this->assertNotSame('', (string) $site->value("SELECT totp_secret FROM tl_users WHERE username = 'admin'"), 'two-factor sign-in is on');
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_backup_codes = NULL WHERE username = 'admin'");
    }
}
