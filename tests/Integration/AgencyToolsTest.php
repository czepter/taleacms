<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Auth;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\SiteFigures;
use Talea\Core\SiteHealth;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite, agency tools (docs/specs/multisite.md, stage D): roles per site (user_sites), the limits of sites and owners, the health
 * figures and the usage file. Owner 1 has the sites main (1) and Copy (2); owner B ("Beta") has its first site and "Beta shop" (tlb2_).
 */
#[CoversClass(Sites::class)]
#[CoversClass(SiteHealth::class)]
#[CoversClass(Owners::class)]
final class AgencyToolsTest extends DatabaseTestCase
{
    private static bool $made = false;

    protected function setUp(): void
    {
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
                $b = Owners::add($db, 'Beta', 'organisation');
                $lines = Owners::provisionPending($db, $config);
                if ($lines !== ['owner tlb_: ready']) {
                    throw new \RuntimeException(implode("\n", $lines));
                }
                Sites::add($db->forOwner($b), 'Beta shop', 'beta-shop');
                Sites::add($db, '=Copy', 'copy', 1, true); // a name that a spreadsheet would run as a formula
                Migrator::migrate($config);
                foreach ([$db, $db->forOwner($b)] as $o) {
                    $o->run('UPDATE {sites} SET ready_at = ? WHERE ready_at IS NULL', [date('Y-m-d H:i:s')]);
                }
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
    }

    protected function tearDown(): void
    {
        foreach (['TALEA_OWNERS', 'TALEA_MULTISITE', 'TALEA_MAX_SITES', 'TALEA_MAX_OWNERS'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    /** @return array<string, mixed> */
    private function user(string $name, int $admin, ?array $sites = null): array
    {
        $id = $this->db()->insert('users', ['username' => $name, 'password' => password_hash('long-enough-pass', PASSWORD_DEFAULT), 'name' => $name, 'email' => $name . '@a.example', 'admin' => $admin, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
        Sites::setUserSites($this->db(), $id, $sites ?? [], $sites !== null);

        return (array) $this->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$id]);
    }

    public function testOnlyTheFlagLimitsAnEmptyListMeansNoSiteAndAdministratorsIgnoreIt(): void
    {
        $plain = $this->user('plain', Auth::EDITOR);
        $this->assertNull(Sites::limitedTo($this->db(), $plain));
        $this->assertTrue(Sites::allows($this->db(), $plain, 2));

        $limited = $this->user('limited', Auth::EDITOR, [2]);
        $this->assertSame([2], Sites::limitedTo($this->db(), $limited));
        $this->assertSame([false, true], [Sites::allows($this->db(), $limited, 1), Sites::allows($this->db(), $limited, 2)]);

        $nowhere = $this->user('nowhere', Auth::EDITOR, []);
        $this->assertSame([], Sites::limitedTo($this->db(), $nowhere), 'limited with no rows: no site, never "every site"');
        $this->assertSame([false, false], [Sites::allows($this->db(), $nowhere, 1), Sites::allows($this->db(), $nowhere, 2)]);
        $this->assertFalse(Sites::allowsHere($this->db(), $nowhere));

        $admin = $this->user('boss', Auth::ADMIN, [2]);
        $this->assertNull(Sites::limitedTo($this->db(), $admin), 'rows of an administrator are ignored');
        $this->assertTrue(Sites::allows($this->db(), $admin, 1));
        $this->assertTrue(Sites::allows($this->db(), null, 1), 'nobody given: nothing to limit');

        putenv('TALEA_MULTISITE');
        $this->assertNull(Sites::limitedTo($this->db(), $limited), 'with multisite off the rows are not consulted');
    }

    public function testRowsAreReplacedAndGoWithTheUser(): void
    {
        $u = $this->user('swap', Auth::AUTHOR, [1, 2]);
        Sites::setUserSites($this->db(), (int) $u['user_id'], [2, 2], true);
        $this->assertSame([2], Sites::limitedTo($this->db(), $u + ['sites_limited' => 1]));
        Sites::setUserSites($this->db(), (int) $u['user_id'], [], true);
        $this->assertSame([], Sites::limitedTo($this->db(), $u + ['sites_limited' => 1]), 'an empty list stays limited');
        Sites::setUserSites($this->db(), (int) $u['user_id'], [], false);
        $this->assertNull(Sites::limitedTo($this->db(), (array) $this->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$u['user_id']])), 'every site again only by saying so');
        Sites::setUserSites($this->db(), (int) $u['user_id'], [1], true);
        $this->db()->delete('users', ['user_id' => (int) $u['user_id']]);
        $this->assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {user_sites} WHERE user_id = ?', [$u['user_id']]));
    }

    public function testTheRowsOfASiteInTheTrashSurviveASaveAndAClearRemovesThemAll(): void
    {
        $u = $this->user('keeps', Auth::EDITOR, [2]);
        Sites::trash($this->db(), 2); // the list on screen does not show it, so a save from that list cannot name it
        Sites::setUserSites($this->db(), (int) $u['user_id'], [], true);
        $this->assertSame([2], array_map('intval', array_column($this->db()->all('SELECT site_id FROM {user_sites} WHERE user_id = ?', [$u['user_id']]), 'site_id')), 'the trashed site\'s row is kept');
        $this->assertSame([2], Sites::limitedTo($this->db(), (array) $this->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$u['user_id']])), 'still limited, not unlimited');
        Sites::setUserSites($this->db(), (int) $u['user_id'], [], false, false);
        $this->assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {user_sites} WHERE user_id = ?', [$u['user_id']]));
    }

    public function testAPasswordSignInOnASiteTheUserMayNotWorkOnIsRefused(): void
    {
        $limited = $this->user('only1', Auth::EDITOR, [1]);
        $session = new \Talea\Core\Session(false, '/', 'tl_', 'tl_');
        $second = $this->db()->forSite('tl2_');
        $answer = (new Auth($second, $session))->login('only1', 'long-enough-pass', '203.0.113.7');
        $this->assertSame('You have no access to this site.', $answer);
        $this->assertNull($second->value('SELECT last_login_at FROM {users} WHERE user_id = ?', [$limited['user_id']]), 'nothing was recorded as a sign-in');
        $wrong = (new Auth($second, $session))->login('only1', 'not the password', '203.0.113.8');
        $this->assertNotSame('You have no access to this site.', $wrong, 'a wrong password gets the usual answer: the refusal does not tell which accounts exist');
    }

    public function testTheHandoffIsNotMadeOrClaimedForAPersonWhoMayNotWorkThere(): void
    {
        $admin = $this->user('hand-admin', Auth::ADMIN);
        $limited = $this->user('hand-limited', Auth::EDITOR, [1]);
        $token = Sites::handoffToken($this->db(), (int) $admin['user_id'], 2, '203.0.113.1');
        $this->assertSame((int) $admin['user_id'], Sites::claimHandoff($this->db(), $token, 2, '203.0.113.1'));

        try {
            Sites::handoffToken($this->db(), (int) $limited['user_id'], 2, '203.0.113.1');
            $this->fail('a token for a site the person may not work on');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
        // a token written before the limit was set is void
        $this->db()->insert('handoff_tokens', ['token_hash' => hash('sha256', str_repeat('b', 64)), 'user_id' => (int) $limited['user_id'], 'site_id' => 2, 'network_hash' => hash('sha256', 'talea-handoff|203.0.113.1'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
        $this->assertNull(Sites::claimHandoff($this->db(), str_repeat('b', 64), 2, '203.0.113.1'));
    }

    public function testTheOverviewListsOnlyTheSitesThePersonMayWorkOn(): void
    {
        $all = array_column(SiteFigures::overview($this->db())['sites'], 'site_id');
        $this->assertSame([1, 2], $all, 'owner 1 only: Beta\'s sites are not in its overview');
        $limited = $this->user('ov', Auth::EDITOR, [2]);
        $this->assertSame([2], array_column(SiteFigures::overview($this->db(), $limited)['sites'], 'site_id'));
    }

    public function testTheLimitsCountEveryOwnerAndIgnoreTheTrash(): void
    {
        $this->assertSame(4, Sites::installCount($this->db()), 'two sites of owner 1 and two of Beta');
        $this->assertNull(Sites::limitRefusal($this->db()), 'no limit set');
        putenv('TALEA_MAX_SITES=5');
        $this->assertNull(Sites::limitRefusal($this->db()));
        putenv('TALEA_MAX_SITES=4');
        $this->assertStringContainsString('limit of 4 sites', (string) Sites::limitRefusal($this->db()));
        $this->assertStringContainsString('limit of 4 sites', (string) Sites::limitRefusal($this->db()->forOwner('tlb_')), 'the limit is the install\'s, Beta\'s sites count too');
        $this->db()->update('sites', ['status' => 'trashed'], ['site_id' => 2]);
        $this->assertNull(Sites::limitRefusal($this->db()), 'a site in the trash does not count');
        $this->db()->update('sites', ['status' => 'paused'], ['site_id' => 2]);
        $this->db()->update('sites', ['status' => 'trashed', 'trashed_at' => date('Y-m-d H:i:s')], ['site_id' => 2]);
        $this->assertNull(Sites::restore($this->db(), 2), 'room for it: restored');
        $this->assertSame('paused', $this->db()->value('SELECT status FROM {sites} WHERE site_id = 2'));
        $this->db()->update('sites', ['status' => 'trashed'], ['site_id' => 2]);
        Sites::add($this->db()->forOwner('tlb_'), 'One more', 'one-more'); // the room was taken meanwhile
        $this->assertStringContainsString('limit of 4 sites', (string) Sites::restore($this->db(), 2), 'a restore counts like a new site');
        $this->assertSame('trashed', $this->db()->value('SELECT status FROM {sites} WHERE site_id = 2'));

        $this->assertNull(Owners::limitRefusal($this->db()));
        putenv('TALEA_MAX_OWNERS=2');
        $this->assertStringContainsString('limit of 2 owners', (string) Owners::limitRefusal($this->db()));
        putenv('TALEA_MAX_OWNERS=3');
        $this->assertNull(Owners::limitRefusal($this->db()));
    }

    public function testHealthIsCountedPerSiteAndNeverAcrossOwners(): void
    {
        $one = $this->db();
        $beta = $this->db()->forOwner('tlb_');
        $row = fn (int $status, string $category = '', ?string $deleted = null): array => ['created_at' => date('Y-m-d H:i:s'), 'data' => '[]', 'status' => $status, 'category' => $category, 'deleted_at' => $deleted];
        foreach ([$row(0), $row(1), $row(2), $row(0, 'spam'), $row(0, '', date('Y-m-d H:i:s'))] as $e) {
            $one->insert('enquiries', $e);
        }
        $one->insert('broken_links', ['kind' => 'page', 'target_id' => 1, 'url' => 'https://gone.example', 'status' => 404, 'checked_at' => date('Y-m-d H:i:s')]);
        $media = ['name' => '', 'image_path' => 'media/a.jpg', 'thumb_path' => 'media/a-t.jpg', 'created_at' => date('Y-m-d H:i:s')];
        $one->insert('media', $media);
        $one->insert('media', ['name' => 'Described', 'image_path' => 'media/b.jpg'] + $media);

        $this->assertSame(['enquiries' => 2, 'broken_links' => 1], SiteHealth::site($one));
        $this->assertSame(['enquiries' => 0, 'broken_links' => 0], SiteHealth::site($one->forSite('tl2_')));
        $this->assertSame(['sites' => 2, 'enquiries' => 2, 'broken_links' => 1, 'media_without_alt' => 1], SiteHealth::owner($one));
        $this->assertSame(['sites' => 2, 'enquiries' => 0, 'broken_links' => 0, 'media_without_alt' => 0], SiteHealth::owner($beta), 'owner B is not counted with owner 1');
    }

    public function testTheUsageFileHasOneRowPerSiteAndMonthAndKeepsOwnersApart(): void
    {
        $one = $this->db();
        $now = date('Y-m-d H:i:s');
        $one->insert('mail', ['recipient' => 'a@a.example', 'subject' => 's', 'created_at' => $now, 'sent_at' => $now]);
        $one->insert('mail', ['recipient' => 'b@a.example', 'subject' => 's', 'created_at' => $now, 'sent_at' => null]);
        $one->insert('enquiries', ['created_at' => $now, 'data' => '[]', 'status' => 0, 'category' => '']);

        $rows = SiteHealth::usageRows($one, 'Owner One', 3);
        $this->assertCount(2 * 3, $rows, 'two sites, three months');
        $this->assertSame(['Owner One', 'main', date('Y-m'), 1, 1], [$rows[0][0], $rows[0][1], $rows[0][2], $rows[0][4], $rows[0][6]]);
        $this->assertGreaterThan(0, $rows[0][3], 'storage on the current month');
        $this->assertSame('', $rows[1][3], 'and only there');
        $this->assertSame(0, $rows[1][4]);
        $this->assertCount(SiteHealth::MAX_MONTHS, SiteHealth::months(99));
        $this->assertCount(12, SiteHealth::months(0));

        $csv = SiteHealth::usageCsv($one, 1);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=Copy", $csv, 'a name that starts like a formula is quoted');
        $this->assertStringNotContainsString('Beta', $csv, 'an owner\'s own file has its own sites only');
        $all = SiteHealth::usageCsvAll($one, 1);
        $this->assertStringContainsString('Beta shop', $all);
        $this->assertStringContainsString('main', $all);
        $this->assertStringNotContainsString('Assistant', $all, 'the assistant records no calls: no column for them');
    }
}
