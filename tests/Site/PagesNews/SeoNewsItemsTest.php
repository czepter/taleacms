<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The "Search engines" fields and the score panel in the news form and the collection item form. */
#[Group('site')]
final class SeoNewsItemsTest extends SiteTestCase
{
    public function testNewsFormHasTheFieldsAndTheScorePanel(): void
    {
        $site = $this->site();
        $site->mcpResult('create_news', ['title' => 'Winter opening hours', 'content' => '<p>Winter opening hours are shorter.</p>', 'category' => 'News', 'focus_keywords' => 'winter opening hours']);
        $id = (int) $site->value('SELECT news_id FROM tl_news WHERE title = ?', ['Winter opening hours']);
        $public = $site->publicId('news', $id);
        $this->assertPage('/admin.php?module=news&action=edit&id=' . $public, 200,
            ['name="focus_keywords"', 'name="key_page"', 'name="canonical_url"', 'name="breadcrumb_title"', 'name="share_title"', 'name="robots[]"', 'data-seo-checks', 'data-check="kw_title"']);
        $this->assertPage('/admin.php?module=news&action=new', 200, ['name="focus_keywords"']);

        $site->exec('UPDATE tl_news SET seo_score = NULL WHERE news_id = ?', [$id]);
        $this->adminPost('/admin.php?module=news&action=save', [
            'news_id' => $public, 'title' => 'Winter opening hours', 'text' => '<p>Winter opening hours are shorter.</p>', 'category_id' => $site->publicId('categories', (int) $site->value('SELECT category_id FROM tl_news WHERE news_id = ?', [$id])),
            'author_id' => $site->publicId('users', (int) $site->value('SELECT author_id FROM tl_news WHERE news_id = ?', [$id])), 'status' => 'published', 'focus_keywords' => "winter opening hours\nhours", 'key_page' => '1', 'robots' => ['', 'nofollow'], 'canonical_url' => 'https://example.com/winter',
            'breadcrumb_title' => 'Winter', 'share_title' => 'Shared', 'share_description' => 'Shared text',
        ], '/admin.php?module=news');
        $row = $site->rows('SELECT * FROM tl_news WHERE news_id = ?', [$id])[0];
        $this->assertSame("winter opening hours\nhours", $row['focus_keywords']);
        $this->assertSame('nofollow', $row['robots']);
        $this->assertSame('https://example.com/winter', $row['canonical_url']);
        $this->assertSame('Shared', $row['share_title']);
        $this->assertNotNull($row['seo_score']);
    }

    public function testCollectionItemSavesCanonicalAndShowsThePanel(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Team', 'detail' => 1, 'fields' => [['label' => 'Bio', 'type' => 'html']]], '/admin.php?module=collections');
        $idk = (int) $site->value("SELECT collection_id FROM tl_collections WHERE slug = 'team'");
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => $site->publicId('collections', $idk), 'item_id' => 0, 'name' => 'Jane Novak', 'visible' => 1,
            'data' => ['bio' => '<p>Jane leads the team.</p>'], 'focus_keywords' => 'Jane', 'canonical_url' => 'https://example.com/jane', 'key_page' => '1'], '/admin.php?module=collections');
        $row = $site->rows("SELECT * FROM tl_collection_items WHERE name = 'Jane Novak'")[0];
        $this->assertSame('https://example.com/jane', $row['canonical_url']);
        $this->assertSame(1, (int) $row['key_page']);
        $this->assertNotNull($row['seo_score']);
        $this->assertPage('/admin.php?module=collections&action=item&id=' . $site->publicId('collections', $idk) . '&item=' . $row['public_id'], 200,
            ['name="focus_keywords"', 'name="canonical_url"', 'data-seo-checks', 'value="https://example.com/jane"']);
    }
}
