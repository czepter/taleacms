<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Bringing an existing site in (docs/specs/multisite.md, "Bringing existing sites in"): the export of this install is uploaded in
 * Sites → Import a Talea archive, cron imports it as site 2, and the pages answer on the new site's host while site 1 goes on.
 */
#[Group('site')]
final class SiteImportTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_BACKGROUND' => '0']];
    }

    public function testAnExportBecomesANewSiteThatCronImports(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $site->exec('INSERT INTO tl_pages (public_id, title, slug, description, text, visible, in_menu, sort_order, updated_at) VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?)',
            ['0f5c1d52-6a53-4c8f-9a52-7d1f3c1b9e77', 'Moved page', 'moved-page', '', '<p>Hello from the old install</p>', date('Y-m-d H:i:s')]);

        $admin = $site->admin();
        $admin->post('/admin.php?module=transfer&action=export', ['_csrf' => $site->csrf($admin, '/admin.php?module=transfer')]);
        preg_match('/export-[0-9]*-[0-9]*\.zip/', $admin->get('/admin.php?module=transfer')->body, $m);
        $this->assertNotEmpty($m, 'the export is listed');
        $zip = $site->workDir('import') . '/old.zip';
        file_put_contents($zip, $admin->get('/admin.php?module=transfer&action=download&file=' . $m[0])->body);

        $this->assertStringContainsString('Import a Talea archive', $admin->get('/admin.php?module=sites')->body);
        $upload = $admin->upload('/admin.php?module=sites&action=create', ['_csrf' => $site->csrf($admin, '/admin.php?module=sites'), 'name' => 'Moved', 'from' => 'file'], ['file' => $zip]);
        $this->assertSame(302, $upload->status);
        $row = $site->rows('SELECT ready_at, import_file, prefix FROM tl_sites WHERE site_id = 2')[0];
        $this->assertNull($row['ready_at']);
        $this->assertSame(['archive.zip', 'tl2_'], [$row['import_file'], $row['prefix']]);
        $this->assertStringContainsString('Importing', $admin->get('/admin.php?module=sites')->body);

        $id = (string) $site->value('SELECT public_id FROM tl_sites WHERE site_id = 2');
        $this->assertStringContainsString('data-import-status', $admin->get('/admin.php?module=sites&action=progress&id=' . $id)->body, 'the progress screen');
        $this->assertSame(302,$admin->get('/admin.php?module=sites&action=edit&id=' . $id)->status, 'an import in progress opens its progress screen');
        $status = json_decode($admin->get('/admin.php?module=sites&action=status&id=' . $id)->body, true);
        $this->assertSame(['running', 4, 0], [$status['state'], count($status['steps']), $status['percent']]);
        $admin->post('/admin.php?module=sites&action=domain_add', ['_csrf' => $site->csrf($admin, '/admin.php?module=sites'), 'id' => $id, 'host' => 'moved.test']);
        $cron = '';
        for ($i = 0; $i < 10 && !str_contains($cron, 'site tl2_: ready'); $i++) {
            $cron = $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']);
        }
        $this->assertStringContainsString('site tl2_: ready', $cron);
        $this->assertNotNull($site->value('SELECT ready_at FROM tl_sites WHERE site_id = 2'));
        $this->assertSame('ready', json_decode($admin->get('/admin.php?module=sites&action=status&id=' . $id)->body, true)['state']);
        $admin->post('/admin.php?module=sites&action=save', ['_csrf' => $site->csrf($admin, '/admin.php?module=sites'), 'id' => $id, 'name' => 'Moved', 'status' => 'live']);

        $host = 'Host: moved.test:' . $site->port;
        $page = $site->client('visitor')->get('/moved-page', [$host]);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Hello from the old install', $page->body);
        $this->assertSame(200, $site->client('visitor')->get('/moved-page')->status === 200 ? 200 : 0, 'site 1 still answers');
        $this->assertStringContainsString('Moved page', $admin->get('/admin.php?module=sites')->body);
    }
}
