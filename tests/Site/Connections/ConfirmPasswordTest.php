<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/** A wrong current password counts like a wrong sign-in: ten in a row lock confirming for 15 minutes, the person stays signed in (#332). */
#[Group('site')]
final class ConfirmPasswordTest extends SiteTestCase
{
    use ConnectionHelpers;

    private const string URL = '/admin.php?action=account';

    private static ?Http $person = null;

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    private function person(): Http
    {
        if (self::$person === null) {
            $site = $this->site();
            $site->signIn($site->admin());
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Confirmer', 'username' => 'confirmer', 'password' => $site->password, 'admin' => 1]);
            self::$person = $site->client('confirmer');
            $site->signIn(self::$person, 'confirmer');
        }

        return self::$person;
    }

    private function failures(): int
    {
        return (int) $this->site()->value("SELECT failed_logins FROM tl_users WHERE username = 'confirmer'");
    }

    private function newToken(string $password): string
    {
        $person = $this->person();

        return $person->post(self::URL, ['_csrf' => $person->get(self::URL)->csrf(), 'op' => 'token_new', 'name' => 'T', 'current_password' => $password])->body;
    }

    public function testWrongCurrentPasswordsAreCounted(): void
    {
        $this->person();
        for ($i = 1; $i <= 3; $i++) {
            $this->assertDoesNotMatchRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken('wrong ' . $i));
            $this->assertSame($i, $this->failures(), 'every wrong password counts');
        }
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($this->site()->password), 'the right password creates the token');
        $this->assertSame(0, $this->failures(), 'the right one ends the series');
    }

    public function testTenWrongPasswordsLockConfirmingButNotTheSession(): void
    {
        $site = $this->site();
        $person = $this->person();
        for ($i = 0; $i < 10; $i++) {
            $this->newToken('wrong');
        }
        $this->assertNotNull($site->value("SELECT locked_until FROM tl_users WHERE username = 'confirmer'"), 'the account is locked');
        $this->assertDoesNotMatchRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($site->password), 'while locked even the right password is refused');
        $this->assertStringContainsString('"csrf"', $person->get('/admin.php?action=token')->body, 'the person stays signed in');
        $this->assertStringContainsString('confirming is locked', $person->get(self::URL)->body);
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0 WHERE username = 'confirmer'");
    }

    public function testAnAccountLockedByStrangersStillConfirmsFromItsKnownDevice(): void
    {
        $site = $this->site();
        $this->person();
        $site->exec("UPDATE tl_user_devices SET failures = 0 WHERE user_id = (SELECT user_id FROM tl_users WHERE username = 'confirmer')"); // the earlier tests guessed from this browser
        // ten wrong passwords typed at the public sign-in page lock the account (set directly: the per-address limit would stop the loop)
        $site->exec("UPDATE tl_users SET locked_until = NOW() + INTERVAL 15 MINUTE, failed_logins = 10 WHERE username = 'confirmer'");
        $this->assertMatchesRegularExpression('/talea_[a-f0-9]{48}/', $this->newToken($site->password), 'the signed-in browser is a known device: the right password confirms');
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0 WHERE username = 'confirmer'");
    }

    public function testWrongPasswordInTheOAuthConsentCounts(): void
    {
        $site = $this->site();
        $person = $this->person();
        $person->get('/oauth/authorize?response_type=code&client_id=' . $this->registerClient() . '&redirect_uri=' . self::REDIRECT_URI
            . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=c');
        $page = $person->get('/admin.php?action=oauth');
        $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1', 'current_password' => 'wrong']);
        $this->assertSame(1, $this->failures(), 'a wrong password in the consent counts');
        $redirect = $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1', 'current_password' => $site->password])->redirect;
        $this->assertStringContainsString('code=', $redirect);
    }
}
