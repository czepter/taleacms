<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Without TALEA_SECRET_KEY nothing is sealed, so a typed value that looks sealed is refused: stored plain it would read back as empty (#335). */
#[Group('site')]
final class SealedSecretsWithoutKeyTest extends SiteTestCase
{
    public function testATypedSecretThatStartsWithTheMarkerIsRefusedWithoutAnInstallKey(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'smtp_password' => 'kept-password']);
        $this->assertSame('kept-password', $site->settingValue('smtp_password'), 'without a key a normal secret is stored as typed');

        $answer = $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'smtp_password' => 'sealed:typed-secret']);
        $this->assertSame('kept-password', $site->settingValue('smtp_password'), 'the refused value is not stored');
        $page = $site->admin()->get('/admin.php?module=settings&tab=mail');
        $this->assertStringContainsString('invalid format', $answer->body . $page->body, 'the field is reported as invalid');
    }
}
