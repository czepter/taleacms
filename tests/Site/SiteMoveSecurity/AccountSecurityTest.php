<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: section 41 of tools/test.sh – a custom role over MCP, password reset, account lock, 3.3.3 sign-in rules. Starts anonymous.
 * Needs from section 28: the custom role "Salesperson" (level 1, only Enquiries) and its member "salesperson".
 */
#[Group('site')]
final class AccountSecurityTest extends SiteTestCase
{
    private const string NEW_PASSWORD = 'New-password-123';
    private const string LOCK_MESSAGE = '/Wrong user name or password, or the account is temporarily locked[^<]*/';
    private const string ANY_MESSAGE = '/Wrong user name or password[^<]*/';

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    private function loginPage(Http $client): string
    {
        return $client->get('/admin.php')->csrf();
    }

    private function login(Http $client, string $csrf, string $user, string $password): \Talea\Tests\Site\Support\Response
    {
        return $client->post('/admin.php', ['_csrf' => $csrf, 'username' => $user, 'password' => $password]);
    }

    private function message(\Talea\Tests\Site\Support\Response $response, string $pattern): string
    {
        return preg_match($pattern, $response->body, $m) === 1 ? $m[0] : '';
    }

    /** Moves a time stored in the session of the client back (the old session_shift). */
    private function shiftSession(Http $client, string $key, int $secondsBack): void
    {
        $jar = (string) (new \ReflectionProperty($client, 'jar'))->getValue($client);
        $id = '';
        foreach (file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $cols = explode("\t", $line);
            if (($cols[5] ?? '') === 'talea') {
                $id = $cols[6];
            }
        }
        $path = (string) ini_get('session.save_path');
        $path = substr($path, (int) strrpos($path, ';') + (str_contains($path, ';') ? 1 : 0));
        $file = ($path !== '' ? $path : sys_get_temp_dir()) . '/sess_' . $id;
        $this->assertFileExists($file, 'the session of the client is on disk');
        file_put_contents($file, preg_replace('/' . $key . '\|i:\d+;/', $key . '|i:' . (time() - $secondsBack) . ';', (string) file_get_contents($file)));
    }

    public function testACustomRoleWithoutNewsCannotCreateNewsOverMcp(): void
    {
        $site = $this->site();
        $site->signIn($site->admin());
        $admin = $site->admin();
        $csrf = fn (): string => $site->csrf();
        $admin->post('/admin.php?module=roles&action=save', ['_csrf' => $csrf(), 'role_id' => 0, 'name' => 'Salesperson', 'level' => 0, 'modules' => ['enquiries', 'collections']]);
        $idr = (int) $site->value('SELECT MAX(role_id) FROM tl_role');
        $admin->post('/admin.php?module=users&action=save', ['_csrf' => $csrf(), 'user_id' => 0, 'username' => 'salesperson', 'password' => $site->password, 'admin' => 'r' . $idr]);
        $admin->post('/admin.php?module=roles&action=save', ['_csrf' => $csrf(), 'role_id' => $idr, 'name' => 'Salesperson', 'level' => 1, 'modules' => ['enquiries']]);
        $this->assertSame('1:enquiries', (string) $site->value("SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.module)) FROM tl_users u JOIN tl_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'salesperson' GROUP BY u.user_id"), 'the role of the member');

        $token = 'talea_' . str_repeat('b', 48);
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tl_users WHERE username = 'salesperson'", [hash('sha256', $token)]);
        $answer = (string) json_encode($site->mcp('create_news', ['title' => 'From salesperson', 'category' => 'news'], $token), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('no access to news', $answer, 'a custom role without News is refused over MCP');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_news WHERE title = 'From salesperson'"), 'a custom role without News creates no news over MCP');
    }

    #[Depends('testACustomRoleWithoutNewsCannotCreateNewsOverMcp')]
    public function testPasswordResetRevokesTheConnectionTokens(): void
    {
        $site = $this->site();
        $refresh = str_repeat('c', 64);
        $site->exec("UPDATE tl_users SET reset_token_hash = ?, reset_sent_at = NOW() + INTERVAL 1 DAY WHERE username = 'salesperson'", [hash('sha256', $refresh)]);
        $client = $site->client('reset');
        $csrf = $client->get('/admin.php?action=password&token=' . $refresh)->csrf();
        $client->post('/admin.php?action=password', ['_csrf' => $csrf, 'token' => $refresh, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD]);

        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_api_tokens t JOIN tl_users u ON u.user_id = t.user_id WHERE u.username = 'salesperson'"), 'a password reset revokes the connection tokens');
    }

    #[Depends('testPasswordResetRevokesTheConnectionTokens')]
    public function testAPasswordSetByAnAdministratorRevokesPersonalTokensToo(): void
    {
        $site = $this->site();
        $token = 'talea_' . str_repeat('d', 48);
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'personal', ?, NOW() FROM tl_users WHERE username = 'salesperson'", [hash('sha256', $token)]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_api_tokens t JOIN tl_users u ON u.user_id = t.user_id WHERE u.username = 'salesperson' AND t.kind = 'token'"), 'the personal token is there');
        $site->admin()->post('/admin.php?module=users&action=save', ['_csrf' => $site->csrf(), 'user_id' => (string) $site->value("SELECT public_id FROM tl_users WHERE username = 'salesperson'"),
            'username' => 'salesperson', 'password' => self::NEW_PASSWORD, 'admin' => 'r' . $site->value('SELECT MAX(role_id) FROM tl_role')]);

        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_api_tokens t JOIN tl_users u ON u.user_id = t.user_id WHERE u.username = 'salesperson'"), 'a password an administrator sets revokes the personal tokens, not only the connections');
    }

    #[Depends('testPasswordResetRevokesTheConnectionTokens')]
    public function testTheAccountLocksAfterTenWrongPasswordsAndAnswersLikeAnyFailure(): void
    {
        $site = $this->site();
        $client = $site->client('lock');
        $csrf = $this->loginPage($client);
        for ($i = 0; $i < 10; $i++) {
            $this->login($client, $csrf, 'salesperson', 'wrong-password-xyz');
        }
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'"); // the per-address limit is reached too – here the account lock alone is tested
        $locked = $this->login($client, $csrf, 'salesperson', self::NEW_PASSWORD);
        $this->assertSame(401, $locked->status, 'after 10 mistakes the account is temporarily locked even for the right password: status');
        $this->assertSame('1', (string) $site->value("SELECT locked_until > NOW() FROM tl_users WHERE username = 'salesperson'"), 'the account is locked until a later time');

        // 3.3.3 (N51): the locked account answers the right password exactly as any wrong password – no confirmation of the password
        $lockedMessage = $this->message($locked, self::LOCK_MESSAGE);
        $wrong = $this->message($this->login($client, $csrf, 'admin', 'wrong-for-admin-1'), self::ANY_MESSAGE);
        $nobody = $this->message($this->login($client, $csrf, 'nobody-like-that', 'whatever-12345'), self::ANY_MESSAGE);
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0, blocked = 1 WHERE username = 'salesperson'");
        $blocked = $this->login($client, $csrf, 'salesperson', self::NEW_PASSWORD);
        $blockedMessage = $this->message($blocked, self::ANY_MESSAGE);

        $this->assertNotSame('', $lockedMessage, 'the locked account gets the common message');
        $this->assertSame($lockedMessage, $wrong, '3.3.3: a locked account and a wrong password get the same answer');
        $this->assertSame($wrong, $nobody, '3.3.3: a wrong password and an unknown name get the same answer');
        $this->assertSame($nobody, $blockedMessage, '3.3.3: a blocked account gets the same answer too');
        $this->assertSame(401, $blocked->status, '3.3.3: a blocked account: status');
        $this->assertStringContainsString('reset your password', $lockedMessage, '3.3.3: the answer offers the reset');
        $this->assertDoesNotMatchRegularExpression('/blocked/', $blocked->body, '3.3.3: a blocked account is not named as blocked');
    }

    #[Depends('testTheAccountLocksAfterTenWrongPasswordsAndAnswersLikeAnyFailure')]
    public function testALockedAccountStillOpensForABrowserThatSignedInBefore(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET blocked = 0, failed_logins = 0, locked_until = NULL WHERE username = 'salesperson'");
        $owner = $site->client('owner');
        $this->assertSame(302, $this->login($owner, $this->loginPage($owner), 'salesperson', self::NEW_PASSWORD)->status, 'the owner signs in');
        $device = $owner->cookies()['tl_device'] ?? '';
        $this->assertNotSame('', $device, 'a completed sign-in leaves the known-device cookie');
        $lock = static fn () => $site->exec("UPDATE tl_users SET failed_logins = 0, locked_until = NOW() + INTERVAL 10 MINUTE WHERE username = 'salesperson'");
        $try = function (string $cookie, string $password) use ($site) {
            $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
            $client = $site->client('stranger');
            $csrf = $this->loginPage($client);
            if ($cookie !== '') {
                $client->setCookie('tl_device', $cookie);
            }

            return $client->post('/admin.php', ['_csrf' => $csrf, 'username' => 'salesperson', 'password' => $password]);
        };

        $lock();
        $refused = $try('', self::NEW_PASSWORD);
        $this->assertSame(401, $refused->status, 'a browser without the cookie is refused while the account is locked');
        $this->assertSame($this->message($try('', 'wrong-password-xyz'), self::ANY_MESSAGE), $this->message($refused, self::ANY_MESSAGE), 'with the common failure answer');
        $lock();
        $this->assertSame(401, $try(bin2hex(random_bytes(32)), self::NEW_PASSWORD)->status, 'a cookie the server never issued is refused');
        $this->assertSame(401, $try(substr($device, 0, -4) . 'abcd', self::NEW_PASSWORD)->status, 'a cookie with an altered token is refused');
        $lock();
        $this->assertSame(401, $try($device, 'wrong-password-xyz')->status, 'a known browser with a wrong password is refused');
        $this->assertSame('1', (string) $site->value("SELECT failed_logins FROM tl_users WHERE username = 'salesperson'"), '... and it counts as a failure');
        $this->assertSame(302, $try($device, self::NEW_PASSWORD)->status, 'a known browser signs in with the right password while the account is locked');
        $this->assertNull($site->value("SELECT locked_until FROM tl_users WHERE username = 'salesperson'"), 'a completed sign-in ends the lock');
    }

    /** A sign-in from a stranger's browser that carries $cookie (if any) while the account is locked. */
    private function attemptLocked(string $cookie, string $password): \Talea\Tests\Site\Support\Response
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET failed_logins = 0, locked_until = NOW() + INTERVAL 10 MINUTE WHERE username = 'salesperson'");
        $client = $site->client('stranger');
        $csrf = $this->loginPage($client);
        if ($cookie !== '') {
            $client->setCookie('tl_device', $cookie);
        }

        return $client->post('/admin.php', ['_csrf' => $csrf, 'username' => 'salesperson', 'password' => $password]);
    }

    #[Depends('testALockedAccountStillOpensForABrowserThatSignedInBefore')]
    public function testAKnownDeviceStopsOpeningAfterFiveFailures(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET blocked = 0, failed_logins = 0, locked_until = NULL WHERE username = 'salesperson'");
        $owner = $site->client('owner');
        $this->login($owner, $this->loginPage($owner), 'salesperson', self::NEW_PASSWORD);
        $device = $owner->cookies()['tl_device'] ?? '';
        $failures = static fn (): string => (string) $site->value('SELECT failures FROM tl_user_devices WHERE token_hash = ?', [hash('sha256', $device)]);
        $this->assertSame('0', $failures(), 'a new known browser has no failures');

        // a stolen cookie guessing passwords: five wrong ones and it is a stranger like any other
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->attemptLocked($device, 'wrong-password-xyz')->status);
        }
        $this->assertSame('5', $failures(), 'every wrong password from the cookie is recorded on it');
        $this->assertSame(401, $this->attemptLocked($device, self::NEW_PASSWORD)->status, 'after five failures the right password no longer passes the lock');

        // a recorded failure count of five does the same, whatever caused it
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0 WHERE username = 'salesperson'");
        $again = $site->client('owner-again');
        $again->setCookie('tl_device', $device);
        $this->login($again, $this->loginPage($again), 'salesperson', self::NEW_PASSWORD);
        $fresh = $again->cookies()['tl_device'] ?? '';
        $this->assertNotSame($device, $fresh, 'a completed sign-in issues a new token');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_user_devices WHERE token_hash = ?', [hash('sha256', $device)]), '... and replaces the one this browser had');
        $this->assertSame(302, $this->attemptLocked($fresh, self::NEW_PASSWORD)->status, 'the new token opens a locked account');
        $site->exec('UPDATE tl_user_devices SET failures = 5 WHERE token_hash = ?', [hash('sha256', $fresh)]);
        $this->assertSame(401, $this->attemptLocked($fresh, self::NEW_PASSWORD)->status, 'five recorded failures end the exemption even with the right password');
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0 WHERE username = 'salesperson'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    #[Depends('testAKnownDeviceStopsOpeningAfterFiveFailures')]
    public function testANewPasswordEndsOtherKnownBrowsersButNotTheOneThatChangedIt(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $site->exec("UPDATE tl_users SET failed_logins = 0, locked_until = NULL WHERE username = 'salesperson'");
        $laptop = $site->client('laptop');
        $this->login($laptop, $this->loginPage($laptop), 'salesperson', self::NEW_PASSWORD);
        $phone = $site->client('phone');
        $this->login($phone, $this->loginPage($phone), 'salesperson', self::NEW_PASSWORD);
        [$laptopDevice, $phoneDevice] = [$laptop->cookies()['tl_device'], $phone->cookies()['tl_device']];
        $this->assertSame(302, $this->attemptLocked($phoneDevice, self::NEW_PASSWORD)->status, 'before the change a known browser opens a locked account');

        $account = $laptop->get('/admin.php?action=account');
        $laptop->post('/admin.php?action=account', ['_csrf' => $account->csrf(), 'op' => 'password', 'current_password' => self::NEW_PASSWORD,
            'new_password' => 'Other-password-456', 'new_password_again' => 'Other-password-456']);
        $reissued = $laptop->cookies()['tl_device'] ?? '';
        $this->assertNotSame($laptopDevice, $reissued, 'the browser that changed the password gets a new token');
        $this->assertSame(401, $this->attemptLocked($phoneDevice, 'Other-password-456')->status, 'another browser loses its exemption');
        $this->assertSame(401, $this->attemptLocked($laptopDevice, 'Other-password-456')->status, '... and so does the old token of this one');
        $this->assertSame(302, $this->attemptLocked($reissued, 'Other-password-456')->status, 'the browser that changed the password keeps its exemption');

        $site->exec("UPDATE tl_users SET password = ?, locked_until = NULL, failed_logins = 0 WHERE username = 'salesperson'", [password_hash(self::NEW_PASSWORD, PASSWORD_DEFAULT)]);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    #[Depends('testANewPasswordEndsOtherKnownBrowsersButNotTheOneThatChangedIt')]
    public function testSessionsEndAfterEightIdleHoursOrTwentyFourInTotal(): void
    {
        $site = $this->site();
        $client = $site->client('session');
        $csrf = $this->loginPage($client);

        // 3.3.3 (N61): the password is read as typed – spaces around it are part of it
        $site->exec("UPDATE tl_users SET blocked = 0, password = ? WHERE username = 'salesperson'", [password_hash(' Mezera-heslo-123 ', PASSWORD_DEFAULT)]);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $this->assertSame(302, $this->login($client, $csrf, 'salesperson', ' Mezera-heslo-123 ')->status, '3.3.3: a password with spaces around it signs in as typed');

        $keepAlive = $client->get('/admin.php?action=token');
        $this->assertSame(200, $keepAlive->status, '3.3.3: a fresh sign-in keeps the keep-alive working: status');
        $this->assertSame(1, substr_count($keepAlive->body, '"csrf"'), '3.3.3: a fresh sign-in keeps the keep-alive working: token');

        $this->shiftSession($client, 'last_seen', 8 * 3600 + 60);
        $idle = $client->get('/admin.php?action=token');
        $this->assertSame(200, $idle->status, '3.3.3: after 8 hours without a request: status');
        $this->assertSame(0, substr_count($idle->body, '"csrf"'), '3.3.3: after 8 hours without a request the keep-alive gives no token');
        $this->assertSame(1, substr_count($idle->body, 'name="password"'), '3.3.3: after 8 hours without a request the sign-in is over');

        $csrf = $idle->csrf();
        $this->login($client, $csrf, 'salesperson', ' Mezera-heslo-123 ');
        $this->shiftSession($client, 'login_at', 24 * 3600 + 60);
        $total = $client->get('/admin.php?action=token');
        $this->assertSame(0, substr_count($total->body, '"csrf"'), '3.3.3: 24 hours after signing in the keep-alive no longer works, however active the tab was');
        $this->assertSame(1, substr_count($total->body, 'name="password"'), '3.3.3: 24 hours after signing in the sign-in is over');
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    /** Drops the PHP session cookie of the client, as a closed browser does; the "stay signed in" cookie stays. */
    private function closeBrowser(Http $client): void
    {
        $jar = (string) (new \ReflectionProperty($client, 'jar'))->getValue($client);
        $lines = array_filter(file($jar, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => (explode("\t", $line)[5] ?? '') !== 'talea');
        file_put_contents($jar, implode("\n", $lines) . "\n");
    }

    #[Depends('testSessionsEndAfterEightIdleHoursOrTwentyFourInTotal')]
    public function testSignInByEmailAndStaySignedIn(): void
    {
        $site = $this->site();
        $password = ' Mezera-heslo-123 ';
        $tokens = static fn (): string => (string) $site->value("SELECT COUNT(*) FROM tl_user_remember_tokens r JOIN tl_users u ON u.user_id = r.user_id WHERE u.username = 'salesperson'");
        $site->exec("UPDATE tl_users SET email = 'sales@example.org' WHERE username = 'salesperson'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");

        $plain = $site->client('plain');
        $this->assertSame(302, $this->login($plain, $this->loginPage($plain), 'Sales@Example.org', $password)->status, 'the e-mail address signs in, in any case');
        $this->assertArrayNotHasKey('tl_remember', $plain->cookies(), 'without the tick there is no "stay signed in" cookie');
        $this->closeBrowser($plain);
        $this->assertSame(1, substr_count($plain->get('/admin.php')->body, 'name="password"'), 'without the tick a closed browser is signed out');

        $client = $site->client('remember');
        $signIn = $client->post('/admin.php', ['_csrf' => $this->loginPage($client), 'username' => 'sales@example.org', 'password' => $password, 'remember' => '1']);
        $this->assertSame(302, $signIn->status, 'sign-in by e-mail with "stay signed in"');
        $this->assertArrayHasKey('tl_remember', $client->cookies(), 'the "stay signed in" cookie is set');
        $this->assertSame('1', $tokens(), 'one token is stored');
        $this->assertNotSame(hash('sha256', $client->cookies()['tl_remember']), $client->cookies()['tl_remember'], 'the cookie is not its own hash');

        $this->closeBrowser($client);
        $this->assertSame(1, substr_count($client->get('/admin.php?action=token')->body, '"csrf"'), 'a closed browser comes back signed in');

        // logging out ends the token and the cookie
        $client->post('/admin.php?action=logout', ['_csrf' => $client->get('/admin.php')->csrf()]);
        $this->assertSame('0', $tokens(), 'signing out deletes the token');
        $this->assertArrayNotHasKey('tl_remember', $client->cookies(), 'signing out drops the cookie');

        // a new password ends every remembered sign-in of the account
        $client->post('/admin.php', ['_csrf' => $this->loginPage($client), 'username' => 'salesperson', 'password' => $password, 'remember' => '1']);
        $site->exec("UPDATE tl_users SET password = ? WHERE username = 'salesperson'", [password_hash(self::NEW_PASSWORD, PASSWORD_DEFAULT)]);
        $this->closeBrowser($client);
        $this->assertSame(1, substr_count($client->get('/admin.php')->body, 'name="password"'), 'after a new password the cookie no longer signs in');
        $this->assertSame('0', $tokens(), 'the stale token is deleted');

        // an address two accounts share signs neither in
        $site->exec("UPDATE tl_users SET email = 'sales@example.org' WHERE username = 'admin'");
        $shared = $site->client('shared');
        $this->assertSame(401, $this->login($shared, $this->loginPage($shared), 'sales@example.org', self::NEW_PASSWORD)->status, 'a shared e-mail address does not sign in');
        $site->exec("UPDATE tl_users SET email = '' WHERE username = 'admin'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    #[Depends('testSignInByEmailAndStaySignedIn')]
    public function testRememberedSignInsEndOnOtherDevices(): void
    {
        $site = $this->site();
        $tokens = static fn (): string => (string) $site->value("SELECT COUNT(*) FROM tl_user_remember_tokens r JOIN tl_users u ON u.user_id = r.user_id WHERE u.username = 'salesperson'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
        $signIn = function (string $name): Http {
            $client = $this->site()->client($name);
            $client->post('/admin.php', ['_csrf' => $this->loginPage($client), 'username' => 'salesperson', 'password' => self::NEW_PASSWORD, 'remember' => '1']);

            return $client;
        };
        $laptop = $signIn('laptop');
        $signIn('phone');
        $this->assertSame('2', $tokens(), 'two devices stay signed in');

        $account = $laptop->get('/admin.php?action=account&tab=password');
        $this->assertTrue($account->contains('name="op" value="remember_end"'), 'My account offers to end the other devices');
        $laptop->post('/admin.php?action=account', ['_csrf' => $account->csrf(), 'op' => 'remember_end']);
        $this->assertSame('1', $tokens(), 'ending the other devices keeps only this one');
        $this->assertArrayHasKey('tl_remember', $laptop->cookies(), 'this device keeps its cookie');
        $devices = $site->value("SELECT COUNT(*) FROM tl_user_devices d JOIN tl_users u ON u.user_id = d.user_id WHERE u.username = 'salesperson'");
        $this->assertSame('1', (string) $devices, 'ending the other devices also ends their known-device tokens');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_user_devices WHERE token_hash = ?', [hash('sha256', $laptop->cookies()['tl_device'])]), '... and this browser keeps its own');

        // turning on two-factor sign-in ends the remembered sign-ins elsewhere: they would skip the new code
        $signIn('tablet');
        $csrf = $laptop->get('/admin.php?action=account')->csrf();
        $laptop->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'totp_start', 'current_password' => self::NEW_PASSWORD]);
        $setup = $laptop->get('/admin.php?action=account&tab=sign-in');
        $this->assertSame(1, preg_match('/<code class="totp-key">([A-Z2-7 ]+)<\/code>/', $setup->body, $key), 'the setup shows the key');
        $code = trim($site->php('echo Talea\Core\Totp::code("' . str_replace(' ', '', $key[1]) . '", intdiv(time(), 30));'));
        $laptop->post('/admin.php?action=account', ['_csrf' => $setup->csrf(), 'op' => 'totp_confirm', 'code' => $code]);
        $this->assertNotSame('', (string) $site->value("SELECT totp_secret FROM tl_users WHERE username = 'salesperson'"), 'the form of My account turns two-factor sign-in on');
        $this->assertSame('1', $tokens(), 'turning on two-factor sign-in ends the other remembered devices');
        $site->exec("UPDATE tl_users SET totp_secret = '', totp_backup_codes = NULL WHERE username = 'salesperson'");
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'login'");
    }

    public function testResetMailsAreLimitedPerAccount(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_users SET email = 'sales@example.test' WHERE username = 'salesperson'");
        $mails = fn (): string => (string) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'sales@example.test'");
        $before = (int) $mails();
        $client = $site->client('reset-flood');
        $hour = intdiv(time(), 3600);
        $answers = [];
        $tokens = [];
        // user name and e-mail take turns: both name the same account and share its allowance
        foreach (['salesperson', 'sales@example.test', 'salesperson', 'sales@example.test'] as $who) {
            $csrf = $client->get('/admin.php?action=password')->csrf();
            $answer = $client->post('/admin.php?action=password', ['_csrf' => $csrf, 'user_id' => $who]);
            $answers[] = [$answer->status, $answer->contains('If such an account exists')];
            $tokens[] = (string) $site->value("SELECT reset_token_hash FROM tl_users WHERE username = 'salesperson'");
        }
        if (intdiv(time(), 3600) !== $hour) {
            $this->markTestSkipped('the requests fell into two counting windows');
        }

        $this->assertSame(array_fill(0, 4, [200, true]), $answers, 'reset: every request gets the same answer, past the account limit too');
        $this->assertSame(3, (int) $mails() - $before, 'reset: at most 3 mails per account per hour, from the user name and the e-mail together');
        $this->assertSame([false, true], [$tokens[1] === $tokens[2], $tokens[2] === $tokens[3]], 'reset: past the limit the last link sent stays valid');

        $site->admin()->post('/admin.php?module=users&action=password_link', ['_csrf' => $site->csrf(),
            'user_id' => (string) $site->value("SELECT public_id FROM tl_users WHERE username = 'salesperson'")]);
        $this->assertSame(4, (int) $mails() - $before, 'reset: a link an administrator sends is not limited');
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'reset'");
    }
}
