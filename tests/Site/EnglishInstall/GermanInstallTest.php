<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use Kaleta\Tests\Site\Support\CzechCheck;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** German installation through the web installer (2.5): German site, admin (du) and the Claude address. */
#[Group('site')]
final class GermanInstallTest extends SiteTestCase
{
    use CzechCheck;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'web' => 'business', 'language' => 'de', 'siteName' => 'Acme GmbH', 'doneText' => 'Fertig, deine Website läuft', 'extensions' => ['claude'], 'enabledExtensions' => 'claude',
            'installerFields' => ['register' => 'informal', 'site_language' => 'de', 'email' => 'office@example.com', 'name' => null]];
    }

    public function testFinishedScreen(): void
    {
        $done = $this->site()->installerResponse;
        $this->assertNoCzech($done->body, 'German installer: finished', true);
        $this->assertStringContainsString('Mit Claude aufbauen', $done->body, 'German installer: Claude hand-over');
        $this->assertStringContainsString('<code>' . $this->site()->base . '/mcp</code>', $done->body, 'German installer: Claude address on the last screen');
        $this->assertFileDoesNotExist($this->site()->path('install.php'), 'German installer: install.php deleted itself');
    }

    public function testAdminAndSiteAreGerman(): void
    {
        $site = $this->site();
        $this->assertSame('admin:de:de', $site->value("SELECT CONCAT(u.username, ':', u.language, ':', n.value) FROM ka_users u, ka_settings n WHERE n.name = 'site_language'"), 'the admin and the site are German');
        $this->assertSame('informal:informal', $site->value("SELECT CONCAT(u.register, ':', n.value) FROM ka_users u, ka_settings n WHERE n.name = 'german_register'"), 'the form of address (du) is saved for the admin and the site');
        $home = $site->client('visitor')->get('/');
        $this->assertStringContainsString('lang="de"', $home->body, 'the German home page is there');
        $this->assertStringContainsString('Acme GmbH', $home->body, 'the site name is on the home page');
    }

    public function testGermanAdminAfterTheInstallation(): void
    {
        $page = $this->assertCzechFree('/admin.php', 200, $this->site()->admin(), true, 'German admin after the installation');
        $this->assertStringContainsString('Einstellungen', $page->body, 'the first administrator sees the German admin');
        $this->assertStringContainsString('admin-de-du.js', $page->body, 'the first administrator (du) gets the script overlay');
    }
}
