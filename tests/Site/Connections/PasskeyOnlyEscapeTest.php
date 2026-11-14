<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use Talea\Tests\Site\Support\SoftwareAuthenticator;

/** TALEA_PASSKEY_ONLY_OFF: passkey-only is off, an administrator without app and backup codes gets in with the password, System status warns (#333). */
#[Group('site')]
final class PasskeyOnlyEscapeTest extends SiteTestCase
{
    use SoftwareAuthenticator;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    protected static function siteOptions(): array
    {
        return ['login' => false, 'env' => ['TALEA_PASSKEY_ONLY_OFF' => '1']];
    }


    private function signedIn(Http $browser): bool
    {
        return str_contains($browser->get('/admin.php?action=token')->body, '"csrf"');
    }

    private function create(string $username, int $level): void
    {
        $site = $this->site();
        $site->signIn($site->admin());
        if ((int) $site->value('SELECT COUNT(*) FROM tl_users WHERE username = ?', [$username]) === 0) {
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => ucfirst($username), 'username' => $username, 'password' => $site->password, 'admin' => $level]);
        }
        $site->exec('DELETE FROM tl_ip_checks');
        $site->exec('DELETE FROM tl_user_passkeys WHERE user_id IN (SELECT user_id FROM tl_users WHERE username = ?)', [$username]);
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_backup_codes = NULL, passkey_only = FALSE, failed_logins = 0, locked_until = NULL, totp_last_step = NULL WHERE username = ?", [$username]);
    }

    public function testTheEscapeSwitchesPasskeyOnlyOff(): void
    {
        $site = $this->site();
        $this->create('author', 0);
        $site->exec("UPDATE tl_users SET totp_secret = ?, totp_backup_codes = ?, passkey_only = TRUE WHERE username = 'author'", [self::SECRET, json_encode([hash('sha256', 'abcde-12345')])]);
        $this->registerKey('author', '8d4c5e6f-7081-4293-8dae-2f3a4b5c6d7e');
        $browser = $site->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $site->password]);
        $this->assertStringContainsString('authenticator app', $first->body, 'the escape: the app code is offered again');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
        $this->assertSame(302, $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => $code])->status);
        $this->assertTrue($this->signedIn($browser));
    }

    public function testAnAdministratorWithoutAppOrBackupCodesSignsInWithThePassword(): void
    {
        $site = $this->site();
        $this->create('locked', 2);
        $this->registerKey('locked', '9e5d6f70-8192-43a4-9ebf-3a4b5c6d7e8f');
        $site->exec("UPDATE tl_users SET totp_backup_codes = '[]' WHERE username = 'locked'");
        $browser = $site->client('locked');
        $first = $browser->post('/admin.php', ['_csrf' => $browser->get('/admin.php')->csrf(), 'username' => 'locked', 'password' => $site->password]);
        $this->assertSame(302, $first->status, 'the administrator lost every second factor: the password is enough');
        $this->assertTrue($this->signedIn($browser));
        $this->assertStringContainsString('TALEA_PASSKEY_ONLY_OFF', (string) $site->value("SELECT description FROM tl_change_log WHERE module = 'signed_in' AND action = 'login' ORDER BY log_id DESC LIMIT 1"), 'the change log says why');

        $this->create('editor', 1);
        $this->registerKey('editor', '8f6e7081-92a3-44b5-8fc0-4b5c6d7e8f90');
        $site->exec("UPDATE tl_users SET totp_backup_codes = '[]' WHERE username = 'editor'");
        $other = $site->client('editor');
        $second = $other->post('/admin.php', ['_csrf' => $other->get('/admin.php')->csrf(), 'username' => 'editor', 'password' => $site->password]);
        $this->assertStringContainsString('name="code"', $second->body, 'an editor in the same state still has to pass the second step');
        $this->assertFalse($this->signedIn($other));
    }

    public function testSystemStatusWarns(): void
    {
        $site = $this->site();
        $site->signIn($site->admin());
        $this->assertStringContainsString('TALEA_PASSKEY_ONLY_OFF is set', $site->admin()->get('/admin.php?module=status')->body);
    }
}
