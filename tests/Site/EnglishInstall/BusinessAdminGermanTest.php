<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\Support\CzechCheck;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The German admin (formal, then informal "du") of the business starter: the same screens, no Czech. */
#[Group('site')]
final class BusinessAdminGermanTest extends SiteTestCase
{
    use BusinessInstall;
    use CzechCheck;

    public function testFormalGermanAdmin(): void
    {
        $this->site()->exec("UPDATE tl_users SET language = 'de' WHERE username = 'admin'");
        $last = null;
        foreach ($this->adminScreens() as $query) {
            $last = $this->assertCzechFree("/admin.php?$query", 200, $this->site()->admin(), true, 'German admin');
        }
        $this->assertStringContainsString('Einstellungen', (string) $last?->body, 'German admin: the settings screen is in German');
        $this->assertStringContainsString('So funktioniert es', (string) $last?->body, 'German admin: the guide link is in German');
    }

    #[Depends('testFormalGermanAdmin')]
    public function testInformalGermanAdmin(): void
    {
        $this->site()->exec("UPDATE tl_users SET register = 'informal' WHERE username = 'admin'");
        $last = null;
        foreach ($this->adminScreens() as $query) {
            $last = $this->assertCzechFree("/admin.php?$query", 200, $this->site()->admin(), true, 'German informal admin');
        }
        $this->assertStringContainsString('admin-de-du.js', (string) $last?->body, 'informal German admin: the script overlay admin-de-du.js is loaded');
        $mail = $this->site()->admin()->get('/admin.php?module=settings&tab=mail')->body;
        $this->assertDoesNotMatchRegularExpression('/(Geben|Wählen|Tragen|Speichern|Verwenden|Prüfen|Klicken) Sie /', $mail, 'informal German admin: a formal imperative (Sie) is still in the mail settings');
    }
}
