<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Site;

/** Short links in the site export (content targets as public ids, back to row ids on import) and in the site audit (kind short_link). */
#[Group('site')]
final class ShortLinksExportAuditTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    private static ?Site $moved = null;

    /** @var array<string, string> */
    private static array $ids = [];

    public static function tearDownAfterClass(): void
    {
        self::$moved?->close();
        self::$moved = null;
        parent::tearDownAfterClass();
    }

    private function link(string $path, string $type, int|string|null $target, string $source = ''): string
    {
        $id = \Talea\Core\Uuid::v4();
        $this->site()->exec(
            'INSERT INTO tl_short_links (public_id, path, target_type, target_id, target_url, utm_source, hits, created_at) VALUES (?, ?, ?, ?, ?, ?, 7, ?)',
            [$id, $path, $type, is_int($target) ? $target : null, is_string($target) ? $target : null, $source, date('Y-m-d H:i:s')],
        );

        return $id;
    }

    public function testExportCarriesContentTargetsAsPublicIds(): string
    {
        $site = $this->site();
        $site->setting('extensions', implode(',', array_unique([...\Talea\Core\Extensions::fromValue($site->settingValue('extensions')), 'short_links'])));
        $site->exec('DELETE FROM tl_short_links');
        $this->assertStringContainsString('"id"', $site->mcpText('create_page', ['title' => 'Aboutus', 'slug' => 'aboutus', 'visible' => true]));
        $page = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'aboutus'");
        self::$ids = ['page' => $site->publicId('pages', $page), 'flyer' => $this->link('flyer', 'page', $page, 'flyer'),
            'code' => $this->link('s/k7f2qm', 'url', 'https://example.org/x'), 'gone' => $this->link('gone', 'page', $page + 9000)];

        $site->admin()->post('/admin.php?module=transfer&action=export', ['_csrf' => $site->csrf()]);
        preg_match_all('/export-[0-9]*-[0-9]*\.zip/', $site->admin()->get('/admin.php?module=transfer')->body, $m);
        $file = max($m[0]);
        $zipFile = $site->workDir('links') . '/' . $file;
        file_put_contents($zipFile, $site->admin()->get('/admin.php?module=transfer&action=download&file=' . $file)->body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile) === true);
        $content = json_decode((string) $zip->getFromName('content.json'), true);
        $zip->close();

        $rows = array_column($content['short_links'] ?? [], null, 'path');
        $this->assertSame(self::$ids['page'], $rows['flyer']['target_id'] ?? null, 'a content target is the public id of the page');
        $this->assertSame('flyer', $rows['flyer']['utm_source'] ?? null);
        $this->assertSame(self::$ids['flyer'], $rows['flyer']['public_id'] ?? null);
        $this->assertSame('https://example.org/x', $rows['s/k7f2qm']['target_url'] ?? null);
        $this->assertArrayNotHasKey('short_link_id', $rows['flyer']);
        $this->assertArrayNotHasKey('hits', $rows['flyer'], 'the counter is only this site statistics');

        return $zipFile;
    }

    #[Depends('testExportCarriesContentTargetsAsPublicIds')]
    public function testImportKeepsPublicIdAndPathAndFollowsTheNewPage(string $zipFile): void
    {
        self::$moved = Site::boot(['web' => 'export', 'siteName' => 'Links new', 'extensions' => ['news']]);
        $new = self::$moved;
        $new->setting('tasks_token', 'own-' . bin2hex(random_bytes(8)));
        $admin = $new->admin();
        $upload = $admin->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['file' => $zipFile]);
        $file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);
        $admin->post('/admin.php?module=transfer&action=talea_run', ['_csrf' => $new->csrf(), 'file' => $file, 'confirmation' => 1]);
        $result = null;
        for ($i = 0; $i < 80; $i++) {
            $result = $admin->post('/admin.php?module=transfer&action=talea', ['_csrf' => $new->csrf(), 'file' => $file]);
            if ($result->contains('The site has been imported') || $result->contains('The import has stopped')) {
                break;
            }
        }
        $this->assertStringContainsString('The site has been imported', (string) $result?->body);

        $paths = array_column($new->rows('SELECT path, public_id FROM tl_short_links ORDER BY path'), 'public_id', 'path');
        $this->assertSame(['flyer' => self::$ids['flyer'], 's/k7f2qm' => self::$ids['code']], $paths, 'public ids and paths are kept; the link to missing content is skipped');
        $this->assertSame(
            (string) $new->internalId('pages', self::$ids['page']),
            (string) $new->value("SELECT target_id FROM tl_short_links WHERE path = 'flyer'"),
            'the content target points at the new page row',
        );
        $r = $new->client()->get('/flyer');
        $this->assertSame([302, $new->base . '/aboutus?utm_source=flyer'], [$r->status, $r->redirect], 'the imported short link works');
    }

    #[Depends('testExportCarriesContentTargetsAsPublicIds')]
    public function testAuditFlagsALinkWhoseTargetIsGone(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_short_links WHERE path = 'gone'");
        $this->assertStringNotContainsString('short_link', $site->mcpText('site_audit'));
        $site->exec("UPDATE tl_pages SET deleted_at = NOW() WHERE slug = 'aboutus'");
        $text = str_replace('\\/', '/', $site->mcpText('site_audit'));
        $this->assertStringContainsString('"short_link"', $text);
        $this->assertStringContainsString('module=short_links&edit=' . self::$ids['flyer'], $text);
        $site->exec("UPDATE tl_pages SET deleted_at = NULL WHERE slug = 'aboutus'");
    }
}
