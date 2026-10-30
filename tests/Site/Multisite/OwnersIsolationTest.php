<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Several owners (docs/specs/multisite.md, "Several owners"): owner A (tl_, host 127.0.0.1) and owner B (tlb_, host localhost) in one
 * install, with the same page slugs, component names and media paths. Nothing of B is reachable from A's host and the other way round:
 * public routes, search, sitemap, admin lists and sessions, MCP tokens and the `site` argument, signed preview links, handoff tokens,
 * waiting files, backups and cron. The tests run in order and build on each other.
 */
#[Group('site')]
final class OwnersIsolationTest extends SiteTestCase
{
    private const string BETA = 'localhost'; // MCP over plain HTTP is allowed on loopback hosts only

    private static string $betaToken = '';
    private static string $alphaComponent = '';
    private static string $betaComponent = '';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => 'k3y-for-the-owners-isolation-test-0123456789abcdef']];
    }

    private function env(): array
    {
        return ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => 'k3y-for-the-owners-isolation-test-0123456789abcdef'];
    }

    /** @return list<string> */
    private function host(bool $beta): array
    {
        return $beta ? ['Host: ' . self::BETA . ':' . $this->site()->port] : [];
    }

    private function get(bool $beta, string $path, ?Http $as = null): Response
    {
        return ($as ?? $this->site()->client('visitor'))->get($path, $this->host($beta));
    }

    /** @param array<string, mixed> $arguments @return array{error: bool, text: string} */
    private function mcp(bool $beta, string $tool, array $arguments = [], ?string $token = null): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
        $response = $this->site()->client('mcp')->post('/mcp', (string) $body, ['Authorization: Bearer ' . ($token ?? ($beta ? self::$betaToken : $this->site()->mcpToken)), 'Content-Type: application/json', ...$this->host($beta)]);
        $result = (array) ($response->json()['result'] ?? []);

        return ['error' => $response->status >= 400 || ($result['isError'] ?? false) === true, 'text' => $response->status >= 400 ? 'HTTP ' . $response->status . ' ' . substr($response->body, 0, 300) : (string) ($result['content'][0]['text'] ?? '')];
    }

    private function betaAdmin(): Http
    {
        static $client = null;
        if ($client === null) {
            $client = $this->site()->client('beta-admin');
            $csrf = $client->get('/admin.php', $this->host(true))->csrf();
            $client->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => $this->site()->password], $this->host(true));
        }

        return $client;
    }

    public function testOwnerBIsSetUpByCronAndGetsContentIdenticalToA(): void
    {
        $site = $this->site();
        $base = 'cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 TALEA_OWNERS=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"';
        $this->assertStringContainsString('exit:0', (string) shell_exec($base));
        $site->signIn($site->admin());
        $site->setting('site_name', 'Alpha site');

        $site->exec("INSERT INTO tl_owners (public_id, name, kind, prefix, status, created_at) VALUES ('7a2d0c9e-0b0e-4a53-9a58-3c1f6f0f2b0b', 'Beta', 'organisation', 'tlb_', 'live', NOW())");
        $cron = $site->cli(['system/docker.php', 'cron'], $this->env());
        $this->assertStringContainsString('owner tlb_: ready', $cron);
        $this->assertStringContainsString('[tlb_] ', $cron, 'owner B\'s jobs ran in a process of their own');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_owners WHERE ready_at IS NOT NULL AND prefix = 'tlb_'"));
        $this->assertSame('tlb_', $site->value('SELECT prefix FROM tlb_sites WHERE site_id = 1'));

        // B's administrator has the very same password hash and number as A's: only the owner decides who they are
        $site->exec("INSERT INTO tlb_users (username, password, name, email, admin, language) SELECT username, password, 'Beta admin', 'beta@example.com', 2, '' FROM tl_users WHERE username = 'admin'");
        self::$betaToken = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tlb_users WHERE username = 'admin'", [hash('sha256', self::$betaToken)]);
        $site->exec("INSERT INTO tlb_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at) VALUES (1, ?, TRUE, FALSE, CURRENT_TIMESTAMP)", [self::BETA]);
        foreach (['site_url' => 'http://' . self::BETA . ':' . $site->port, 'site_name' => 'Beta site'] as $name => $value) {
            $site->exec('INSERT INTO tlb_settings (name, value) VALUES (?, ?)', [$name, $value]);
        }
        $site->exec("INSERT INTO tlb_settings (name, value) SELECT name, value FROM tl_settings WHERE name IN ('extensions', 'tasks_token')");
        $this->assertSame([1, 1], [(int) $site->value("SELECT user_id FROM tl_users WHERE username = 'admin'"), (int) $site->value("SELECT user_id FROM tlb_users WHERE username = 'admin'")]);

        foreach ([false, true] as $beta) {
            $name = $beta ? 'BETA' : 'ALPHA';
            $r = $this->mcp($beta, 'create_page', ['title' => 'About', 'slug' => 'about', 'content' => "<p>{$name}-SECRET-TEXT</p>", 'visible' => true]);
            $this->assertFalse($r['error'], $r['text']);
            $r = $this->mcp($beta, 'create_page', ['title' => 'Draft', 'slug' => 'draft', 'content' => "<p>{$name}-DRAFT-TEXT</p>", 'visible' => false]);
            $this->assertFalse($r['error'], $r['text']);
            $r = $this->mcp($beta, 'create_page', ['title' => $name . ' only', 'slug' => strtolower($name) . '-only', 'content' => '<p>x</p>', 'visible' => true]);
            $this->assertFalse($r['error'], $r['text']);
            $component = json_decode($this->mcp($beta, 'save_component', ['name' => 'Card'])['text'], true);
            $this->assertIsArray($component, 'a component named Card on both owners');
            if ($beta) {
                self::$betaComponent = (string) ($component['id'] ?? $component['component']['id'] ?? '');
            } else {
                self::$alphaComponent = (string) ($component['id'] ?? $component['component']['id'] ?? '');
            }
            // the same media path on both owners
            $site->exec(($beta ? 'INSERT INTO tlb_media' : 'INSERT INTO tl_media') . " (name, image_path, created_at) VALUES ('{$name} photo', 'media/2026/10/photo-aaaaaa.jpg', NOW())");
        }
        $this->assertNotSame('', self::$alphaComponent);
        $this->assertNotSame(self::$alphaComponent, self::$betaComponent);
    }

    public function testPublicRoutesSearchAndSitemapStayInsideTheHost(): void
    {
        foreach ([false, true] as $round) { // the second round is served from each site's page cache
            $alpha = $this->get(false, '/about');
            $beta = $this->get(true, '/about');
            $this->assertSame([200, 200], [$alpha->status, $beta->status], "round {$round}");
            $this->assertStringContainsString('ALPHA-SECRET-TEXT', $alpha->body);
            $this->assertStringNotContainsString('BETA-SECRET-TEXT', $alpha->body);
            $this->assertStringContainsString('BETA-SECRET-TEXT', $beta->body);
            $this->assertStringNotContainsString('ALPHA-SECRET-TEXT', $beta->body);
        }
        $this->assertSame(404, $this->get(false, '/beta-only')->status, 'a page of B is not on A\'s host');
        $this->assertSame(404, $this->get(true, '/alpha-only')->status);

        $map = [$this->get(false, '/sitemap.xml')->body, $this->get(true, '/sitemap.xml')->body];
        $this->assertStringContainsString('alpha-only', $map[0]);
        $this->assertStringNotContainsString('beta-only', $map[0]);
        $this->assertStringContainsString('beta-only', $map[1]);
        $this->assertStringNotContainsString('alpha-only', $map[1]);
        $this->assertStringNotContainsString(self::BETA, $map[0]);
        $this->assertStringNotContainsString('127.0.0.1', $map[1]);

        $this->assertStringNotContainsString('BETA only', $this->get(false, '/search?search=only')->body);
        $this->assertStringNotContainsString('ALPHA only', $this->get(true, '/search?search=only')->body);
        $this->assertStringNotContainsString('beta-only', $this->get(false, '/llms.txt')->body);
        $this->assertStringNotContainsString('alpha-only', $this->get(true, '/feed.json')->body);
    }

    public function testAdminListsAndComponentsOfTheOtherOwnerAreNotThere(): void
    {
        $site = $this->site();
        $alpha = $site->admin()->get('/admin.php?module=pages');
        $this->assertStringContainsString('ALPHA only', $alpha->body);
        $this->assertStringNotContainsString('BETA only', $alpha->body);
        $beta = $this->betaAdmin()->get('/admin.php?module=pages', $this->host(true));
        $this->assertStringContainsString('BETA only', $beta->body);
        $this->assertStringNotContainsString('ALPHA only', $beta->body);

        // components named alike, each owner sees its own; the other's public id is unknown
        $this->assertStringContainsString(self::$alphaComponent, $this->mcp(false, 'list_components')['text']);
        $this->assertStringNotContainsString(self::$betaComponent, $this->mcp(false, 'list_components')['text']);
        $this->assertStringNotContainsString(self::$alphaComponent, $this->mcp(true, 'list_components')['text']);
        $this->assertSame(404, $this->get(false, '/_component/' . self::$betaComponent, $site->admin())->status);

        // the same media path, two rows, two owners
        $this->assertStringContainsString('ALPHA photo', $this->mcp(false, 'list_media')['text']);
        $this->assertStringNotContainsString('BETA photo', $this->mcp(false, 'list_media')['text']);
        $this->assertStringContainsString('BETA photo', $this->mcp(true, 'list_media')['text']);
        $this->assertStringNotContainsString('ALPHA photo', $this->mcp(true, 'list_media')['text']);
    }

    public function testASessionOfOneOwnerIsNothingOnTheOthersHost(): void
    {
        $site = $this->site();
        // curl matches its cookie jar against the Host header, so the session cookie of one owner goes to the other's host by hand: the same
        // user number and the same password hash on both sides, only the owner tells them apart
        $this->assertSame(200, $site->admin()->get('/admin.php?module=pages')->status);
        $alphaCookie = 'Cookie: talea=' . $site->admin()->cookies()['talea'];
        $betaCookie = 'Cookie: talea=' . $this->betaAdmin()->cookies()['talea'];
        $this->assertNotSame($alphaCookie, $betaCookie);

        $onBeta = $site->client('replay-a')->get('/admin.php?module=pages', [...$this->host(true), $alphaCookie]);
        $this->assertStringNotContainsString('BETA only', $onBeta->body);
        $this->assertStringNotContainsString('ALPHA only', $onBeta->body);
        $this->assertStringContainsString('name="password"', $onBeta->body, 'the sign-in page, not the administration');

        $onAlpha = $site->client('replay-b')->get('/admin.php?module=pages', [$betaCookie]);
        $this->assertStringNotContainsString('ALPHA only', $onAlpha->body);
        $this->assertStringContainsString('name="password"', $onAlpha->body);

        // the same cookies on their own hosts still work
        $this->assertStringContainsString('ALPHA only', $site->client('replay-a2')->get('/admin.php?module=pages', [$alphaCookie])->body);
        $this->assertStringContainsString('BETA only', $site->client('replay-b2')->get('/admin.php?module=pages', [...$this->host(true), $betaCookie])->body);
        // and A's old sessions (no owner marker) are A's: the marker is only written for further owners
        $this->assertStringContainsString('ALPHA only', $site->admin()->get('/admin.php?module=pages')->body);
    }

    public function testTokensAndTheSiteArgumentDoNotCrossOwners(): void
    {
        $site = $this->site();
        $this->assertTrue($this->mcp(true, 'list_pages', [], $site->mcpToken)['error'], 'A\'s token on B\'s host');
        $this->assertTrue($this->mcp(false, 'list_pages', [], self::$betaToken)['error'], 'B\'s token on A\'s host');
        $betaSite = (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1');
        $before = (int) $site->value('SELECT COUNT(*) FROM tl_pages');
        $r = $this->mcp(false, 'create_page', ['title' => 'Leak', 'visible' => false, 'site' => $betaSite]);
        $this->assertTrue($r['error']);
        $this->assertStringContainsString('Unknown site', $r['text']);
        $this->assertSame([$before, 0], [(int) $site->value('SELECT COUNT(*) FROM tl_pages'), (int) $site->value("SELECT COUNT(*) FROM tlb_pages WHERE title = 'Leak'")], 'nothing was written anywhere');
        $this->assertStringNotContainsString($betaSite, $this->mcp(false, 'list_owner_sites')['text']);
        $this->assertStringNotContainsString('tlb', $this->mcp(false, 'list_owner_sites')['text']);
    }

    public function testASignedPreviewLinkOfOneOwnerShowsNothingOnTheOther(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('ALPHA-DRAFT-TEXT', $this->get(false, $this->previewPath(false), $site->client('visitor'))->body, 'the link works on its own host');
        $this->assertSame(404, $this->get(false, '/draft')->status, 'the draft is hidden without the key');
        $onBeta = $this->get(true, $this->previewPath(false));
        $this->assertStringNotContainsString('ALPHA-DRAFT-TEXT', $onBeta->body);
        $this->assertStringNotContainsString('BETA-DRAFT-TEXT', $onBeta->body, 'A\'s key does not open B\'s hidden page either');
        $this->assertSame(404, $onBeta->status);
        $this->assertSame(404, $this->get(false, $this->previewPath(true))->status, 'and the other way round');
    }

    private function previewPath(bool $beta): string
    {
        $id = (string) $this->site()->value(($beta ? 'SELECT public_id FROM tlb_pages' : 'SELECT public_id FROM tl_pages') . " WHERE slug = 'draft'");
        $text = $this->mcp($beta, 'preview_link', ['id' => $id])['text'];
        preg_match('#(/[^\s"\\\\]*preview_key=[^"&\s\\\\]+)#', $text, $m);
        $this->assertNotEmpty($m, $text);

        return $m[1];
    }

    public function testHandoffTokensAreBoundToTheirOwner(): void
    {
        $site = $this->site();
        $token = bin2hex(random_bytes(32));
        $site->exec('INSERT INTO tl_handoff_tokens (token_hash, user_id, site_id, network_hash, expires_at) VALUES (?, 1, 1, ?, ?)', [hash('sha256', $token), hash('sha256', 'talea-handoff|127.0.0.1'), gmdate('Y-m-d H:i:s', time() + 60)]);
        $b = $site->client('handoff-b');
        $b->get('/admin.php?handoff=' . $token, $this->host(true));
        $this->assertStringContainsString('name="password"', $b->get('/admin.php?module=pages', $this->host(true))->body, 'B has no such token: not signed in');
        $this->assertNull($site->value('SELECT used_at FROM tl_handoff_tokens WHERE token_hash = ?', [hash('sha256', $token)]), 'and A\'s row was not touched');
        $a = $site->client('handoff-a');
        $a->get('/admin.php?handoff=' . $token);
        $this->assertStringContainsString('ALPHA only', $a->get('/admin.php?module=pages')->body, 'on its own owner the token signs in');
    }

    public function testAnAccountsResetAllowanceIsNotSharedWithTheOtherOwnersAccountWithTheSameNumber(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_users SET email = 'alpha-admin@example.com', reset_token_hash = '' WHERE user_id = 1");
        $site->exec("UPDATE tlb_users SET reset_token_hash = '' WHERE user_id = 1");
        $ask = function (bool $beta) use ($site): void {
            $client = $site->client('reset-' . random_int(1, 1_000_000));
            $client->post('/admin.php?action=password', ['_csrf' => $client->get('/admin.php?action=password', $this->host($beta))->csrf(), 'user_id' => 'admin'], $this->host($beta));
        };
        for ($i = 0; $i < 4; $i++) { // three mails an hour per account, whoever asks: the fourth is not sent
            $site->exec("UPDATE tl_users SET reset_token_hash = '' WHERE user_id = 1");
            $ask(false);
        }
        $this->assertSame('', $site->value('SELECT reset_token_hash FROM tl_users WHERE user_id = 1'), 'owner A\'s account is over its allowance');
        $ask(true);
        $this->assertNotSame('', $site->value('SELECT reset_token_hash FROM tlb_users WHERE user_id = 1'), 'owner B\'s account with the same number is not');
    }

    public function testAWaitingFileOfOneOwnerIsUnknownToTheOther(): void
    {
        $site = $this->site();
        $path = 'media/2026/10/waiting-bbbbbb.jpg';
        @mkdir($site->path('storage/staging/media/2026/10'), 0775, true);
        file_put_contents($site->path('storage/staging/' . $path), 'jpeg-of-b');
        $site->exec("INSERT INTO tlb_media (name, image_path, staged_at, created_at) VALUES ('waiting', ?, NOW(), NOW())", [$path]);
        $this->assertSame(200, $this->get(true, '/' . $path, $this->betaAdmin())->status, 'B\'s administrator sees its waiting file');
        $this->assertSame(404, $this->get(false, '/' . $path, $site->admin())->status, 'A\'s administrator does not');
        $this->assertSame(404, $this->get(true, '/' . $path)->status, 'a visitor neither');
    }

    public function testBackupsAndTheInstallsFilesBelongToTheFirstOwner(): void
    {
        $site = $this->site();
        $csrf = $site->csrf();
        $site->admin()->post('/admin.php?module=settings&action=backup', ['_csrf' => $csrf]);
        $files = glob($site->path('storage/backups/talea-*')) ?: [];
        $this->assertNotSame([], $files, 'owner A made a backup');
        $file = basename($files[0]);

        $beta = $this->betaAdmin();
        $this->assertSame(403, $beta->get('/admin.php?module=settings&action=download_backup&file=' . $file, $this->host(true))->status);
        $tab = $beta->get('/admin.php?module=settings&tab=backups', $this->host(true));
        $this->assertStringNotContainsString($file, $tab->body, 'the install\'s backups are not listed to B');
        $made = $beta->post('/admin.php?module=settings&action=backup', ['_csrf' => $tab->csrf()], $this->host(true));
        $this->assertCount(count($files), glob($site->path('storage/backups/talea-*')) ?: [], 'B made none');
        $this->assertStringNotContainsString($file, $made->body);
        // every spelling of the action reaches the same method (PHP ignores case in method names) and is refused the same way
        foreach (['downloadbackup', 'Download_Backup', 'DOWNLOADBACKUP', 'download__backup', 'download_backup_', '_download_backup', 'downloadBackup'] as $spelling) {
            $r = $beta->get('/admin.php?module=settings&action=' . $spelling . '&file=' . $file, $this->host(true));
            $this->assertContains($r->status, [403, 404], $spelling);
            $this->assertStringNotContainsString('CREATE TABLE', $r->body, $spelling);
        }
        $this->assertSame(403, $beta->get('/admin.php?module=settings&action=downloadbackup&file=' . $file, $this->host(true))->status, 'the spelling without the underscore');
        foreach (['deletebackup' => ['file' => $file], 'deletelog' => [], 'mediabackup' => [], 'restorebackup' => ['file' => $file], 'backup' => []] as $spelling => $data) {
            $r = $beta->post('/admin.php?module=settings&action=' . $spelling, ['_csrf' => $tab->csrf()] + $data, $this->host(true));
            $this->assertSame(403, $r->status, $spelling);
        }
        $this->assertFileExists($files[0], 'the backup of A is still there');
        $this->assertNotSame(200, $beta->get('/admin.php?module=transfer', $this->host(true))->status, 'the export files are the install\'s too');
        $this->assertSame(200, $site->admin()->get('/admin.php?module=transfer')->status);
    }

    public function testAPausedOwnerAnswers503AnArchivedOne404AndKeepsItsHost(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_owners SET status = 'paused' WHERE prefix = 'tlb_'");
        $paused = $this->get(true, '/about');
        $this->assertSame(503, $paused->status);
        $this->assertSame(['3600', 'noindex'], [$paused->headers['retry-after'] ?? '', $paused->headers['x-robots-tag'] ?? '']);
        $this->assertSame(503, $this->get(true, '/admin.php', $this->betaAdmin())->status, 'the administration too');
        $this->assertSame(200, $this->get(true, '/health')->status);
        $this->assertSame(200, $this->get(false, '/about')->status, 'owner A is unaffected');

        $site->exec("UPDATE tl_owners SET status = 'archived' WHERE prefix = 'tlb_'");
        $this->assertSame(404, $this->get(true, '/about')->status);
        $this->assertSame(200, $this->get(true, '/health')->status);
        $this->assertStringNotContainsString('tlb_', $this->get(true, '/about')->body);
        $taken = $this->mcp(false, 'add_site_domain', ['id' => (string) $site->value('SELECT public_id FROM tl_sites WHERE site_id = 1'), 'host' => self::BETA]);
        $this->assertTrue($taken['error'], 'an archived client\'s domain cannot be taken over by another owner');
        $this->assertStringContainsString('already used', $taken['text']);
        $site->exec("UPDATE tl_owners SET status = 'live' WHERE prefix = 'tlb_'");
        $this->assertSame(200, $this->get(true, '/about')->status);
    }

    public function testCronRunsEachOwnerOnItsOwnTablesAndSkipsAPausedOne(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_jobs');
        $site->exec('DELETE FROM tlb_jobs');
        $live = $site->cli(['system/docker.php', 'cron'], $this->env());
        $this->assertStringContainsString('[tlb_] ', $live);
        $this->assertGreaterThan(0, (int) $site->value('SELECT COUNT(*) FROM tlb_jobs'));
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tlb_jobs WHERE name IN ('backup', 'media_sync')"), 'the install\'s own jobs do not run for B');
        $this->assertGreaterThan(0, (int) $site->value("SELECT COUNT(*) FROM tl_jobs WHERE name = 'backup'") + 1);

        $site->exec("UPDATE tl_owners SET status = 'paused' WHERE prefix = 'tlb_'");
        $this->assertStringNotContainsString('[tlb_] ', $site->cli(['system/docker.php', 'cron'], $this->env()), 'a paused owner runs nothing');
        $site->exec("UPDATE tl_owners SET status = 'live' WHERE prefix = 'tlb_'");

        $this->assertStringContainsString('No further owner', $site->cli(['system/docker.php', 'cron', 'tlz_', 'tlz_'], $this->env()));
        $this->assertStringContainsString('No further owner', $site->cli(['system/docker.php', 'cron', 'tlb_', 'tl2_'], $this->env()), 'a site prefix of another owner');
    }

    public function testFurtherOwnersAreRefusedAtStartWithoutTheFlag(): void
    {
        $out = (string) shell_exec('cd ' . escapeshellarg($this->site()->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"');
        $this->assertStringContainsString('TALEA_OWNERS', $out);
        $this->assertStringContainsString('exit:1', $out);
    }
}
