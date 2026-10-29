<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite stage 3 (docs/specs/multisite.md): the "Share with all sites" box on Components and Facts and what the other site then sees, the
 * page cache that a shared change clears for every site, and the inbox lists (Enquiries, Bookings) with "all sites". Site 1 answers to
 * 127.0.0.1 ("First"), the second site to second.test ("Second"). The tests run in order and build on each other.
 */
#[Group('site')]
final class SharedAcrossSitesTest extends SiteTestCase
{
    private static ?\Talea\Tests\Site\Support\Http $second = null;

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    /** @return list<string> */
    private function onSecond(): array
    {
        return ['Host: second.test:' . $this->site()->port];
    }

    private function secondGet(string $path): Response
    {
        return (self::$second ?? throw new \LogicException('The second site is not set up.'))->get($path, $this->onSecond());
    }

    /** @param array<string, string> $fields */
    private function secondPost(string $path, array $fields): Response
    {
        $csrf = $this->secondGet('/admin.php')->csrf();

        return (self::$second ?? throw new \LogicException('The second site is not set up.'))->post($path, ['_csrf' => $csrf] + $fields, $this->onSecond());
    }

    /** @param array<string, string> $fields */
    private function firstPost(string $path, array $fields): Response
    {
        $admin = $this->site()->admin();

        return $admin->post($path, ['_csrf' => $this->site()->csrf($admin, '/admin.php')] + $fields);
    }

    private function cacheFile(string $folder): string
    {
        return $this->site()->root . '/storage/cache/' . $folder . '/probe.html';
    }

    private function fillCaches(): void
    {
        foreach (['pages', 'pages-tl2_'] as $folder) {
            @mkdir(dirname($this->cacheFile($folder)), 0775, true);
            file_put_contents($this->cacheFile($folder), 'cached');
        }
    }

    public function testASecondSiteIsSetUpAndTheAdministratorIsSignedInOnIt(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $this->firstPost('/admin.php?module=sites&action=create', ['name' => 'Second', 'from' => '']);
        $second = $site->publicId('sites', 2);
        $this->firstPost('/admin.php?module=sites&action=domain_add', ['id' => $second, 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $this->firstPost('/admin.php?module=sites&action=save', ['id' => $second, 'name' => 'Second', 'status' => 'live', 'redirect_to' => '']);
        $this->firstPost('/admin.php?module=sites&action=save', ['id' => $site->publicId('sites', 1), 'name' => 'First', 'status' => 'live', 'redirect_to' => '']);

        $r = $this->firstPost('/admin.php?module=sites&action=handoff', ['id' => $second]);
        $this->assertSame(303, $r->status);
        self::$second = $site->client('second');
        $this->assertSame(302, self::$second->get(substr($r->headers['location'], strpos($r->headers['location'], '/admin?')), $this->onSecond())->status);
        $this->assertSame(200, $this->secondGet('/admin.php?module=components')->status, 'signed in on the second site');
    }

    public function testASharedComponentShowsOnTheOtherSiteAndALocalOneDoesNot(): void
    {
        $site = $this->site();
        $form = $site->admin()->get('/admin.php?module=components&action=new');
        $this->assertStringContainsString('Share with all sites', $form->body);
        $this->assertDoesNotMatchRegularExpression('/name="shared"[^>]*checked/', $form->body, 'a new component is the site\'s own unless the box is ticked');

        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Shared card', 'shared' => '1']);
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Local card']);
        $this->assertNull($site->value("SELECT site_id FROM tl_components WHERE name = 'Shared card'"));
        $this->assertSame(1, (int) $site->value("SELECT site_id FROM tl_components WHERE name = 'Local card'"));

        $list = $this->secondGet('/admin.php?module=components')->body;
        $this->assertStringContainsString('Shared card', $list);
        $this->assertStringNotContainsString('Local card', $list, 'another site\'s own component is not listed');
        $this->assertStringContainsString('shared with all sites', $site->admin()->get('/admin.php?module=components')->body);

        $local = $site->publicId('components', (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Local card'"));
        $this->assertSame(404, $this->secondGet('/admin.php?module=components&action=edit&id=' . $local)->status, 'refused by id on the other site');
        $this->assertSame(404, $this->secondPost('/admin.php?module=components&action=save', ['component_id' => $local, 'name' => 'Taken over'])->status);
        $this->secondPost('/admin.php?module=components&action=delete', ['component_id' => $local]);
        $this->assertSame('Local card', $site->value('SELECT name FROM tl_components WHERE name = ?', ['Local card']), 'and not deleted from there');
        $this->assertSame(404, $site->client('visitor')->get('/_component/' . $local, ['Host: second.test:' . $site->port])->status, 'nor previewed there');

        // a shared component is shared for good, from the admin and over MCP
        $shared = $site->publicId('components', (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Shared card'"));
        $this->assertMatchesRegularExpression('/name="shared"[^>]*checked disabled/', $site->admin()->get('/admin.php?module=components&action=edit&id=' . $shared)->body);
        $this->assertStringContainsString('stays shared', $site->mcpText('save_component', ['id' => $shared, 'shared' => false]));
        $site->mcpResult('save_component', ['name' => 'From Claude', 'shared' => true]);
        $this->assertNull($site->value("SELECT site_id FROM tl_components WHERE name = 'From Claude'"));
        $this->assertTrue(array_values(array_filter($site->mcpResult('list_components'), fn (array $c): bool => $c['name'] === 'From Claude'))[0]['shared']);
    }

    public function testASharedFactIsTheSameOnBothSitesUntilOneOverridesIt(): void
    {
        $site = $this->site();
        $this->firstPost('/admin.php?module=facts&action=save', ['key' => 'since', 'label' => 'Since', 'type' => 'year', 'value' => '2004', 'shared' => '1']);
        $this->assertNull($site->value("SELECT site_id FROM tl_facts WHERE fact_key = 'since'"));
        $this->assertStringContainsString('2004', $this->secondGet('/admin.php?module=facts')->body);

        // the second site saves it without the box: its own copy, the shared one stays
        $this->secondPost('/admin.php?module=facts&action=save', ['key' => 'since', 'label' => 'Since', 'type' => 'year', 'value' => '2010']);
        $this->assertSame([null, '2'], [$site->rows("SELECT site_id FROM tl_facts WHERE fact_key = 'since' ORDER BY site_id IS NULL DESC")[0]['site_id'], (string) $site->value("SELECT site_id FROM tl_facts WHERE fact_key = 'since' AND site_id IS NOT NULL")]);
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_facts WHERE fact_key = 'since'"));
        $this->assertStringContainsString('2010', $this->secondGet('/admin.php?module=facts')->body);
        $first = $site->admin()->get('/admin.php?module=facts')->body;
        $this->assertStringContainsString('2004', $first);
        $this->assertStringNotContainsString('2010', $first);

        // the second site cannot make its copy shared as well: one shared row per key
        $this->secondPost('/admin.php?module=facts&action=save', ['key' => 'since', 'label' => 'Since', 'type' => 'year', 'value' => '2010', 'shared' => '1']);
        $this->assertSame([1, 1], [(int) $site->value("SELECT COUNT(*) FROM tl_facts WHERE fact_key = 'since' AND site_id IS NULL"), (int) $site->value("SELECT COUNT(*) FROM tl_facts WHERE fact_key = 'since' AND site_id = 2")]);
        $shared = $site->mcpResult('save_fact', ['key' => 'everywhere', 'label' => 'Everywhere', 'value' => 'yes', 'shared' => true]);
        $this->assertSame('yes', $shared['value']);
        $this->assertNull($site->value("SELECT site_id FROM tl_facts WHERE fact_key = 'everywhere'"));
        $this->assertStringContainsString('everywhere', $this->secondGet('/admin.php?module=facts')->body);
    }

    public function testASharedChangeClearsThePageCacheOfEverySite(): void
    {
        $this->fillCaches();
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Another local one']);
        $this->assertFileDoesNotExist($this->cacheFile('pages'), 'a local change clears the site\'s own pages');
        $this->assertFileExists($this->cacheFile('pages-tl2_'), 'and leaves the other site\'s');

        $shared = $this->site()->publicId('components', (int) $this->site()->value("SELECT component_id FROM tl_components WHERE name = 'Shared card'"));
        $this->fillCaches();
        $this->firstPost('/admin.php?module=components&action=save', ['component_id' => $shared, 'name' => 'Shared card, renamed']);
        $this->assertFileDoesNotExist($this->cacheFile('pages'));
        $this->assertFileDoesNotExist($this->cacheFile('pages-tl2_'), 'a shared component shows on every site');

        $this->fillCaches();
        $this->firstPost('/admin.php?module=facts&action=save', ['key' => 'plain', 'label' => 'Plain', 'value' => 'x']);
        $this->assertFileDoesNotExist($this->cacheFile('pages'));
        $this->assertFileExists($this->cacheFile('pages-tl2_'), 'a new fact is the site\'s own');

        $this->fillCaches();
        $this->firstPost('/admin.php?module=facts&action=save', ['key' => 'since', 'label' => 'Since', 'type' => 'year', 'value' => '2005', 'shared' => '1']);
        $this->assertSame('2005', (string) $this->site()->value("SELECT value FROM tl_facts WHERE fact_key = 'since' AND site_id IS NULL"));
        $this->assertFileDoesNotExist($this->cacheFile('pages-tl2_'), 'the shared fact shows on every site');
    }

    public function testTheInboxListsEnquiriesOfAllSites(): void
    {
        $site = $this->site();
        $now = date('Y-m-d H:i:s');
        $site->exec('INSERT INTO tl_enquiries (created_at, form, email, data) VALUES (?, ?, ?, ?)', [$now, 'Contact', 'one@example.com', '[]']);
        $site->exec('INSERT INTO tl2_enquiries (created_at, form, email, data) VALUES (?, ?, ?, ?)', [$now, 'Contact', 'two@example.com', '[]']);

        $here = $site->admin()->get('/admin.php?module=enquiries');
        $this->assertStringContainsString('one@example.com', $here->body);
        $this->assertStringNotContainsString('two@example.com', $here->body, 'this site only unless asked');
        $this->assertStringContainsString('name="sites"', $here->body, 'the select is offered');

        $all = $site->admin()->get('/admin.php?module=enquiries&sites=all');
        $this->assertStringContainsString('one@example.com', $all->body);
        $this->assertStringContainsString('two@example.com', $all->body);
        $this->assertStringContainsString('<span class="tag"><bdi>Second</bdi></span>', $all->body, 'each row names its site');
        $this->assertStringContainsString('Open on Second', $all->body);
        $this->assertStringNotContainsString('Open on First', $all->body, 'a row of this site is handled here');
        $this->assertStringContainsString('<option value="all" selected>', $all->body);
        $this->assertStringContainsString('sites=all', $all->body, 'the status tabs keep the choice');

        $found = $site->admin()->get('/admin.php?module=enquiries&sites=all&search=two%40');
        $this->assertStringContainsString('two@example.com', $found->body);
        $this->assertStringNotContainsString('one@example.com', $found->body, 'the search works over all sites');
        $this->assertStringNotContainsString('one@example.com', $site->admin()->get('/admin.php?module=enquiries&sites=all&status=resolved')->body);

        $site->exec('UPDATE tl2_enquiries SET deleted_at = ? WHERE email = ?', [$now, 'two@example.com']);
        $this->assertStringNotContainsString('two@example.com', $site->admin()->get('/admin.php?module=enquiries&sites=all')->body, 'the trash stays out');
        $this->assertStringContainsString('two@example.com', $site->admin()->get('/admin.php?module=enquiries&sites=all&status=trash')->body);
        $site->exec('UPDATE tl2_enquiries SET deleted_at = NULL WHERE email = ?', ['two@example.com']);

        // an editor (not an administrator) is not offered the choice, and asking for it changes nothing
        $site->exec("INSERT INTO tl_users (username, password, email, admin) VALUES ('editor', ?, 'editor@example.test', 1)", [password_hash('editor-pass-123', PASSWORD_DEFAULT)]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) VALUES ((SELECT user_id FROM tl_users WHERE username = 'editor'), 'enquiries')");
        $editor = $site->client('editor');
        $site->signIn($editor, 'editor', 'editor-pass-123');
        $asked = $editor->get('/admin.php?module=enquiries&sites=all');
        $this->assertSame(200, $asked->status);
        $this->assertStringContainsString('one@example.com', $asked->body);
        $this->assertStringNotContainsString('two@example.com', $asked->body);
        $this->assertStringNotContainsString('name="sites"', $asked->body);
    }

    public function testAnEnquiryOfAnotherSiteOpensThereThroughTheHandoff(): void
    {
        $site = $this->site();
        $public = (string) $site->value('SELECT public_id FROM tl2_enquiries WHERE email = ?', ['two@example.com']);
        $second = $site->publicId('sites', 2);

        $r = $this->firstPost('/admin.php?module=sites&action=handoff', ['id' => $second, 'to' => 'module=enquiries&action=detail&id=' . $public]);
        $this->assertSame(303, $r->status);
        $url = $r->headers['location'];
        $this->assertStringContainsString('second.test', $url);
        $browser = $site->client('lander');
        $landed = $browser->get(substr($url, strpos($url, '/admin?')), $this->onSecond());
        $this->assertSame(302, $landed->status);
        $this->assertStringContainsString($public, $landed->headers['location'], 'lands on the enquiry');
        $this->assertStringContainsString('two@example.com', $browser->get(substr($landed->headers['location'], (int) strpos($landed->headers['location'], '/admin')), $this->onSecond())->body);

        foreach (['to=https://evil.example/', 'to=module%3D..%2F..%2Fx', 'module=a&action=' . str_repeat('x', 60)] as $bad) {
            $r = $this->firstPost('/admin.php?module=sites&action=handoff', ['id' => $second, 'to' => $bad]);
            $this->assertStringNotContainsString('&to=', $r->headers['location'], 'only an administration address is a landing');
        }
    }

    public function testTheBookingsAgendaCanSpanAllSites(): void
    {
        $site = $this->site();
        $site->mcp('update_settings', ['settings' => ['extensions' => array_merge(explode(',', $site->settingValue('extensions')), ['bookings'])]]);
        $day = date('Y-m-d', strtotime('+3 days'));
        foreach (['tl_' => 'Anna Booker', 'tl2_' => 'Bruno Booker'] as $prefix => $name) {
            $site->exec("INSERT INTO {$prefix}booking_services (name, duration_min) VALUES ('Haircut', 30)");
            $site->exec("INSERT INTO {$prefix}booking_staff (name) VALUES ('Jana')");
            $site->exec("INSERT INTO {$prefix}bookings (service_id, staff_id, starts_at, ends_at, name, email, token_hash, created_at) VALUES ((SELECT MAX(id) FROM {$prefix}booking_services), (SELECT MAX(id) FROM {$prefix}booking_staff), ?, ?, ?, 'b@example.com', ?, ?)",
                ["$day 10:00:00", "$day 10:30:00", $name, hash('sha256', $name), date('Y-m-d H:i:s')]);
        }

        $here = $site->admin()->get('/admin.php?module=bookings');
        $this->assertStringContainsString('Anna Booker', $here->body);
        $this->assertStringNotContainsString('Bruno Booker', $here->body);
        $this->assertStringContainsString('name="sites"', $here->body);

        $all = $site->admin()->get('/admin.php?module=bookings&sites=all');
        $this->assertStringContainsString('Anna Booker', $all->body);
        $this->assertStringContainsString('Bruno Booker', $all->body);
        $this->assertStringContainsString('<span class="tag"><bdi>Second</bdi></span>', $all->body);
        $this->assertStringContainsString('Open on Second', $all->body);
        $this->assertStringNotContainsString('name="staff"', $all->body, 'people and services are per site');
        $this->assertStringContainsString('sites=all', $all->body);

        $site->exec('UPDATE tl2_bookings SET status = ?', ['cancelled']);
        $this->assertStringNotContainsString('Bruno Booker', $site->admin()->get('/admin.php?module=bookings&sites=all')->body, 'the default view shows what still has to happen');
        $this->assertStringContainsString('Bruno Booker', $site->admin()->get('/admin.php?module=bookings&sites=all&view=all')->body);
    }
}
