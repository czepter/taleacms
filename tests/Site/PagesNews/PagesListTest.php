<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The pages overview in the shared list design (docs/specs/admin-lists.md): status tabs, row menu, selection bar. */
#[Group('site')]
final class PagesListTest extends SiteTestCase
{
    public function testListShowsStatusTabsAndRowMenu(): void
    {
        $site = $this->site();
        $home = (int) $site->settingValue('home_page');
        [$hidden, $draft] = array_map('intval', array_column($site->rows('SELECT page_id FROM tl_pages WHERE deleted_at IS NULL AND page_id <> ? ORDER BY page_id', [$home]), 'page_id'));
        $site->exec('UPDATE tl_pages SET visible = FALSE, build_draft = NULL WHERE page_id = ?', [$hidden]);
        $site->exec('UPDATE tl_pages SET visible = TRUE, build_draft = ? WHERE page_id = ?', ['{"v":1,"children":[]}', $draft]);
        $hiddenCount = (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE deleted_at IS NULL AND visible = FALSE');
        $total = (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE deleted_at IS NULL');

        $this->assertPage('/admin.php?module=pages', 200, [
            '<span class="page-count">' . $total . ' pages</span>',
            '<nav class="list-tabs"',
            'Hidden <span class="list-tab-count">' . $hiddenCount . '</span>',
            'popovertarget="row-menu-' . $site->publicId('pages', $hidden) . '"',
            'class="status status-hidden"',
            'Changes not published',
            'The home page cannot be deleted.',
            'class="selection-bar"',
            'name="bulk" value="trash"',
            'name="selected[]" value="' . $site->publicId('pages', $hidden) . '" form="bulk"',
        ], message: 'pages list in the shared list design');

        $draftTitle = (string) $site->value('SELECT title FROM tl_pages WHERE page_id = ?', [$draft]);
        $hiddenTitle = (string) $site->value('SELECT title FROM tl_pages WHERE page_id = ?', [$hidden]);
        $this->assertPage('/admin.php?module=pages&status=changes', 200, $draftTitle, message: 'the tab of unpublished changes lists the draft page');
        $this->assertPageLacks('/admin.php?module=pages&status=changes', 'row-menu-' . $site->publicId('pages', $hidden), message: '… and not the hidden page without a draft (' . $hiddenTitle . ')');
        $this->assertPageLacks('/admin.php?module=pages&status=hidden', 'row-menu-' . $site->publicId('pages', $draft), message: 'the hidden tab lists only hidden pages');
    }
}
