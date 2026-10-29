<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite stage 1 (docs/specs/multisite.md): the startup guard of bin/migrate, host routing and the lifecycle states of a site.
 * Site 1 answers to 127.0.0.1 (the host of its site address); the second site is registered here and answers to second.test.
 */
#[Group('site')]
final class MultisiteRoutingTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    private function port(): int
    {
        return $this->site()->port;
    }

    private function migrate(bool $multisite): string
    {
        return (string) shell_exec('cd ' . escapeshellarg($this->site()->root) . ' && ' . ($multisite ? 'TALEA_MULTISITE=1 ' : '') . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"');
    }

    private function on(string $host, string $path, bool $admin = false): Response
    {
        // curl matches cookies against the Host header, so the administrator's session cookie goes along by hand
        $cookie = $admin ? ['Cookie: talea=' . $this->site()->admin()->cookies()['talea']] : [];

        return $this->site()->client('visitor')->get($path, ['Host: ' . $host . ':' . $this->port(), ...$cookie]);
    }

    private function putSiteTwo(string $status, ?int $redirectTo = null): void
    {
        $this->site()->exec('UPDATE tl_sites SET status = ?, redirect_to_site_id = ? WHERE site_id = 2', [$status, $redirectTo]);
    }

    public function testTheStartGuardAndTheFirstDomain(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_sites (public_id, name, slug, prefix, status, created_at) VALUES ('6f1d0c9e-0b0e-4a53-9a58-3c1f6f0f2b02', 'Second', 'second', 'tl2_', 'live', NOW())");

        $off = $this->migrate(false);
        $this->assertStringContainsString('TALEA_MULTISITE is off', $off, 'two sites without the flag: the start is refused');
        $this->assertStringContainsString('exit:1', $off);

        $on = $this->migrate(true);
        $this->assertStringContainsString('exit:0', $on);
        $this->assertStringContainsString('Site 1 answers to 127.0.0.1', $on, 'the host of the site address becomes site 1\'s primary domain');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_site_domains WHERE site_id = 1 AND host = '127.0.0.1' AND is_primary = TRUE"));
        $this->assertStringNotContainsString('Site 1 answers', $this->migrate(true), 'the domain is added once');

        $site->signIn($site->admin()); // the first sign-in came before site 1 had a domain: the host was unknown
        $site->exec("INSERT INTO tl2_settings (name, value) VALUES ('site_name', 'Second site')");
        $site->exec("INSERT INTO tl_site_domains (site_id, host, is_primary, redirect_to_primary) VALUES (2, 'second.test', TRUE, FALSE)");
        $site->exec("INSERT INTO tl_site_domains (site_id, host, is_primary, redirect_to_primary) VALUES (2, 'alias.test', FALSE, TRUE)");
    }

    public function testHostsRouteToTheirSite(): void
    {
        $this->assertStringContainsString('Second site', $this->on('second.test', '/nothing-here')->body);
        $this->assertStringNotContainsString('Second site', $this->on('127.0.0.1', '/nothing-here')->body);

        $unknown = $this->on('unknown.test', '/');
        $this->assertSame(404, $unknown->status, 'an unknown host never falls back to site 1');
        $this->assertSame("Not found\n", $unknown->body);
        $health = $this->on('unknown.test', '/health');
        $this->assertSame([200, "ok\n"], [$health->status, $health->body], '/health answers on any host');
    }

    public function testASecondaryDomainRedirectsToThePrimaryOne(): void
    {
        $r = $this->on('alias.test', '/page?x=1');
        $this->assertSame(301, $r->status);
        $this->assertSame('http://second.test:' . $this->port() . '/page?x=1', $r->headers['location']);
    }

    public function testAPausedSiteIsClosedToVisitors(): void
    {
        $this->putSiteTwo('paused');
        $r = $this->on('second.test', '/');
        $this->assertSame(503, $r->status);
        $this->assertSame('3600', $r->headers['retry-after']);
        $this->assertSame('noindex', $r->headers['x-robots-tag']);
        $this->assertStringContainsString('This site is not public yet', $r->body);
        $this->assertSame(200, $this->on('second.test', '/health')->status);
        $this->assertNotSame(503, $this->on('second.test', '/', admin: true)->status, 'a signed-in administrator passes');
        $this->assertNotSame(503, $this->on('127.0.0.1', '/')->status, 'the other site is unaffected');
    }

    public function testAnArchivedSiteRedirectsOrIsReadOnly(): void
    {
        $this->putSiteTwo('archived', 1);
        $r = $this->on('second.test', '/news?page=2');
        $this->assertSame(301, $r->status);
        $this->assertSame('http://127.0.0.1:' . $this->port() . '/news?page=2', $r->headers['location']);

        $this->putSiteTwo('archived');
        $this->assertNotSame(503, $this->on('second.test', '/')->status);
        $post = $this->site()->client('visitor')->post('/form', ['x' => '1'], ['Host: second.test:' . $this->port()]);
        $this->assertSame(403, $post->status);
        $this->assertStringContainsString('no longer accepts submissions', $post->body);

        // deny by default: any other write is refused too, reading and withdrawing consent still work
        $host = ['Host: second.test:' . $this->port()];
        $visitor = $this->site()->client('visitor');
        foreach (['/subscribe' => ['email' => 'a@example.com'], '/_comment' => ['x' => '1'], '/popup' => ['x' => '1'], '/subscribe?confirm=' . str_repeat('a', 32) => []] as $path => $data) {
            $r = $visitor->post($path, $data, $host);
            $this->assertSame(403, $r->status, $path);
            $this->assertStringContainsString('no longer accepts submissions', $r->body, $path);
        }
        $this->assertSame(200, $this->on('second.test', '/')->status);
        foreach (['/subscribe?unsubscribe=' . str_repeat('a', 32) => [], '/_booking/cancel/' . str_repeat('a', 32) => [], '/some-page' => ['tl_page_password' => 'x']] as $path => $data) {
            $this->assertStringNotContainsString('no longer accepts submissions', $visitor->post($path, $data, $host)->body, $path);
        }
    }

    public function testATrashedSiteDoesNotAnswer(): void
    {
        $this->putSiteTwo('trashed');
        $this->assertSame(404, $this->on('second.test', '/')->status);
        $this->putSiteTwo('live');
        $this->assertSame(200, $this->on('second.test', '/health')->status);
    }
}
