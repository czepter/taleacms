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
    /** @var resource|null the fake SMTP server of the member login test */
    private static $smtp = null;
    private static string $mailDir = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$smtp !== null) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
            self::$smtp = null;
        }
        parent::tearDownAfterClass();
    }

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

    public function testRenamingAClassLeavesTheOtherSitesComponentsAlone(): void
    {
        $site = $this->site();
        $build = '{"v":1,"children":[{"id":"a1","type":"section","tag":"section","content":{},"classes":["tile"]}]}';
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Own with tile']);
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Shared with tile', 'shared' => '1']);
        $this->secondPost('/admin.php?module=components&action=save', ['name' => 'Second with tile']);
        foreach (['Own with tile', 'Shared with tile', 'Second with tile'] as $name) {
            $site->exec('UPDATE tl_components SET build = ? WHERE name = ?', [$build, $name]);
        }

        $usage = json_decode($this->firstPost('/admin.php?module=pages&action=build_class', ['name' => 'tile', 'usage' => '1'])->body, true)['usage'];
        $this->assertEqualsCanonicalizing(['component Own with tile', 'component Shared with tile'], array_values(array_filter($usage, fn (string $u): bool => str_contains($u, 'with tile'))), 'the places named are this site\'s own and the shared ones');

        $this->assertTrue(json_decode($this->firstPost('/admin.php?module=pages&action=build_class', ['name' => 'tile', 'new_name' => 'panel'])->body, true)['ok']);
        $classes = fn (string $name): string => (string) $site->value('SELECT build FROM tl_components WHERE name = ?', [$name]);
        $this->assertStringContainsString('"panel"', $classes('Own with tile'));
        $this->assertStringContainsString('"tile"', $classes('Shared with tile'), 'a shared component is not rewritten for one site');
        $this->assertStringContainsString('"tile"', $classes('Second with tile'), 'nor is another site\'s own');
    }

    public function testAFormCannotLiveInASharedComponent(): void
    {
        $site = $this->site();
        $form = '{"v":1,"children":[{"id":"fm1","type":"form","tag":"form","content":{}}]}';
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Holds a form']);
        $site->exec('UPDATE tl_components SET build_draft = ? WHERE name = ?', [$form, 'Holds a form']);
        $own = $site->publicId('components', (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Holds a form'"));

        // sharing it is refused, from the admin and over MCP
        $this->firstPost('/admin.php?module=components&action=save', ['component_id' => $own, 'name' => 'Holds a form', 'shared' => '1']);
        $this->assertSame(1, (int) $site->value("SELECT site_id FROM tl_components WHERE name = 'Holds a form'"));
        $this->assertStringContainsString('cannot contain a form', $site->mcpText('save_component', ['id' => $own, 'shared' => true]));
        $this->assertSame(1, (int) $site->value("SELECT site_id FROM tl_components WHERE name = 'Holds a form'"));

        // and a form cannot be saved or published into one that is already shared
        $shared = $site->publicId('components', (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Shared card'"));
        $saved = $this->firstPost('/admin.php?module=components&action=build_save&id=' . $shared, ['build' => $form]);
        $this->assertSame(422, $saved->status);
        $this->assertStringContainsString('cannot contain a form', $saved->body);
        $this->assertStringContainsString('cannot contain a form', $site->mcpText('save_build', ['component' => $shared, 'build' => json_decode($form, true)]));
        $this->assertStringNotContainsString('"form"', implode(' ', array_map('strval', $site->rows("SELECT build, build_draft FROM tl_components WHERE name = 'Shared card'")[0])), 'nothing of it was stored');

        // a component of this site only takes one, and the form then belongs to this site
        $this->assertStringNotContainsString('cannot contain a form', $site->mcpText('save_build', ['component' => $own, 'build' => json_decode($form, true)]));
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

    /** The path and query of the newest sign-in link mailed to an address (the mails are caught by tools/fake-smtp.php). */
    private function memberLinkTo(string $email, int $skip): string
    {
        for ($i = 0; $i < 60; $i++) {
            $files = glob(self::$mailDir . '/*.eml') ?: [];
            sort($files);
            foreach (array_reverse(array_slice($files, $skip)) as $file) {
                [$headers, $body] = array_pad(explode("\r\n\r\n", (string) file_get_contents($file), 2), 2, '');
                $text = $headers . "\n" . (str_contains($headers, 'base64') ? base64_decode((string) preg_replace('/\s+/', '', $body)) : $body);
                if (str_contains($text, 'X-Rcpt-To: ' . $email) && preg_match('~https?://[^\s]+/member/verify\?([^\s]+)~', $text, $m) === 1) {
                    return '/member/verify?' . $m[1];
                }
            }
            usleep(100_000);
        }
        $this->fail('No sign-in link was mailed to ' . $email);
    }

    public function testAMemberLoginLinkOfOneSiteDoesNotSignInOnAnother(): void
    {
        $site = $this->site();
        $port = $site->freePort();
        self::$mailDir = $site->workDir('smtp-sites');
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, self::$mailDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
        $extensions = $site->settingValue('extensions') . ',members';
        foreach (['tl_', 'tl2_'] as $prefix) { // both sites: SMTP to the fake server and the members extension
            foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.org', 'extensions' => $extensions] as $name => $value) {
                $site->exec("REPLACE INTO {$prefix}settings (name, value) VALUES (?, ?)", [$name, $value]);
            }
        }

        foreach (['tl_', 'tl2_'] as $prefix) { // a known member on both sites (a link is only mailed to one, unless sign-up is open)
            $site->exec("INSERT INTO {$prefix}members (email, created_at) VALUES ('same.person@example.com', ?)", [date('Y-m-d H:i:s')]);
        }
        $a = $site->client('member-a');
        $b = $site->client('member-b');
        $hostA = ['Host: 127.0.0.1:' . $site->port];
        $email = 'same.person@example.com'; // the same address on both sites: only the link's site tells them apart
        $a->post('/member', ['email' => $email, 'website' => ''], $hostA);
        $linkA = $this->memberLinkTo($email, 0);
        $sent = count(glob(self::$mailDir . '/*.eml') ?: []); // B's link is looked for after these
        
        $this->secondPostPublic('/member', ['email' => $email, 'website' => ''], $b);
        $linkB = $this->memberLinkTo($email, $sent);
        $this->assertNotSame($linkA, $linkB, 'each site mails its own link');
        $tokenA = (string) preg_replace('/^.*token=([a-f0-9]{64}).*$/', '$1', $linkA);

        // site A's link, claimed on site B
        $this->assertSame(400, $b->get($linkA, $this->onSecond())->status, 'site B does not even show the button for it');
        $wrong = $b->post('/member/verify', ['token' => $tokenA, 'return' => ''], $this->onSecond());
        $this->assertNotSame(303, $wrong->status, 'the other site does not sign anybody in');
        $this->assertArrayNotHasKey('tl_member', $b->cookies(), 'no member session on B');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_member_tokens WHERE used_at IS NULL AND token_hash = ?', [hash('sha256', $tokenA)]), 'and the link was not consumed by B');

        // still good on site A
        $right = $a->post('/member/verify', ['token' => $tokenA, 'return' => ''], $hostA);
        $this->assertSame(303, $right->status, 'the link works on its own site afterwards');
        $this->assertArrayHasKey('tl_member', $a->cookies());
    }

    /** @param array<string, string> $fields */
    private function secondPostPublic(string $path, array $fields, \Talea\Tests\Site\Support\Http $as): Response
    {
        return $as->post($path, $fields, $this->onSecond());
    }

    public function testACloneRendersLikeItsSource(): void
    {
        $site = $this->site();
        $first = $site->publicId('sites', 1);

        // site 1: a home page and a page whose build uses a component of this site only
        $this->firstPost('/admin.php?module=components&action=save', ['name' => 'Clone banner']);
        $component = (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Clone banner'");
        $this->assertSame(1, (int) $site->value('SELECT site_id FROM tl_components WHERE component_id = ?', [$component]), 'a local component');
        $site->exec('UPDATE tl_components SET build = ?, build_draft = ? WHERE component_id = ?', [$banner = json_encode(['v' => 1, 'children' => [['id' => 'b1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Banner of the local component']]]]), $banner, $component]);
        $home = $site->mcpResult('create_page', ['title' => 'Start here', 'slug' => 'start-here', 'content' => '<p>Welcome text of the home page</p>', 'visible' => true]);
        $site->setting('home_page', (string) $site->rowId($home['id']));
        $about = $site->mcpResult('create_page', ['title' => 'About us', 'slug' => 'clone-about', 'content' => '<p>Text</p>', 'visible' => true]);
        $saved = $site->mcpText('save_build', ['id' => $about['id'], 'publish' => true, 'build' => ['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'tag' => 'section', 'content' => new \stdClass(), 'children' => [
            ['id' => 'h1', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'About the clone']],
            ['id' => 'c1', 'type' => 'component', 'tag' => 'div', 'content' => ['component' => $site->publicId('components', $component)]],
        ]]]]]);
        $this->assertStringContainsString('component', (string) $site->value('SELECT build FROM tl_pages WHERE slug = ?', ['clone-about']), $saved);
        $site->clearPageCache();

        // the clone: a row from the Sites area, set up by cron, with a domain of its own
        $this->firstPost('/admin.php?module=sites&action=create', ['name' => 'Twin', 'from' => $first]);
        $twin = $site->publicId('sites', 3);
        $this->firstPost('/admin.php?module=sites&action=domain_add', ['id' => $twin, 'host' => 'twin.test']);
        $this->assertStringContainsString('site tl3_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $this->firstPost('/admin.php?module=sites&action=save', ['id' => $twin, 'name' => 'Twin', 'status' => 'live', 'redirect_to' => '']);
        $this->assertNotSame($component, (int) $site->value("SELECT component_id FROM tl_components WHERE name = 'Clone banner' AND site_id = 3"), 'the clone has its own copy of the component');

        $onFirst = ['Host: 127.0.0.1:' . $site->port];
        $onTwin = ['Host: twin.test:' . $site->port];
        // what legitimately differs between the two renderings; the pages hold no forms or inline scripts, so no per-request tokens turn up
        $normalise = static function (string $html, string $host) use ($site): string {
            // the host name (and port) in absolute URLs is the site's own
            $html = str_replace([$host . ':' . $site->port, $host], 'HOST', $html);
            // the share image address is an HMAC with the site's own secret key (Front\ShareImage), so it differs; what it draws is the same brief
            $html = (string) preg_replace('~/og/[a-f0-9]{32}\.png~', '/og/HASH.png', $html);

            return $html;
        };
        foreach (['/', '/clone-about'] as $path) {
            $one = $site->client('v1')->get($path, $onFirst);
            $two = $site->client('v2')->get($path, $onTwin);
            $this->assertSame(200, $one->status, "site 1 $path");
            $this->assertSame(200, $two->status, "twin $path");
            $this->assertStringContainsString($path === '/' ? 'Welcome text of the home page' : 'Banner of the local component', $one->body, 'site 1 itself');
            $this->assertStringContainsString($path === '/' ? 'Welcome text of the home page' : 'Banner of the local component', $two->body);
            [$x, $y] = [explode("\n", $normalise($one->body, '127.0.0.1')), explode("\n", $normalise($two->body, 'twin.test'))];
            $this->assertSame([], [...array_diff($x, $y), ...array_diff($y, $x)], "$path renders identically on the clone (the lines that differ)");
            $this->assertSame($x, $y, "$path renders identically on the clone");
        }
    }
}
