<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Db;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\SiteTransfer;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Moving a site between owners (SiteTransfer): the report and its hash, the refusals, the copy with its people, host names and public ids, the failure path. */
#[CoversClass(SiteTransfer::class)]
final class SiteTransferTest extends DatabaseTestCase
{
    private static bool $made = false;

    protected function setUp(): void
    {
        // the tables of the new sites are DDL, which ends a transaction on MySQL: made once, before the test's transaction starts
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                $db = $this->db();
                Owners::add($db, 'Beta', 'organisation', 'boss@beta.example');
                if (Owners::provisionPending($db, $config) !== ['owner tlb_: ready']) {
                    throw new \RuntimeException('The owner Beta was not set up.');
                }
                Sites::add($db, 'Shop', 'shop');                                    // tl2_, the site that moves
                $beta = $db->forOwner('tlb_');
                Sites::add($beta, 'Shop', 'shop', 2, true, 'tl_');                  // tlb2_: where the first transfer lands
                Sites::add($beta, 'Shop failing', 'shop-failing', 2, true, 'tl_');  // tlb3_: the one that fails
                Migrator::migrate($config);
                $db->run("UPDATE {sites} SET ready_at = ? WHERE prefix = 'tl2_'", [date('Y-m-d H:i:s')]);
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
        Sites::forgetCurrent();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        Sites::forgetCurrent();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    /** @return array<string, mixed> */
    private function operator(): array
    {
        $id = $this->db()->insert('users', ['username' => 'op', 'password' => 'x', 'name' => 'Olivia Operator', 'email' => 'op@alpha.example', 'admin' => 2, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);

        return (array) $this->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$id]);
    }

    private function user(Db $owner, string $username, string $email): int
    {
        return $owner->insert('users', ['username' => $username, 'password' => 'x', 'name' => ucfirst($username), 'email' => $email, 'admin' => 1, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    private function row(Db $owner, string $prefix): array
    {
        return (array) $owner->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]);
    }

    private function report(string $owner = 'tlb_'): array
    {
        $db = $this->db();
        $target = (array) $db->one('SELECT * FROM {owners} WHERE prefix = ?', [$owner]);

        return SiteTransfer::report($db, $this->row($db, 'tl2_'), $target);
    }

    public function testTheReportListsWhatMovesAndItsHashFollowsIt(): void
    {
        $alpha = $this->db();
        $shop = $alpha->forSite('tl2_');
        $alpha->run("INSERT INTO {site_domains} (site_id, host, is_primary, redirect_to_primary, kind, verified_at) VALUES (2, 'shop.alpha.example', TRUE, FALSE, 'domain', ?)", [date('Y-m-d H:i:s')]);
        $shop->upsert('settings', ['name' => 'smtp_password', 'value' => 'secret'], ['name']);
        $shop->insert('enquiries', $this->enquiry('eve@x.example'));
        $anna = $this->user($alpha, 'anna', 'Anna@Example.com');
        $this->user($this->db()->forOwner('tlb_'), 'anna-b', 'anna@example.com');
        $bob = $this->user($alpha, 'bob', 'bob@example.com');
        $shop->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => $anna, 'user_name' => 'Anna', 'via' => '', 'module' => 'pages', 'action' => 'save', 'description' => '']);
        $shop->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => $bob, 'user_name' => 'Bob', 'via' => '', 'module' => 'pages', 'action' => 'save', 'description' => '']);

        $r = $this->report();

        $this->assertSame([], $r['blockers']);
        $this->assertSame(1, $r['counts']['enquiries']);
        $this->assertSame([['host' => 'shop.alpha.example', 'verified' => true]], $r['domains']);
        $this->assertSame(['smtp_password'], $r['secrets']);
        $this->assertSame([['name' => 'Anna', 'email' => 'Anna@Example.com']], $r['people']['mapped'], 'found again by e-mail, whatever the case');
        $this->assertSame([['name' => 'Bob', 'email' => 'bob@example.com']], $r['people']['unmapped']);
        $this->assertNotSame([], $r['warnings']);
        $this->assertSame($r['hash'], $this->report()['hash'], 'the same site gives the same hash');

        $shop->insert('enquiries', $this->enquiry('mia@x.example'));
        $this->assertNotSame($r['hash'], $this->report()['hash'], 'a change in the site changes the hash');
    }

    /** @return array<string, mixed> */
    private function enquiry(string $email): array
    {
        return ['created_at' => date('Y-m-d H:i:s'), 'email' => $email, 'data' => '{}'];
    }

    public function testTheBlockersOfTheSpec(): void
    {
        $alpha = $this->db();
        $target = (array) $alpha->one('SELECT * FROM {owners} WHERE prefix = ?', ['tlb_']);
        $this->assertContains('The first site of an owner cannot be moved.', SiteTransfer::report($alpha, $this->row($alpha, 'tl_'), $target)['blockers']);
        $this->assertContains('The site already belongs to this owner.', SiteTransfer::report($alpha, $this->row($alpha, 'tl2_'), (array) $alpha->one('SELECT * FROM {owners} WHERE prefix = ?', ['tl_']))['blockers']);

        $notReady = (array) $alpha->one('SELECT * FROM {owners} WHERE prefix = ?', [Owners::add($alpha, 'Gamma')]);
        $this->assertContains('The owner that gets the site is not set up or is archived.', SiteTransfer::report($alpha, $this->row($alpha, 'tl2_'), $notReady)['blockers']);

        $alpha->run("UPDATE {sites} SET ready_at = NULL WHERE prefix = 'tl2_'");
        $this->assertContains('A site in the trash or one that is not set up yet cannot be moved.', $this->report()['blockers']);
        $alpha->run("UPDATE {sites} SET ready_at = ? WHERE prefix = 'tl2_'", [date('Y-m-d H:i:s')]);

        $alpha->run('UPDATE {sites} SET look_parent_id = 2 WHERE site_id = 1');
        $this->assertContains('This site is the parent of a look family. Let its child sites leave the family first.', $this->report()['blockers']);
        $alpha->run('UPDATE {sites} SET look_parent_id = NULL WHERE site_id = 1');
        $alpha->run('UPDATE {sites} SET look_parent_id = 1 WHERE site_id = 2');
        $r = $this->report();
        $this->assertSame([], $r['blockers'], 'a child may move');
        $this->assertContains('This site takes its look from a parent site. It leaves the family and keeps its current copy.', $r['warnings']);
        $alpha->run('UPDATE {sites} SET look_parent_id = NULL WHERE site_id = 2');

        $alpha->run('INSERT INTO {site_domains} (site_id, host, is_primary, redirect_to_primary, kind) VALUES (2, ?, TRUE, FALSE, ?)', ['taken.example', 'domain']);
        $alpha->forOwner('tlb_')->run('INSERT INTO {site_domains} (site_id, host, is_primary, redirect_to_primary, kind) VALUES (1, ?, TRUE, FALSE, ?)', ['taken.example', 'domain']);
        $this->assertContains('A host name of this site is already used in the new owner.', $this->report()['blockers']);
    }

    public function testStartNeedsTheHashOfAFreshReportAndAnOperator(): void
    {
        $alpha = $this->db();
        $operator = $this->operator();
        $site = (string) $this->row($alpha, 'tl2_')['public_id'];
        $beta = (string) $alpha->value('SELECT public_id FROM {owners} WHERE prefix = ?', ['tlb_']);
        $hash = $this->report()['hash'];

        $this->assertStringContainsString('changed since', (string) SiteTransfer::start($alpha, $operator, $site, $beta, str_repeat('0', 64)));
        $this->assertStringContainsString('operators', (string) SiteTransfer::start($alpha, ['admin' => 1] + $operator, $site, $beta, $hash));
        $this->assertNull($alpha->value("SELECT 1 FROM {sites} WHERE transfer_to IS NOT NULL"), 'nothing was written');

        $this->assertNull(SiteTransfer::start($alpha, $operator, $site, $beta, $hash));
        $source = $this->row($alpha, 'tl2_');
        $this->assertNotNull($source['transfer_to']);
        $new = (array) $alpha->forOwner('tlb_')->one('SELECT * FROM {sites} WHERE prefix = ?', [$source['transfer_to']]);
        $this->assertSame('paused', $new['status']);
        $this->assertNull($new['ready_at']);
        $this->assertSame('Olivia Operator', $new['transfer_by']);
        $this->assertSame('tl_', $new['source_owner']);
        $this->assertNotSame($source['public_id'], $new['public_id'], 'the live site takes the id only when it is done');
        $this->assertContains('A transfer of this site is already under way.', $this->report()['blockers']);
        $this->assertStringContainsString('already under way', (string) SiteTransfer::start($alpha, $operator, $site, $beta, $this->report()['hash']));
    }

    /** Marks the pre-made site tlb2_ (or tlb3_) as the target of a transfer of tl2_, as start() does. */
    private function queue(string $prefix = 'tlb2_'): array
    {
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        $beta->update('sites', ['transfer_by' => 'Olivia Operator'], ['prefix' => $prefix]);
        $alpha->update('sites', ['transfer_to' => $prefix], ['prefix' => 'tl2_']);

        return $this->row($beta, $prefix);
    }

    public function testTheCopyKeepsEverythingMapsPeopleMovesHostsAndSwapsTheIds(): void
    {
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        $shop = $alpha->forSite('tl2_');
        $now = date('Y-m-d H:i:s');
        $alpha->update('sites', ['status' => 'live'], ['prefix' => 'tl2_']);
        $alpha->run("INSERT INTO {site_domains} (site_id, host, is_primary, redirect_to_primary, kind, verified_at) VALUES (2, 'shop.alpha.example', TRUE, FALSE, 'domain', ?)", [$now]);
        foreach (['smtp_password' => 'mail-secret', 'site_url' => 'https://shop.alpha.example', 'site_name' => 'Shop', 'secret_key' => 'old-key'] as $k => $v) {
            $shop->upsert('settings', ['name' => $k, 'value' => $v], ['name']);
        }
        $shop->insert('enquiries', $this->enquiry('eve@x.example'));
        $anna = $this->user($alpha, 'anna', 'Anna@Example.com');
        $annaInBeta = $this->user($beta, 'anna-b', 'anna@example.com');
        $bob = $this->user($alpha, 'bob', 'bob@example.com');
        $category = $shop->insert('categories', ['name' => 'Cat', 'slug' => 'cat', 'description' => '']);
        $shop->insert('news', ['title' => 'By Anna', 'slug' => 'by-anna', 'text' => '', 'intro' => '', 'category_id' => $category, 'author_id' => $anna, 'published_at' => $now]);
        $shop->insert('news', ['title' => 'By Bob', 'slug' => 'by-bob', 'text' => '', 'intro' => '', 'category_id' => $category, 'author_id' => $bob, 'published_at' => $now]);
        $shop->insert('change_log', ['created_at' => $now, 'user_id' => $bob, 'user_name' => 'Bob', 'via' => '', 'module' => 'pages', 'action' => 'save', 'description' => 'edited']);
        $shop->insert('mail', ['recipient' => 'x@y.example', 'subject' => 's', 'body' => '{}', 'created_at' => $now]);
        $oldId = (string) $this->row($alpha, 'tl2_')['public_id'];
        $newRow = $this->queue();

        Sites::provision($beta, $newRow);

        $copy = $beta->forSite('tlb2_');
        $moved = $this->row($beta, 'tlb2_');
        $this->assertNotNull($moved['ready_at']);
        $this->assertSame('live', $moved['status'], 'the state of the old site');
        $this->assertNull($moved['transfer_by']);
        $this->assertSame($oldId, $moved['public_id'], 'the live site keeps the public id');
        $old = $this->row($alpha, 'tl2_');
        $this->assertNotSame($oldId, $old['public_id']);
        $this->assertSame('trashed', $old['status']);
        $this->assertNull($old['transfer_to']);
        $this->assertSame('Shop (transferred to Beta)', $old['name']);
        $this->assertSame(1, (int) $alpha->value('SELECT COUNT(*) FROM {sites} WHERE public_id = ?', [$oldId]) + (int) $beta->value('SELECT COUNT(*) FROM {sites} WHERE public_id = ?', [$oldId]), 'one row carries the id');

        $this->assertSame(1, (int) $copy->value('SELECT COUNT(*) FROM {enquiries}'), 'visitors\' data moves');
        $this->assertSame('mail-secret', $copy->value("SELECT value FROM {settings} WHERE name = 'smtp_password'"), 'secrets move');
        $this->assertSame('https://shop.alpha.example', $copy->value("SELECT value FROM {settings} WHERE name = 'site_url'"));
        $this->assertNotSame('old-key', $copy->value("SELECT value FROM {settings} WHERE name = 'secret_key'"), 'new signing key');
        $this->assertNotSame('', $copy->value("SELECT value FROM {settings} WHERE name = 'secret_key'"));
        $this->assertSame(0, (int) $copy->value('SELECT COUNT(*) FROM {mail}'), 'queues stay');
        $this->assertSame($annaInBeta, (int) $copy->value("SELECT author_id FROM {news} WHERE slug = 'by-anna'"), 'same e-mail, other case');
        $this->assertNull($copy->value("SELECT author_id FROM {news} WHERE slug = 'by-bob'"));
        $this->assertSame('Bob', $copy->value("SELECT user_name FROM {change_log} WHERE action = 'save'"));
        $this->assertNull($copy->value("SELECT user_id FROM {change_log} WHERE action = 'save'"));

        $this->assertSame([], $alpha->all('SELECT host FROM {site_domains} WHERE site_id = 2 AND kind = ?', ['domain']), 'the old site keeps no host name');
        $host = (array) $beta->one("SELECT * FROM {site_domains} WHERE host = 'shop.alpha.example'");
        $this->assertSame((int) $moved['site_id'], (int) $host['site_id']);
        $this->assertNotNull($host['verified_at'], 'verification state moves along');
        $this->assertTrue((bool) $host['is_primary']);

        $this->assertSame('transfer', $alpha->forSite('tl_')->value("SELECT action FROM {change_log} WHERE action = 'transfer'"));
        $this->assertStringContainsString('Olivia Operator', (string) $alpha->forSite('tl_')->value("SELECT user_name FROM {change_log} WHERE action = 'transfer'"));
        $this->assertSame('moved from owner', $copy->value("SELECT action FROM {change_log} WHERE action = 'moved from owner'"));
        $this->assertSame(0, Sites::pending($beta) === [] ? 0 : count(array_filter(Sites::pending($beta), fn (array $s): bool => $s['prefix'] === 'tlb2_')));
    }

    public function testAMovedChildLeavesItsLookFamily(): void
    {
        $alpha = $this->db();
        $alpha->run('UPDATE {sites} SET look_parent_id = 1 WHERE site_id = 2');
        $beta = $alpha->forOwner('tlb_');
        Sites::provision($beta, $this->queue());
        $this->assertNull($this->row($beta, 'tlb2_')['look_parent_id']);
    }

    public function testAFailedCopyDropsTheNewSiteAndFreesTheOldOne(): void
    {
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        $shop = $alpha->forSite('tl2_');
        // a media file whose folder in the new owner cannot be made (a file is in the way): the copy stops half-way
        $betaId = (string) $alpha->value('SELECT public_id FROM {owners} WHERE prefix = ?', ['tlb_']);
        $file = TALEA_ROOT . '/media/2026/10/transfer-test-' . getmypid() . '.jpg';
        $blocker = TALEA_ROOT . '/media/' . $betaId;
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, 'x');
        file_put_contents($blocker, 'in the way');
        try {
            $path = 'media/2026/10/' . basename($file);
            $alpha->insert('media', ['image_path' => $path, 'thumb_path' => '', 'created_at' => date('Y-m-d H:i:s')]);
            $shop->insert('pages', ['title' => 'P', 'slug' => 'p', 'text' => '<img src="' . $path . '">']);
            $row = $this->queue('tlb3_');

            try {
                Sites::provision($beta, $row);
                $this->fail('The copy should have failed.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot create the folder', $e->getMessage());
            }

            $this->assertNull($beta->value('SELECT 1 FROM {sites} WHERE prefix = ?', ['tlb3_']), 'the new site\'s row is gone');
            if (!$this->isSqlite()) { // SQLite cannot switch its foreign keys off inside the test's transaction, so the drop is refused there (and reported)
                $this->assertFalse($beta->forSite('tlb3_')->tableExists('settings'), 'and its tables');
            }
            $old = $this->row($alpha, 'tl2_');
            $this->assertNull($old['transfer_to']);
            $this->assertStringContainsString('Cannot create the folder', $old['transfer_error']);
            $this->assertNotSame('trashed', $old['status'], 'the old site is untouched');
        } finally {
            @unlink($file);
            @unlink($blocker);
        }
    }
}
