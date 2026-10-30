<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite, agency tools (docs/specs/multisite.md, stage D) over HTTP: roles per site at the sign-in, in a running session and in MCP
 * (the calling site and the `site` argument), the site limit in the admin and in MCP, the health columns and the usage file.
 * The tests run in order and build on each other. Site 1 answers to 127.0.0.1, the second site to second.test; TALEA_MAX_SITES is 3.
 */
#[Group('site')]
final class AgencyToolsTest extends SiteTestCase
{
    private static string $second = '';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_MAX_SITES' => '3']];
    }

    private function host(string $host): string
    {
        return 'Host: ' . $host . ':' . $this->site()->port;
    }

    /** @return array<string, mixed> */
    private function call(string $tool, array $arguments = [], ?string $token = null): array
    {
        $result = $this->site()->mcp($tool, $arguments, $token)['result'] ?? [];

        return ['error' => ($result['isError'] ?? false) === true, 'text' => (string) ($result['content'][0]['text'] ?? '')];
    }

    /** A person who is not an administrator: an editor limited to the given site numbers (null = every site). Returns the MCP token. */
    private function editor(string $name, ?array $sites): string
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_users (username, password, email, admin, sites_limited) VALUES (?, ?, ?, 1, ?)", [$name, password_hash('editor-pass-123', PASSWORD_DEFAULT), $name . '@example.test', $sites !== null ? 1 : 0]);
        $sites ??= [];
        foreach ($sites as $n) {
            $site->exec('INSERT INTO tl_user_sites (user_id, site_id) SELECT user_id, ? FROM tl_users WHERE username = ?', [$n, $name]);
        }
        $token = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, ?, ?, NOW() FROM tl_users WHERE username = ?", [$name, hash('sha256', $token), $name]);

        return $token;
    }

    /** Signs a browser in on a host; true when the administration answers afterwards (false: the sign-in form again). */
    private function signsIn(Http $client, string $host, string $user): bool
    {
        $h = [$this->host($host)];
        $csrf = $client->get('/admin.php', $h)->csrf();
        $client->post('/admin.php', ['_csrf' => $csrf, 'username' => $user, 'password' => 'editor-pass-123'], $h);

        return !$this->signedOut($client, $host);
    }

    private function signedOut(Http $client, string $host): bool
    {
        return str_contains($client->get('/admin.php', [$this->host($host)])->body, 'name="password"');
    }

    public function testSetUp(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        self::$second = $site->mcpResult('create_site', ['name' => 'Second'])['site']['id'];
        $site->mcpResult('add_site_domain', ['id' => self::$second, 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $site->mcpResult('update_site', ['id' => self::$second, 'status' => 'live']);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_user_sites'));
    }

    public function testTheSignInAndARunningSessionFollowTheSites(): void
    {
        $site = $this->site();
        $this->editor('only1', [1]);
        $this->editor('only2', [2]);
        $this->editor('everywhere', null);

        $b = $site->client('b1');
        $this->assertTrue($this->signsIn($b, '127.0.0.1', 'only1'));
        $this->assertTrue($this->signedOut($site->client('b2'), 'second.test'));
        $c = $site->client('b3');
        $this->assertFalse($this->signsIn($c, '127.0.0.1', 'only2'), 'limited to the second site: not on the first');
        $this->assertStringContainsString('You have no access to this site.', $c->get('/admin.php', [$this->host('127.0.0.1')])->body . $c->post('/admin.php', ['_csrf' => $c->get('/admin.php', [$this->host('127.0.0.1')])->csrf(), 'username' => 'only2', 'password' => 'editor-pass-123'], [$this->host('127.0.0.1')])->body);
        $this->assertTrue($this->signsIn($site->client('b4'), 'second.test', 'only2'));
        $this->assertFalse($this->signsIn($site->client('b5'), 'second.test', 'only1'), 'limited to the first site: not on the second');
        $this->assertTrue($this->signsIn($site->client('b6'), '127.0.0.1', 'everywhere'));
        $this->assertTrue($this->signsIn($site->client('b7'), 'second.test', 'everywhere'));

        // limited with no site at all: nowhere (an empty list never means every site)
        $this->editor('nobody', []);
        $this->assertFalse($this->signsIn($site->client('b8'), '127.0.0.1', 'nobody'));
        $this->assertFalse($this->signsIn($site->client('b9'), 'second.test', 'nobody'));

        // a session that is running when the rows change ends at the next request
        $this->assertFalse($this->signedOut($b, '127.0.0.1'));
        $site->exec("DELETE FROM tl_user_sites WHERE user_id = (SELECT user_id FROM tl_users WHERE username = 'only1')");
        $site->exec("INSERT INTO tl_user_sites (user_id, site_id) SELECT user_id, 2 FROM tl_users WHERE username = 'only1'");
        $this->assertTrue($this->signedOut($b, '127.0.0.1'), 'treated as signed out on the site it may no longer work on');

        // administrators have every site whatever rows say
        $site->exec("INSERT INTO tl_user_sites (user_id, site_id) SELECT user_id, 2 FROM tl_users WHERE username = 'admin'");
        $this->assertSame(200, $site->admin()->get('/admin.php?module=sites')->status);
        $this->assertFalse($this->signedOut($site->admin(), '127.0.0.1'));
        $site->exec("DELETE FROM tl_user_sites WHERE user_id = (SELECT user_id FROM tl_users WHERE username = 'admin')");
    }

    public function testTheUsersFormSetsTheLimitExplicitly(): void
    {
        $site = $this->site();
        $this->editor('formed', [2]);
        $public = (string) $site->value("SELECT public_id FROM tl_users WHERE username = 'formed'");
        $admin = $site->admin();
        $save = function (array $extra) use ($admin, $public): void {
            $page = $admin->get('/admin.php?module=users&action=edit&id=' . $public);
            $admin->post('/admin.php?module=users&action=save', ['_csrf' => $page->csrf(), 'user_id' => $public, 'username' => 'formed', 'name' => 'Formed', 'email' => 'formed@example.test', 'admin' => '1'] + $extra);
        };
        $state = fn (): array => [(int) $site->value("SELECT sites_limited FROM tl_users WHERE username = 'formed'"), (int) $site->value("SELECT COUNT(*) FROM tl_user_sites WHERE user_id = (SELECT user_id FROM tl_users WHERE username = 'formed')")];

        $save(['sites_scope' => 'chosen']); // the checklist was not part of the form (hidden): nothing changes
        $this->assertSame([1, 1], $state(), 'a form without the checklist keeps the limit');
        $save(['sites_shown' => '1', 'sites_scope' => 'chosen']);
        $this->assertSame([1, 0], $state(), 'limited with nothing ticked: no site, not every site');
        $this->assertFalse($this->signsIn($site->client('f1'), '127.0.0.1', 'formed'));
        $save(['sites_shown' => '1', 'sites_scope' => 'chosen', 'sites' => [self::$second]]);
        $this->assertSame([1, 1], $state());
        $save(['sites_shown' => '1', 'sites_scope' => 'all', 'sites' => [self::$second]]);
        $this->assertSame([0, 1], $state(), 'every site is an answer of its own; the ticked row stays, but the flag decides');
        $this->assertTrue($this->signsIn($site->client('f2'), '127.0.0.1', 'formed'));
    }

    public function testMcpFollowsTheSites(): void
    {
        $site = $this->site();
        $only2 = $this->editor('mcp2', [2]);
        $only1 = $this->editor('mcp1', [1]);
        $all = $this->editor('mcpall', null);

        $this->assertSame('The token is invalid or missing.', $site->mcp('list_pages', [], $only2)['error'] ?? null, 'the connection of a person who may not work on the first site');
        $this->assertFalse($this->call('list_pages', [], $only1)['error']);
        $r = $this->call('list_pages', ['site' => self::$second], $only1);
        $this->assertTrue($r['error']);
        $this->assertStringContainsString('Unknown site', $r['text'], 'the answer for an id that does not exist');
        $this->assertSame($this->call('list_pages', ['site' => str_repeat('0', 8) . '-0000-4000-8000-' . str_repeat('0', 12)], $only1)['text'], $r['text']);
        $this->assertFalse($this->call('list_pages', ['site' => self::$second], $all)['error']);
        $this->assertFalse($this->call('list_pages', ['site' => self::$second])['error'], 'the administrator');
    }

    public function testTheSiteLimitHoldsInTheAdminAndInMcp(): void
    {
        $site = $this->site();
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_sites WHERE status <> 'trashed'"));
        $third = $site->mcpResult('create_site', ['name' => 'Third']);
        $this->assertSame('Third', $third['site']['name']);

        $r = $this->call('create_site', ['name' => 'Fourth']);
        $this->assertTrue($r['error']);
        $this->assertStringContainsString('limit of 3 sites', $r['text']);
        $admin = $site->admin();
        $post = $admin->post('/admin.php?module=sites&action=create', ['_csrf' => $site->csrf($admin, '/admin.php?module=sites'), 'name' => 'Fourth', 'from' => '']);
        $this->assertStringContainsString('limit of 3 sites', $post->body);
        $this->assertSame(3, (int) $site->value('SELECT COUNT(*) FROM tl_sites'));

        $site->mcpResult('trash_site', ['id' => $third['site']['id']]);
        $this->assertSame('Fourth', $site->mcpResult('create_site', ['name' => 'Fourth'])['site']['name'], 'a site in the trash does not count');
    }

    public function testHealthColumnsAndTheUsageFile(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_enquiries (public_id, created_at, data, status, category) VALUES (?, NOW(), '[]', 0, '')", ['00000000-0000-4000-8000-000000000001']);
        $list = $site->admin()->get('/admin.php?module=sites');
        $this->assertStringContainsString('Open enquiries', $list->body);
        $this->assertStringContainsString('Broken links', $list->body);
        $this->assertStringContainsString('Images without a description', $list->body);
        $health = json_decode($site->mcpText('list_owner_sites'), true)['sites'][0]['health'];
        $this->assertSame(['enquiries' => 1, 'broken_links' => 0], $health);

        $csv = $site->admin()->get('/admin.php?module=sites&action=usage&months=2');
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString('text/csv', $csv->headers['content-type'] ?? '');
        $lines = array_values(array_filter(explode("\n", trim($csv->body))));
        $this->assertCount(1 + 2 * 2, $lines, 'a header, then two months for each of the two sites that are set up (not the trashed one, not the one waiting for cron)');
        $this->assertStringContainsString(date('Y-m'), $csv->body);

        // not for anybody but administrators
        $browser = $site->client('csv-editor');
        $this->assertTrue($this->signsIn($browser, '127.0.0.1', 'everywhere'));
        $denied = $browser->get('/admin.php?module=sites&action=usage');
        $this->assertStringNotContainsString('Owner;Site', $denied->body);
        $this->assertStringNotContainsString('text/csv', $denied->headers['content-type'] ?? '');
    }
}
