<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\JobsFleetFacts;

use Kaleta\Tests\Site\Support\Site;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: sections 49 "2.9 fleet console" and 50 "2.16 shared design kit" of tools/test.sh. A second installed site is the console,
 * this site (the member) pairs with it. The kit tests build on the pairing, so both sections stay in one class.
 */
#[Group('site')]
final class FleetTest extends SiteTestCase
{
    use Helpers;

    private static ?Site $console = null;
    private static string $pairingKey = '';
    private static int $fleetId = 0;
    private static string $consoleToken = '';
    private static int $kitComponent = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        try {
            self::$console = Site::boot(['siteName' => 'Agency console', 'web' => 'business', 'extensions' => ['fleet', 'claude']]);
            self::$console->setting('extensions', 'fleet,claude');
        } catch (\Throwable) {
            self::$console = null; // no MySQL: the tests are skipped by the base class
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$console?->close();
        self::$console = null;
        parent::tearDownAfterClass();
    }

    private function console(): Site
    {
        return self::$console ?? throw new \LogicException('No console site.');
    }

    // ---- 2.9 fleet console

    public function testConsoleGivesAOneTimePairingKey(): void
    {
        $console = $this->console();
        // the console's own update channel is not reachable here: it "knows" a newer version 9.9.9 from its cache
        $console->setting('update_url', 'http://127.0.0.1:1/update.json');
        $console->setting('update_cache', json_encode(['url' => 'http://127.0.0.1:1/update.json', 'checked' => time(), 'manifest' => ['version' => '9.9.9', 'changes' => []], 'error' => null]));

        $this->postAs($console, '/admin.php?module=fleet&action=pairing_key', [], '/admin.php?module=fleet');
        $page = $console->admin()->get('/admin.php?module=fleet');
        preg_match('/kaleta-console:[A-Za-z0-9_-]*/', $page->body, $m);
        self::$pairingKey = $m[0] ?? '';
        $this->assertNotSame('', self::$pairingKey, 'console: a one-time pairing key');
        $this->assertStringNotContainsString('kaleta-console:', $console->admin()->get('/admin.php?module=fleet')->body, 'console: the pairing key is shown only once');
        $this->assertSame(404, $this->site()->client()->post('/fleet/heartbeat', '{}')->status, 'a site without the fleet extension has no console addresses');
    }

    public function testSitePairsWithTheConsole(): void
    {
        $site = $this->site();
        $console = $this->console();
        $tab = $site->admin()->get('/admin.php?module=settings&tab=console');
        $this->assertStringContainsString('name="pairing_key"', $tab->body, 'Settings → Fleet console offers pairing');
        $this->postAs($site, '/admin.php?module=settings&action=fleet_pair', ['pairing_key' => self::$pairingKey, 'fleet_updates' => '1'], '/admin.php?module=settings&tab=console');

        $this->sameValue($console->base . '|1|1', $site->value("SELECT CONCAT((SELECT value FROM ka_settings WHERE name = 'fleet_console_url'), '|', (SELECT value <> '' FROM ka_settings WHERE name = 'fleet_site_id'), '|', (SELECT value FROM ka_settings WHERE name = 'fleet_updates'))"), 'pairing: the site knows its console and its number there');
        $this->sameValue('1|1|1|1|1', $console->value("SELECT CONCAT(COUNT(*), '|', MAX(last_seen IS NOT NULL), '|', MAX(version <> ''), '|', MAX(manage_updates), '|', MAX(heartbeat LIKE '%enquiries_unanswered%')) FROM ka_fleet_sites"), 'pairing: the console has the site with its first report');
        $this->sameValue('1', $console->value('SELECT COUNT(*) FROM ka_fleet_pairing WHERE used_at IS NOT NULL'), 'pairing: the code works only once');
        $this->assertStringNotContainsString('@', (string) $console->value('SELECT heartbeat FROM ka_fleet_sites'), 'the report carries no e-mail addresses');

        $name = $site->settingValue('site_name');
        $this->assertStringContainsString($name, $console->admin()->get('/admin.php?module=fleet&show=all')->body, 'console: the site is in the list');
        self::$fleetId = (int) $console->value('SELECT id FROM ka_fleet_sites LIMIT 1');
        $this->assertStringContainsString('name="ring"', $console->admin()->get('/admin.php?module=fleet&action=detail&id=' . $console->publicId('fleet_sites', self::$fleetId))->body, 'console: the detail of a site with its update ring');
    }

    public function testForgedAndRepeatedReportsAreRefused(): void
    {
        $console = $this->console();
        $id = self::$fleetId;
        $this->assertSame(403, $console->client()->post('/fleet/heartbeat', '{"site_id":"' . $console->publicId('fleet_sites', $id) . '","ts":' . time() . '}', ['X-Kaleta-Signature: AAAA'])->status, 'console: a report with a wrong signature is refused');
        $this->assertSame(404, $console->client()->post('/fleet/heartbeat', '{"site_id":"' . $id . '"}')->status, 'console: a report of an unknown site is refused');
        $key = (string) $console->value('SELECT public_key FROM ka_fleet_sites LIMIT 1');
        $this->assertSame(403, $console->client()->post('/fleet/pair', json_encode(['action' => 'pair', 'code' => str_repeat('0', 32), 'public_key' => $key, 'url' => 'http://x.test', 'ts' => 0]))->status, 'console: pairing with an unknown code is refused');
    }

    public function testTheHeartbeatJobReportsOnItsOwn(): void
    {
        $site = $this->site();
        $before = (int) $this->console()->value('SELECT last_ts FROM ka_fleet_sites WHERE id = ?', [self::$fleetId]);
        // the report carries a one-second timestamp and a loaded machine can run the job inside the same second: retry, never weaken the assertion
        $after = $before;
        for ($try = 0; $try < 6 && $after <= $before; $try++) {
            sleep(1);
            $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'heartbeat'");
            $site->runTasks();
            $after = (int) $this->console()->value('SELECT last_ts FROM ka_fleet_sites WHERE id = ?', [self::$fleetId]);
        }
        $this->assertGreaterThan($before, $after, 'the background job sends the report');
    }

    public function testStagedUpdatesAndTakingTheDecisionBack(): void
    {
        $site = $this->site();
        $console = $this->console();
        $this->sameValue('', $site->value("SELECT value FROM ka_settings WHERE name = 'fleet_update_allowed'"), 'staged updates: a normal site waits for the test sites');
        $this->postAs($console, '/admin.php?module=fleet&action=ring', ['id' => $console->publicId('fleet_sites', self::$fleetId), 'ring' => 'canary'], '/admin.php?module=fleet&action=detail&id=' . $console->publicId('fleet_sites', self::$fleetId));
        sleep(1);
        $this->postAs($site, '/admin.php?module=settings&action=fleet_send', [], '/admin.php?module=settings&tab=console');
        // HF-12: the in-app updater is off, so a site reports no available update and the console has nothing to allow (the staging returns with HF-13)
        $this->sameValue('', $site->value("SELECT value FROM ka_settings WHERE name = 'fleet_update_allowed'"), 'staged updates: the updater is off, no version is allowed');
        $this->assertStringNotContainsString('9.9.9', $site->admin()->get('/admin.php?module=settings&tab=console')->body, 'the site shows no update');
        $this->postAs($site, '/admin.php?module=settings&action=fleet_updates', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('0|', $site->value("SELECT CONCAT((SELECT value FROM ka_settings WHERE name = 'fleet_updates'), '|', (SELECT value FROM ka_settings WHERE name = 'fleet_update_allowed'))"), 'the site takes the decision about updates back');
    }

    public function testUptimeAndSilentSites(): void
    {
        $site = $this->site();
        $console = $this->console();
        $id = self::$fleetId;
        $this->postAs($console, '/admin.php?module=fleet&action=check', [], '/admin.php?module=fleet');
        $this->sameValue('1', $console->value('SELECT up FROM ka_fleet_sites WHERE id = ?', [$id]), 'uptime: the console sees the site up');
        $console->exec("UPDATE ka_fleet_sites SET url = 'http://127.0.0.1:1', last_seen = NOW() - INTERVAL 30 HOUR, silent_reported = 0 WHERE id = ?", [$id]);
        $this->postAs($console, '/admin.php?module=fleet&action=check', [], '/admin.php?module=fleet');
        $this->postAs($console, '/admin.php?module=fleet&action=check', [], '/admin.php?module=fleet');
        $this->sameValue('0|1|1', $console->value("SELECT CONCAT((SELECT up FROM ka_fleet_sites WHERE id = $id), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_down'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_silent'))"), 'uptime: down twice in a row is an event, and so is a site that stopped reporting');
        $this->assertStringContainsString('badge-error', $console->admin()->get('/admin.php?module=fleet')->body, 'console: a down site is first in the list of what needs attention');
        $console->exec('UPDATE ka_fleet_sites SET url = ? WHERE id = ?', [$site->base, $id]);
    }

    public function testClaudeOnTheConsoleReadsTheFleet(): void
    {
        $console = $this->console();
        self::$consoleToken = 'kaleta_' . bin2hex(random_bytes(24));
        $console->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM ka_users WHERE username = 'admin'", [hash('sha256', self::$consoleToken)]);

        $list = $this->consoleMcp('list_sites');
        $this->assertStringContainsString('console_decides_updates', $list, 'MCP list_sites on the console: who decides updates');
        $this->assertStringContainsString('newest_version', $list, 'MCP list_sites on the console: the newest version');
        $this->assertStringContainsString('jobs_failing', $this->consoleMcp('get_site', ['id' => $this->console()->publicId('fleet_sites', self::$fleetId)]), 'MCP get_site: the last report');
        $this->assertStringNotContainsString('newest_version', $this->mcpText('list_sites'), 'list_sites exists only on a console');
    }

    // ---- 2.16 shared design kit

    public function testConsoleOffersAndPublishesTheKit(): void
    {
        $console = $this->console();
        $console->exec("INSERT INTO ka_classes (name, style, css, updated_at) VALUES ('kit-band', '{}', 'padding: 2rem;', NOW())");
        $console->setting('design_system', '{"colors":{"primary":"#aa0000"}}');
        $console->exec("INSERT INTO ka_components (name, properties, build, updated_at) VALUES ('Kit card', '[]', ?, NOW())",
            ['{"v":1,"children":[{"type":"section","children":[{"type":"heading","content":{"text":"Kit card v1"}},{"type":"custom_html","content":{"code":"<script>alert(1)</script>"}}]}]}']);
        $console->exec("INSERT INTO ka_sections (name, element, updated_at) VALUES ('Kit banner', '{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Kit banner\"}}]}', NOW())");
        self::$kitComponent = (int) $console->value("SELECT component_id FROM ka_components WHERE name = 'Kit card'");
        $section = (int) $console->value("SELECT section_id FROM ka_sections WHERE name = 'Kit banner'");

        $page = $console->admin()->get('/admin.php?module=fleet&action=kit');
        $this->assertTrue($page->contains('name="design_system"') && $page->contains('value="kit-band"') && $page->contains('value="' . $console->publicId('components', self::$kitComponent) . '"'), 'console: the shared kit screen offers the design system, classes, components and sections');
        $this->postAs($console, '/admin.php?module=fleet&action=kit_publish', ['design_system' => '1', 'classes' => ['kit-band'], 'components' => [$console->publicId('components', self::$kitComponent)], 'sections' => [$console->publicId('sections', $section)]], '/admin.php?module=fleet&action=kit');
        $this->sameValue('1|1|1|1|1|0|64|design system, 1 class, 1 component, 1 section', $console->value("SELECT CONCAT(version, '|', manifest LIKE '%#aa0000%', '|', manifest LIKE '%kit-band%', '|', manifest LIKE '%Kit card v1%', '|', manifest LIKE '%Kit banner%', '|', manifest LIKE '%<script%', '|', LENGTH(sha256), '|', summary) FROM ka_fleet_kits"), 'console: kit version 1 is published – signed content without the custom-code element');
        $this->postAs($console, '/admin.php?module=fleet&action=kit_publish', [], '/admin.php?module=fleet&action=kit');
        $this->sameValue('1', $console->value('SELECT COUNT(*) FROM ka_fleet_kits'), 'console: an empty kit is not published');
    }

    public function testASiteThatDidNotOptInIgnoresTheKit(): void
    {
        $site = $this->site();
        sleep(1);
        $this->assertStringContainsString('name="fleet_kit"', $site->admin()->get('/admin.php?module=settings&tab=console')->body, 'Settings → Fleet console offers receiving the kit (off by default)');
        $this->postAs($site, '/admin.php?module=settings&action=fleet_send', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('0|0|0', $site->value("SELECT CONCAT(COALESCE((SELECT value FROM ka_settings WHERE name = 'fleet_kit_version'), '0'), '|', (SELECT COUNT(*) FROM ka_components WHERE kit_key IS NOT NULL), '|', COALESCE((SELECT value LIKE '%kit-band%' FROM ka_settings WHERE name = 'look_draft'), 0))"), 'a site with the kit off ignores it');
    }

    public function testWithTheKitOnTheSiteReceivesDraftsOnly(): void
    {
        $site = $this->site();
        $this->postAs($site, '/admin.php?module=settings&action=fleet_kit', ['fleet_kit' => '1'], '/admin.php?module=settings&tab=console');
        sleep(1);
        $this->postAs($site, '/admin.php?module=settings&action=fleet_send', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('1|1|0|0', $site->value("SELECT CONCAT((SELECT value FROM ka_settings WHERE name = 'fleet_kit_version'), '|', (SELECT value LIKE '%#aa0000%' AND value LIKE '%kit-band%' FROM ka_settings WHERE name = 'look_draft'), '|', COALESCE((SELECT value LIKE '%#aa0000%' FROM ka_settings WHERE name = 'design_system'), 0), '|', (SELECT COUNT(*) FROM ka_classes WHERE name = 'kit-band'))"), 'kit on: the look draft has the token and the class, the published look and the classes are unchanged');
        $this->sameValue('1|1|1|1', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_components WHERE kit_key = 'kit-card' AND build IS NULL AND build_draft LIKE '%Kit card v1%' AND build_draft NOT LIKE '%<script%'), '|', (SELECT COUNT(*) FROM ka_sections WHERE kit_key = 'kit-banner'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_received'), '|', (SELECT COUNT(*) FROM ka_change_log WHERE action = 'fleet_kit_received'))"), 'kit on: the component is a draft without a published build and without the code element, the section is in the library, the event is recorded');
        $tab = $site->admin()->get('/admin.php?module=settings&tab=console');
        $this->assertTrue($tab->contains('module=components') && $tab->contains('module=appearance'), 'the site shows the received version with links to the waiting drafts');
    }

    public function testASecondVersionUpdatesTheSameComponent(): void
    {
        $site = $this->site();
        $console = $this->console();
        $console->exec("UPDATE ka_components SET build = REPLACE(build, 'Kit card v1', 'Kit card v2') WHERE component_id = ?", [self::$kitComponent]);
        $this->postAs($console, '/admin.php?module=fleet&action=kit_publish', ['components' => [$console->publicId('components', self::$kitComponent)]], '/admin.php?module=fleet&action=kit');
        sleep(1);
        $this->postAs($site, '/admin.php?module=settings&action=fleet_send', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('1|1|2', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_components WHERE kit_key = 'kit-card'), '|', (SELECT build_draft LIKE '%Kit card v2%' FROM ka_components WHERE kit_key = 'kit-card'), '|', (SELECT value FROM ka_settings WHERE name = 'fleet_kit_version'))"), "kit version 2 updates the component's draft (no duplicate)");
        $this->sameValue('1', $console->value("SELECT heartbeat LIKE '%\"kit_version\":1%' FROM ka_fleet_sites WHERE id = ?", [self::$fleetId]), 'the report carried the version applied before');
    }

    public function testATamperedKitIsRefused(): void
    {
        $site = $this->site();
        $console = $this->console();
        $this->postAs($console, '/admin.php?module=fleet&action=kit_publish', ['design_system' => '1'], '/admin.php?module=fleet&action=kit');
        $console->exec("UPDATE ka_fleet_kits SET manifest = REPLACE(manifest, '#aa0000', '#bb0000') WHERE version = 3");
        sleep(1);
        $this->postAs($site, '/admin.php?module=settings&action=fleet_send', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('2|0|1|1', $site->value("SELECT CONCAT((SELECT value FROM ka_settings WHERE name = 'fleet_kit_version'), '|', (SELECT value LIKE '%#bb0000%' FROM ka_settings WHERE name = 'look_draft'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.kit_refused'), '|', (SELECT value <> '' FROM ka_settings WHERE name = 'fleet_kit_error'))"), 'a tampered kit is refused: the version stays, the draft does not change, the refusal is an event');
        $this->assertSame(403, $console->client()->post('/fleet/kit', json_encode(['action' => 'kit', 'site_id' => $console->publicId('fleet_sites', self::$fleetId), 'ts' => time()]), ['X-Kaleta-Signature: AAAA'])->status, 'console: a kit request without a valid signature is refused');
        $this->assertSame(404, $console->client()->post('/fleet/kit', '{"action":"kit","site_id":"' . self::$fleetId . '"}')->status, 'console: a kit request of an unknown site is refused');
    }

    public function testMcpReportsTheKitVersions(): void
    {
        $info = $this->mcpText('site_info');
        $this->assertStringContainsString('fleet_kit', $info, 'MCP site_info reports the kit version on a member site');
        $this->assertStringContainsString('applied_at', $info, 'MCP site_info: when it was applied');
        $list = $this->consoleMcp('list_sites');
        $this->assertStringContainsString('kit_version', $list, 'MCP list_sites on the console shows the newest kit');
        $this->assertStringContainsString('contents', $list, 'MCP list_sites on the console shows each site\'s version');
    }

    public function testDisconnectingAndAUsedKeyDoesNotPairAgain(): void
    {
        $site = $this->site();
        $this->postAs($site, '/admin.php?module=settings&action=fleet_unpair', [], '/admin.php?module=settings&tab=console');
        $this->sameValue('0|', $this->console()->value('SELECT COUNT(*) FROM ka_fleet_sites') . '|' . $site->value("SELECT value FROM ka_settings WHERE name = 'fleet_console_url'"), 'disconnecting removes the site from the console and the console from the site');
        $this->postAs($site, '/admin.php?module=settings&action=fleet_pair', ['pairing_key' => self::$pairingKey, 'fleet_updates' => '1'], '/admin.php?module=settings&tab=console');
        $this->sameValue('', $site->value("SELECT value FROM ka_settings WHERE name = 'fleet_console_url'"), 'a used pairing key does not pair again');
    }

    /** A tool called on the console with its own token. @param array<string, mixed> $args */
    private function consoleMcp(string $tool, array $args = []): string
    {
        $answer = $this->console()->mcp($tool, $args, self::$consoleToken);

        return (string) ($answer['result']['content'][0]['text'] ?? json_encode($answer));
    }
}
