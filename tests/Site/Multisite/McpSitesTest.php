<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Multisite stage 4 (docs/specs/multisite.md): one Claude connection across the sites. The optional `site` argument runs a tool on a
 * sibling (and an unknown, foreign or trashed id is an error, never a fallback), the journal of such a call is undone on the sibling, the
 * site tools manage the sites (administrators with full access only), and a Talea archive import is refused with several sites.
 * The tests run in order and build on each other. Site 1 answers to 127.0.0.1, the second site to second.test.
 */
#[Group('site')]
final class McpSitesTest extends SiteTestCase
{
    private static string $second = '';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function call(string $tool, array $arguments = [], ?string $token = null): array
    {
        $result = $this->site()->mcp($tool, $arguments, $token)['result'] ?? [];

        return ['error' => ($result['isError'] ?? false) === true, 'text' => (string) ($result['content'][0]['text'] ?? '')];
    }

    public function testSitesAreCreatedAndListedWithPublicIdsOnly(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $created = $site->mcpResult('create_site', ['name' => 'Second']);
        self::$second = $created['site']['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', self::$second);
        $this->assertSame(['paused', false], [$created['site']['status'], $created['site']['ready']]);

        $this->call('add_site_domain', ['id' => self::$second, 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $this->call('update_site', ['id' => self::$second, 'status' => 'live']);

        $list = $site->mcpText('list_owner_sites');
        $this->assertStringNotContainsString('tl2_', $list, 'a prefix never leaves the server');
        $sites = json_decode($list, true)['sites'];
        $this->assertSame([['main', true], ['second', false]], array_map(fn (array $s): array => [$s['slug'], $s['current']], $sites));
        $this->assertSame([['second.test', true, false]], array_map(fn (array $d): array => [$d['host'], $d['primary'], $d['redirect_to_primary']], $sites[1]['domains']));
        $this->assertSame(['live', true], [$sites[1]['status'], $sites[1]['ready']]);
    }

    public function testSiteRunsAToolOnTheSiblingAndNamesIt(): void
    {
        $site = $this->site();
        $r = $site->mcpResult('create_page', ['title' => 'On the second site', 'visible' => false, 'site' => self::$second]);
        $this->assertSame(self::$second, $r['on_site'], 'the result names the site');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl2_pages WHERE title = ?', ['On the second site']));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE title = ?', ['On the second site']));

        $site->mcpResult('create_page', ['title' => 'On the first site', 'visible' => false]);
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE title = ?', ['On the first site']), 'without site it acts on the site the connection calls');
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl2_pages WHERE title = ?', ['On the first site']));

        $titles = array_column($site->mcpResult('list_pages', ['site' => self::$second]), 'title');
        $this->assertContains('On the second site', $titles);
        $this->assertNotContains('On the first site', $titles, 'reads follow the site too');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl2_change_log WHERE action = 'create_page'"), 'the change log of the sibling has the change');
        $info = $site->mcpResult('site_info', ['site' => self::$second]);
        $this->assertSame([self::$second, true], [$info['on_site'], isset($info['site'])], 'site_info keeps the site name of its own');
    }

    public function testAnUnknownForeignOrTrashedSiteIsAnErrorNeverAFallback(): void
    {
        $site = $this->site();
        $before = (int) $site->value('SELECT COUNT(*) FROM tl_pages');
        foreach (['not-an-id', '3f9c1b6e-5d2a-4c1b-9a7e-0b1c2d3e4f5a', '1'] as $bad) {
            $r = $this->call('create_page', ['title' => 'Nowhere', 'visible' => false, 'site' => $bad]);
            $this->assertTrue($r['error'], $bad);
            $this->assertStringContainsString('Unknown site', $r['text']);
        }
        $this->assertSame($before, (int) $site->value('SELECT COUNT(*) FROM tl_pages'), 'nothing fell back to the first site');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl2_pages WHERE title = 'Nowhere'"));

        $third = $site->mcpResult('create_site', ['name' => 'Third'])['site']['id'];
        $this->assertStringContainsString('still being set up', $this->call('list_pages', ['site' => $third])['text'], 'not ready');
        $site->mcpResult('update_site', ['id' => $third, 'trash' => true]);
        $this->assertStringContainsString('in the trash', $this->call('list_pages', ['site' => $third])['text']);
        $site->mcpResult('update_site', ['id' => $third, 'restore' => true]);
        $this->assertSame('paused', $site->mcpResult('list_owner_sites')['sites'][2]['status']);
        $site->mcpResult('update_site', ['id' => $third, 'trash' => true]);
    }

    public function testACallOnTheSiblingIsUndoneOnTheSibling(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_agent_sessions SET last_at = '2000-01-01 00:00:00'");
        $site->mcpResult('create_page', ['title' => 'Undo on the sibling', 'visible' => false, 'site' => self::$second]);
        $this->assertSame(['tl2_'], array_values(array_unique(array_column($site->rows("SELECT site_prefix FROM tl_agent_journal WHERE tbl = 'pages' ORDER BY id DESC LIMIT 1"), 'site_prefix'))));
        $session = (int) $site->value('SELECT MAX(id) FROM tl_agent_sessions');

        $this->call('undo_agent_session', ['id' => $session, 'confirm' => true]);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl2_pages WHERE title = ?', ['Undo on the sibling']), 'the row went from the second site');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE title = ?', ['On the first site']));
    }

    public function testSiteToolsManageDomainsAndStates(): void
    {
        $site = $this->site();
        $r = $site->mcpResult('add_site_domain', ['id' => self::$second, 'host' => 'WWW.Second.Test', 'redirect_to_primary' => true]);
        $this->assertSame([['second.test', true, false], ['www.second.test', false, true]], array_map(fn (array $d): array => [$d['host'], $d['primary'], $d['redirect_to_primary']], $r['site']['domains']));
        $this->assertStringContainsString('already used', $this->call('add_site_domain', ['id' => self::$second, 'host' => 'second.test'])['text']);
        $this->assertStringContainsString('host name', $this->call('add_site_domain', ['id' => self::$second, 'host' => 'http://bad/'])['text']);

        $r = $site->mcpResult('update_site_domain', ['id' => self::$second, 'host' => 'www.second.test', 'primary' => true]);
        $this->assertSame([['www.second.test', true], ['second.test', false]], array_map(fn (array $d): array => [$d['host'], $d['primary']], $r['site']['domains']));
        $r = $site->mcpResult('remove_site_domain', ['id' => self::$second, 'host' => 'second.test']);
        $this->assertSame(['www.second.test'], array_column($r['site']['domains'], 'host'));
        $site->mcpResult('add_site_domain', ['id' => self::$second, 'host' => 'second.test', 'primary' => true]);

        $this->assertStringContainsString('first site', $this->call('update_site', ['id' => $site->mcpResult('list_owner_sites')['sites'][0]['id'], 'trash' => true])['text']);
        $archived = $site->mcpResult('update_site', ['id' => self::$second, 'status' => 'archived', 'redirect_to' => $site->mcpResult('list_owner_sites')['sites'][0]['id']]);
        $this->assertSame(['archived', $site->mcpResult('list_owner_sites')['sites'][0]['id']], [$archived['site']['status'], $archived['site']['redirect_to']]);
        $site->mcpResult('update_site', ['id' => self::$second, 'status' => 'live', 'redirect_to' => '']);
    }

    public function testADraftsOnlyConnectionCannotChangeSites(): void
    {
        $site = $this->site();
        $token = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, access, created_at) SELECT user_id, 'drafts', ?, 'drafts', NOW() FROM tl_users WHERE username = 'admin'", [hash('sha256', $token)]);
        foreach ([['create_site', ['name' => 'Nope']], ['update_site', ['id' => self::$second, 'status' => 'paused']], ['add_site_domain', ['id' => self::$second, 'host' => 'nope.test']],
            ['update_site_domain', ['id' => self::$second, 'host' => 'second.test', 'primary' => true]], ['remove_site_domain', ['id' => self::$second, 'host' => 'second.test']]] as [$tool, $args]) {
            $r = $this->call($tool, $args, $token);
            $this->assertTrue($r['error'], $tool);
            $this->assertStringContainsString('only save drafts', $r['text']);
        }
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_sites WHERE name = 'Nope'"));
        $names = array_column((array) ($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $token)['result']['tools'] ?? []), 'name');
        $this->assertContains('list_owner_sites', $names, 'listing the sites is a read');
        $this->assertNotContains('create_site', $names);

        // a person who is not an administrator is refused the site tools, too
        $site->exec("INSERT INTO tl_users (username, password, email, admin) VALUES ('editor2', ?, 'editor2@example.test', 1)", [password_hash('editor-pass-123', PASSWORD_DEFAULT)]);
        $editor = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'editor', ?, NOW() FROM tl_users WHERE username = 'editor2'", [hash('sha256', $editor)]);
        $this->assertStringContainsString('for administrators', $this->call('list_owner_sites', [], $editor)['text']);
    }

    public function testAnArchiveImportIsRefusedWhileThereAreSeveralSites(): void
    {
        $page = $this->site()->admin()->get('/admin.php?module=transfer');
        $this->assertStringContainsString('only possible while the install has one site', $page->body);
        $this->assertStringNotContainsString('id="file-talea"', $page->body, 'no upload form');
        $backups = $this->site()->admin()->get('/admin.php?module=settings&tab=backups')->body;
        $this->assertTrue(!preg_match('/<button[^>]*restore_backup/', $backups) || str_contains($backups, 'puts back every site'), 'the restore says it covers every site');
    }
}
