<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\SiteTestCase;
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

    private function login(Http $client, string $csrf, string $user, string $password): \Kaleta\Tests\Site\Support\Response
    {
        return $client->post('/admin.php', ['_csrf' => $csrf, 'username' => $user, 'password' => $password]);
    }

    private function message(\Kaleta\Tests\Site\Support\Response $response, string $pattern): string
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
            if (($cols[5] ?? '') === 'kaleta') {
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
        $idr = (int) $site->value('SELECT MAX(role_id) FROM ka_role');
        $admin->post('/admin.php?module=users&action=save', ['_csrf' => $csrf(), 'user_id' => 0, 'username' => 'salesperson', 'password' => $site->password, 'admin' => 'r' . $idr]);
        $admin->post('/admin.php?module=roles&action=save', ['_csrf' => $csrf(), 'role_id' => $idr, 'name' => 'Salesperson', 'level' => 1, 'modules' => ['enquiries']]);
        $this->assertSame('1:enquiries', (string) $site->value("SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.module)) FROM ka_users u JOIN ka_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'salesperson' GROUP BY u.user_id"), 'the role of the member');

        $token = 'kaleta_' . str_repeat('b', 48);
        $site->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM ka_users WHERE username = 'salesperson'", [hash('sha256', $token)]);
        $answer = (string) json_encode($site->mcp('create_news', ['title' => 'From salesperson', 'category' => 'news'], $token), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('no access to news', $answer, 'a custom role without News is refused over MCP');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_news WHERE title = 'From salesperson'"), 'a custom role without News creates no news over MCP');
    }

    #[Depends('testACustomRoleWithoutNewsCannotCreateNewsOverMcp')]
    public function testPasswordResetRevokesTheConnectionTokens(): void
    {
        $site = $this->site();
        $refresh = str_repeat('c', 64);
        $site->exec("UPDATE ka_users SET reset_token_hash = ?, reset_sent_at = NOW() + INTERVAL 1 DAY WHERE username = 'salesperson'", [hash('sha256', $refresh)]);
        $client = $site->client('reset');
        $csrf = $client->get('/admin.php?action=password&token=' . $refresh)->csrf();
        $client->post('/admin.php?action=password', ['_csrf' => $csrf, 'token' => $refresh, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD]);

        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_api_tokens t JOIN ka_users u ON u.user_id = t.user_id WHERE u.username = 'salesperson'"), 'a password reset revokes the connection tokens');
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
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'login'"); // the per-address limit is reached too – here the account lock alone is tested
        $locked = $this->login($client, $csrf, 'salesperson', self::NEW_PASSWORD);
        $this->assertSame(401, $locked->status, 'after 10 mistakes the account is temporarily locked even for the right password: status');
        $this->assertSame('1', (string) $site->value("SELECT locked_until > NOW() FROM ka_users WHERE username = 'salesperson'"), 'the account is locked until a later time');

        // 3.3.3 (N51): the locked account answers the right password exactly as any wrong password – no confirmation of the password
        $lockedMessage = $this->message($locked, self::LOCK_MESSAGE);
        $wrong = $this->message($this->login($client, $csrf, 'admin', 'wrong-for-admin-1'), self::ANY_MESSAGE);
        $nobody = $this->message($this->login($client, $csrf, 'nobody-like-that', 'whatever-12345'), self::ANY_MESSAGE);
        $site->exec("UPDATE ka_users SET locked_until = NULL, failed_logins = 0, blocked = 1 WHERE username = 'salesperson'");
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
    public function testSessionsEndAfterEightIdleHoursOrTwentyFourInTotal(): void
    {
        $site = $this->site();
        $client = $site->client('session');
        $csrf = $this->loginPage($client);

        // 3.3.3 (N61): the password is read as typed – spaces around it are part of it
        $site->exec("UPDATE ka_users SET blocked = 0, password = ? WHERE username = 'salesperson'", [password_hash(' Mezera-heslo-123 ', PASSWORD_DEFAULT)]);
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'login'");
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
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'login'");
    }
}
