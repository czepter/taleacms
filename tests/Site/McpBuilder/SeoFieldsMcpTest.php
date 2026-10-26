<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\LinksConnectors\FakeServices;
use Talea\Tests\Site\Support\Site;
use Talea\Tests\Site\Support\SiteTestCase;

/** The "Search engines" fields are saved from MCP and the admin, returned by the read tools and survive an export and import. */
#[Group('site')]
final class SeoFieldsMcpTest extends SiteTestCase
{
    use FakeServices;

    private const array SEO = ['focus_keywords' => 'a, b', 'key_page' => true, 'robots' => 'nofollow,bogus,max-snippet:50', 'canonical_url' => 'https://example.org/',
        'breadcrumb_title' => 'Crumb', 'share_title' => 'Share title', 'share_description' => 'Share description'];

    private function category(): string
    {
        return (string) $this->site()->value("SELECT name FROM tl_categories WHERE language = '' ORDER BY category_id LIMIT 1");
    }

    public function testPageFieldsAreSavedReturnedAndCleaned(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Seo page', 'slug' => 'seo-page', 'content' => '<p>x</p>', 'canonical_url' => 'ftp://nope']);
        $this->assertSame('', $site->value("SELECT canonical_url FROM tl_pages WHERE slug = 'seo-page'"), 'a canonical that is not http(s) is refused');
        $id = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'seo-page'");
        $site->mcp('update_page', ['id' => $id] + self::SEO);

        $json = $this->mcpJson('get_page', ['id' => $id]);
        $this->assertStringContainsString('"focus_keywords":"a\nb"', $json);
        $this->assertStringContainsString('"robots":"nofollow,max-snippet:50"', $json);
        $this->assertStringContainsString('"canonical_url":"https://example.org/"', $json);
        $this->assertStringContainsString('"key_page":true', $json);
        $this->assertStringContainsString('"share_title":"Share title"', $json);

        $site->mcp('update_page', ['id' => $id, 'robots' => '']);
        $this->assertSame('', $site->value("SELECT robots FROM tl_pages WHERE slug = 'seo-page'"));
        $this->assertSame("a\nb", $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'seo-page'"), 'fields that were not sent stay');
    }

    public function testAdminFormsSaveThem(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Form page', 'slug' => 'form-page', 'content' => '<p>x</p>']);
        $id = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'form-page'");
        $save = fn (array $fields) => $this->adminPost('/admin.php?module=pages&action=settings_save', ['id' => $id] + $fields);
        $save(['tab' => 'seo', 'seo_title' => 'T', 'focus_keywords' => "x\ny", 'key_page' => '1']);
        $save(['tab' => 'advanced', 'robots' => ['', 'noarchive', 'nofollow']]);
        $save(['tab' => 'sharing', 'share_title' => 'S']);
        $this->assertSame("x\ny|nofollow,noarchive|1|S", $site->value("SELECT CONCAT(focus_keywords, '|', robots, '|', key_page, '|', share_title) FROM tl_pages WHERE slug = 'form-page'"));
    }

    public function testNewsAndCollectionItems(): void
    {
        $site = $this->site();
        $site->mcp('create_news', ['title' => 'Seo news', 'category' => $this->category()] + self::SEO);
        $newsId = (string) $site->value("SELECT public_id FROM tl_news WHERE title = 'Seo news'");
        $this->assertStringContainsString('"focus_keywords":"a\nb"', $this->mcpJson('get_news', ['id' => $newsId]));
        $site->mcp('create_collection', ['name' => 'Seo coll', 'slug' => 'seo-coll', 'fields' => [['label' => 'Role', 'type' => 'text']]]);
        $site->mcp('save_collection_item', ['collection' => 'seo-coll', 'name' => 'Item', 'values' => ['role' => 'x']] + self::SEO);
        $this->assertSame("a\nb|nofollow,max-snippet:50|1", $site->value("SELECT CONCAT(focus_keywords, '|', robots, '|', key_page) FROM tl_collection_items WHERE slug = 'item'"));
        $this->assertStringContainsString('"robots":"nofollow,max-snippet:50"', $this->mcpJson('list_collection_items', ['collection' => 'seo-coll']));
    }

    public function testADraftsOnlyConnectionMaySetThem(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Draft seo', 'slug' => 'draft-seo', 'content' => '<p>x</p>']);
        $id = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'draft-seo'");
        $site->exec("UPDATE tl_api_tokens SET access = 'drafts'");
        try {
            $site->mcp('update_page', ['id' => $id, 'focus_keywords' => 'draft word', 'canonical_url' => 'https://example.org/d']);
        } finally {
            $site->exec("UPDATE tl_api_tokens SET access = 'full'");
        }
        $this->assertSame('draft word|https://example.org/d', $site->value("SELECT CONCAT(focus_keywords, '|', canonical_url) FROM tl_pages WHERE slug = 'draft-seo'"));
    }

    public function testAnEditWithoutThePublishRightClearsTheSchedule(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Planned', 'slug' => 'planned', 'content' => '<p>x</p>']);
        $id = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'planned'");
        $site->exec("UPDATE tl_pages SET publish_at = '2030-05-01 09:00:00' WHERE public_id = ?", [$id]);
        $site->mcp('update_page', ['id' => $id, 'description' => 'By a publisher.']);
        $this->assertSame('2030-05-01 09:00:00', (string) $site->value('SELECT publish_at FROM tl_pages WHERE public_id = ?', [$id]), 'a publisher keeps the schedule');
        $site->exec("UPDATE tl_api_tokens SET access = 'drafts'");
        try {
            $site->mcp('update_page', ['id' => $id, 'description' => 'Unreviewed.']);
        } finally {
            $site->exec("UPDATE tl_api_tokens SET access = 'full'");
        }
        $this->assertSame('Unreviewed.', $site->value('SELECT description FROM tl_pages WHERE public_id = ?', [$id]));
        $this->assertNull($site->value('SELECT publish_at FROM tl_pages WHERE public_id = ?', [$id]), 'unreviewed changes never go live on their own');
    }

    public function testExportAndImportKeepAllSevenColumns(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Move page', 'slug' => 'move-page', 'content' => '<p>x</p>'] + self::SEO);
        $site->mcp('create_news', ['title' => 'Move news', 'category' => $this->category()] + self::SEO);
        $site->mcp('create_collection', ['name' => 'Move coll', 'slug' => 'move-coll', 'fields' => [['label' => 'Role', 'type' => 'text']]]);
        $site->mcp('save_collection_item', ['collection' => 'move-coll', 'name' => 'Move item', 'values' => ['role' => 'x']] + self::SEO);

        $admin = $site->admin();
        $admin->post('/admin.php?module=transfer&action=export', ['_csrf' => $site->csrf()]);
        preg_match_all('/export-[0-9]*-[0-9]*\.zip/', $admin->get('/admin.php?module=transfer')->body, $m);
        $zipFile = $site->workDir('seo') . '/' . max($m[0]);
        file_put_contents($zipFile, $admin->get('/admin.php?module=transfer&action=download&file=' . max($m[0]))->body);

        $new = Site::boot(['web' => 'export', 'siteName' => 'Seo new', 'extensions' => ['news']]);
        try {
            $new->setting('tasks_token', 'own-' . bin2hex(random_bytes(8)));
            $upload = $new->admin()->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['file' => $zipFile]);
            $file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);
            $new->admin()->post('/admin.php?module=transfer&action=talea_run', ['_csrf' => $new->csrf(), 'file' => $file, 'confirmation' => 1]);
            $result = null;
            for ($i = 0; $i < 80; $i++) {
                $result = $new->admin()->post('/admin.php?module=transfer&action=talea', ['_csrf' => $new->csrf(), 'file' => $file]);
                if ($result->contains('The site has been imported') || $result->contains('The import has stopped')) {
                    break;
                }
            }
            $this->assertStringContainsString('The site has been imported', (string) $result?->body);
            $expected = "a\nb|1|nofollow,max-snippet:50|https://example.org/|Crumb|Share title|Share description";
            $sql = "SELECT CONCAT(focus_keywords, '|', key_page, '|', robots, '|', canonical_url, '|', breadcrumb_title, '|', share_title, '|', share_description) FROM ";
            $this->assertSame($expected, $new->value($sql . "tl_pages WHERE slug = 'move-page'"), 'page');
            $this->assertSame($expected, $new->value($sql . "tl_news WHERE title = 'Move news'"), 'news');
            $this->assertSame($expected, $new->value($sql . "tl_collection_items WHERE slug = 'move-item'"), 'collection item');
        } finally {
            $new->close();
        }
    }
}
