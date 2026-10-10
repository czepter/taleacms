<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Add-on API 2 (issue #27) through the example add-on docs/examples/extensions/guard (early hook, table with migrations, health row,
 * hand-over finding, event type with an alert, declared settings, cron-only job) and small add-ons written here for the failures.
 * The v1 example `hello` is covered unchanged by AddonsExtensionApiTest. The tests of the class run in order.
 */
#[Group('site')]
final class AddonsApi2Test extends SiteTestCase
{
    use AgentHelpers;

    private function extension(string $slug, array $manifest, string $code): void
    {
        $dir = $this->site()->path('extensions/' . $slug);
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/extension.json', (string) json_encode($manifest));
        file_put_contents($dir . '/Extension.php', $code);
    }

    private function toggle(string $slug, int $on, bool $trust = true): void
    {
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => $slug, 'on' => $on] + ($trust ? ['trust' => 1] : []));
    }

    private function enabled(): string
    {
        return $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_enabled'");
    }

    private function tableExists(string $table): bool
    {
        return $this->sq('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?', [$table]) === '1';
    }

    public function testTheListShowsWhatAnAddonDeclaresBeforeItIsSwitchedOn(): void
    {
        $guard = $this->site()->path('extensions/guard');
        mkdir($guard . '/migrations', 0775, true);
        $source = dirname(__DIR__, 3) . '/docs/examples/extensions/guard';
        foreach (['extension.json', 'Extension.php', 'migrations/0001-create-log.sql', 'migrations/0002-add-note.sql'] as $file) {
            copy($source . '/' . $file, $guard . '/' . $file);
        }
        $manifest = (string) file_get_contents($guard . '/extension.json');
        file_put_contents($guard . '/extension.json', str_replace('">=3.0"', '">=2.0"', $manifest));

        $page = $this->assertPage('/admin.php?module=addons', 200, ['Guard', 'It declares that it:', 'runs on every public request before anything else and may refuse it', 'creates and keeps its own database tables'], message: 'API 2: capabilities are shown before switching on');
        $this->assertSame(2, substr_count($page->body, 'Official add-on'), 'only the bundled Domain watch and Firewall are official, a folder copied in is not');
        $this->assertFalse($this->tableExists('tl_ext_guard_log'), 'nothing is created before the add-on is switched on');
    }

    public function testASwitchedOnApi2AddonRunsItsMigrationsAndRefusesARequestEarly(): void
    {
        $this->toggle('guard', 1, trust: false);
        $this->assertSame('', $this->enabled(), 'the trust tick is still needed');

        $this->toggle('guard', 1);
        $this->assertSame('guard', $this->enabled(), 'switched on');
        $this->assertTrue($this->tableExists('tl_ext_guard_log'), 'migration 0001 created the table');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'tl_ext_guard_log' AND column_name = 'note'"), 'migration 0002 changed it');
        $this->assertSame('2', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_migrated.guard'"), 'the number of the last migration is kept');

        $visitor = $this->site()->client('visitor');
        $refused = $visitor->get('/guard-blocked');
        $this->assertSame(403, $refused->status, 'the early hook refused the request');
        $this->assertStringContainsString('Refused by the guard add-on.', $refused->body, 'with its own answer');
        $this->assertSame(200, $visitor->get('/')->status, 'other requests go on');
        $this->assertSame('1', $this->sq('SELECT COUNT(*) FROM tl_ext_guard_log'), 'the refusal was logged in the add-on\'s table');
        $this->assertSame('', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_error.guard'"), 'no error');
    }

    public function testACronOnlyJobDoesNotRunOnAVisitButDoesFromCron(): void
    {
        $visitor = $this->site()->client('visitor');
        foreach (['/', '/contact', '/'] as $path) {
            $visitor->get($path);
        }
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_jobs WHERE name = 'ext_guard_cleanup'"), 'a visit does not run a cron-only job');
        $status = $this->assertPage('/admin.php?module=status', 200, ['Guard: delete old log rows', 'Refused requests', 'Guard'], message: 'System status lists the job and the add-on\'s row');
        $this->assertStringContainsString('only from cron', $status->body, 'the job says it runs only from cron');

        $this->site()->runTasks();
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_jobs WHERE name = 'ext_guard_cleanup' AND last_ok IS NOT NULL"), 'cron runs it');
    }

    public function testAHandoverFindingAndTheHealthRowComeFromTheAddon(): void
    {
        $audit = $this->mcpText('site_audit', ['kind' => 'handover']);
        $this->assertMatchesRegularExpression('/"handover": ?"guard\.refused"/', $audit, 'the add-on\'s finding is among the hand-over findings, keyed by its slug');
        $this->assertStringContainsString('The guard refused requests', $audit);
        $this->assertStringContainsString('1 in the log', (string) $this->site()->admin()->get('/admin.php?module=status')->body, 'System status has the add-on\'s row');
    }

    public function testDeclaredSettingsAreValidatedAndTheEventTypeSendsAnAlert(): void
    {
        $page = '/admin.php?module=addons&action=page&p=guard.settings';
        $this->assertPage($page, 200, ['Path to refuse', 'Keep the log for (days)'], message: 'the declared settings have a page');
        $this->adminPost($page, ['_ext_settings' => 1, 'blocked_path' => '/other-blocked', 'keep_days' => 'abc'], $page);
        $this->assertSame('', $this->sq("SELECT value FROM tl_settings WHERE name = 'ext.guard.keep_days'"), 'an invalid number is not saved');
        $this->assertSame('/other-blocked', $this->sq("SELECT value FROM tl_settings WHERE name = 'ext.guard.blocked_path'"), 'the valid field is');
        $visitor = $this->site()->client('visitor');
        $this->assertSame(403, $visitor->get('/other-blocked')->status, 'the new value takes effect');
        $this->assertNotSame(403, $visitor->get('/guard-blocked')->status, 'the old one is free again');

        $listed = $this->mcpRawText('list_events', ['types' => ['nothing.']]);
        $this->assertStringContainsString('guard.refused', $listed, 'the add-on\'s event type is listed');

        // the alert: a warning of an add-on type that asked for alerts goes out, a warning of an unknown type does not
        $site = $this->site();
        $site->setting('site_email', 'owner@example.test');
        $problems = "SELECT COUNT(*) FROM tl_mail WHERE subject LIKE '%problem%'";
        $before = (int) $this->sq($problems);
        $site->exec("UPDATE tl_settings SET value = (SELECT COALESCE(MAX(id), 0) FROM tl_events) WHERE name = 'alerts_cursor'");
        $site->exec("UPDATE tl_settings SET value = '0' WHERE name = 'alerts_last_sent'");
        $site->exec("INSERT INTO tl_settings (name, value) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM tl_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tl_settings WHERE name = 'alerts_cursor')");
        $site->exec("INSERT INTO tl_events (created_at, type, severity, message) VALUES (NOW(), 'stranger.thing', 'warning', 'Test: a warning nobody asked an alert for')");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'alerts'");
        $site->runTasks();
        $this->assertSame($before, (int) $this->sq($problems), 'a warning of an unknown type is no alert');

        $site->exec("INSERT INTO tl_events (created_at, type, severity, message) VALUES (NOW(), 'guard.refused', 'warning', 'Test: the guard refused many requests')");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'alerts'");
        $site->runTasks();
        $this->assertSame($before + 1, (int) $this->sq($problems), 'a warning of the add-on\'s alert type sends the alert e-mail');
    }

    public function testSwitchedOffNothingOfTheAddonRemainsExceptItsData(): void
    {
        $this->toggle('guard', 0, trust: false);
        $visitor = $this->site()->client('visitor');
        $this->assertNotSame(403, $visitor->get('/other-blocked')->status, 'the early hook is gone');
        $this->assertDoesNotMatchRegularExpression('/"handover": ?"guard\./', $this->mcpText('site_audit', ['kind' => 'handover']), 'the finding is gone');
        $this->assertStringNotContainsString('Refused requests', (string) $this->site()->admin()->get('/admin.php?module=status')->body, 'the health row is gone');
        $this->assertSame(404, $this->site()->admin()->get('/admin.php?module=addons&action=page&p=guard.settings')->status, 'the settings page is gone');
        $this->assertStringNotContainsString('guard.refused', $this->mcpRawText('list_events', ['types' => ['nothing.']]), 'the event type is gone');
        $this->assertTrue($this->tableExists('tl_ext_guard_log'), 'the data stays');
    }

    public function testUninstallKeepsOrDeletesTheData(): void
    {
        $this->toggle('guard', 1);
        $this->toggle('guard', 0, trust: false);
        $uninstall = '/admin.php?module=addons&action=uninstall';
        $this->adminPost($uninstall, ['slug' => 'guard'], '/admin.php?module=addons');
        $this->assertTrue($this->tableExists('tl_ext_guard_log'), 'without a choice nothing happens');

        $this->adminPost($uninstall, ['slug' => 'guard', 'data' => 'keep'], '/admin.php?module=addons');
        $this->assertTrue($this->tableExists('tl_ext_guard_log') && $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name LIKE 'ext.guard.%'") !== '0', 'keep: the table and the settings stay');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_events WHERE type = 'addon.uninstalled'"), 'the uninstall is an event');

        $this->toggle('guard', 1);
        $this->assertSame('2', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_migrated.guard'"), 'switched on again, the migrations do not run twice');
        $this->adminPost($uninstall, ['slug' => 'guard', 'data' => 'delete'], '/admin.php?module=addons');
        $this->assertTrue($this->tableExists('tl_ext_guard_log'), 'a switched-on add-on is not uninstalled');

        $this->toggle('guard', 0, trust: false);
        $this->adminPost($uninstall, ['slug' => 'guard', 'data' => 'delete'], '/admin.php?module=addons');
        $this->assertFalse($this->tableExists('tl_ext_guard_log'), 'delete: the table is dropped');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name LIKE 'ext.guard.%' OR name LIKE 'addons_migrated.guard'"), 'delete: the settings and the migration state are gone');

        $this->toggle('guard', 1);
        $this->assertTrue($this->tableExists('tl_ext_guard_log'), 'switched on after a delete, the migrations run again');
        $this->toggle('guard', 0, trust: false);
    }

    public function testAFailingEarlyHookIsSwitchedOffAndTheRequestContinues(): void
    {
        $this->extension('boom', ['name' => 'Boom', 'class' => 'Boom\\Ext', 'requires' => ['api' => 2], 'capabilities' => ['early_request']], <<<'PHP'
<?php namespace Boom; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {
    $api->earlyRequest(function (\Talea\Core\Request $request): ?\Talea\Core\Response { throw new \RuntimeException('deliberately broken hook'); });
} }
PHP . "\n");
        $this->toggle('boom', 1);
        $this->assertSame('boom', $this->enabled(), 'it loads: register() does not fail, only the hook');

        $this->assertSame(200, $this->site()->client('visitor')->get('/')->status, 'the request that met the failing hook goes on');
        $this->assertSame('', $this->enabled(), 'the add-on is switched off');
        $this->assertStringContainsString('deliberately broken hook', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_error.boom'"), 'the error is kept for Add-ons');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_events WHERE type = 'addon.failed' AND message LIKE '%deliberately broken hook%'"), 'and recorded as an event');
        $this->assertPage('/admin.php?module=addons', 200, 'deliberately broken hook', message: 'Add-ons shows it');
    }

    public function testASlowEarlyHookCountsAsFailedAndItsAnswerIsNotUsed(): void
    {
        $this->extension('slow', ['name' => 'Slow', 'class' => 'Slow\\Ext', 'requires' => ['api' => 2], 'capabilities' => ['early_request']], <<<'PHP'
<?php namespace Slow; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {
    $api->earlyRequest(function (\Talea\Core\Request $request): ?\Talea\Core\Response { usleep(400000); return new \Talea\Core\Response('too late', 403); });
} }
PHP . "\n");
        $this->toggle('slow', 1);

        $this->assertSame(200, $this->site()->client('visitor')->get('/')->status, 'a hook over its time is not allowed to refuse');
        $this->assertSame('', $this->enabled(), 'it is switched off');
        $this->assertStringContainsString('took longer', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_error.slow'"), 'the reason is kept');
    }

    public function testApi2IsGatedByTheManifestAndTheDeclaredCapabilities(): void
    {
        // an add-on written for API 1 may not call API 2
        $this->extension('oldapi', ['name' => 'Old API', 'class' => 'Oldapi\\Ext', 'requires' => ['api' => 1]], <<<'PHP'
<?php namespace Oldapi; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {
    $api->healthRows(fn ($app): array => []);
} }
PHP . "\n");
        $this->toggle('oldapi', 1);
        $this->site()->client('visitor')->get('/');
        $this->assertSame('', $this->enabled(), 'the API 1 add-on that calls API 2 is switched off at its first request');
        $this->assertStringContainsString('needs extension API 2', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_error.oldapi'"), 'with a message that says why');

        // an early hook needs the capability in the manifest
        $this->extension('quiet', ['name' => 'Quiet', 'class' => 'Quiet\\Ext', 'requires' => ['api' => 2]], <<<'PHP'
<?php namespace Quiet; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {
    $api->earlyRequest(fn ($request) => null);
} }
PHP . "\n");
        $this->toggle('quiet', 1);
        $this->site()->client('visitor')->get('/');
        $error = $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_error.quiet'");
        $this->assertTrue(str_contains($error, 'early_request') && str_contains($error, 'does not declare it'), 'an undeclared early hook is refused: ' . $error);

        // tables need the capability, too
        $this->extension('tabled', ['name' => 'Tabled', 'class' => 'Tabled\\Ext', 'requires' => ['api' => 2]], '<?php namespace Tabled; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {} }' . "\n");
        mkdir($this->site()->path('extensions/tabled/migrations'), 0775, true);
        file_put_contents($this->site()->path('extensions/tabled/migrations/0001-x.sql'), "CREATE TABLE IF NOT EXISTS {ext_tabled_x} (id {pk});\n");
        $this->assertPage('/admin.php?module=addons', 200, 'does not declare &quot;tables&quot; in its capabilities', message: 'an add-on with tables and no declaration cannot be switched on');
    }

    public function testAMigrationMayOnlyChangeTheAddonsOwnTables(): void
    {
        $this->extension('thief', ['name' => 'Thief', 'class' => 'Thief\\Ext', 'requires' => ['api' => 2], 'capabilities' => ['tables']], '<?php namespace Thief; final class Ext implements \Talea\Extension\ExtensionInterface { public function register(\Talea\Extension\Api $api): void {} }' . "\n");
        mkdir($this->site()->path('extensions/thief/migrations'), 0775, true);
        file_put_contents($this->site()->path('extensions/thief/migrations/0001-steal.sql'), "DELETE FROM {users};\n");
        $this->toggle('thief', 1);
        $this->assertSame('', $this->enabled(), 'the add-on is not switched on');
        $this->assertGreaterThan(0, (int) $this->sq('SELECT COUNT(*) FROM tl_users'), 'and Talea\'s tables are untouched');
        $this->assertPage('/admin.php?module=addons', 200, [], message: 'Add-ons still works');
    }
}
