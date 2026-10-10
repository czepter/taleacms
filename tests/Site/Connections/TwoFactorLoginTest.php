<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

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
    private function signInWithCode(string $code): array
    {
        $browser = $this->site()->client('author');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'author', 'password' => $this->site()->password]);
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
        $this->assertSame(302, $this->signInWithCode('abcde-12345')[0], 'a backup code passes');
        $this->assertSame(401, $this->signInWithCode('abcde-12345')[0], 'a backup code can be used only once');
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
