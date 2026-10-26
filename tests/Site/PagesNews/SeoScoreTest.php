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
}
