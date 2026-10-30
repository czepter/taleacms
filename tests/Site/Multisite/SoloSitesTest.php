<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite, the solopreneur additions (docs/specs/multisite.md): the owner's overview tab, the hub card and the Projects element, the
 * subscribers of all sites and the report setting. Site 1 answers to 127.0.0.1 ("First"), the second site to second.test ("Second").
 * The tests run in order and build on each other.
 */
#[Group('site')]
final class SoloSitesTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    /** @param array<string, string> $fields */
    private function firstPost(string $path, array $fields, string $formPage = '/admin.php'): Response
    {
        $admin = $this->site()->admin();

        return $admin->post($path, ['_csrf' => $this->site()->csrf($admin, $formPage)] + $fields);
    }

    private function secondId(): string
    {
        return $this->site()->publicId('sites', 2);
    }

    public function testASecondSiteIsSetUp(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $this->firstPost('/admin.php?module=sites&action=create', ['name' => 'Second', 'from' => '']);
        $this->firstPost('/admin.php?module=sites&action=domain_add', ['id' => $this->secondId(), 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $this->firstPost('/admin.php?module=sites&action=save', ['id' => $this->secondId(), 'name' => 'Second', 'status' => 'live', 'redirect_to' => '']);
        $this->firstPost('/admin.php?module=sites&action=save', ['id' => $site->publicId('sites', 1), 'name' => 'First', 'status' => 'live', 'redirect_to' => '']);
        $this->assertSame('live', $site->value('SELECT status FROM tl_sites WHERE site_id = 2'));
    }

    public function testTheOverviewTabShowsEachSitesFigures(): void
    {
        $site = $this->site();
        $today = date('Y-m-d');
        $site->exec('INSERT INTO tl_stats_days (day, visits, views) VALUES (?, 40, 90)', [$today]);
        $site->exec('INSERT INTO tl2_stats_days (day, visits, views) VALUES (?, 12, 18)', [$today]);
        $site->exec('INSERT INTO tl2_enquiries (created_at, form, email, data) VALUES (?, ?, ?, ?)', [date('Y-m-d H:i:s'), 'Contact', 'ov@example.com', '[]']);

        $list = $site->admin()->get('/admin.php?module=sites');
        $this->assertMatchesRegularExpression('#/sites/overview|action=overview#', $list->body, 'the Sites list has the tab');

        $overview = $site->admin()->get('/admin.php?module=sites&action=overview');
        $this->assertSame(200, $overview->status);
        $this->assertStringContainsString('Last 7 days', $overview->body);
        $this->assertStringContainsString('Last 30 days', $overview->body);
        $this->assertMatchesRegularExpression('#<bdi>First</bdi></a></td>\s*<td class="number">40</td>\s*<td class="number">90</td>#', $overview->body);
        $this->assertMatchesRegularExpression('#<bdi>Second</bdi></a></td>\s*<td class="number">12</td>\s*<td class="number">18</td>\s*<td class="number">1</td>#', $overview->body);
        $this->assertMatchesRegularExpression('#<th scope="row">Total</th><td class="number">52</td><td class="number">108</td><td class="number">1</td>#', $overview->body, 'the totals are the sums');
    }

    public function testTheHubCardShowsOnTheProjectsElementOfTheOtherSite(): void
    {
        $site = $this->site();
        $edit = $site->admin()->get('/admin.php?module=sites&action=edit&id=' . $this->secondId());
        $this->assertStringContainsString('name="card_summary"', $edit->body);
        $this->assertStringContainsString('name="show_in_hub"', $edit->body);

        $site->mcp('create_page', ['title' => 'Projects', 'slug' => 'projects', 'visible' => true]);
        $build = '{"v":1,"children":[{"id":"sc1","type":"site_cards","tag":"div","content":{"count":6,"link_text":"Visit the site"},"style":{},"classes":[],"children":[]}]}';
        $site->exec('UPDATE tl_pages SET build = ?, build_draft = ?, visible = TRUE WHERE slug = ?', [$build, $build, 'projects']);
        $visitor = $site->client('visitor');
        $this->assertStringNotContainsString('class="tl-site-card"', $visitor->get('/projects')->body, 'nobody is switched on yet');

        $save = fn (string $summary, string $show) => $this->firstPost('/admin.php?module=sites&action=save', ['id' => $this->secondId(), 'name' => 'Second', 'status' => 'live', 'redirect_to' => '', 'card_summary' => $summary, 'card_image' => '', 'show_in_hub' => $show]);
        $this->assertSame(302, $save('Tea from the hills', '1')->status);
        $this->assertSame('Tea from the hills', $site->value('SELECT card_summary FROM tl_sites WHERE site_id = 2'));
        $body = $visitor->get('/projects')->body;
        $this->assertStringContainsString('<h3>Second</h3>', $body);
        $this->assertStringContainsString('Tea from the hills', $body);
        $this->assertStringContainsString('href="http://second.test:' . $site->port . '/"', $body, 'the link goes to the primary host');
        $this->assertStringNotContainsString('<h3>First</h3>', $body, 'a site does not list itself');

        $save('Tea from the valley', '1'); // the cached copy of the page is gone with the change
        $this->assertStringContainsString('Tea from the valley', $visitor->get('/projects')->body);
        $save('Tea from the valley', '');
        $this->assertStringNotContainsString('class="tl-site-card"', $visitor->get('/projects')->body, 'switched off again: one site, nothing to show');

        $bad = $this->firstPost('/admin.php?module=sites&action=save', ['id' => $this->secondId(), 'name' => 'Second', 'status' => 'live', 'redirect_to' => '', 'card_image' => 'https://evil.example/a.jpg', 'show_in_hub' => '1']);
        $this->assertSame(302, $bad->status);
        $this->assertSame('', $site->value('SELECT card_image FROM tl_sites WHERE site_id = 2'), 'only a media path is taken');
    }

    public function testClaudeSetsTheCardOverMcp(): void
    {
        $site = $this->site();
        $r = $site->mcpResult('update_site', ['id' => $this->secondId(), 'card_summary' => 'From Claude', 'card_image' => 'media/2026/10/tea.jpg', 'show_in_hub' => true]);
        $this->assertSame(['summary' => 'From Claude', 'image' => 'media/2026/10/tea.jpg', 'show_in_hub' => true], $r['site']['card']);
        $this->assertSame(1, (int) $site->value('SELECT show_in_hub FROM tl_sites WHERE site_id = 2'));
        $this->assertStringContainsString('media library', $site->mcpText('update_site', ['id' => $this->secondId(), 'card_image' => '../x']));
        $this->assertContains('site_cards', array_column((array) ($site->mcpResult('builder_schema', ['full' => true])['elements'] ?? []), 'type'), 'the element is offered with several sites');
    }

    public function testTheSubscribersOfAllSitesAreOneRowPerAddress(): void
    {
        $site = $this->site();
        $now = date('Y-m-d H:i:s');
        $site->exec('INSERT INTO tl_subscribers (public_id, email, token, status, created_at) VALUES (?, ?, ?, 1, ?)', [\Talea\Core\Uuid::v4(), 'shared@example.com', bin2hex(random_bytes(16)), $now]);
        $site->exec('INSERT INTO tl2_subscribers (public_id, email, token, status, created_at) VALUES (?, ?, ?, 0, ?)', [\Talea\Core\Uuid::v4(), 'shared@example.com', bin2hex(random_bytes(16)), $now]);
        $site->exec('INSERT INTO tl2_subscribers (public_id, email, token, status, created_at) VALUES (?, ?, ?, 1, ?)', [\Talea\Core\Uuid::v4(), 'second-only@example.com', bin2hex(random_bytes(16)), $now]);

        $here = $site->admin()->get('/admin.php?module=subscribers');
        $this->assertStringContainsString('shared@example.com', $here->body);
        $this->assertStringNotContainsString('second-only@example.com', $here->body, 'this site only unless asked');
        $this->assertStringContainsString('name="sites"', $here->body);

        $all = $site->admin()->get('/admin.php?module=subscribers&sites=all');
        $this->assertSame(1, substr_count($all->body, 'shared@example.com'), 'one row per address');
        $this->assertStringContainsString('second-only@example.com', $all->body);
        $this->assertStringContainsString('<option value="all" selected>', $all->body);
        $this->assertStringContainsString('<bdi>First</bdi> · confirmed', $all->body);
        $this->assertStringContainsString('<bdi>Second</bdi> · awaiting confirmation', $all->body, 'the state on each site');
        $this->assertStringContainsString('name="to" value="module=subscribers"', $all->body, 'a chip of the other site opens there');
        $this->assertStringContainsString('title="Open on Second"', $all->body);
        $this->assertStringNotContainsString('title="Open on First"', $all->body);
        $this->assertStringNotContainsString('action=delete', $all->body, 'nothing is changed from the combined list');

        $found = $site->admin()->get('/admin.php?module=subscribers&sites=all&search=second-only');
        $this->assertStringContainsString('second-only@example.com', $found->body);
        $this->assertStringNotContainsString('shared@example.com', $found->body);
    }

    public function testTheReportSettingIsOnSiteOneOnly(): void
    {
        $site = $this->site();
        $tab = '/admin.php?module=settings&tab=reports';
        $this->assertStringContainsString('name="report_all_sites"', $site->admin()->get($tab)->body);

        $this->firstPost('/admin.php?module=settings&action=save', ['tab' => 'reports', 'report_monthly' => '1', 'report_all_sites' => '1'], $tab);
        $this->assertSame('1', $site->settingValue('report_all_sites'));
        $this->firstPost('/admin.php?module=settings&action=save', ['tab' => 'reports', 'report_monthly' => '1'], $tab);
        $this->assertSame('0', $site->settingValue('report_all_sites'), 'an unticked box turns it off');

        $site->exec("UPDATE tl_settings SET value = '1' WHERE name = 'report_all_sites'");
        $second = $site->client('second');
        $handoff = $this->firstPost('/admin.php?module=sites&action=handoff', ['id' => $this->secondId()]);
        $on = ['Host: second.test:' . $site->port];
        $second->get(substr($handoff->headers['location'], strpos($handoff->headers['location'], '/admin?')), $on);
        $this->assertStringNotContainsString('report_all_sites', $second->get($tab, $on)->body, 'a further site has no such box');
    }
}
