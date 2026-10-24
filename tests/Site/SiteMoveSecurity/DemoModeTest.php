<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: tools/test-demo.sh – the public demo (2.6, docs/DEMO.md): the site is switched to demo mode in config.php, snapshotted,
 * changed through the admin and reset. Checks the shared sign-in, what the demo refuses and that the reset brings everything back.
 * The shared account is the harness administrator. Starts anonymous: every request goes through the visitor's own browser.
 */
#[Group('site')]
final class DemoModeTest extends SiteTestCase
{
    private static ?Http $visitor = null;

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    public static function tearDownAfterClass(): void
    {
        self::$visitor = null;
        parent::tearDownAfterClass();
    }

    /** Runs php system/demo.php <command> in the site; returns [exit code, output]. @return array{int, string} */
    private function demo(string $command): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->site()->path('system/demo.php')) . ' ' . $command . ' 2>&1 < /dev/null', $output, $code);

        return [$code, implode("\n", $output)];
    }

    /** The visitor signed in with the shared account. */
    private function visitor(): Http
    {
        if (self::$visitor === null) {
            self::$visitor = $this->site()->client('demo');
            $this->site()->signIn(self::$visitor);
        }

        return self::$visitor;
    }

    /** POST as the demo visitor with a fresh anti-forgery token. @param array<string, mixed> $fields */
    private function post(string $path, array $fields = []): \Talea\Tests\Site\Support\Response
    {
        return $this->visitor()->post($path, ['_csrf' => $this->site()->csrf($this->visitor())] + $fields);
    }

    private function backups(): int
    {
        return count(glob($this->site()->path('storage/backups') . '/*') ?: []);
    }

    public function testTheDemoIsSwitchedOnAndSnapshotted(): void
    {
        $site = $this->site();
        $file = $site->path('config.php');
        $config = require $file;
        $config['demo'] = ['user' => 'admin', 'password' => $site->password]; // the shared account, as docs/DEMO.md writes it
        file_put_contents($file, "<?php\nreturn " . var_export($config, true) . ";\n");
        // 3.2: features are chosen by whoever runs the demo before the snapshot (visitors cannot switch them) – here Bookings
        $site->setting('extensions', 'bookings');

        [$code, $output] = $this->demo('snapshot');
        $this->assertSame(0, $code, 'snapshot: ' . $output);
        $this->assertStringContainsString('Snapshot saved', $output, 'the snapshot is saved');
    }

    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testVisitorsSeeTheDemo(): void
    {
        $site = $this->site();
        $guest = $site->client('guest');
        $this->assertPage('/admin.php', 200, ['Sign in as admin with the password ' . $site->password, 'value="' . $site->password . '"'], $guest, 'the sign-in page shows and fills in the shared account');

        $home = $this->assertPage('/', 200, 'Talea demo – try the admin', $guest, 'public pages carry the demo badge');
        $this->assertStringStartsWith('noindex', $home->headers['x-robots-tag'] ?? '', 'public pages: X-Robots-Tag noindex');
        $this->assertMatchesRegularExpression('/^Disallow: \/$/m', $guest->get('/robots.txt')->body, 'robots.txt disallows everything');
        $this->assertSame(403, $guest->post('/mcp', '{}', ['Content-Type: application/json'])->status, 'the Claude connection is off');
    }

    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testTheDemoRefusesAccountsAndCode(): void
    {
        $site = $this->site();
        $this->assertPage('/admin.php?module=pages', 200, 'Public demo: everything you change here is reset in', $this->visitor(), 'the admin shows the reset notice');

        $this->post('/admin.php?module=users&action=save', ['username' => 'intruder', 'password' => 'Intruder-2026-x', 'admin' => 2]);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_users WHERE username = 'intruder'"), 'no new users');

        $hash = (string) $site->value("SELECT password FROM tl_users WHERE username = 'admin'");
        $this->post('/admin.php?action=account', ['op' => 'password', 'current_password' => $site->password, 'new_password' => 'Taken-over-2026', 'new_password_again' => 'Taken-over-2026']);
        $this->assertSame($hash, (string) $site->value("SELECT password FROM tl_users WHERE username = 'admin'"), 'the shared password cannot be changed');
        $other = $site->client('other');
        $site->signIn($other);
        $this->assertStringContainsString('/admin/pages', $other->get('/admin.php?module=pages')->body, 'another visitor still signs in with the shared password');

        $this->post('/admin.php?module=settings&action=save', ['tab' => 'analytics', 'head_code' => '<script>alert(1)</script>', 'stats' => 1]);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_settings WHERE name = 'head_code' AND value LIKE '%alert%'"), 'no code fields');

        $this->post('/admin.php?module=settings&action=save', ['tab' => 'mail', 'mail_mode' => 'smtp', 'smtp_host' => 'evil.example']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_settings WHERE name = 'smtp_host' AND value = 'evil.example'"), 'mail settings stay');
    }

    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testFeaturesComeFromTheSnapshotOnly(): void
    {
        $this->assertSame(200, $this->visitor()->get('/admin.php?module=bookings')->status, 'the features of the snapshot show (Bookings)');
        $this->post('/admin.php?module=extensions&action=save', ['tab' => 'extensions', 'extensions' => ['bookings', 'claude']]);
        $this->assertSame('bookings', $this->site()->settingValue('extensions'), 'visitors cannot switch features');
    }

    /** 3.3.2 (N24): the screens built on Settings (System status, Claude settings, Features, Business details) inherit its actions – the demo filters by the class. */
    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testNoSettingsScreenHandlesBackupsOrPairs(): void
    {
        $site = $this->site();
        $this->assertSame(302, $this->visitor()->get('/admin.php?module=settings&action=download_backup&file=x')->status, 'backups cannot be downloaded');

        $before = $this->backups();
        foreach (['status', 'claude_settings', 'extensions', 'business', 'settings'] as $module) {
            $this->post("/admin.php?module=$module&action=backup");
        }
        $this->assertSame($before, $this->backups(), 'no Settings screen makes a backup');

        foreach (['status', 'claude_settings', 'extensions', 'business'] as $module) {
            $this->assertSame(302, $this->visitor()->get("/admin.php?module=$module&action=download_backup&file=x")->status, "no Settings screen hands out a backup ($module)");
        }

        $pair = $this->post('/admin.php?module=status&action=fleet_pair', ['pairing_key' => 'x']);
        $this->assertStringContainsString('switched off in the public demo', $this->visitor()->get(str_replace($site->base, '', $pair->redirect))->body, 'System status cannot pair with a console');
        $this->post('/admin.php?module=claude_settings&action=save', ['claude_instructions' => 'Injected']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_settings WHERE name = 'claude_instructions' AND value = 'Injected'"), 'Claude settings cannot be saved');
    }

    /** The settings the demo saves are an allow-list: a script host, a tag manager and maintenance mode stay as they are. */
    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testTheDemoSavesOnlyAllowedSettings(): void
    {
        $site = $this->site();
        $this->post('/admin.php?module=settings&action=save', ['tab' => 'analytics', 'matomo_url' => 'https://evil.example/', 'matomo_id' => 1, 'gtm_id' => 'GTM-EVIL1', 'ga4_id' => 'G-DEMO1234']);
        $this->post('/admin.php?module=settings&action=save', ['tab' => 'general', 'maintenance' => 1, 'site_name' => 'Demo']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_settings WHERE (name IN ('matomo_url', 'gtm_id') AND value <> '') OR (name = 'maintenance' AND value = '1')"), 'no Matomo, GTM or maintenance');
        $this->assertSame('G-DEMO1234', $site->settingValue('ga4_id'), 'GA4 is allowed');
    }

    #[Depends('testTheDemoIsSwitchedOnAndSnapshotted')]
    public function testTheResetBringsBackTheDatabaseAndMedia(): void
    {
        $site = $this->site();
        $this->post('/admin.php?module=settings&action=save', ['tab' => 'general', 'site_name' => 'Changed by a visitor']);
        $this->assertSame('Changed by a visitor', $site->settingValue('site_name'), 'visitors can change the site');
        $site->exec("UPDATE tl_pages SET title = 'Defaced' WHERE page_id = ?", [(int) $site->value('SELECT MIN(page_id) FROM tl_pages')]);
        file_put_contents($site->path('media/visitor.txt'), 'visitor upload');

        [$code, $output] = $this->demo('reset');
        $this->assertSame(0, $code, 'reset: ' . $output);
        $this->assertNotSame('Changed by a visitor', $site->settingValue('site_name'), 'the reset brings back the settings');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_pages WHERE title = 'Defaced'"), 'the reset brings back the pages');
        $this->assertFileDoesNotExist($site->path('media/visitor.txt'), 'the reset brings back the media');
        $this->assertStringContainsString('/admin/pages', $this->visitor()->get('/admin.php?module=pages')->body, 'the admin works after the reset');
        $this->assertNotSame(200, $site->client('web')->get('/system/demo.php')->status, 'demo.php does not run from the web');
    }
}
