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
    public function testAPasskeyAloneSignsInWhenTheDeviceChecksItsOwner(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        // a software authenticator: an ES256 key registered for the author
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $id = random_bytes(20);
        $site->exec("INSERT INTO tl_user_passkeys (public_id, user_id, name, credential_hash, credential_id, public_key, alg, sign_count, created_at) SELECT ?, user_id, 'test', ?, ?, ?, -7, 0, NOW() FROM tl_users WHERE username = 'author'",
            ['8f1c3a52-7b2e-4c1d-9a6f-0e5b4d3c2a10', hash('sha256', $id), Passkey::b64($id), (string) openssl_pkey_get_details($key)['key']]);
        $origin = Passkey::origin($site->base);
        $answer = static function (string $challenge, int $flags) use ($key, $id, $origin): string {
            $client = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
            $data = hash('sha256', Passkey::rpId($origin), true) . chr($flags) . pack('N', 0);
            openssl_sign($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256);

            return (string) json_encode(['id' => Passkey::b64($id), 'clientDataJSON' => Passkey::b64($client), 'authenticatorData' => Passkey::b64($data), 'signature' => Passkey::b64((string) $signature)]);
        };

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
        $page = $app->get('/admin.php?action=account');
        $csrf = $page->csrf();
        $this->assertStringContainsString('id="passkey-password"', $page->body, '3.3.3: My account asks for the password next to the passkey');
        $this->assertStringContainsString('id="email-password"', $page->body, '3.3.3: My account asks for the password next to the e-mail');

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
}
