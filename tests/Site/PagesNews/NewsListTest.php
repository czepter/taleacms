<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The news overview and its simple neighbours (categories, tags) in the shared list design (docs/specs/admin-lists.md). */
#[Group('site')]
final class NewsListTest extends SiteTestCase
{
    public function testNewsListShowsTabsStatusAndSelection(): void
    {
        $site = $this->site();
        $first = (int) $site->value('SELECT MIN(news_id) FROM tl_news WHERE deleted_at IS NULL');
        $this->adminPost('/admin.php?module=news&action=duplicate', ['news_id' => $site->publicId('news', $first)], '/admin.php?module=news');
        $ids = array_map('intval', array_column($site->rows('SELECT news_id FROM tl_news WHERE deleted_at IS NULL ORDER BY news_id'), 'news_id'));
        $this->assertGreaterThanOrEqual(2, count($ids), 'the starter news item and its copy');
        $site->exec('UPDATE tl_news SET visible = TRUE, published_at = NOW() + INTERVAL 5 DAY WHERE news_id = ?', [$ids[0]]);
        $site->exec('UPDATE tl_news SET deleted_at = NOW(), visible = FALSE WHERE news_id = ?', [$ids[1]]);
        $scheduled = $site->publicId('news', $ids[0]);
        $trashed = $site->publicId('news', $ids[1]);

        $this->assertPage('/admin.php?module=news', 200, [
            '<nav class="list-tabs"', 'Scheduled <span class="list-tab-count">', 'Trash <span class="list-tab-count">1</span>',
            'class="status status-planned"', 'popovertarget="row-menu-' . $scheduled . '"',
            'name="selected[]" value="' . $scheduled . '" form="bulk"', 'class="selection-bar"', 'name="bulk" value="trash"', 'name="bulk" value="category"',
        ], message: 'news list in the shared list design');
        $this->assertPage('/admin.php?module=news&status=trash', 200, [
            'name="selected[]" value="' . $trashed . '" form="bulk"', 'formaction="' , '/admin/news/delete_permanently', 'aria-label="Restore: ',
        ], message: 'the trash tab restores and deletes the selection');

        $this->adminPost('/admin.php?module=news&action=restore', ['selected' => [$trashed]], '/admin.php?module=news&status=trash');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_news WHERE news_id = ? AND deleted_at IS NULL', [$ids[1]]), 'restore takes the selection bar\'s selected[]');
    }

    public function testCategoriesAndTagsUseRowMenus(): void
    {
        $site = $this->site();
        $category = (string) $site->value('SELECT public_id FROM tl_categories ORDER BY category_id LIMIT 1');
        $this->assertPage('/admin.php?module=categories', 200, ['class="page-count"', 'popovertarget="row-menu-' . $category . '"', 'class="cell-sub"'], message: 'categories list');
        $tag = '7c1e9a52-3b4d-4f6e-9a8b-0c1d2e3f4a5b';
        $site->exec("INSERT INTO tl_tags (public_id, name, slug) VALUES (?, 'Own page', 'own-page')", [$tag]);
        $list = $this->assertPage('/admin.php?module=tags', 200, ['class="page-count"', '/admin/tags?edit=' . $tag . '"'], message: 'tags list');
        $this->assertStringNotContainsString('id="name"', $list->body, 'the list carries no form');
        $this->assertPage('/admin.php?module=tags&edit=' . $tag, 200, ['class="navigation-row"', 'value="Own page"', 'form="tag-delete"', 'data-confirm=', '<form id="tag-delete"'], message: 'a tag is edited on its own page');
        $this->assertPage('/admin.php?module=tags&edit=00000000-0000-4000-8000-000000000000', 404, message: 'an unknown tag');
    }
}
