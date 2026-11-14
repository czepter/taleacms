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
            '<span class="page-count">' . $total . '</span>',
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

    public function testImportedPagesWaitInTheLayoutTab(): void
    {
        $site = $this->site();
        $home = (int) $site->settingValue('home_page');
        [$first, $second] = array_map('intval', array_column($site->rows('SELECT page_id FROM tl_pages WHERE deleted_at IS NULL AND page_id <> ? ORDER BY page_id', [$home]), 'page_id'));
        $site->exec("UPDATE tl_pages SET layout_pending = TRUE, import_source = 'https://old.example/a/', updated_at = '2026-02-03 04:05:06' WHERE page_id IN (?, ?)", [$first, $second]);

        $this->assertPage('/admin.php?module=pages', 200, ['Layout not rebuilt <span class="list-tab-count">2</span>', 'Layout not rebuilt yet', 'name="bulk" value="layout_done"'], message: 'the tab counts the imported pages');
        $this->assertPage('/admin.php?module=pages&status=layout', 200, 'row-menu-' . $site->publicId('pages', $first), message: 'the tab lists them');

        $this->adminPost('/admin.php?module=pages&action=layout_done', ['page_id' => $site->publicId('pages', $first)], '/admin.php?module=pages');
        $this->assertSame(0, (int) $site->value('SELECT layout_pending FROM tl_pages WHERE page_id = ?', [$first]));
        $this->assertSame('2026-02-03 04:05:06', (string) $site->value('SELECT updated_at FROM tl_pages WHERE page_id = ?', [$first]), 'ticking off does not touch the page');

        $this->adminPost('/admin.php?module=pages&action=bulk', ['bulk' => 'layout_done', 'selected' => [$site->publicId('pages', $second)]], '/admin.php?module=pages');
        $this->assertSame(0, (int) $site->value('SELECT layout_pending FROM tl_pages WHERE page_id = ?', [$second]));
        $this->assertSame('2026-02-03 04:05:06', (string) $site->value('SELECT updated_at FROM tl_pages WHERE page_id = ?', [$second]));
        $this->assertPageLacks('/admin.php?module=pages', 'Layout not rebuilt <span', message: 'the tab goes when the queue is empty');
    }

    public function testLanguageFilterAppliesOnChange(): void
    {
        $site = $this->site();
        $site->setting('extensions', $site->settingValue('extensions') . ',languages');
        $site->setting('additional_languages', 'de');
        $this->assertMatchesRegularExpression('~<select name="language"[^>]*data-submit-on-change~', $this->assertPage('/admin.php?module=pages', 200, message: 'pages list with languages')->body, 'picking a language applies the filter');
    }
}
