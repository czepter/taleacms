<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The news trash: hidden from the web and the editor, restore as a draft, emptied after 30 days (was: section 22 of tools/test.sh). */
#[Group('site')]
final class NewsTrashTest extends SiteTestCase
{
    public function testNewsTrashLifecycle(): void
    {
        $site = $this->site();
        $idc = (int) $site->value("SELECT news_id FROM tl_news WHERE slug = 'our-new-website-is-live'");
        $public = $site->publicId('news', $idc);
        $this->assertPage('/admin.php?module=news', 200, 'name="bulk" value="trash"', message: 'news list: the selection bar moves news to the trash');

        $this->adminPost('/admin.php?module=news&action=delete', ['delete' => [$public]], '/admin.php?module=news');
        $this->assertPage('/news/our-new-website-is-live', 404, message: 'news in the trash is not on the web');
        $this->assertPage('/news/our-new-website-is-live?preview=1', 404, message: 'news in the trash is not in the preview either');
        $this->assertPage('/admin.php?module=news&status=trash', 200, 'Our new website is live', message: 'Trash tab');
        $this->assertPage("/admin.php?module=news&action=edit&id=$public", 404, message: 'news in the trash cannot be edited');

        $this->adminPost('/admin.php?module=news&action=restore', ['delete' => [$public]], '/admin.php?module=news');
        $this->assertSame('0/1', (string) $site->value('SELECT CONCAT(visible, \'/\', deleted_at IS NULL) FROM tl_news WHERE news_id = ?', [$idc]), 'restored news comes back as a draft');

        $site->exec('UPDATE tl_news SET visible = 1, deleted_at = NOW() - INTERVAL 31 DAY WHERE news_id = ?', [$idc]);
        $this->assertPage('/admin.php', 200, 'Welcome,', message: 'entering the administration');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_news WHERE news_id = ?', [$idc]), 'an admin page view does not empty the trash (#337)');

        $site->mcp('create_collection', ['name' => 'Trash test list', 'preset' => 'faq']);
        $site->exec("INSERT INTO tl_collection_items (public_id, collection_id, name, slug, data, created_at, deleted_at) SELECT '6f1c2d3e-4b5a-4c6d-8e7f-0011223344bb', collection_id, 'Old', 'old-trashed-item', '{}', NOW(), NOW() - INTERVAL 31 DAY FROM tl_collections ORDER BY collection_id LIMIT 1");
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_collection_items WHERE slug = 'old-trashed-item'"), 'the site has a collection to put an old trashed item into');
        $site->exec("DELETE FROM tl_jobs WHERE name = 'trash'");
        $site->runTasks();
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_news WHERE news_id = ?', [$idc]), 'the daily trash job deletes news older than 30 days in the trash for good');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_collection_items WHERE slug = 'old-trashed-item'"), 'and collection items');
    }
}
