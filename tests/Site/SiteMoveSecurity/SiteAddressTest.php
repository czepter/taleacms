<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The site address (Core\SiteUrl): TALEA_SITE_URL wins on start (a domain move in the hosting), MCP changes it for full-access
 * administrators while the variable is not set, and the optional redirect of other hosts (redirect_to_site_url).
 */
#[Group('site')]
final class SiteAddressTest extends SiteTestCase
{
    private const string MOVED = 'https://moved.example.com';

    /** @return list<array<string, mixed>> the change log rows of the site address, oldest first */
    private function addressLog(): array
    {
        return $this->site()->rows("SELECT description, COALESCE(reason, '') AS reason FROM tl_change_log WHERE module = 'settings' AND action = 'site_url' ORDER BY log_id");
    }

    public function testMcpChangesTheAddressForFullAccessOnly(): void
    {
        $site = $this->site();
        $other = 'http://localhost:' . $site->port('other-host');
        $result = $site->mcpResult('update_settings', ['settings' => ['site_url' => $other . '/']]);
        $this->assertSame([$other, $other], [$result['saved']['site_url'] ?? null, $site->settingValue('site_url')], 'MCP: an administrator with full access sets the address (the trailing slash goes)');
        $this->assertSame([['description' => $site->base . ' → ' . $other, 'reason' => '']], $this->addressLog(), 'MCP: the change is in the change log, old → new');

        $result = $site->mcpResult('update_settings', ['settings' => ['site_url' => 'https://example.com/blog']]);
        $this->assertSame([true, $other], [str_contains((string) ($result['errors']['site_url'] ?? ''), 'no path'), $site->settingValue('site_url')], 'MCP: an address with a path is refused');

        $drafts = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, access, token_hash, created_at) SELECT user_id, 'drafts', 'drafts', ?, NOW() FROM tl_users WHERE username = 'admin'", [hash('sha256', $drafts)]);
        $site->mcp('update_settings', ['settings' => ['site_url' => 'https://phishing.example']], $drafts);
        $this->assertSame($other, $site->settingValue('site_url'), 'MCP: a drafts-only connection cannot change the address password-reset links are built from');

        $site->mcpResult('update_settings', ['settings' => ['site_url' => $site->base]]);
        $this->assertSame($site->base, $site->settingValue('site_url'), 'MCP: and back');
    }

    public function testTheEnvironmentWinsOnStart(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $this->assertStringContainsString('<link rel="canonical" href="' . $site->base, $visitor->get('/')->body, 'before: the cached home page carries the installed address');
        $this->assertStringContainsString('<loc>' . $site->base . '/', $visitor->get('/sitemap.xml')->body, 'before: the cached sitemap too (its cache key has no host)');
        $logged = count($this->addressLog());
        $start = ['system/docker.php', 'site-url'];
        $this->assertSame('', $site->cli($start), 'start without TALEA_SITE_URL: nothing to do');

        $this->assertSame("Site address changed to " . self::MOVED . ".\n", $site->cli($start, ['TALEA_SITE_URL' => self::MOVED . '/']), 'start with a new TALEA_SITE_URL: the address changes');
        $this->assertSame(self::MOVED, $site->settingValue('site_url'));
        $this->assertSame(['description' => $site->base . ' → ' . self::MOVED, 'reason' => 'TALEA_SITE_URL'], $this->addressLog()[$logged] ?? null, 'the change log names the variable');
        $home = $visitor->get('/')->body;
        $this->assertStringContainsString('<link rel="canonical" href="' . self::MOVED . '/', $home, 'canonical: the new host, not a cached page with the old one');
        $this->assertStringContainsString('<meta property="og:url" content="' . self::MOVED . '/', $home, 'og:url: the new host');
        $this->assertStringContainsString('<loc>' . self::MOVED . '/', $visitor->get('/sitemap.xml')->body, 'sitemap: the new host');
        $this->assertStringContainsString('Sitemap: ' . self::MOVED . '/sitemap.xml', $visitor->get('/robots.txt')->body, 'robots.txt: the new host');
        $this->assertStringContainsString(self::MOVED . '/news/', $visitor->get('/rss.xml')->body, 'feed: the new host');
        $this->assertStringNotContainsString($site->base, $home, 'no trace of the old host on the home page');

        $this->assertSame("Site address is " . self::MOVED . ".\n", $site->cli($start, ['TALEA_SITE_URL' => self::MOVED]), 'the next start with the same value');
        $this->assertCount($logged + 1, $this->addressLog(), 'an unchanged address is not logged again');
        $this->assertStringContainsString('must be an address like', $site->cli($start, ['TALEA_SITE_URL' => 'https://example.com/blog']), 'a value with a path is reported');
        $this->assertSame(self::MOVED, $site->settingValue('site_url'), 'and changes nothing');

        $site->setting('site_url', $site->base);
        $site->clearPageCache();
    }

    public function testAnAddressTheServerSetsIsReadOnly(): void
    {
        $site = $this->site();
        $port = $site->startPhp($site->root, 'system/dev-router.php', ['TALEA_SITE_URL' => self::MOVED]);
        $admin = new Http('http://127.0.0.1:' . $port, $site->workDir('jars'), 'env-admin');
        $site->signIn($admin);
        $form = $admin->get('/admin.php?module=settings&tab=general');
        $this->assertMatchesRegularExpression('#id="site_url" name="site_url" value="[^"]*" readonly>#', $form->body, 'admin: the field is read-only while TALEA_SITE_URL is set');
        $this->assertStringContainsString('TALEA_SITE_URL', $form->body, 'admin: and says the server sets it');
        $admin->post('/admin.php?module=settings&action=save', ['_csrf' => $form->csrf(), 'tab' => 'general', 'site_url' => 'https://posted.example', 'site_name' => $site->settingValue('site_name')]);
        $this->assertSame($site->base, $site->settingValue('site_url'), 'admin: a posted address is not saved');

        $call = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_settings', 'arguments' => ['settings' => ['site_url' => 'https://other.example']]]]);
        $text = (string) ($admin->post('/mcp', $call, ['Authorization: Bearer ' . $site->mcpToken, 'Content-Type: application/json'])->json()['result']['content'][0]['text'] ?? '');
        $this->assertStringContainsString('The server sets the site address (TALEA_SITE_URL)', $text, 'MCP: refused with the reason');
        $this->assertSame($site->base, $site->settingValue('site_url'));
    }

    public function testOtherHostsRedirectToTheSiteAddress(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $other = ['Host: localhost:' . parse_url($site->base, PHP_URL_PORT)];
        $this->assertSame(200, $visitor->get('/news?page=1', $other)->status, 'off by default: another host is served');

        $site->setting('redirect_to_site_url', '1');
        $moved = $visitor->get('/news?page=1', $other);
        $this->assertSame([301, $site->base . '/news?page=1'], [$moved->status, $moved->headers['location'] ?? ''], 'on: another host gets a 301 to the same path on the site address');
        $this->assertSame(200, $visitor->get('/news')->status, 'the site address itself is served');
        foreach (['/health', '/mcp', '/?preview_key=x', '/.well-known/oauth-protected-resource', '/admin.php'] as $path) {
            $this->assertNotSame(301, $visitor->get($path, $other)->status, "$path answers on any host");
        }
        $site->setting('redirect_to_site_url', '0');
    }
}
