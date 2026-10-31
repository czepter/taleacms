<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\Support\SiteTestCase;
use Talea\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/** The installer wizard: a step is checked when it is sent, a look card shows the real preview, the preview answers 404 for unknown keys, the last step installs. */
#[Group('site')]
final class InstallerWizardTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => [], 'enabledExtensions' => '', 'login' => false];
    }

    /** The installer deletes itself and writes config.php; put it back. */
    protected function setUp(): void
    {
        parent::setUp();
        @unlink($this->site()->path('config.php'));
        copy(dirname(__DIR__, 3) . '/install.php', $this->site()->path('install.php'));
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): string
    {
        return $this->site()->client('installer')->post('/install.php', ['language' => 'en', 'register' => 'informal'] + $fields)->body;
    }

    public function testStepIsCheckedWhenSent(): void
    {
        $body = $this->post(['step' => 'website', 'go' => 'next', 'site_name' => '', 'site_language' => 'en', 'time_zone' => 'Europe/London']);
        $this->assertStringContainsString('Fill in the site name.', $body, 'an empty site name is reported on the step');
        $this->assertStringContainsString('name="step" value="website"', $body, 'and the wizard stays on it');
        $body = $this->post(['step' => 'website', 'go' => 'next', 'site_name' => 'Acme', 'site_language' => 'en', 'time_zone' => 'Europe/London']);
        $this->assertStringContainsString('name="step" value="business"', $body, 'a valid step leads to the next one');
        $this->assertStringContainsString('name="site_name" value="Acme"', $body, 'and its answers travel in hidden fields');
    }

    public function testLookCardShowsThePreview(): void
    {
        $body = $this->post(['step' => 'look', 'blueprint' => 'restaurant', 'look' => 'noir', 'site_name' => 'Acme', 'site_language' => 'en']);
        $this->assertStringContainsString('name="step" value="look"', $body, 'a look card keeps the step');
        $this->assertStringContainsString('value="noir" formnovalidate aria-pressed="true"', $body, 'the pressed card is Noir');
        $this->assertStringContainsString('preview=noir', $body, 'the frame points at the preview of that look');
        $body = $this->post(['step' => 'look', 'go' => 'next', 'blueprint' => 'restaurant', 'look_kept' => 'brasserie', 'site_name' => 'Acme']);
        $this->assertStringContainsString('name="step" value="features"', $body);
        $this->assertStringContainsString('name="look" value="brasserie"', $body, 'the look travels on');
    }

    public function testPreviewIsTheRealHomePage(): void
    {
        $client = $this->site()->client('installer');
        $page = $client->get('/install.php?preview=harbor&language=en');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('/image/template.css', $page->body, 'rendered by the site\'s own pipeline');
        $this->assertStringContainsString('--tl-color-primary', $page->body, 'with the look\'s design tokens');
        $this->assertSame(404, $client->get('/install.php?preview=nope&language=en')->status);
        $this->assertSame(404, $client->get('/install.php?preview=harbor&language=zz')->status);
    }

    public function testLastStepInstalls(): void
    {
        $site = $this->site();
        $fields = ['step' => 'account', 'go' => 'next', 'site_name' => 'Wizard Ltd', 'site_language' => 'en', 'time_zone' => 'Europe/London', 'blueprint' => 'restaurant', 'look' => 'brasserie',
            'username' => 'wizard', 'name' => 'Wiz', 'email' => '', 'password' => $site->password, 'password2' => 'other-password', 'extensions' => ['news'],
            'db_driver' => TestDatabase::driver(), 'db_host' => TestDatabase::server()['host'], 'db_port' => TestDatabase::server()['port'], 'db_name' => $site->database . (TestDatabase::isSqlite() ? '.sqlite' : ''),
            'db_user' => TestDatabase::server()['username'], 'db_password' => TestDatabase::server()['password'], 'db_prefix' => 'wz_'];
        $this->assertStringContainsString('The passwords do not match.', $this->post($fields), 'the account step reports mismatching passwords');
        $this->assertStringContainsString('Done, your website is running', $this->post(['password2' => $site->password] + $fields), 'the last step installs');
        $this->assertSame('Wizard Ltd', $site->value("SELECT value FROM wz_settings WHERE name = 'site_name'"));
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM wz_collections WHERE preset = 'menu'"), 'the blueprint made its collections');
    }
}
