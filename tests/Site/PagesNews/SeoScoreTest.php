<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The stored SEO score: kept current on save, NULL without a focus keyword, kw_unique across pages. */
#[Group('site')]
final class SeoScoreTest extends SiteTestCase
{
    /** @param array<string, mixed> $fields */
    private function savePage(array $fields): void
    {
        $this->adminPost('/admin.php?module=pages&action=save', $fields + ['visible' => '1', 'text' => '<p>Test page about testing.</p>'], '/admin.php?module=pages');
    }

    private function row(string $slug): array
    {
        return $this->site()->rows('SELECT page_id, seo_score, seo_checked_at FROM tl_pages WHERE slug = ?', [$slug])[0];
    }

    public function testScoreFollowsTheFocusKeywordAndFlagsADuplicate(): void
    {
        $site = $this->site();
        $this->savePage(['title' => 'Test page', 'slug' => 'test-page', 'focus_keywords' => 'Test page']);
        $first = $this->row('test-page');
        $this->assertNotNull($first['seo_score'], 'a focus keyword gives a score');
        $this->assertNotNull($first['seo_checked_at']);

        $this->savePage(['title' => 'Test page', 'slug' => 'test-page', 'page_id' => $site->publicId('pages', (int) $first['page_id']), 'focus_keywords' => '']);
        $cleared = $this->row('test-page');
        $this->assertNull($cleared['seo_score'], 'no keyword, no score');
        $this->assertNotNull($cleared['seo_checked_at'], 'the check time is still written');

        $this->savePage(['title' => 'Test page', 'slug' => 'test-page', 'page_id' => $site->publicId('pages', (int) $first['page_id']), 'focus_keywords' => 'Test page']);
        $this->savePage(['title' => 'Other page', 'slug' => 'other-page', 'focus_keywords' => "test PAGE\nsecond"]);
        $second = $this->row('other-page');
        $analysis = $site->mcpResult('get_page', ['id' => $site->publicId('pages', (int) $second['page_id'])])['seo_analysis'];
        $checks = array_column(array_merge(...array_values($analysis['groups'])), 'ok', 'check');
        $this->assertFalse($checks['kw_unique'], 'the first keyword is already used by another page');
        $this->assertNotNull($analysis['score']);
    }

    public function testNewsScoreAndItsAnalysisOnRead(): void
    {
        $site = $this->site();
        $created = $site->mcpResult('create_news', ['title' => 'Winter opening hours', 'content' => '<p>Winter opening hours are shorter.</p>', 'category' => 'News', 'focus_keywords' => 'winter opening hours']);
        $id = (int) $site->value('SELECT news_id FROM tl_news WHERE title = ?', ['Winter opening hours']);
        $this->assertGreaterThan(0, $id, json_encode($created));
        $this->assertNotNull($site->value('SELECT seo_score FROM tl_news WHERE news_id = ?', [$id]));
        $analysis = $site->mcpResult('get_news', ['id' => $site->publicId('news', $id)])['seo_analysis'];
        $this->assertIsInt($analysis['score']);
    }

    public function testEditInPlaceRefreshesTheScore(): void
    {
        $site = $this->site();
        $this->savePage(['title' => 'Inline page', 'slug' => 'inline-page', 'focus_keywords' => 'inline page']);
        $id = (int) $this->row('inline-page')['page_id'];
        $site->exec("UPDATE tl_pages SET seo_checked_at = '2000-01-01 00:00:00', seo_score = 1 WHERE page_id = ?", [$id]);
        $this->adminPost('/admin.php?module=pages&action=save_text&id=' . $site->publicId('pages', $id), ['title' => 'Inline page', 'text' => '<p>Inline page, edited in place.</p>', 'back' => '/inline-page'], '/admin.php?module=pages');
        $row = $this->row('inline-page');
        $this->assertGreaterThan('2000-01-01 00:00:00', $row['seo_checked_at']);
        $this->assertNotSame(1, (int) $row['seo_score']);
    }

    public function testKeywordTakenIgnoresWildcardsAndLaterLines(): void
    {
        $site = $this->site();
        $this->savePage(['title' => 'Sale a', 'slug' => 'sale-a', 'focus_keywords' => '50x off']);
        $this->savePage(['title' => 'Sale b', 'slug' => 'sale-b', 'focus_keywords' => "something\n50% off"]);
        $this->savePage(['title' => 'Sale c', 'slug' => 'sale-c', 'focus_keywords' => '50% off']);
        $kw = fn (string $slug): bool => array_column(array_merge(...array_values($site->mcpResult('get_page', ['id' => $site->publicId('pages', (int) $this->row($slug)['page_id'])])['seo_analysis']['groups'])), 'ok', 'check')['kw_unique'];
        $this->assertTrue($kw('sale-c'), '% is not a wildcard, and a second line does not count');
        $this->assertTrue($kw('sale-a'), '"50x off" is not taken by "50% off"');
        $this->savePage(['title' => 'Sale d', 'slug' => 'sale-d', 'focus_keywords' => '50% OFF']);
        $this->assertFalse($kw('sale-c'), 'a real first-line match counts');
        $site->exec("UPDATE tl_pages SET focus_keywords = ? WHERE slug = 'sale-d'", ["50% off\r\nx"]);
        $this->assertFalse($kw('sale-c'), 'CRLF stored value still matches');
    }
}
