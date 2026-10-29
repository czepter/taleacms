<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use Talea\Core\Passkey;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Two-factor sign-in (TOTP and backup codes) and the password asked for passkeys and e-mail changes (was: section 46 of tools/test.sh). */
#[Group('site')]
final class TwoFactorLoginTest extends SiteTestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private static ?Http $app = null;

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    /** First and second step of the sign-in of "author"; returns the status of the code step and the browser. @return array{int, Http} */
    private function signInWithCode(string $code, bool $remember = false): array
    {
        $browser = $this->site()->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $this->site()->password] + ($remember ? ['remember' => '1'] : []));
        $this->assertStringContainsString('name="code"', $first->body, 'the second step is asked for');

        return [$browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => $code])->status, $browser];
    }

    public function testAppCodeAndBackupCodes(): void
    {
        $site = $this->site();
        // section 5 created the news author; create him here as the administrator did
        $site->signIn($site->admin());
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Author', 'username' => 'author', 'password' => $site->password, 'admin' => 0]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_users WHERE username = 'author'"), 'the author exists');

        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET totp_secret = ?, totp_backup_codes = ? WHERE username = 'author'", [self::SECRET, json_encode([hash('sha256', 'abcde-12345')])]);

        $this->assertSame(401, $this->signInWithCode('000000')[0], 'a wrong code from the app does not pass');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
        [$status, self::$app] = $this->signInWithCode($code);
        $this->assertSame(302, $status, 'sign-in with the code from the app (TOTP)');
        $this->assertSame(401, $this->signInWithCode($code)[0], 'a code from the app signs in only once (no replay within the drift)');
        $this->assertSame(302, $this->signInWithCode('abcde-12345')[0], 'a backup code passes');
        $this->assertSame(401, $this->signInWithCode('abcde-12345')[0], 'a backup code can be used only once');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testStaySignedInWaitsForTheCode(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET totp_last_step = NULL WHERE username = 'author'"); // the code of this step was spent above
        $browser = $site->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $site->password, 'remember' => '1']);
        $this->assertArrayNotHasKey('tl_remember', $browser->cookies(), 'the password alone sets no "stay signed in" cookie');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
        $this->assertSame(302, $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'code', 'code' => $code])->status, 'the code completes the sign-in');
        $this->assertArrayHasKey('tl_remember', $browser->cookies(), 'the tick is kept across the code step');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testALockedAccountNeedsAKnownBrowserForBothSteps(): void
    {
        $site = $this->site();
        $device = self::$app?->cookies()['tl_device'] ?? '';
        $this->assertNotSame('', $device, 'the completed sign-in left the known-device cookie');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
        $fresh = function () use ($site): array {
            $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
            $site->exec("UPDATE tl_users SET failed_logins = 0, totp_last_step = NULL, locked_until = NOW() + INTERVAL 10 MINUTE WHERE username = 'author'");
            $browser = $site->client('author');

            return [$browser, $browser->get('/admin.php')->csrf()];
        };
        $post = static function (Http $browser, array $data, string $cookie) {
            $cookie === '' ? $browser->dropCookie('tl_device') : $browser->setCookie('tl_device', $cookie);

            return $browser->post('/admin.php', $data);
        };
        $password = ['username' => 'author', 'password' => $site->password];

        [$browser, $csrf] = $fresh();
        $first = $post($browser, ['_csrf' => $csrf] + $password, '');
        $this->assertSame(401, $first->status, 'without the cookie the password step is refused while the account is locked');
        $this->assertStringNotContainsString('name="code"', $first->body, '... and no code is asked for');

        [$browser, $csrf] = $fresh();
        $this->assertStringContainsString('name="code"', $post($browser, ['_csrf' => $csrf] + $password, $device)->body, 'a known browser gets to the code step');
        $this->assertSame(401, $post($browser, ['_csrf' => $csrf, 'step' => 'code', 'code' => '000000'], $device)->status, 'a wrong code from it is refused');
        $this->assertSame('1', (string) $site->value("SELECT failed_logins FROM tl_users WHERE username = 'author'"), '... and counts as a failure');

        [$browser, $csrf] = $fresh();
        $post($browser, ['_csrf' => $csrf] + $password, $device);
        $this->assertSame(401, $post($browser, ['_csrf' => $csrf, 'step' => 'code', 'code' => $code], '')->status, 'the code step is account-locked for a browser without the cookie, even with the right code');

        [$browser, $csrf] = $fresh();
        $post($browser, ['_csrf' => $csrf] + $password, $device);
        $this->assertSame(302, $post($browser, ['_csrf' => $csrf, 'step' => 'code', 'code' => $code], $device)->status, 'a known browser completes both steps while the account is locked');
        $this->assertNull($site->value("SELECT locked_until FROM tl_users WHERE username = 'author'"), 'a completed sign-in ends the lock');

        // a stolen cookie that has the password and guesses codes: after five wrong ones it is a stranger like any other
        $device = $browser->cookies()['tl_device'] ?? '';
        $this->assertNotSame('', $device, 'the completed sign-in issued a fresh token');
        [$browser, $csrf] = $fresh();
        $post($browser, ['_csrf' => $csrf] + $password, $device);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $post($browser, ['_csrf' => $csrf, 'step' => 'code', 'code' => '000000'], $device)->status);
        }
        $this->assertSame('5', (string) $site->value('SELECT failures FROM tl_user_devices WHERE token_hash = ?', [hash('sha256', $device)]), 'every wrong code from the cookie is recorded on it');
        $this->assertNotSame(302, $post($browser, ['_csrf' => $csrf, 'step' => 'code', 'code' => $code], $device)->status, 'after five wrong codes even the right one stays locked out');
        $this->assertNull($site->value("SELECT totp_last_step FROM tl_users WHERE username = 'author'"), '... and it is not spent');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testAPasskeyAloneSignsInWhenTheDeviceChecksItsOwner(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        [$key, $id] = $this->registerKey('8f1c3a52-7b2e-4c1d-9a6f-0e5b4d3c2a10');
        $answer = fn (string $challenge, int $flags): string => $this->keyAnswer($key, $id, $challenge, $flags);
        $browser = $site->client('passkey');
        $page = $browser->get('/admin.php');
        $this->assertTrue($page->contains('data-passkey-signin'), 'the first step offers a passkey once an account has one');
        $csrf = $page->csrf();
        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();
        $this->assertSame([[], 'required'], [$options['allowCredentials'] ?? null, $options['userVerification'] ?? null], 'the first step names no account and asks the device to check its owner');

        $unverified = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $answer((string) $options['challenge'], 0x01)]);
        $this->assertSame(401, $unverified->status, 'a key whose device did not check its owner does not sign in alone');

        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();
        $signed = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $answer((string) $options['challenge'], 0x05), 'remember' => '1']);
        $this->assertSame([200, true], [$signed->status, $signed->json()['ok'] ?? null], 'the verified passkey signs in without name, password or code');
        $this->assertArrayHasKey('tl_remember', $browser->cookies(), '"stay signed in" works with a passkey too');
        $this->assertSame(1, substr_count($browser->get('/admin.php?action=token')->body, '"csrf"'), 'the browser is signed in');
        $site->exec("DELETE FROM tl_user_passkeys WHERE name = 'test'");
        $site->exec("UPDATE tl_users SET failed_logins = 0 WHERE username = 'author'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    /** A software authenticator: an ES256 key registered for the author. @return array{OpenSSLAsymmetricKey, string} */
    private function registerKey(string $publicId): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $id = random_bytes(20);
        $this->site()->exec("INSERT INTO tl_user_passkeys (public_id, user_id, name, credential_hash, credential_id, public_key, alg, sign_count, created_at) SELECT ?, user_id, 'test', ?, ?, ?, -7, 0, NOW() FROM tl_users WHERE username = 'author'",
            [$publicId, hash('sha256', $id), Passkey::b64($id), (string) openssl_pkey_get_details($key)['key']]);

        return [$key, $id];
    }

    /** The answer of the authenticator to a sign-in challenge. */
    private function keyAnswer(\OpenSSLAsymmetricKey $key, string $id, string $challenge, int $flags): string
    {
        $origin = Passkey::origin($this->site()->base);
        $client = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $data = hash('sha256', Passkey::rpId($origin), true) . chr($flags) . pack('N', 0);
        openssl_sign($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256);

        return (string) json_encode(['id' => Passkey::b64($id), 'clientDataJSON' => Passkey::b64($client), 'authenticatorData' => Passkey::b64($data), 'signature' => Passkey::b64((string) $signature)]);
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testWrongCodesAddUpDespiteTheRightPassword(): void
    {
        $site = $this->site();
        // 3.3.2 (N7): a correct password does not reset the count of wrong codes
        $site->exec("UPDATE tl_users SET failed_logins = 0 WHERE username = 'author'");
        $this->signInWithCode('111111');
        $this->signInWithCode('222222');
        $this->assertSame('2', (string) $site->value("SELECT failed_logins FROM tl_users WHERE username = 'author'"), '3.3.2: wrong codes add up across sign-ins with the right password');
        $site->exec("UPDATE tl_users SET failed_logins = 0 WHERE username = 'author'");
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testPasskeyAndEmailChangeAskForTheCurrentPassword(): void
    {
        $site = $this->site();
        $app = self::$app ?? throw new \LogicException('No signed-in author.');
        $page = $app->get('/admin.php?action=account&tab=sign-in');
        $csrf = $page->csrf();
        $this->assertStringContainsString('id="passkey-password"', $page->body, '3.3.3: My account asks for the password next to the passkey');
        $this->assertStringContainsString('id="email-password"', $app->get('/admin.php?action=account')->body, '3.3.3: My account asks for the password next to the e-mail');

        $post = static fn (array $fields) => $app->post('/admin.php?action=account', ['_csrf' => $csrf] + $fields);
        $statuses = [$post(['op' => 'passkey_options'])->status, $post(['op' => 'passkey_options', 'current_password' => 'wrong-password-1'])->status];
        $right = $post(['op' => 'passkey_options', 'current_password' => $site->password]);
        $statuses[] = $right->status;
        $this->assertSame([403, 403, 200], $statuses, '3.3.3: a passkey challenge only with the current password');
        $this->assertSame(1, preg_match_all('/"challenge"/m', $right->body), '3.3.3: the challenge is in the answer');

        $site->exec("UPDATE tl_users SET email = 'author-original@example.cz', language = '' WHERE username = 'author'");
        $site->exec("DELETE FROM tl_mail WHERE recipient = 'author-original@example.cz'");
        $post(['op' => 'profile', 'name' => 'Author', 'email' => 'attacker@example.cz']);
        $post(['op' => 'profile', 'name' => 'Author', 'email' => 'attacker@example.cz', 'current_password' => 'wrong-password-1']);
        $this->assertSame('author-original@example.cz', $site->value("SELECT email FROM tl_users WHERE username = 'author'"), '3.3.3: without the current password the e-mail stays');

        $post(['op' => 'profile', 'name' => 'Author-name', 'email' => 'author-original@example.cz']);
        $this->assertSame('Author-name|author-original@example.cz', $site->value("SELECT CONCAT(name, '|', email) FROM tl_users WHERE username = 'author'"),
            '3.3.3: other details save without the password while the e-mail stays the same');

        $post(['op' => 'profile', 'name' => 'Author', 'email' => 'author-new@example.cz', 'current_password' => $site->password]);
        $this->assertSame('author-new@example.cz|1', $site->value("SELECT CONCAT((SELECT email FROM tl_users WHERE username = 'author'), '|', (SELECT COUNT(*) FROM tl_mail WHERE recipient = 'author-original@example.cz' AND subject LIKE 'The e-mail address of your account%'))"),
            '3.3.3: with the current password the e-mail changes and the old address gets a notice');
    }

    /** The author with a passkey and no app, two backup codes; returns the key. @return array{OpenSSLAsymmetricKey, string} */
    private function passkeyOnlyAuthor(): array
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("DELETE FROM tl_user_passkeys");
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_last_step = NULL, failed_logins = 0, locked_until = NULL, totp_backup_codes = ? WHERE username = 'author'",
            [json_encode([hash('sha256', 'aaaaa-11111'), hash('sha256', 'bbbbb-22222')])]);

        return $this->registerKey('8f1c3a52-7b2e-4c1d-9a6f-0e5b4d3c2a11');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testAPasskeyIsASecondFactorOfItsOwn(): void
    {
        $site = $this->site();
        [$key, $id] = $this->passkeyOnlyAuthor();

        $browser = $site->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $site->password]);
        $this->assertStringContainsString('name="code"', $first->body, 'the password alone does not sign in: the second step is asked for');
        $this->assertStringContainsString('data-passkey-signin', $first->body, 'the passkey is the main way of the second step');
        $this->assertStringNotContainsString('authenticator app', $first->body, 'an account without the app is not asked for a code from it');
        $this->assertStringNotContainsString('inputmode="numeric"', $first->body, 'backup codes contain letters');
        $this->assertStringNotContainsString('"csrf"', $browser->get('/admin.php?action=token')->body, 'not signed in yet');

        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();
        $this->assertCount(1, $options['allowCredentials'] ?? [], 'the challenge names the keys of the account');
        $signed = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $this->keyAnswer($key, $id, (string) $options['challenge'], 0x01)]);
        $this->assertSame([200, true], [$signed->status, $signed->json()['ok'] ?? null], 'the passkey completes the sign-in after the password');
        $this->assertSame(1, substr_count($browser->get('/admin.php?action=token')->body, '"csrf"'), 'the browser is signed in');

        $this->assertSame(401, $this->signInWithCode('000000')[0], 'a wrong code does not pass');
        $empty = trim($site->php('echo Talea\Core\Totp::code("", intdiv(time(), 30));'));
        $this->assertSame(401, $this->signInWithCode($empty)[0], 'a code computed from an empty secret never passes');
        $this->assertSame(302, $this->signInWithCode('aaaaa-11111')[0], 'a backup code completes the sign-in');
        $this->assertSame(401, $this->signInWithCode('aaaaa-11111')[0], 'a backup code works once');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testMyAccountAddsAndRemovesPasskeysWithoutTheApp(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("DELETE FROM tl_user_passkeys");
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_backup_codes = NULL, failed_logins = 0, locked_until = NULL WHERE username = 'author'");
        $browser = $site->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $this->assertSame(302, $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $site->password])->status, 'no second step yet: the password signs in');

        $page = $browser->get('/admin.php?action=account&tab=sign-in');
        $csrf = $page->csrf();
        $this->assertStringContainsString('data-passkey-add', $page->body, 'My account offers a passkey without the app');
        $post = static fn (array $fields) => $browser->post('/admin.php?action=account', ['_csrf' => $csrf] + $fields);
        $this->assertSame(403, $post(['op' => 'passkey_options', 'current_password' => 'wrong-password-1'])->status, 'still only with the current password');
        $options = $post(['op' => 'passkey_options', 'current_password' => $site->password]);
        $this->assertSame(200, $options->status, 'the challenge is issued to an account without the app');

        // a software authenticator registers
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);
        $id = random_bytes(20);
        $origin = Passkey::origin($site->base);
        $client = (string) json_encode(['type' => 'webauthn.create', 'challenge' => $options->json()['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $data = hash('sha256', Passkey::rpId($origin), true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id
            . str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $details['key']));
        $saved = $post(['op' => 'passkey_save', 'name' => 'Laptop', 'answer' => json_encode([
            'id' => Passkey::b64($id), 'clientDataJSON' => Passkey::b64($client), 'authenticatorData' => Passkey::b64($data),
            'publicKey' => Passkey::b64($der), 'publicKeyAlgorithm' => -7,
        ])]);
        $this->assertTrue($saved->json()['ok'] ?? false, 'the first passkey is saved: ' . $saved->body);
        $this->assertNotSame('', (string) $site->value("SELECT totp_backup_codes FROM tl_users WHERE username = 'author'"), 'it turned two-factor sign-in on: backup codes exist');

        $page = $browser->get('/admin.php?action=account&tab=sign-in');
        $this->assertSame(1, preg_match_all('/<p class="backup-codes">[0-9a-f]{5}-[0-9a-f]{5}/', $page->body), 'the backup codes are shown once');
        $this->assertSame(0, preg_match_all('/<p class="backup-codes">/', $browser->get('/admin.php?action=account&tab=sign-in')->body), '... and not again');

        // the last passkey goes: two-factor sign-in is off, the backup codes with it
        $csrf = $page->csrf();
        $delete = ['_csrf' => $csrf, 'op' => 'passkey_delete', 'passkey_id' => $site->value('SELECT public_id FROM tl_user_passkeys LIMIT 1')];
        $browser->post('/admin.php?action=account', $delete);
        $browser->post('/admin.php?action=account', $delete + ['current_password' => 'wrong-password-1']);
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_user_passkeys'), 'a stolen session without the password cannot remove the second factor');
        $browser->post('/admin.php?action=account', $delete + ['current_password' => $site->password]);
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_user_passkeys'), 'the passkey is removed');
        $this->assertNull($site->value("SELECT totp_backup_codes FROM tl_users WHERE username = 'author'"), 'the backup codes are cleared');
        $other = $site->client('author');
        $this->assertSame(302, $other->post('/admin.php', ['_csrf' => $other->get('/admin.php')->csrf(), 'username' => 'author', 'password' => $site->password])->status, 'the password alone signs in again');
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testAPasskeySatisfiesTheRequiredTwoFactor(): void
    {
        $site = $this->site();
        [$key, $id] = $this->passkeyOnlyAuthor();
        $browser = $site->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $site->password]);
        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $this->keyAnswer($key, $id, (string) $options['challenge'], 0x01)]);

        $site->setting('require_2fa', 'everyone');
        $withKey = $browser->get('/admin.php?module=news');
        $site->exec('DELETE FROM tl_user_passkeys');
        $without = $browser->get('/admin.php?module=news');
        $site->setting('require_2fa', '');

        $this->assertSame(200, $withKey->status, 'a passkey meets the two-factor requirement of the site');
        $this->assertSame(302, $without->status, 'without a passkey (or the app) the requirement sends the account to My account');
    }
}
