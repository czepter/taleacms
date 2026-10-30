<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite stage 2 (docs/specs/multisite.md): the Sites area writes only rows, cron sets the new site up, the administrator
 * opens it with a one-time handoff link, and a trashed site stops answering. Site 1 answers to 127.0.0.1, the new site to second.test.
 * The tests run in order and build on each other.
 */
#[Group('site')]
final class SitesAreaTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    private function host(string $host): string
    {
        return 'Host: ' . $host . ':' . $this->site()->port;
    }

    /** @param array<string, string> $fields */
    private function postSites(string $action, array $fields): Response
    {
        $admin = $this->site()->admin();

        return $admin->post('/admin.php?module=sites&action=' . $action, ['_csrf' => $this->site()->csrf($admin, '/admin.php?module=sites')] + $fields);
    }

    private function secondId(): string
    {
        return (string) $this->site()->value('SELECT public_id FROM tl_sites WHERE site_id = 2');
    }

    /** The session cookie a response set, as a header for the next request. */
    private function cookieOf(Response $r): array
    {
        return preg_match('/(talea=[^;]+)/', $r->headers['set-cookie'] ?? '', $m) === 1 ? ['Cookie: ' . $m[1]] : [];
    }

    /** Opens a page of the administration with the given cookie header on a host; true when the sign-in form is what comes back. */
    private function isSignedOut(string $host, array $cookie): bool
    {
        return str_contains($this->site()->client('browser')->get('/admin.php', [$this->host($host), ...$cookie])->body, 'name="password"');
    }

    private function handoffUrl(): string
    {
        $r = $this->postSites('handoff', ['id' => $this->secondId()]);
        $this->assertSame(303, $r->status);
        $this->assertMatchesRegularExpression('#^http://second\.test:' . $this->site()->port . '/admin\?handoff=[a-f0-9]{64}$#', $r->headers['location']);

        return $r->headers['location'];
    }

    public function testANewSiteIsOnlyARowUntilCronSetsItUp(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin()); // the first sign-in came before site 1 had a domain

        $list = $site->admin()->get('/admin.php?module=sites');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('New site', $list->body);

        $this->assertSame(302, $this->postSites('create', ['name' => 'Second', 'from' => ''])->status);
        $row = $site->rows('SELECT status, ready_at, source_site_id, prefix FROM tl_sites WHERE site_id = 2')[0];
        $this->assertSame(['paused', null, 1, 'tl2_'], [$row['status'], $row['ready_at'], (int) $row['source_site_id'], $row['prefix']]);
        $this->assertStringContainsString('Setting up', $site->admin()->get('/admin.php?module=sites')->body);

        foreach (['http://second.test', 'second.test:8080', 'Bad Host', ''] as $bad) {
            $this->postSites('domain_add', ['id' => $this->secondId(), 'host' => $bad]);
        }
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_site_domains WHERE site_id = 2'), 'only a plain host name is taken');
        $this->postSites('domain_add', ['id' => $this->secondId(), 'host' => 'Second.Test']);
        $this->postSites('domain_add', ['id' => $this->secondId(), 'host' => 'alias.test']);
        $this->postSites('domain_add', ['id' => $this->secondId(), 'host' => 'second.test']);
        $this->assertSame([['second.test', 1], ['alias.test', 0]], array_map(fn (array $r): array => [$r['host'], (int) $r['is_primary']], $site->rows('SELECT host, is_primary FROM tl_site_domains WHERE site_id = 2 ORDER BY domain_id')), 'lower-case, unique, the first is primary');

        $r = $site->client('visitor')->get('/', [$this->host('second.test')]);
        $this->assertSame(503, $r->status, 'a site cron has not set up has no tables yet');

        $cron = $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']);
        $this->assertStringContainsString('site tl2_: ready', $cron);
        $this->assertNotNull($site->value('SELECT ready_at FROM tl_sites WHERE site_id = 2'));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl2_pages'));
        $this->assertStringNotContainsString('site tl2_', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']), 'set up once');
    }

    public function testDomainsAndTheStateOfASite(): void
    {
        $site = $this->site();
        $id = $this->secondId();
        $this->postSites('domain_redirect', ['id' => $id, 'host' => 'alias.test', 'redirect' => '1']);
        $this->assertSame(1, (int) $site->value("SELECT redirect_to_primary FROM tl_site_domains WHERE host = 'alias.test'"));
        $this->assertSame(301, $site->client('visitor')->get('/x', [$this->host('alias.test')])->status);
        $this->postSites('domain_primary', ['id' => $id, 'host' => 'alias.test']);
        $this->assertSame([1, 0], [(int) $site->value("SELECT is_primary FROM tl_site_domains WHERE host = 'alias.test'"), (int) $site->value("SELECT redirect_to_primary FROM tl_site_domains WHERE host = 'alias.test'")]);
        $this->postSites('domain_primary', ['id' => $id, 'host' => 'second.test']);

        $this->postSites('domain_remove', ['id' => $this->site()->publicId('sites', 1), 'host' => '127.0.0.1']);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_site_domains WHERE host = '127.0.0.1'"), 'the host name in use cannot be removed');

        $this->postSites('save', ['id' => $id, 'name' => 'Second site', 'status' => 'live', 'redirect_to' => '']);
        $this->assertSame(['Second site', 'live'], [$site->value('SELECT name FROM tl_sites WHERE site_id = 2'), $site->value('SELECT status FROM tl_sites WHERE site_id = 2')]);
        $live = $site->client('visitor')->get('/', [$this->host('second.test')]);
        $this->assertNotSame(503, $live->status);
        $this->assertNotSame("Not found\n", $live->body, 'the second site answers with its own pages');
    }

    public function testTheHandoffSignsTheAdministratorInOnTheOtherSite(): void
    {
        $site = $this->site();
        $url = $this->handoffUrl();
        $path = substr($url, strpos($url, '/admin?'));

        $r = $site->client('browser')->get($path, [$this->host('second.test')]);
        $this->assertSame(302, $r->status);
        $cookie = $this->cookieOf($r);
        $this->assertNotSame([], $cookie, 'a new session');
        $this->assertFalse($this->isSignedOut('second.test', $cookie), 'signed in on the second site');
        $this->assertSame(200, $site->client('browser')->get('/admin.php?module=sites', [$this->host('second.test'), ...$cookie])->status);

        $again = $site->client('browser')->get($path, [$this->host('second.test')]);
        $this->assertTrue($this->isSignedOut('second.test', $this->cookieOf($again)), 'a token works once');
    }

    public function testAHandoffTokenIsBoundToItsSiteAndExpires(): void
    {
        $site = $this->site();
        $other = substr($this->handoffUrl(), strpos($this->handoffUrl(), '/admin?'));
        $this->assertTrue($this->isSignedOut('127.0.0.1', $this->cookieOf($site->client('browser')->get($other, [$this->host('127.0.0.1')]))), 'a token of site 2 does nothing on site 1');

        $url = $this->handoffUrl();
        $token = substr($url, -64);
        $site->exec('UPDATE tl_handoff_tokens SET expires_at = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() - 5), hash('sha256', $token)]);
        $this->assertTrue($this->isSignedOut('second.test', $this->cookieOf($site->client('browser')->get(substr($url, strpos($url, '/admin?')), [$this->host('second.test')]))), 'expired');

        $url = $this->handoffUrl();
        $site->exec("UPDATE tl_users SET blocked = TRUE WHERE username = 'admin'");
        $blocked = $this->isSignedOut('second.test', $this->cookieOf($site->client('browser')->get(substr($url, strpos($url, '/admin?')), [$this->host('second.test')])));
        $site->exec("UPDATE tl_users SET blocked = FALSE WHERE username = 'admin'");
        $this->assertTrue($blocked, 'a blocked account is not signed in');
        $this->assertStringNotContainsString($token, (string) $site->value('SELECT GROUP_CONCAT(token_hash) FROM tl_handoff_tokens'), 'only hashes are stored');
    }

    public function testTrashAndRestore(): void
    {
        $site = $this->site();
        $id = $this->secondId();
        $this->postSites('trash', ['id' => $site->publicId('sites', 1)]);
        $this->assertSame('live', $site->value('SELECT status FROM tl_sites WHERE site_id = 1'), 'the first site cannot be deleted');

        $this->postSites('trash', ['id' => $id]);
        $this->assertSame('trashed', $site->value('SELECT status FROM tl_sites WHERE site_id = 2'));
        $this->assertSame(404, $site->client('visitor')->get('/', [$this->host('second.test')])->status);
        $this->assertStringContainsString('Trash', $site->admin()->get('/admin.php?module=sites')->body);

        $this->postSites('restore', ['id' => $id]);
        $this->assertSame('paused', $site->value('SELECT status FROM tl_sites WHERE site_id = 2'));
        $this->assertSame(503, $site->client('visitor')->get('/', [$this->host('second.test')])->status);
    }
}
