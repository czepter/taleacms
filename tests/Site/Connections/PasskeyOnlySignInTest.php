<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;
use Talea\Tests\Site\Support\SoftwareAuthenticator;

/**
 * Passkey-only sign-in (#333): the table of what signs in which account, and that nobody is locked out. Every test starts the account
 * "author" from a clean state.
 */
#[Group('site')]
final class PasskeyOnlySignInTest extends SiteTestCase
{
    use SoftwareAuthenticator;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';
    private const string BACKUP = 'abcde-12345';

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    /** The account "author" in a state: any of 'app', 'key' (a key for this host), 'otherkey' (a key for another host), 'only' (the account's switch), 'backup'. @return array{OpenSSLAsymmetricKey, string}|null the key */
    private function author(array $has = []): ?array
    {
        $site = $this->site();
        if ((int) $site->value("SELECT COUNT(*) FROM tl_users WHERE username = 'author'") === 0) {
            $site->signIn($site->admin());
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Author', 'username' => 'author', 'password' => $site->password, 'admin' => 0]);
        }
        $site->exec("DELETE FROM tl_ip_checks");
        $site->exec('DELETE FROM tl_user_passkeys');
        $site->exec('DELETE FROM tl_user_devices');
        $site->setting('passkey_only', '0');
        $site->exec('UPDATE tl_users SET totp_secret = ?, totp_backup_codes = ?, passkey_only = ?, failed_logins = 0, locked_until = NULL, totp_last_step = NULL WHERE username = ?',
            [in_array('app', $has, true) ? self::SECRET : '', in_array('backup', $has, true) ? json_encode([hash('sha256', self::BACKUP)]) : null, in_array('only', $has, true) ? 1 : 0, 'author']);
        $key = null;
        if (in_array('key', $has, true)) {
            $key = $this->registerKey('author', '7c3b4d5e-6f70-4182-9c9d-1e2f3a4b5c6d');
        } elseif (in_array('otherkey', $has, true)) {
            $key = $this->registerKey('author', '7c3b4d5e-6f70-4182-9c9d-1e2f3a4b5c6e', 'other.example');
        }

        return $key;
    }

    /** The password step in a new browser. @return array{Http, string, Response} */
    private function passwordStep(string $password = ''): array
    {
        $browser = $this->site()->client('author');
        $csrf = $browser->get('/admin.php')->csrf();

        return [$browser, $csrf, $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $password !== '' ? $password : $this->site()->password])];
    }

    private function signedIn(Http $browser): bool
    {
        return str_contains($browser->get('/admin.php?action=token')->body, '"csrf"');
    }

    private function appCode(): string
    {
        $this->site()->exec("UPDATE tl_users SET totp_last_step = NULL WHERE username = 'author'");

        return trim($this->site()->php('echo Talea\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
    }

    /** Password, then the code field: returns the status of the code step and whether the browser is signed in. @return array{int, bool, string} */
    private function passwordAndCode(string $code): array
    {
        [$browser, $csrf, $first] = $this->passwordStep();
        $this->assertStringContainsString('name="code"', $first->body, 'the password alone only starts the second step');
        $second = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => $code]);

        return [$second->status, $this->signedIn($browser), $first->body];
    }

    /** Password, then the key as the second step. */
    private function passwordAndKey(\OpenSSLAsymmetricKey $key, string $id): bool
    {
        [$browser, $csrf] = $this->passwordStep();
        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $this->keyAnswer($key, $id, (string) $options['challenge'], 0x01)]);

        return $this->signedIn($browser);
    }

    public function testPasswordOnlyAccountSignsInWithThePassword(): void
    {
        $this->author();
        [$browser, , $first] = $this->passwordStep();
        $this->assertSame(302, $first->status);
        $this->assertTrue($this->signedIn($browser));
    }

    public function testAppAccount(): void
    {
        $this->author(['app', 'backup']);
        $this->assertSame([302, true], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'password and the code from the app');
        $this->author(['app', 'backup']);
        $this->assertSame([302, true], array_slice($this->passwordAndCode(self::BACKUP), 0, 2), 'password and a backup code');
        $this->author(['app', 'backup']);
        [$browser, , $first] = $this->passwordStep();
        $this->assertStringContainsString('authenticator app', $first->body);
        $this->assertFalse($this->signedIn($browser), 'the password alone is not enough');
    }

    public function testPasskeyAccount(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup']);
        $this->assertSame(200, $this->signInWithKey($this->site()->client('author'), $key, $id)->status, 'the passkey alone');
        $this->assertSame([302, true], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'password and the app code');
        $this->assertTrue($this->passwordAndKey($key, $id), 'password and the passkey');
        $this->assertSame([302, true], array_slice($this->passwordAndCode(self::BACKUP), 0, 2), 'password and a backup code');
        [$browser] = $this->passwordStep();
        $this->assertFalse($this->signedIn($browser), 'the password alone does not');
    }

    public function testPasskeyOnlyAccountRefusesTheAppCode(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup', 'only']);
        $this->assertSame(200, $this->signInWithKey($this->site()->client('author'), $key, $id)->status, 'the passkey alone');
        [$status, $in, $page] = $this->passwordAndCode($this->appCode());
        $this->assertSame([401, false], [$status, $in], 'password and the app code do not');
        $this->assertStringNotContainsString('authenticator app', $page, 'the second step does not offer the app');
        $this->assertTrue($this->passwordAndKey($key, $id), 'password and the passkey');
        $this->assertSame([302, true], array_slice($this->passwordAndCode(self::BACKUP), 0, 2), 'password and a backup code');
        [$browser] = $this->passwordStep();
        $this->assertFalse($this->signedIn($browser), 'the password alone does not');
    }

    public function testTheSiteSettingMakesEveryAccountWithAPasskeyPasskeyOnly(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup']);
        $this->site()->setting('passkey_only', '1');
        $this->assertSame([401, false], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'the site asks for it: the app code is refused');
        $this->assertTrue($this->passwordAndKey($key, $id));
        $this->site()->setting('passkey_only', '0');
        $this->assertSame([302, true], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'setting off: the app code works again');
    }

    public function testPasskeyOnlyWithoutAWorkingPasskeyIsNotLocked(): void
    {
        $site = $this->site();
        $this->author(['app', 'backup', 'only']);
        $this->assertSame([302, true], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'the switch is on but there is no passkey: the app code works');

        $this->author(['app', 'backup', 'only', 'otherkey']);
        $this->assertSame([302, true], array_slice($this->passwordAndCode($this->appCode()), 0, 2), 'a passkey of another host does not count');

        $this->author(['only']);
        $site->setting('passkey_only', '1');
        [$browser, , $first] = $this->passwordStep();
        $this->assertSame(302, $first->status, 'no second factor at all: the password signs in');
        $this->assertTrue($this->signedIn($browser));
    }
}
