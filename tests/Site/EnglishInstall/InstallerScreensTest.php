<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use Dom\HTMLDocument;
use Kaleta\Tests\Site\Support\CzechCheck;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The installer's screens in English and German: the form, a wrong database user, missing fields. */
#[Group('site')]
final class InstallerScreensTest extends SiteTestCase
{
    use CzechCheck;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => [], 'enabledExtensions' => '', 'login' => false];
    }

    /** The installer deletes itself and writes config.php; put it back the way the shell script did before the screens. */
    protected function setUp(): void
    {
        parent::setUp();
        @unlink($this->site()->path('config.php'));
        copy(dirname(__DIR__, 3) . '/install.php', $this->site()->path('install.php'));
    }

    public function testEnglishInstallerForm(): void
    {
        $this->assertCzechFree('/install.php?language=en', 200, label: 'installer');
    }

    public function testGermanInstallerForm(): void
    {
        $page = $this->assertCzechFree('/install.php?language=de', 200, german: true, label: 'German installer');
        $this->assertStringContainsString('Datenbank', $page->body, 'German installer: not in German');
    }

    public function testWrongDatabaseUserIsReportedInPlainWords(): void
    {
        $site = $this->site();
        $page = $site->client('installer')->post('/install.php', [
            'language' => 'en', 'db_host' => getenv('KALETA_TEST_DB_HOST'), 'db_port' => getenv('KALETA_TEST_DB_PORT'), 'db_name' => $site->database, 'db_user' => 'nosuchuser',
            'db_password' => 'wrong', 'db_prefix' => 'ka_', 'site_name' => 'Acme', 'starter' => 'business', 'username' => 'admin', 'email' => '', 'password' => $site->password, 'password2' => $site->password,
        ]);
        $this->assertNoCzech($page->body, 'installer: wrong database user');
        $field = HTMLDocument::createFromString($page->body, LIBXML_NOERROR)->querySelector('#db_user')?->parentNode?->textContent ?? '';
        $this->assertStringContainsString('user name or password', $field, 'a wrong database user is reported in plain words at the User field');
        $this->assertStringNotContainsString('SQLSTATE', $page->body, 'no SQL error code is shown');
    }

    public function testMissingFieldsAndPasswordsThatDoNotMatch(): void
    {
        $page = $this->site()->client('installer')->post('/install.php', ['language' => 'en', 'db_name' => '', 'db_user' => '', 'username' => 'admin', 'password' => $this->site()->password, 'password2' => 'other']);
        $this->assertNoCzech($page->body, 'installer: missing fields and passwords that do not match');
    }
}
