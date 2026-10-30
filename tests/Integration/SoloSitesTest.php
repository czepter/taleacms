<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\AllSitesReport;
use Talea\Core\App;
use Talea\Core\Migrator;
use Talea\Core\MonthlyReport;
use Talea\Core\Report;
use Talea\Core\Request;
use Talea\Core\Settings;
use Talea\Core\SiteFigures;
use Talea\Core\Sites;
use Talea\Core\SubscriberSites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite, the solopreneur additions (docs/specs/multisite.md): the hub cards, the owner's overview, the report for all sites and the
 * subscribers of all sites, on a first site and two further ones (Two, Three) that are set up.
 */
#[CoversClass(Sites::class)]
#[CoversClass(SiteFigures::class)]
#[CoversClass(AllSitesReport::class)]
#[CoversClass(SubscriberSites::class)]
#[CoversClass(MonthlyReport::class)]
final class SoloSitesTest extends DatabaseTestCase
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
                Sites::add($this->db(), 'Two', 'two');     // tl2_
                Sites::add($this->db(), 'Three', 'three'); // tl3_
                Migrator::migrate($config);
                $this->db()->run('UPDATE {sites} SET ready_at = ? WHERE ready_at IS NULL', [date('Y-m-d H:i:s')]);
                self::$sites = true;
            }
        }
        parent::setUp();
        Sites::forgetCurrent();
    }

    public static function tearDownAfterClass(): void
    {
        self::$sites = false;
        Sites::forgetCurrent();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    private function app(string $prefix = 'tl_'): App
    {
        $app = new App(['db' => $this->dbConfig() + ($prefix !== 'tl_' ? ['site_prefix' => $prefix] : [])], Request::fromGlobals());
        (new \ReflectionProperty(App::class, 'db'))->setValue($app, $this->db()->forSite($prefix));

        return $app;
    }

    private function siteId(string $prefix): int
    {
        return (int) $this->db()->value('SELECT site_id FROM {sites} WHERE prefix = ?', [$prefix]);
    }

    public function testTheHubCardsListTheOtherLiveSitesThatAreSwitchedOn(): void
    {
        $db = $this->db();
        [$two, $three] = [$this->siteId('tl2_'), $this->siteId('tl3_')];
        $this->assertNull(Sites::saveCard($db, $two, '  A shop for tea  ', 'media/2026/10/tea.jpg', true));
        $this->assertSame('A shop for tea', $db->value('SELECT card_summary FROM {sites} WHERE site_id = ?', [$two]));
        $this->assertSame([], Sites::hubCards($db, 1), 'a site without a host name has no link to show');

        Sites::addDomain($db, $two, 'tea.example');
        Sites::addDomain($db, $three, 'three.example');
        $this->assertSame([], Sites::hubCards($db, 1), 'paused');
        $db->update('sites', ['status' => 'live'], ['site_id' => $two]);
        $db->update('sites', ['status' => 'live'], ['site_id' => $three]);
        $this->assertSame([['name' => 'Two', 'summary' => 'A shop for tea', 'image' => 'media/2026/10/tea.jpg', 'host' => 'tea.example']], Sites::hubCards($db, 1), 'Three is not switched on');
        $this->assertSame([], Sites::hubCards($db, $two), 'the site itself is never listed on its own pages');

        Sites::saveCard($db, $three, '', '', true);
        $this->assertSame(['Two', 'Three'], array_column(Sites::hubCards($db, 1), 'name'));
        $this->assertSame(['Three'], array_column(Sites::hubCards($db, $two), 'name'));
    }

    public function testACardImageMustBeAMediaPath(): void
    {
        $two = $this->siteId('tl2_');
        foreach (['https://evil.example/x.jpg', 'media/../config.php', '/etc/passwd', 'media/a b.jpg'] as $bad) {
            $this->assertNotNull(Sites::saveCard($this->db(), $two, '', $bad, true), $bad);
        }
        $this->assertNotNull(Sites::saveCard($this->db(), 999, '', '', true), 'a site that does not exist');
    }

    public function testTheOverviewHasTheNumbersOfEachSite(): void
    {
        $db = $this->db();
        $day = date('Y-m-d');
        $db->insert('stats_days', ['day' => $day, 'visits' => 10, 'views' => 25]);
        $two = $db->forSite('tl2_');
        $two->insert('stats_days', ['day' => $day, 'visits' => 4, 'views' => 6]);
        $two->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'email' => 'a@example.com', 'data' => '{}']);
        $two->insert('subscribers', ['email' => 'sub@example.com', 'token' => str_repeat('b', 32), 'status' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $overview = SiteFigures::overview($db);

        $this->assertSame(['main', 'Two', 'Three'], array_column($overview['sites'], 'name'));
        foreach ($overview['sites'] as $site) {
            foreach (SiteFigures::PERIODS as $days) {
                $glance = Report::glance($db->forSite($db->value('SELECT prefix FROM {sites} WHERE site_id = ?', [$site['site_id']]) . ''), $days)['totals'];
                $row = $site['periods'][$days];
                $this->assertSame([$glance['visits'], $glance['views'], $glance['enquiries'], $glance['signups']], [$row['visits'], $row['views'], $row['enquiries'], $row['signups']], $site['name'] . ' ' . $days);
            }
        }
        $this->assertSame([10, 4], [$overview['sites'][0]['periods'][7]['visits'], $overview['sites'][1]['periods'][7]['visits']]);
        $this->assertSame(['visits' => 14, 'views' => 31, 'enquiries' => 1, 'bookings' => 0, 'signups' => 1, 'conversion' => 14.3], $overview['totals'][30], 'totals are the sums; the rate is 2 leads in 14 visits');
        $this->assertSame(50.0, $overview['sites'][1]['periods'][30]['conversion']);
        $this->assertNull($overview['sites'][2]['periods'][30]['conversion'], 'no visitors, no rate');
    }

    public function testATrashedOrUnfinishedSiteIsNotInTheOverview(): void
    {
        $this->db()->run("UPDATE {sites} SET status = 'trashed' WHERE prefix = 'tl3_'");
        $this->db()->run('UPDATE {sites} SET ready_at = NULL WHERE prefix = ?', ['tl2_']);
        $this->assertSame(['main'], array_column(SiteFigures::overview($this->db())['sites'], 'name'));
    }

    public function testTheReportForAllSitesHasASectionForEachOtherSite(): void
    {
        $db = $this->db();
        $month = (new \DateTimeImmutable('first day of last month'))->setTime(0, 0);
        $this->assertSame([], AllSitesReport::collect($this->app(), $month), 'multisite is off');

        putenv('TALEA_MULTISITE=1');
        $this->assertTrue(AllSitesReport::available($this->app()));
        $this->assertSame([], AllSitesReport::collect($this->app(), $month), 'the setting is off');
        $this->assertFalse(AllSitesReport::available($this->app('tl2_')), 'the setting belongs to site 1');

        $this->app()->settings()->set('report_all_sites', '1');
        $two = $db->forSite('tl2_');
        $two->insert('stats_days', ['day' => $month->format('Y-m-15'), 'visits' => 7, 'views' => 9]);
        $two->insert('enquiries', ['created_at' => $month->format('Y-m-10 10:00:00'), 'email' => 'x@example.com', 'data' => '{}']);

        $sections = AllSitesReport::collect($this->app(), $month);
        $this->assertSame(['Two', 'Three'], array_column($sections, 'name'));
        $this->assertSame([7, 9, 1], [$sections[0]['visits'], $sections[0]['views'], $sections[0]['enquiries']]);
        $this->assertSame([0, 0, 0], [$sections[1]['visits'], $sections[1]['views'], $sections[1]['enquiries']]);

        $mail = MonthlyReport::render(['month' => $month->format('Y-m'), 'sites' => $sections], new Settings($db), 'https://example.test');
        $this->assertStringContainsString('SITE: TWO', $mail['text']);
        $this->assertStringContainsString('SITE: THREE', $mail['text']);
        $this->assertStringContainsString('Site: Two', $mail['html']);
        $none = MonthlyReport::render(['month' => $month->format('Y-m')], new Settings($db), 'https://example.test');
        $this->assertStringNotContainsString('SITE: ', $none['text'], 'without the setting the report is as before');
    }

    public function testAFurtherSiteSendsNoReportWhenSiteOneCoversIt(): void
    {
        putenv('TALEA_MULTISITE=1');
        $two = $this->app('tl2_');
        $this->assertFalse(AllSitesReport::coveredBySiteOne($two));
        $this->app()->settings()->set('report_all_sites', '1');
        $two->settings()->set('report_monthly', '1');
        $this->assertTrue(AllSitesReport::coveredBySiteOne($two));
        $this->assertFalse(AllSitesReport::coveredBySiteOne($this->app()), 'site 1 reports itself');
        $this->assertSame('in the report of site 1', MonthlyReport::runIfDue($two));
        putenv('TALEA_MULTISITE');
        $this->assertFalse(AllSitesReport::coveredBySiteOne($two), 'multisite off: nothing is covered');
    }

    public function testTheSubscribersOfAllSitesAreOneRowPerAddress(): void
    {
        $db = $this->db();
        $add = function (\Talea\Core\Db $d, string $email, int $status, string $at): void {
            $d->insert('subscribers', ['email' => $email, 'token' => bin2hex(random_bytes(16)), 'status' => $status, 'created_at' => $at]);
        };
        $add($db, 'Both@Example.com', 1, '2026-10-01 10:00:00');
        $add($db->forSite('tl2_'), 'both@example.com', 0, '2026-10-05 10:00:00');
        $add($db->forSite('tl2_'), 'only-two@example.com', 1, '2026-10-02 10:00:00');
        $add($db->forSite('tl3_'), 'only-three@example.com', 0, '2026-10-03 10:00:00');
        $sites = array_map(fn (string $p): array => ['site_id' => $this->siteId($p), 'prefix' => $p], ['tl_', 'tl2_', 'tl3_']);

        $all = SubscriberSites::list($db, $sites, '', 1);

        $this->assertSame(4 - 1, $all['total']);
        $this->assertSame(2, $all['confirmed'], 'both@ and only-two@ are confirmed on at least one site');
        $this->assertSame(['both@example.com', 'only-three@example.com', 'only-two@example.com'], array_column($all['rows'], 'email'), 'the newest sign-up first');
        $this->assertSame([1 => 1, 2 => 0], $all['rows'][0]['sites'], 'the state on each site');
        $this->assertSame('2026-10-05 10:00:00', $all['rows'][0]['last']);

        $found = SubscriberSites::list($db, $sites, 'three', 1);
        $this->assertSame(['only-three@example.com'], array_column($found['rows'], 'email'));
        $this->assertSame([1, 0], [$found['total'], $found['confirmed']]);
        $this->assertSame([], SubscriberSites::list($db, $sites, '', 2)['rows'], 'the second page is empty');
    }
}
