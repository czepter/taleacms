<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Core\PageLock;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Rate-limit counters are kept per owner (docs/specs/multisite.md): wrong page passwords from one address up to the limit on owner B's
 * host do not count against the same address on owner A's host, while they still block on B. (Sign-in tries are counted in the owner's
 * own database already; the file counters are the page lock, member links, MCP and the firewall.)
 */
#[Group('site')]
final class OwnersLimitsTest extends SiteTestCase
{
    private const string BETA = 'localhost';
    private const string BETA_ID = '7a2d0c9e-0b0e-4a53-9a58-3c1f6f0f2b0b';
    private const string ENV_KEY = 'k3y-for-the-owners-limits-test-0123456789abcdef';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => self::ENV_KEY]];
    }

    /** @return list<string> */
    private function host(bool $beta): array
    {
        return $beta ? ['Host: ' . self::BETA . ':' . $this->site()->port] : [];
    }

    public function testASecondOwnerWithALockedPageOnEachHost(): void
    {
        $site = $this->site();
        $env = ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => self::ENV_KEY];
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 TALEA_OWNERS=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->exec("INSERT INTO tl_owners (public_id, name, kind, prefix, status, created_at) VALUES ('" . self::BETA_ID . "', 'Beta', 'organisation', 'tlb_', 'live', NOW())");
        $this->assertStringContainsString('owner tlb_: ready', $site->cli(['system/docker.php', 'cron'], $env));
        $site->exec("INSERT INTO tlb_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at) VALUES (1, ?, TRUE, FALSE, CURRENT_TIMESTAMP)", [self::BETA]);
        $site->exec('INSERT INTO tlb_settings (name, value) VALUES (?, ?)', ['site_url', 'http://' . self::BETA . ':' . $site->port]);
        $site->exec("INSERT INTO tlb_settings (name, value) SELECT name, value FROM tl_settings WHERE name IN ('extensions', 'tasks_token')");
        // owner B's administrator (same hash as A's) with a token; one locked page on each host, created over MCP
        $site->exec("INSERT INTO tlb_users (username, password, name, email, admin, language) SELECT username, password, 'Beta admin', 'beta@example.com', 2, '' FROM tl_users WHERE username = 'admin'");
        $token = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tlb_users WHERE username = 'admin'", [hash('sha256', $token)]);
        $hash = password_hash('partner-2026', PASSWORD_DEFAULT);
        foreach ([false, true] as $beta) {
            $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'create_page', 'arguments' => ['title' => 'Locked', 'slug' => 'locked', 'content' => '<p>Secret text</p>', 'visible' => true]]]);
            $response = $site->client('mcp')->post('/mcp', (string) $body, ['Authorization: Bearer ' . ($beta ? $token : $site->mcpToken), 'Content-Type: application/json', ...$this->host($beta)]);
            $this->assertSame(200, $response->status, $response->body);
            $site->exec('UPDATE ' . ($beta ? 'tlb_' : 'tl_') . 'pages SET password_hash = ? WHERE slug = ?', [$hash, 'locked']);
        }
        $site->clearPageCache();
    }

    public function testWrongPasswordsOnBDoNotBlockTheSameAddressOnA(): void
    {
        $visitor = $this->site()->client('limits-visitor');
        $visitor->get('/locked', $this->host(true));
        for ($i = 0; $i <= PageLock::ATTEMPTS; $i++) {
            $visitor->post('/locked', ['tl_page_password' => 'wrong-' . $i], $this->host(true));
        }
        $blocked = $visitor->post('/locked', ['tl_page_password' => 'partner-2026'], $this->host(true));
        $this->assertSame([403, true], [$blocked->status, $blocked->contains('Too many attempts')], 'B: the address is blocked');

        $visitor->get('/locked');
        $wrong = $visitor->post('/locked', ['tl_page_password' => 'wrong']);
        $this->assertSame([403, false], [$wrong->status, $wrong->contains('Too many attempts')], 'A: a wrong password is only wrong');
        $this->assertSame(303, $visitor->post('/locked', ['tl_page_password' => 'partner-2026'])->status, 'A: the right password opens the page');

        $this->assertNotSame([], glob($this->site()->path('storage/cache/limits/' . self::BETA_ID . '/page-lock-*')) ?: [], 'B counts in its own folder');
        $this->assertNotSame([], glob($this->site()->path('storage/cache/limits/page-lock-*')) ?: [], 'A keeps the plain folder');
    }
}
