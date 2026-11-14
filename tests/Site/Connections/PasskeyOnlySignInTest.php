<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Core\Passkey;
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
            [in_array('app', $has, true) ? self::SECRET : '', in_array('backup', $has, true) ? json_encode([hash('sha256', self::BACKUP), hash('sha256', 'bbbbb-22222'), hash('sha256', 'ccccc-33333')]) : null, in_array('only', $has, true) ? 1 : 0, 'author']);
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

    private const string ACCOUNT = '/admin.php?action=account';

    /** A browser signed in as "author" with its key, whose session was then lost: "stay signed in" restores it with no proof recorded. */
    private function staleSession(\OpenSSLAsymmetricKey $key, string $id): Http
    {
        $browser = $this->site()->client('author');
        $this->assertSame(200, $this->signInWithKey($browser, $key, $id, true)->status);
        $browser->dropCookie('talea');
        $this->assertTrue($this->signedIn($browser), 'the sign-in is restored');

        return $browser;
    }

    private function newToken(Http $browser, array $proof): string
    {
        $csrf = (string) ($browser->get('/admin.php?action=token')->json()['csrf'] ?? '');

        return $browser->post(self::ACCOUNT, $proof + ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'T', 'access' => 'drafts', 'lifetime' => '30'])->body;
    }

    private function tokenCount(): int
    {
        return (int) $this->site()->value("SELECT COUNT(*) FROM tl_api_tokens t JOIN tl_users u ON u.user_id = t.user_id WHERE u.username = 'author' AND t.kind = 'token'");
    }

    private function backupCodesLeft(): int
    {
        return count((array) json_decode((string) $this->site()->value("SELECT totp_backup_codes FROM tl_users WHERE username = 'author'"), true));
    }

    public function testAPasskeyOnlyAccountNeverConfirmsWithThePassword(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup', 'only']);
        $site = $this->site();

        // a sign-in with the password and a backup code is a fresh proof of kind backup
        [$browser, $csrf] = $this->passwordStep();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => self::BACKUP]);
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($browser, []), 'a fresh backup-code sign-in confirms without anything typed');
        $this->assertSame(1, $this->tokenCount());

        $stale = $this->staleSession($key, $id);
        $answer = $this->newToken($stale, ['current_password' => $site->password]);
        $this->assertDoesNotMatchRegularExpression('/talea_[a-f0-9]{48}/', $answer);
        $this->assertSame(1, $this->tokenCount(), 'the password confirms nothing for a passkey-only account');
        $this->assertStringContainsString('Confirm with your passkey or a backup code.', $stale->get(self::ACCOUNT . '&tab=claude')->body);

        $this->assertDoesNotMatchRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($stale, ['backup_code' => 'wrong-wrong']));
        $left = $this->backupCodesLeft();
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($stale, ['backup_code' => 'bbbbb-22222']), 'a backup code confirms');
        $this->assertSame($left - 1, $this->backupCodesLeft(), 'and is spent');

        $stale = $this->staleSession($key, $id);
        $csrf = (string) ($stale->get('/admin.php?action=token')->json()['csrf'] ?? '');
        $options = $stale->post('/admin.php?action=proof', ['_csrf' => $csrf, 'step' => 'options'])->json();
        $this->assertSame(200, $stale->post('/admin.php?action=proof', ['_csrf' => $csrf, 'step' => 'verify', 'answer' => $this->keyAnswer($key, $id, (string) $options['challenge'], 0x05)])->status);
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($stale, []), 'a passkey confirmation counts');
    }

    public function testTheAccountSwitch(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup']);
        $site = $this->site();
        $set = function (Http $browser, array $fields): void {
            $browser->post(self::ACCOUNT, $fields + ['_csrf' => (string) ($browser->get('/admin.php?action=token')->json()['csrf'] ?? ''), 'op' => 'passkey_only']);
        };
        $flag = fn (): string => (string) $site->value("SELECT passkey_only FROM tl_users WHERE username = 'author'");

        $stale = $this->staleSession($key, $id);
        $set($stale, ['passkey_only' => '1']);
        $this->assertSame('0', $flag(), 'nothing is saved without a confirmation');
        $set($stale, ['passkey_only' => '1', 'current_password' => $site->password]);
        $this->assertSame('1', $flag(), 'a normal account turns it on with its password');

        $stale = $this->staleSession($key, $id);
        $set($stale, ['passkey_only' => '', 'current_password' => $site->password]);
        $this->assertSame('1', $flag(), 'now the password no longer confirms');
        $set($stale, ['passkey_only' => '', 'backup_code' => self::BACKUP]);
        $this->assertSame('0', $flag(), 'a backup code turns it off');
        $this->assertStringContainsString('Passkey-only sign-in', $stale->get(self::ACCOUNT . '&tab=sign-in')->body);
    }

    public function testNewBackupCodes(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup']);
        $site = $this->site();
        $stale = $this->staleSession($key, $id);
        $csrf = fn (): string => (string) ($stale->get('/admin.php?action=token')->json()['csrf'] ?? '');
        $stale->post(self::ACCOUNT, ['_csrf' => $csrf(), 'op' => 'backup_codes_new']);
        $this->assertSame(3, $this->backupCodesLeft(), 'nothing changes without a confirmation');
        $page = $stale->post(self::ACCOUNT, ['_csrf' => $csrf(), 'op' => 'backup_codes_new', 'current_password' => $site->password]);
        $this->assertSame(1, preg_match('/<p class="backup-codes">([0-9a-f]{4}(?:-[0-9a-f]{4}){3})/', $page->body, $new), 'eight new codes are shown');
        $this->assertSame(8, $this->backupCodesLeft());
        $this->assertSame([401, false], array_slice($this->passwordAndCode(self::BACKUP), 0, 2), 'the old code stops working');
        $this->assertSame([302, true], array_slice($this->passwordAndCode($new[1]), 0, 2), 'a new one signs in');
    }

    public function testRegisterAgainReplacesTheKey(): void
    {
        [$key, $id] = $this->author(['app', 'key', 'backup']);
        $site = $this->site();
        $site->exec('UPDATE tl_user_passkeys SET discoverable = NULL');
        $stale = $this->staleSession($key, $id); // the sign-in with the key alone makes it discoverable
        $this->assertSame('1', (string) $site->value('SELECT discoverable FROM tl_user_passkeys'), 'a key that signed in alone is kept on its device');
        $site->exec('UPDATE tl_user_passkeys SET discoverable = NULL');
        $page = $stale->get(self::ACCOUNT . '&tab=sign-in');
        $keyId = (string) $site->value('SELECT public_id FROM tl_user_passkeys');
        $this->assertStringContainsString('data-passkey-replace="' . $keyId . '"', $page->body, 'a key not known to be kept offers "Register again"');

        $csrf = (string) ($stale->get('/admin.php?action=token')->json()['csrf'] ?? '');
        $options = $stale->post(self::ACCOUNT, ['_csrf' => $csrf, 'op' => 'passkey_options', 'current_password' => $site->password, 'replace' => $keyId])->json();
        $this->assertSame([], $options['excludeCredentials'] ?? null, 'the key to replace is not excluded');
        $this->assertSame(['credProps' => true], $options['extensions'] ?? null);
        $registration = $this->registrationAnswer($options);
        $saved = $stale->post(self::ACCOUNT, ['_csrf' => $csrf, 'op' => 'passkey_save', 'name' => 'Again', 'replace' => $keyId, 'discoverable' => '1', 'answer' => $registration['answer']]);
        $this->assertTrue($saved->json()['ok'] ?? false, $saved->body);
        $this->assertSame(['Again|1|' . Passkey::rpId($site->base)], array_map(static fn (array $r): string => $r['name'] . '|' . $r['discoverable'] . '|' . $r['rp_id'], $site->rows('SELECT name, discoverable, rp_id FROM tl_user_passkeys')), 'the old key is gone and the new one is kept on its device');
        $this->assertSame(3, $this->backupCodesLeft(), 'no new backup codes: the account had two-factor sign-in already');
        $this->assertSame(200, $this->signInWithKey($this->site()->client('author'), $registration['key'], $registration['id'])->status, 'the new key signs in');
    }
}
