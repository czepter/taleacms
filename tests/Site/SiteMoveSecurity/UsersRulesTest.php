<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Users follows My account: the own row has no password, e-mail or two-factor reset, and sensitive changes to others need fresh proof (#332). */
#[Group('site')]
final class UsersRulesTest extends SiteTestCase
{
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
}
