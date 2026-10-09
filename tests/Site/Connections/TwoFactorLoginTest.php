<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Connections;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\SiteTestCase;
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

    /** First and second step of the sign-in of "autor"; returns the status of the code step and the browser. @return array{int, Http} */
    private function signInWithCode(string $code): array
    {
        $browser = $this->site()->client('autor');
        $csrf = $browser->get('/admin.php')->csrf();
        $first = $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'autor', 'password' => $this->site()->password]);
        $this->assertStringContainsString('name="kod"', $first->body, 'the second step is asked for');

        return [$browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'kod', 'kod' => $code])->status, $browser];
    }

    public function testAppCodeAndBackupCodes(): void
    {
        $site = $this->site();
        // section 5 created the news author; create him here as the administrator did
        $site->signIn($site->admin());
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Autor', 'username' => 'autor', 'password' => $site->password, 'admin' => 0]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_users WHERE username = 'autor'"), 'the author exists');

        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE ka_users SET totp_secret = ?, totp_backup_codes = ? WHERE username = 'autor'", [self::SECRET, json_encode([hash('sha256', 'abcde-12345')])]);

        $this->assertSame(401, $this->signInWithCode('000000')[0], 'a wrong code from the app does not pass');
        $code = trim($site->php('echo Kaleta\Core\Totp::code("' . self::SECRET . '", intdiv(time(), 30));'));
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
        $site->exec("UPDATE ka_users SET failed_logins = 0 WHERE username = 'autor'");
        $this->signInWithCode('111111');
        $this->signInWithCode('222222');
        $this->assertSame('2', (string) $site->value("SELECT failed_logins FROM ka_users WHERE username = 'autor'"), '3.3.2: wrong codes add up across sign-ins with the right password');
        $site->exec("UPDATE ka_users SET failed_logins = 0 WHERE username = 'autor'");
    }

    #[Depends('testAppCodeAndBackupCodes')]
    public function testPasskeyAndEmailChangeAskForTheCurrentPassword(): void
    {
        $site = $this->site();
        $app = self::$app ?? throw new \LogicException('No signed-in author.');
        $page = $app->get('/admin.php?action=account');
        $csrf = $page->csrf();
        $this->assertStringContainsString('id="klic-heslo"', $page->body, '3.3.3: My account asks for the password next to the passkey');
        $this->assertStringContainsString('id="email-heslo"', $page->body, '3.3.3: My account asks for the password next to the e-mail');

        $post = static fn (array $fields) => $app->post('/admin.php?action=account', ['_csrf' => $csrf] + $fields);
        $statuses = [$post(['co' => 'klic_moznosti'])->status, $post(['co' => 'klic_moznosti', 'soucasne' => 'wrong-password-1'])->status];
        $right = $post(['co' => 'klic_moznosti', 'soucasne' => $site->password]);
        $statuses[] = $right->status;
        $this->assertSame([403, 403, 200], $statuses, '3.3.3: a passkey challenge only with the current password');
        $this->assertSame(1, preg_match_all('/"challenge"/m', $right->body), '3.3.3: the challenge is in the answer');

        $site->exec("UPDATE ka_users SET email = 'autor-puvodni@example.cz', language = '' WHERE username = 'autor'");
        $site->exec("DELETE FROM ka_mail WHERE recipient = 'autor-puvodni@example.cz'");
        $post(['co' => 'profil', 'jmeno' => 'Autor', 'email' => 'utocnik@example.cz']);
        $post(['co' => 'profil', 'jmeno' => 'Autor', 'email' => 'utocnik@example.cz', 'soucasne' => 'wrong-password-1']);
        $this->assertSame('autor-puvodni@example.cz', $site->value("SELECT email FROM ka_users WHERE username = 'autor'"), '3.3.3: without the current password the e-mail stays');

        $post(['co' => 'profil', 'jmeno' => 'Autor-jmeno', 'email' => 'autor-puvodni@example.cz']);
        $this->assertSame('Autor-jmeno|autor-puvodni@example.cz', $site->value("SELECT CONCAT(name, '|', email) FROM ka_users WHERE username = 'autor'"),
            '3.3.3: other details save without the password while the e-mail stays the same');

        $post(['co' => 'profil', 'jmeno' => 'Autor', 'email' => 'autor-novy@example.cz', 'soucasne' => $site->password]);
        $this->assertSame('autor-novy@example.cz|1', $site->value("SELECT CONCAT((SELECT email FROM ka_users WHERE username = 'autor'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'autor-puvodni@example.cz' AND subject LIKE 'E-mail va%'))"),
            '3.3.3: with the current password the e-mail changes and the old address gets a notice');
    }
}
