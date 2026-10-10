<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\PagesNews;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The news trash: hidden from the web and the editor, restore as a draft, emptied after 30 days (was: section 22 of tools/test.sh). */
#[Group('site')]
final class NewsTrashTest extends SiteTestCase
{
    public function testNewsTrashLifecycle(): void
    {
        $site = $this->site();
        $idc = (int) $site->value("SELECT news_id FROM ka_news WHERE slug = 'our-new-website-is-live'");
        $public = $site->publicId('news', $idc);
        $this->assertPage('/admin.php?module=news', 200, 'Delete selected', message: 'news list');

        $this->adminPost('/admin.php?module=news&action=delete', ['delete' => [$public]], '/admin.php?module=news');
        $this->assertPage('/news/our-new-website-is-live', 404, message: 'news in the trash is not on the web');
        $this->assertPage('/news/our-new-website-is-live?preview=1', 404, message: 'news in the trash is not in the preview either');
        $this->assertPage('/admin.php?module=news&status=trash', 200, 'Our new website is live', message: 'Trash tab');
        $this->assertPage("/admin.php?module=news&action=edit&id=$public", 404, message: 'news in the trash cannot be edited');

        $this->adminPost('/admin.php?module=news&action=restore', ['delete' => [$public]], '/admin.php?module=news');
        $this->assertSame('0/1', (string) $site->value('SELECT CONCAT(visible, \'/\', deleted_at IS NULL) FROM ka_news WHERE news_id = ?', [$idc]), 'restored news comes back as a draft');

        $site->exec('UPDATE ka_news SET visible = 1, deleted_at = NOW() - INTERVAL 31 DAY WHERE news_id = ?', [$idc]);
        $this->assertPage('/admin.php', 200, 'Dashboard', message: 'entering the administration empties the old trash');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_news WHERE news_id = ?', [$idc]), 'news older than 30 days in the trash is deleted for good');
    }
}
