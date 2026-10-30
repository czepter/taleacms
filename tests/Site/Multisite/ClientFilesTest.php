<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Bringing existing sites in, the two screens that touch a client (docs/specs/multisite.md, "Bringing existing sites in"): an operator imports
 * a Talea archive into a CLIENT owner from the Owners area, and a client administrator makes, lists, downloads and restores its own backups and
 * exports a site from Sites → Backups and exports, without reaching another owner's files. The tests run in order and build on each other.
 */
#[Group('site')]
final class ClientFilesTest extends SiteTestCase
{
    private const string BETA = 'localhost';
    private const string ENV_SECRET = 'k3y-for-the-client-files-test-0123456789abcdef01';

    private static string $betaToken = '';
    private static string $betaOwner = '';
    private static string $archive = '';

    protected static function siteOptions(): array
    {
        return ['env' => self::envVars()];
    }

    /** @return array<string, string> */
    private static function envVars(): array
    {
        return ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => self::ENV_SECRET, 'TALEA_TRUSTED_PROXIES' => '127.0.0.1'];
    }

    /** @return list<string> */
    private function host(bool $beta = true): array
    {
        return $beta ? ['Host: ' . self::BETA . ':' . $this->site()->port] : [];
    }

    /** @param array<string, mixed> $arguments @return array{error: bool, text: string} */
    private function mcp(string $tool, array $arguments = []): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
        $response = $this->site()->client('mcp')->post('/mcp', (string) $body, ['Authorization: Bearer ' . self::$betaToken, 'Content-Type: application/json', ...$this->host()]);
        $result = (array) ($response->json()['result'] ?? []);

        return ['error' => $response->status >= 400 || ($result['isError'] ?? false) === true, 'text' => (string) ($result['content'][0]['text'] ?? $response->body)];
    }

    /** @param array<string, string> $fields */
    private function post(Http $as, string $path, array $fields = [], string $formPage = '/admin.php?module=sites'): Response
    {
        return $as->post($path, ['_csrf' => $as->get($formPage, $this->host())->csrf()] + $fields, $this->host());
    }

    private function client(): Http
    {
        static $client = null;
        if ($client === null) {
            $client = $this->site()->client('beta-admin');
            $csrf = $client->get('/admin.php', $this->host())->csrf();
            $client->post('/admin.php', ['_csrf' => $csrf, 'username' => 'client', 'password' => $this->site()->password], $this->host());
        }

        return $client;
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);

        return base64_encode((string) ob_get_clean());
    }

    public function testAnOperatorImportsAnArchiveIntoAClientOwner(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 TALEA_OWNERS=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());

        // the archive: an export of owner 1's site with one page
        $site->exec('INSERT INTO tl_pages (public_id, title, slug, description, text, visible, in_menu, sort_order, updated_at) VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?)',
            ['0f5c1d52-6a53-4c8f-9a52-7d1f3c1b9e78', 'Archived page', 'archived-page', '', '<p>From the old install</p>', date('Y-m-d H:i:s')]);
        $admin = $site->admin();
        $admin->post('/admin.php?module=transfer&action=export', ['_csrf' => $site->csrf($admin, '/admin.php?module=transfer')]);
        preg_match('/export-[0-9]*-[0-9]*\.zip/', $admin->get('/admin.php?module=transfer')->body, $m);
        $this->assertNotEmpty($m, 'the export is listed');
        self::$archive = $site->workDir('import') . '/old.zip';
        file_put_contents(self::$archive, $admin->get('/admin.php?module=transfer&action=download&file=' . $m[0])->body);

        // the client owner, set up as in the operators' test
        $new = $admin->get('/admin.php?module=owners&action=new');
        $admin->post('/admin.php?module=owners&action=create', ['_csrf' => $new->csrf(), 'name' => 'Beta GmbH', 'kind' => 'organisation', 'admin_email' => 'boss@beta.example']);
        self::$betaOwner = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tlb_'");
        $this->assertStringContainsString('owner tlb_: ready', $site->cli(['system/docker.php', 'cron'], self::envVars()));
        $edit = $admin->get('/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $admin->post('/admin.php?module=owners&action=domain_add', ['_csrf' => $edit->csrf(), 'id' => self::$betaOwner, 'host' => self::BETA]);
        $site->exec("INSERT INTO tlb_users (username, password, name, email, admin, language) SELECT 'client', password, 'Client admin', 'client@beta.example', 2, '' FROM tl_users WHERE username = 'admin'");
        self::$betaToken = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tlb_users WHERE username = 'client'", [hash('sha256', self::$betaToken)]);
        foreach (['site_url' => 'http://' . self::BETA . ':' . $site->port, 'site_name' => 'Beta site'] as $name => $value) {
            $site->exec('INSERT INTO tlb_settings (name, value) VALUES (?, ?)', [$name, $value]);
        }
        $site->exec("INSERT INTO tlb_settings (name, value) SELECT name, value FROM tl_settings WHERE name IN ('extensions', 'tasks_token')");

        // the client already has a page and a picture of its own
        $this->assertFalse($this->mcp('create_page', ['title' => 'Beta home', 'slug' => 'beta-home', 'content' => '<p>BETA-TEXT</p>', 'visible' => true])['error']);
        $up = json_decode($this->mcp('upload_file', ['filename' => 'beta-logo.png', 'data' => $this->png()])['text'], true);
        $logo = (string) ($up['path'] ?? $up['image_path'] ?? '');
        $this->assertNotSame('', $logo);
        $this->assertFileExists($site->path($logo));

        // the client cannot reach the operators' import; the operator uploads into the client
        $this->assertNotSame(200, $this->client()->get('/admin.php?module=owners&action=import', $this->host())->status);
        $form = $admin->get('/admin.php?module=owners&action=import');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString(self::$betaOwner, $form->body, 'the client is offered as the owner that gets the site');
        $r = $admin->upload('/admin.php?module=owners&action=import', ['_csrf' => $form->csrf(), 'name' => 'Imported old site', 'to' => self::$betaOwner], ['file' => self::$archive]);
        $this->assertSame(302, $r->status);
        $row = $site->rows("SELECT site_id, prefix, ready_at, import_file FROM tlb_sites WHERE name = 'Imported old site'")[0] ?? null;
        $this->assertNotNull($row, 'the new site is a site of the client');
        $this->assertNull($row['ready_at']);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_sites WHERE name = 'Imported old site'"), 'nothing was written to the operator\'s own sites');
        $prefix = (string) $row['prefix'];

        $cron = '';
        for ($i = 0; $i < 10 && !str_contains($cron, 'site ' . $prefix . ': ready'); $i++) {
            $cron = $site->cli(['system/docker.php', 'cron'], self::envVars());
        }
        $this->assertStringContainsString('site ' . $prefix . ': ready', $cron);
        $this->assertNotNull($site->value("SELECT ready_at FROM tlb_sites WHERE name = 'Imported old site'"));

        // it answers on its host (set up here by SQL: host names are covered elsewhere)
        $site->exec('INSERT INTO tlb_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at) VALUES (?, ?, TRUE, FALSE, ?)', [(int) $row['site_id'], 'imported.test', date('Y-m-d H:i:s')]);
        $site->exec("UPDATE tlb_sites SET status = 'live' WHERE site_id = ?", [(int) $row['site_id']]);
        $page = $site->client('visitor')->get('/archived-page', ['Host: imported.test:' . $site->port]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('From the old install', $page->body);

        // the client's own site and media survive
        $this->assertStringContainsString('BETA-TEXT', $site->client('visitor2')->get('/beta-home', $this->host())->body);
        $this->assertFileExists($site->path($logo));
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_media WHERE image_path = ?', [$logo]));
        $this->assertSame(200, $site->client('img')->get('/' . $logo, $this->host())->status);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'archived-page'"), 'owner 1 is untouched');
    }

    public function testAClientMakesListsDownloadsAndRestoresItsOwnBackup(): void
    {
        $site = $this->site();
        $client = $this->client();
        $files = $client->get('/admin.php?module=sites&action=files', $this->host());
        $this->assertSame(200, $files->status);
        $this->assertStringContainsString('Create backup now', $files->body);
        $this->assertStringContainsString('Backups and exports', $client->get('/admin.php?module=sites', $this->host())->body, 'the Sites area links to it');

        // state at backup time: a page, a second user, a spare connection, one host name
        $this->assertFalse($this->mcp('create_page', ['title' => 'Keep me', 'slug' => 'keep-me', 'content' => '<p>KEEP-ME</p>', 'visible' => true])['error']);
        $site->exec("INSERT INTO tlb_users (username, password, name, email, admin, language) SELECT 'staff', password, 'Staff', 'staff@beta.example', 1, '' FROM tl_users WHERE username = 'admin'");
        $spare = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'spare', ?, NOW() FROM tlb_users WHERE username = 'client'", [hash('sha256', $spare)]);

        $r = $this->post($client, '/admin.php?module=sites&action=backup_create', [], '/admin.php?module=sites&action=files');
        $this->assertSame(302, $r->status);
        $files = $client->get('/admin.php?module=sites&action=files', $this->host());
        $this->assertSame(1, preg_match('/backup_download\?file=(talea-[0-9a-z-]+\.sql(?:\.gz)?)/', $files->body, $m), 'the backup is listed');
        $backup = $m[1];
        $folder = $site->path('storage/backups/owners/' . self::$betaOwner);
        $this->assertFileExists($folder . '/' . $backup, 'in the owner\'s own folder');
        $this->assertFileDoesNotExist($site->path('storage/backups/' . $backup), 'not in the install\'s folder');

        $download = $client->get('/admin.php?module=sites&action=backup_download&file=' . $backup, $this->host());
        $this->assertSame(200, $download->status);
        $this->assertGreaterThan(100, strlen($download->body));
        $this->assertSame(file_get_contents($folder . '/' . $backup), $download->body);

        // after the backup: content changes, which a restore undoes; a host name, a blocked user and a revoked connection, which it does not
        $site->exec("DELETE FROM tlb_pages WHERE slug = 'keep-me'");
        $this->assertFalse($this->mcp('create_page', ['title' => 'Only after', 'slug' => 'only-after', 'content' => '<p>AFTER</p>', 'visible' => true])['error']);
        $site->exec('INSERT INTO tlb_site_domains (site_id, host, is_primary, redirect_to_primary, verified_at) VALUES (1, ?, FALSE, FALSE, ?)', ['extra-after.test', date('Y-m-d H:i:s')]);
        $site->exec("UPDATE tlb_users SET blocked = TRUE WHERE username = 'staff'");
        $site->exec("DELETE FROM tlb_api_tokens WHERE name = 'spare'");

        $r = $this->post($client, '/admin.php?module=sites&action=backup_restore', ['file' => $backup], '/admin.php?module=sites&action=files');
        $this->assertSame(302, $r->status);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb_pages WHERE slug = 'keep-me'"), 'content is back');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tlb_pages WHERE slug = 'only-after'"), 'what was added since is gone');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb_site_domains WHERE host = 'extra-after.test'"), 'the host name stays as it is now');
        $this->assertSame(1, (int) $site->value("SELECT blocked FROM tlb_users WHERE username = 'staff'"), 'the blocked user stays blocked');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tlb_api_tokens WHERE name = 'spare'"), 'the revoked connection stays revoked');
        $this->assertSame(200, $site->client('visitor3')->get('/keep-me', $this->host())->status);
        $this->assertSame(200, $client->get('/admin.php?module=sites', $this->host())->status, 'the session survives');
        $this->assertGreaterThanOrEqual(2, count(glob($folder . '/talea-*.sql*') ?: []), 'the state before the restore was saved as a new backup');

        // owner 1 is not touched by any of this
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'archived-page'"));
        $this->assertSame([], array_intersect(array_map('basename', glob($folder . '/talea-*.sql*') ?: []), array_map('basename', glob($site->path('storage/backups') . '/talea-*.sql*') ?: [])), 'none of them in the install\'s folder');

        // deleting
        $this->post($client, '/admin.php?module=sites&action=backup_delete', ['file' => $backup], '/admin.php?module=sites&action=files');
        $this->assertFileDoesNotExist($folder . '/' . $backup);
    }

    public function testAClientExportsASiteAndDownloadsIt(): void
    {
        $site = $this->site();
        $client = $this->client();
        $r = $this->post($client, '/admin.php?module=sites&action=export_create', [], '/admin.php?module=sites&action=files');
        $this->assertSame(302, $r->status);
        $files = $client->get('/admin.php?module=sites&action=files', $this->host());
        $this->assertSame(1, preg_match('/export_download\?file=(export-[0-9a-z-]+\.zip)/', $files->body, $m), 'the export is listed');
        $this->assertFileExists($site->path('storage/backups/owners/' . self::$betaOwner . '/' . $m[1]));
        $zip = $client->get('/admin.php?module=sites&action=export_download&file=' . $m[1], $this->host());
        $this->assertSame(200, $zip->status);
        $this->assertSame('PK', substr($zip->body, 0, 2), 'a zip archive');

        // owner 1 sees none of it
        $this->assertStringNotContainsString($m[1], $site->admin()->get('/admin.php?module=transfer')->body);
    }

    public function testAClientCannotDownloadOrRestoreAnotherOwnersFileByGuessingItsName(): void
    {
        $site = $this->site();
        $client = $this->client();
        // a file in owner 1's folder (the install's), with the shape of a backup name, and one in another owner's folder
        $alpha = $site->path('storage/backups');
        @mkdir($alpha, 0775, true);
        file_put_contents($alpha . '/talea-alpha-secret.sql', "-- talea backup of owner 1\nCREATE TABLE `tl_pages` (x INT);\n");
        $other = $site->path('storage/backups/owners/00000000-0000-4000-8000-000000000000');
        @mkdir($other, 0775, true);
        file_put_contents($other . '/talea-gamma-secret.sql', "-- talea backup of another owner\n");
        $pages = (int) $site->value('SELECT COUNT(*) FROM tlb_pages');
        $alphaPages = (int) $site->value('SELECT COUNT(*) FROM tl_pages');

        $names = ['talea-alpha-secret.sql', 'talea-gamma-secret.sql', '../talea-alpha-secret.sql', '../../talea-alpha-secret.sql', '../owners/00000000-0000-4000-8000-000000000000/talea-gamma-secret.sql', '..%2Ftalea-alpha-secret.sql'];
        foreach ($names as $name) {
            $get = $client->get('/admin.php?module=sites&action=backup_download&file=' . rawurlencode($name), $this->host());
            $this->assertSame(404, $get->status, 'download ' . $name);
            $this->assertStringNotContainsString('CREATE TABLE', $get->body);
            $this->assertStringNotContainsString('another owner', $get->body);
            $export = $client->get('/admin.php?module=sites&action=export_download&file=' . rawurlencode($name), $this->host());
            $this->assertSame(404, $export->status, 'export download ' . $name);
            $r = $this->post($client, '/admin.php?module=sites&action=backup_restore', ['file' => $name], '/admin.php?module=sites&action=files');
            $this->assertStringNotContainsString('have been restored', $r->body, 'restore ' . $name);
            $this->assertStringNotContainsString('have been restored', $client->get('/admin.php?module=sites&action=files', $this->host())->body);
            $this->post($client, '/admin.php?module=sites&action=backup_delete', ['file' => $name], '/admin.php?module=sites&action=files');
        }
        $this->assertFileExists($alpha . '/talea-alpha-secret.sql', 'not deleted');
        $this->assertFileExists($other . '/talea-gamma-secret.sql', 'not deleted');
        $this->assertSame([$pages, $alphaPages], [(int) $site->value('SELECT COUNT(*) FROM tlb_pages'), (int) $site->value('SELECT COUNT(*) FROM tl_pages')], 'no data changed');

        // the operators' own administration has no backup list of the client either, and the first owner has no such screen
        $this->assertSame(404, $site->admin()->get('/admin.php?module=sites&action=files')->status);
        $this->assertSame(404, $site->admin()->get('/admin.php?module=sites&action=backup_download&file=talea-alpha-secret.sql')->status);
    }
}
