<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Migrator;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite phase 1, stage 2 (docs/specs/multisite.md): what cron does with a site the admin registered – tables from the migrator,
 * settings or a full copy from Sites::provision() – the trash cleanup that drops a site's tables, and the handoff tokens.
 */
#[CoversClass(Sites::class)]
final class SiteProvisioningTest extends DatabaseTestCase
{
    private static bool $sites = false;

    protected function setUp(): void
    {
        // the new sites' tables are DDL, which ends a transaction on MySQL: made once, before the test's transaction starts
        if (!self::$sites) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null; // no database: parent::setUp() skips the test
            }
            if ($config !== null) {
                Sites::add($this->db(), 'Empty', 'empty');                 // tl2_
                Sites::add($this->db(), 'Copy', 'copy', 1, true);          // tl3_
                Sites::add($this->db(), 'Doomed', 'doomed');               // tl4_
                Migrator::migrate($config);
                self::$sites = true;
            }
        }
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        self::$sites = false;
        parent::tearDownAfterClass();
    }

    /** @return array<string, mixed> */
    private function row(string $prefix): array
    {
        return (array) $this->db()->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]);
    }

    public function testANewSiteIsPendingUntilItIsProvisioned(): void
    {
        $this->assertSame(['tl2_', 'tl3_', 'tl4_'], array_column(Sites::pending($this->db()), 'prefix'));
        $this->assertSame(['tl_'], Sites::readyPrefixes($this->db()), 'site 1 is ready from the migration on');
        $this->assertFalse(Sites::resolve($this->addDomain('tl2_', 'pending.example'), 'pending.example')['ready']);
    }

    private function addDomain(string $prefix, string $host): \Talea\Core\Db
    {
        $this->db()->insert('site_domains', ['site_id' => (int) $this->row($prefix)['site_id'], 'host' => $host, 'is_primary' => true, 'redirect_to_primary' => false]);

        return $this->db();
    }

    public function testAnEmptySiteTakesOverTheAccountSettingsOnly(): void
    {
        $this->db()->upsert('settings', ['name' => 'mail_from', 'value' => 'owner@example.test'], ['name']);
        $this->db()->upsert('settings', ['name' => 'site_name', 'value' => 'The first site'], ['name']);
        $this->db()->insert('pages', ['slug' => 'only-on-one', 'title' => 'Only on one', 'text' => '']);

        Sites::provision($this->db(), $this->row('tl2_'));

        $site = $this->db()->forSite('tl2_');
        $this->assertSame('owner@example.test', $site->value("SELECT value FROM {settings} WHERE name = 'mail_from'"));
        $this->assertNull($site->value("SELECT value FROM {settings} WHERE name = 'site_name'"), 'only the account settings are taken over');
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM {pages}'));
        $this->assertNotNull($this->row('tl2_')['ready_at']);
        $this->assertContains('tl2_', Sites::readyPrefixes($this->db()));
        $this->assertTrue(Sites::resolve($this->addDomain('tl2_', 'empty.example'), 'empty.example')['ready']);
    }

    public function testACopyBringsTheContentAndKeepsNumberingAhead(): void
    {
        $db = $this->db();
        $id = $db->insert('pages', ['slug' => 'about', 'title' => 'About', 'text' => 'Hello']);
        $public = (string) $db->value('SELECT public_id FROM {pages} WHERE page_id = ?', [$id]);
        $db->upsert('settings', ['name' => 'site_name', 'value' => 'The first site'], ['name']);
        foreach (['secret_key' => 'first-sites-secret', 'webhook_secret' => 'first-webhook', 'tasks_token' => 'first-tasks', 'health_token' => 'first-health', 'captcha_secret' => 'first-captcha', 'smtp_password' => 'mail-pass', 'ai_key' => 'ai-key'] as $k => $v) {
            $db->upsert('settings', ['name' => $k, 'value' => $v], ['name']);
        }
        $db->upsert('settings', ['name' => 'some_unlisted_option', 'value' => 'x'], ['name']);
        $db->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'email' => 'v@example.com', 'data' => '{}']);
        $db->insert('subscribers', ['email' => 'sub@example.com', 'token' => str_repeat('a', 32), 'status' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->upsert('settings', ['name' => 'site_url', 'value' => 'https://first.example'], ['name']);
        $db->upsert('jobs', ['name' => 'links', 'last_run' => date('Y-m-d H:i:s'), 'failures' => 0, 'runs' => 3, 'duration_ms' => 1], ['name']);

        Sites::provision($db, $this->row('tl3_'));

        $copy = $db->forSite('tl3_');
        $this->assertSame('Hello', $copy->value('SELECT text FROM {pages} WHERE slug = ?', ['about']));
        $this->assertSame($public, $copy->value('SELECT public_id FROM {pages} WHERE slug = ?', ['about']), 'rows keep their public ids');
        $this->assertSame('The first site', $copy->value("SELECT value FROM {settings} WHERE name = 'site_name'"));
        foreach (['secret_key', 'webhook_secret', 'tasks_token', 'health_token', 'captcha_secret', 'site_url'] as $own) {
            $this->assertNull($copy->value('SELECT value FROM {settings} WHERE name = ?', [$own]), $own . ' is not copied');
        }
        $this->assertNull($copy->value("SELECT value FROM {settings} WHERE name = 'some_unlisted_option'"), 'a setting outside the allow-list is not copied');
        $this->assertSame(0, (int) $copy->value('SELECT COUNT(*) FROM {enquiries}'), 'no visitors\' personal data');
        $this->assertSame(0, (int) $copy->value('SELECT COUNT(*) FROM {subscribers}'));
        $this->assertSame('mail-pass', $copy->value("SELECT value FROM {settings} WHERE name = 'smtp_password'"), 'account secrets are shared');
        $this->assertSame('ai-key', $copy->value("SELECT value FROM {settings} WHERE name = 'ai_key'"));
        $this->assertSame(0, (int) $copy->value('SELECT COUNT(*) FROM {jobs}'), 'the job state is not copied');
        $next = $copy->insert('pages', ['slug' => 'next', 'title' => 'Next', 'text' => '']);
        $this->assertGreaterThan($id, $next, 'the auto-number moved past the copied keys');
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE slug = ?', ['about']), 'the source is untouched');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE slug = ?', ['next']));

        Sites::provision($db, $this->row('tl3_')); // a second run (after a failure) leaves the same copy, not a doubled one
        $this->assertSame(1, (int) $copy->value('SELECT COUNT(*) FROM {pages} WHERE slug = ?', ['about']));
        $this->assertSame(0, (int) $copy->value('SELECT COUNT(*) FROM {pages} WHERE slug = ?', ['next']));
    }

    public function testASiteCannotBeMadeFromItself(): void
    {
        $this->db()->update('sites', ['source_site_id' => (int) $this->row('tl2_')['site_id']], ['prefix' => 'tl2_']);
        $this->expectException(\RuntimeException::class);
        Sites::provision($this->db(), $this->row('tl2_'));
    }

    public function testHostsAreValidatedAndUnique(): void
    {
        $this->assertSame('www.example.com', Sites::normalizeHost('  WWW.Example.COM '));
        foreach (['', 'https://example.com', 'example.com:8080', 'example.com/path', 'exa mple.com', '-bad.example', 'a..b', str_repeat('a', 64) . '.example'] as $bad) {
            $this->assertNull(Sites::normalizeHost($bad), $bad);
        }
        $this->assertNull(Sites::addDomain($this->db(), 1, 'Main.Example'));
        $this->assertSame('main.example', Sites::primaryHost($this->db(), 1), 'the first host of a site is its primary one');
        $this->assertNotNull(Sites::addDomain($this->db(), 2, 'main.example'), 'a host belongs to one site');
        $this->assertNull(Sites::addDomain($this->db(), 1, 'www.main.example'));

        $alias = (int) $this->db()->value('SELECT domain_id FROM {site_domains} WHERE host = ?', ['www.main.example']);
        Sites::makePrimary($this->db(), 1, $alias);
        $this->assertSame('www.main.example', Sites::primaryHost($this->db(), 1));
        $this->assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {site_domains} WHERE site_id = 1 AND is_primary = TRUE'));
        $this->assertNotNull(Sites::removeDomain($this->db(), 1, $alias, 'www.main.example'), 'the host in use cannot be removed');
        $this->assertNull(Sites::removeDomain($this->db(), 1, $alias, 'elsewhere.example'));
        $this->assertSame('main.example', Sites::primaryHost($this->db(), 1), 'the next host takes over as primary');
    }

    public function testASiteWorksThroughTrashAndRestoreButSiteOneNever(): void
    {
        $this->assertNotNull(Sites::trash($this->db(), 1));
        $this->assertSame('live', $this->db()->value('SELECT status FROM {sites} WHERE site_id = 1'));

        $id = (int) $this->row('tl4_')['site_id'];
        $this->assertNull(Sites::trash($this->db(), $id));
        $this->assertSame('trashed', $this->row('tl4_')['status']);
        Sites::restore($this->db(), $id);
        $this->assertSame('paused', $this->row('tl4_')['status']);
        $this->assertNotNull(Sites::save($this->db(), $id, '', 'live', null), 'a name is required');
        $this->assertNotNull(Sites::save($this->db(), $id, 'Doomed', 'archived', $id), 'a site cannot point at itself');
        $this->assertNull(Sites::save($this->db(), $id, 'Doomed', 'archived', 1));
        $this->assertSame(1, (int) $this->row('tl4_')['redirect_to_site_id']);
    }

    public function testTheCleanupDropsTheTablesOfASiteTrashedLongAgo(): void
    {
        $this->db()->pdo()->rollBack(); // DROP is DDL: it ends a transaction on MySQL
        $db = $this->db();
        $exists = fn (string $real): bool => (int) $db->value($db->dialect()->tableExistsSql(), [$real]) > 0;
        $id = (int) $this->row('tl4_')['site_id'];
        $db->insert('site_domains', ['site_id' => $id, 'host' => 'doomed.example', 'is_primary' => true, 'redirect_to_primary' => false]);
        $this->assertTrue($exists('tl4_pages'));

        Sites::trash($db, $id);
        $this->assertSame('dropped 0', Sites::cleanup($db), 'recently trashed: kept');
        $this->assertTrue($exists('tl4_pages'));

        $db->update('sites', ['trashed_at' => date('Y-m-d H:i:s', time() - 31 * 86400)], ['site_id' => $id]);
        $this->assertSame('dropped 1', Sites::cleanup($db));

        $this->assertFalse($exists('tl4_pages'));
        $this->assertFalse($exists('tl4_settings'));
        $this->assertTrue($exists('tl2_pages'), 'the other sites are untouched');
        $this->assertTrue($exists('tl_pages'));
        $this->assertNull($db->one('SELECT * FROM {sites} WHERE site_id = ?', [$id]));
        $this->assertNull($db->one('SELECT * FROM {site_domains} WHERE host = ?', ['doomed.example']), 'the domains go with the site');
        $this->expectException(\InvalidArgumentException::class);
        Sites::dropTables($db, 'tl_');
    }

    public function testAHandoffTokenWorksOnceOnItsSiteOnly(): void
    {
        $user = $this->db()->insert('users', ['username' => 'owner', 'password' => 'x', 'email' => 'owner@example.test']);
        $token = Sites::handoffToken($this->db(), $user, 2);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {handoff_tokens} WHERE token_hash = ?', [$token]), 'only the sha256 is stored');
        $this->assertNull(Sites::claimHandoff($this->db(), $token, 1), 'bound to its target site');
        $this->assertSame($user, Sites::claimHandoff($this->db(), $token, 2));
        $this->assertNull(Sites::claimHandoff($this->db(), $token, 2), 'single use');
        $this->assertNull(Sites::claimHandoff($this->db(), 'not-a-token', 2));

        $late = Sites::handoffToken($this->db(), $user, 2);
        $this->db()->run('UPDATE {handoff_tokens} SET expires_at = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() - 5), hash('sha256', $late)]);
        $this->assertNull(Sites::claimHandoff($this->db(), $late, 2), 'expired after 60 seconds');
    }
}
