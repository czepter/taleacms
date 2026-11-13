<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/** Users follows My account: the own row has no password, e-mail or two-factor reset, and sensitive changes to others need fresh proof (#332). */
#[Group('site')]
final class UsersRulesTest extends SiteTestCase
{
    /** An administrator whose sign-in was restored from "stay signed in": signed in, with no proof recorded in the session. */
    private function staleAdmin(): Http
    {
        $site = $this->site();
        $browser = $site->client('stale-admin');
        $csrf = $browser->get('/admin.php')->csrf();
        $browser->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => $site->password, 'remember' => '1']);
        $browser->dropCookie('talea');
        $this->assertStringContainsString('"csrf"', $browser->get('/admin.php?action=token')->body, 'the sign-in is restored');

        return $browser;
    }

    /** Posts the user form of $username as $as with $fields on top of what the person has now. @param array<string, mixed> $fields */
    private function saveUser(Http $as, string $username, array $fields): Response
    {
        $row = $this->site()->rows('SELECT public_id, name, email, admin FROM tl_users WHERE username = ?', [$username])[0];
        $csrf = $as->get('/admin.php?module=users&action=edit&id=' . $row['public_id'])->csrf();

        return $as->post('/admin.php?module=users&action=save', $fields + ['_csrf' => $csrf, 'user_id' => $row['public_id'], 'username' => $username, 'name' => $row['name'], 'email' => $row['email'], 'admin' => $row['admin']]);
    }

    private function person(string $username, int $level = 0): void
    {
        if ((int) $this->site()->value('SELECT COUNT(*) FROM tl_users WHERE username = ?', [$username]) === 0) {
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => ucfirst($username), 'username' => $username, 'email' => $username . '@example.test', 'password' => $this->site()->password, 'admin' => $level]);
        }
    }

    private function adminId(): string
    {
        return (string) $this->site()->value("SELECT public_id FROM tl_users WHERE username = 'admin'");
    }

    public function testTheOwnRowHasNoPasswordEmailOrTwoFactorReset(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_users SET totp_secret = 'JBSWY3DPEHPK3PXP' WHERE username = 'admin'");
        $email = (string) $site->value("SELECT email FROM tl_users WHERE username = 'admin'");
        $hash = (string) $site->value("SELECT password FROM tl_users WHERE username = 'admin'");

        $form = $this->assertPage('/admin.php?module=users&action=edit&id=' . $this->adminId(), 200, 'Change it in My account');
        $this->assertStringNotContainsString('name="password"', $form->body);
        $this->assertStringNotContainsString('name="totp_reset"', $form->body);
        $this->assertStringNotContainsString('name="email"', $form->body);

        $this->adminPost('/admin.php?module=users&action=save', [
            'user_id' => $this->adminId(), 'name' => 'Admin', 'username' => 'admin', 'email' => 'evil@example.test',
            'password' => 'another-long-password', 'totp_reset' => '1', 'admin' => 2,
        ]);
        $this->assertSame($email, (string) $site->value("SELECT email FROM tl_users WHERE username = 'admin'"), 'the e-mail is unchanged');
        $this->assertSame($hash, (string) $site->value("SELECT password FROM tl_users WHERE username = 'admin'"), 'the password is unchanged');
        $this->assertSame('JBSWY3DPEHPK3PXP', (string) $site->value("SELECT totp_secret FROM tl_users WHERE username = 'admin'"), 'two-factor sign-in is unchanged');
        $site->exec("UPDATE tl_users SET totp_secret = '' WHERE username = 'admin'");
    }

    public function testSensitiveChangesNeedAFreshProof(): void
    {
        $site = $this->site();
        $this->person('other');
        $stale = $this->staleAdmin();
        $hash = (string) $site->value("SELECT password FROM tl_users WHERE username = 'other'");

        $refused = $this->saveUser($stale, 'other', ['password' => 'a-new-long-password']);
        $this->assertSame($hash, (string) $site->value("SELECT password FROM tl_users WHERE username = 'other'"), 'no new password without proof');
        $this->assertStringContainsString('Confirm the change with your password or your passkey.', $refused->body);

        $this->saveUser($stale, 'other', ['password' => 'a-new-long-password', 'current_password' => 'wrong']);
        $this->assertSame($hash, (string) $site->value("SELECT password FROM tl_users WHERE username = 'other'"), 'a wrong password proves nothing');
        $this->saveUser($stale, 'other', ['password' => 'a-new-long-password', 'current_password' => $site->password]);
        $this->assertNotSame($hash, (string) $site->value("SELECT password FROM tl_users WHERE username = 'other'"), 'with the password it is saved');

        $this->saveUser($stale, 'other', ['email' => 'changed@example.test']);
        $this->assertSame('other@example.test', (string) $site->value("SELECT email FROM tl_users WHERE username = 'other'"), 'no new e-mail without proof');
        $this->saveUser($stale, 'other', ['email' => 'changed@example.test', 'current_password' => $site->password]);
        $this->assertSame('changed@example.test', (string) $site->value("SELECT email FROM tl_users WHERE username = 'other'"));

        $this->saveUser($stale, 'other', ['admin' => 2]);
        $this->assertSame('0', (string) $site->value("SELECT admin FROM tl_users WHERE username = 'other'"), 'no new administrator without proof');
        $this->saveUser($stale, 'other', ['admin' => 2, 'current_password' => $site->password]);
        $this->assertSame('2', (string) $site->value("SELECT admin FROM tl_users WHERE username = 'other'"));
        $this->saveUser($stale, 'other', ['admin' => 0, 'current_password' => $site->password]);

        $this->saveUser($stale, 'other', ['name' => 'Other Person']);
        $this->assertSame('Other Person', (string) $site->value("SELECT name FROM tl_users WHERE username = 'other'"), 'a harmless change needs no proof');
    }

    public function testARecentSignInCounts(): void
    {
        $site = $this->site();
        $this->person('recent');
        $this->saveUser($site->admin(), 'recent', ['email' => 'recent-new@example.test']);
        $this->assertSame('recent-new@example.test', (string) $site->value("SELECT email FROM tl_users WHERE username = 'recent'"), 'the administrator signed in a moment ago');
    }

    public function testARoleWithEverySectionNeedsAFreshProof(): void
    {
        $site = $this->site();
        $stale = $this->staleAdmin();
        $csrf = $stale->get('/admin.php?module=roles&action=new')->csrf();
        $all = array_keys(\Talea\Admin\Modules\Roles::configurable());
        $post = static fn (array $extra) => $stale->post('/admin.php?module=roles&action=save', $extra + ['_csrf' => $csrf, 'role_id' => 0, 'name' => 'Everything', 'level' => 0, 'modules' => $all]);
        $post([]);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_role WHERE name = 'Everything'"), 'no role with every section without proof');
        $post(['current_password' => $site->password]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_role WHERE name = 'Everything'"));
        $csrf = $stale->get('/admin.php?module=roles&action=new')->csrf();
        $stale->post('/admin.php?module=roles&action=save', ['_csrf' => $csrf, 'role_id' => 0, 'name' => 'Small', 'level' => 0, 'modules' => ['news']]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_role WHERE name = 'Small'"), 'a role with a few sections needs no proof');
    }

    /** Posts the General tab as $as with the field values it shows and $fields on top. @param array<string, mixed> $fields */
    private function saveGeneral(Http $as, array $fields): void
    {
        $site = $this->site();
        $csrf = (string) ($as->get('/admin.php?action=token')->json()['csrf'] ?? '');
        $as->post('/admin.php?module=settings&action=save', $fields + ['_csrf' => $csrf, 'tab' => 'general', 'site_name' => $site->settingValue('site_name'), 'site_url' => $site->settingValue('site_url')]);
    }

    public function testWeakeningTwoFactorNeedsAFreshProof(): void
    {
        $site = $this->site();
        $stale = $this->staleAdmin();
        $site->exec("UPDATE tl_users SET totp_secret = 'JBSWY3DPEHPK3PXP' WHERE username = 'admin'"); // the requirement does not send the administrator away
        $site->setting('require_2fa', 'everyone');
        $this->saveGeneral($stale, ['require_2fa' => '']);
        $this->assertSame('everyone', $site->settingValue('require_2fa'), 'weakening without proof is not saved');
        $this->saveGeneral($stale, ['require_2fa' => '', 'current_password' => $site->password]);
        $this->assertSame('', $site->settingValue('require_2fa'), 'with the password it is');
        $this->saveGeneral($stale, ['require_2fa' => 'admins']);
        $this->assertSame('admins', $site->settingValue('require_2fa'), 'tightening needs no proof');
        $site->setting('require_2fa', '');
        $site->exec("UPDATE tl_users SET totp_secret = '' WHERE username = 'admin'");
    }
}
