<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite, domains (docs/specs/multisite.md, "Domains and certificates"): a host that is not verified does not route, the token file
 * and the proxy's certificate question answer on any host, a preview subdomain routes and is never indexed. One owner, so the Sites area
 * keeps adding hosts verified. Site 1 answers to 127.0.0.1; main.preview.test is its preview address. The tests build on each other.
 */
#[Group('site')]
final class DomainsTest extends SiteTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_PREVIEW_DOMAIN' => 'preview.test', 'TALEA_VERIFIED_HOSTS' => 'vouched.test', 'TALEA_TRUSTED_PROXIES' => '127.0.0.1']];
    }

    private function on(string $host, string $path): Response
    {
        return $this->site()->client('visitor')->get($path, ['Host: ' . $host . ':' . $this->site()->port]);
    }

    /** @param array<string, string> $fields */
    private function postSites(string $action, array $fields): Response
    {
        $admin = $this->site()->admin();

        return $admin->post('/admin.php?module=sites&action=' . $action, ['_csrf' => $this->site()->csrf($admin, '/admin.php?module=sites')] + $fields);
    }

    public function testPreviewsAreAddedByCronAndSiteOneKeepsItsFirstHost(): void
    {
        $site = $this->site();
        $env = ['TALEA_MULTISITE' => '1', 'TALEA_PREVIEW_DOMAIN' => 'preview.test'];
        $this->assertStringContainsString('Site 1 answers to 127.0.0.1', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1'));
        $this->assertNotNull($site->value("SELECT verified_at FROM tl_site_domains WHERE host = '127.0.0.1'"), 'the first domain is the owner\'s own');
        $this->assertStringContainsString('preview hosts added: 1', $site->cli(['system/docker.php', 'cron'], $env));
        $this->assertStringNotContainsString('preview hosts added', $site->cli(['system/docker.php', 'cron'], $env), 'once');
        $this->assertSame(['preview', 0], [$site->value("SELECT kind FROM tl_site_domains WHERE host = 'main.preview.test'"), (int) $site->value("SELECT is_primary FROM tl_site_domains WHERE host = 'main.preview.test'")]);
        $site->signIn($site->admin()); // the first sign-in came before site 1 had a domain
        $site->exec("INSERT INTO tl_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at, token) VALUES (1, 'pending.test', FALSE, FALSE, NULL, ?)", [self::TOKEN]);
        // the list of hosts the operator vouches for counts for a name that is a host of owner 1 (a name nobody added routes nowhere)
        $site->exec("INSERT INTO tl_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at, token) VALUES (1, 'vouched.test', FALSE, FALSE, NULL, 'ffffffffffffffffffffffffffffffff')");
    }

    public function testAHostThatIsNotVerifiedDoesNotRoute(): void
    {
        $r = $this->on('pending.test', '/');
        $this->assertSame([404, "Not found\n"], [$r->status, $r->body]);
        $this->assertSame(404, $this->on('pending.test', '/admin/')->status, 'nor does its administration');
        $this->assertSame(200, $this->on('pending.test', '/health')->status, '/health answers on any host');
        $this->assertSame(200, $this->on('127.0.0.1', '/')->status);
    }

    public function testTheTokenFileAnswersOnlyForThePendingHostAndToken(): void
    {
        $path = '/.well-known/talea-domain/' . self::TOKEN;
        $r = $this->on('pending.test', $path);
        $this->assertSame([200, self::TOKEN], [$r->status, $r->body]);
        $this->assertSame(404, $this->on('127.0.0.1', $path)->status, 'another host does not carry the token');
        $this->assertSame(404, $this->on('pending.test', '/.well-known/talea-domain/' . str_repeat('0', 32))->status);
        $this->assertSame(404, $this->on('pending.test', '/.well-known/talea-domain/short')->status);
    }

    public function testTheCertificateQuestionIsAnsweredOnAnyHostWithoutNamingAnOwner(): void
    {
        foreach ([['127.0.0.1', 200], ['main.preview.test', 200], ['vouched.test', 200], ['pending.test', 404], ['unknown.test', 404], ['Bad Host', 404], ['', 404]] as [$domain, $status]) {
            foreach (['127.0.0.1', 'unknown.test', 'pending.test'] as $asked) {
                $r = $this->on($asked, '/_talea/tls-allowed?domain=' . rawurlencode($domain));
                $this->assertSame($status, $r->status, 'domain "' . $domain . '" asked on ' . $asked);
                $this->assertContains($r->body, ["ok\n", "Not found\n"], 'the body says nothing else');
                $this->assertSame('no-store', $r->headers['cache-control']);
            }
        }
    }

    public function testAPreviewHostRoutesAndIsNeverIndexed(): void
    {
        $page = $this->on('main.preview.test', '/');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('noindex', $page->headers['x-robots-tag']);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page->body);
        $robots = $this->on('main.preview.test', '/robots.txt');
        $this->assertStringContainsString("Disallow: /\n", $robots->body);
        $this->assertStringContainsString('noindex', $robots->headers['x-robots-tag']);
        $own = $this->on('127.0.0.1', '/');
        $this->assertArrayNotHasKey('x-robots-tag', $own->headers, 'the site itself is unchanged');
        $this->assertStringNotContainsString('noindex, nofollow', $own->body);
    }

    public function testTheVerificationDoneByTheAdminAndByTheEnvironment(): void
    {
        $site = $this->site();
        $id = $site->publicId('sites', 1);
        $this->postSites('domain_add', ['id' => $id, 'host' => 'Added.Test']);
        $this->assertNotNull($site->value("SELECT verified_at FROM tl_site_domains WHERE host = 'added.test'"), 'one owner: a host added in the Sites area is verified at once');
        $this->assertSame(200, $this->on('added.test', '/')->status);

        $edit = $site->admin()->get('/admin.php?module=sites&action=edit&id=' . $id)->body;
        $this->assertStringContainsString('Not verified', $edit);
        $this->assertStringContainsString('/.well-known/talea-domain/' . self::TOKEN, $edit);
        $this->assertStringContainsString('talea-verify=' . self::TOKEN, $edit);

        $check = $this->postSites('domain_check', ['id' => $id, 'host' => 'pending.test']); // .test never resolves: nothing is fetched, the host stays unverified
        $this->assertSame(302, $check->status);
        $this->assertNull($site->value("SELECT verified_at FROM tl_site_domains WHERE host = 'pending.test'"));

        $this->postSites('domain_verify', ['id' => $id, 'host' => 'pending.test']); // vouching for a host is the Owners area's: not an action of the Sites area
        $this->assertNull($site->value("SELECT verified_at FROM tl_site_domains WHERE host = 'pending.test'"), 'the Sites area cannot mark a host verified');
        $site->exec("UPDATE tl_site_domains SET verified_at = CURRENT_TIMESTAMP, token = NULL WHERE host = 'pending.test'");
        $this->assertSame(200, $this->on('pending.test', '/')->status, 'verified: it routes');
        $this->assertSame(404, $this->on('pending.test', '/.well-known/talea-domain/' . self::TOKEN)->status, 'and no longer carries a token');
    }

    public function testTheQuestionIsRateLimited(): void
    {
        $limited = 0;
        for ($i = 0; $i < 640 && $limited === 0; $i++) {
            $limited = $this->on('127.0.0.1', '/_talea/tls-allowed?domain=x.test')->status === 429 ? $i : 0;
        }
        $this->assertGreaterThan(0, $limited, 'one name is slowed down after a few hundred questions a minute');
        // the proxy's address is the same for every name, a flood of random names must not lock out the others: counted per name
        $this->assertSame(404, $this->on('127.0.0.1', '/_talea/tls-allowed?domain=y.test')->status, 'another name is still answered');
        $this->assertSame(200, $this->on('127.0.0.1', '/_talea/tls-allowed?domain=127.0.0.1')->status);
    }

    public function testTheTokenPathHasItsOwnCounter(): void
    {
        // asking for tokens (a guess) is counted per network apart from the certificate questions, which are per name
        $status = 0;
        for ($i = 0; $i < 640 && $status !== 429; $i++) {
            $status = $this->on('pending.test', '/.well-known/talea-domain/' . str_repeat('0', 32))->status;
        }
        $this->assertSame(429, $status);
        $this->assertSame(200, $this->on('127.0.0.1', '/_talea/tls-allowed?domain=127.0.0.1')->status, 'the certificate questions are not affected');
    }
}
