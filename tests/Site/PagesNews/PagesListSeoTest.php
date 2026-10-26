<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The pages list with the Page check columns (score, focus keyword, links), the score filter and editing titles in the list. */
#[Group('site')]
final class PagesListSeoTest extends SiteTestCase
{
    /** @return array<string, string> slug => public id of three visible pages: no keyword, a low score, a good score */
    private function pages(): array
    {
        $site = $this->site();
        $ids = [];
        foreach (['plain' => '', 'weak' => 'weak words', 'strong' => 'strong words'] as $slug => $keyword) {
            $site->mcp('create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'visible' => true, 'in_menu' => false, 'content' => '<p>' . ucfirst($slug) . ' text. <a href="/plain">Plain</a></p>']);
            $site->exec('UPDATE tl_pages SET focus_keywords = ?, seo_score = ?, key_page = TRUE, noindex = FALSE WHERE slug = ?', [$keyword, ['plain' => null, 'weak' => 42, 'strong' => 91][$slug], $slug]);
            $ids[$slug] = $site->publicId('pages', (int) $site->value('SELECT page_id FROM tl_pages WHERE slug = ?', [$slug]));
        }

        return $ids;
    }

    public function testTheListShowsScoreKeywordAndLinks(): void
    {
        $this->pages();
        $body = $this->assertPage('/admin.php?module=pages', 200, ['Search score', 'Focus keyword', 'Links in · out', 'No keyword', 'website-score-low">42<', 'website-score-good">91<', 'weak words'])->body;
        $this->assertStringContainsString('name="score"', $body, 'the score filter');
        $this->assertMatchesRegularExpression('~/plain <.*?<td class="cell-links">2 · 0</td>~s', $body, 'two pages link to Plain; its link to itself does not count');
        $this->assertMatchesRegularExpression('~/weak <.*?<td class="cell-links">0 · 1<~s', $body);
    }

    public function testTheScoreFilterKeepsOnlyItsBand(): void
    {
        $ids = $this->pages();
        $this->assertPage('/admin.php?module=pages&score=low', 200, 'row-menu-' . $ids['weak']);
        $this->assertPageLacks('/admin.php?module=pages&score=low', ['row-menu-' . $ids['strong'], 'row-menu-' . $ids['plain']], message: 'only scores under 50');
        $this->assertPage('/admin.php?module=pages&score=none', 200, 'row-menu-' . $ids['plain']);
        $this->assertPageLacks('/admin.php?module=pages&score=good', 'row-menu-' . $ids['weak']);
    }

    public function testBulkSaveChangesOnlyTheRowsSent(): void
    {
        $ids = $this->pages();
        $site = $this->site();
        $this->assertPage('/admin.php?module=pages&seo_edit=1', 200, ['name="rows[' . $ids['weak'] . '][seo_title]"', 'name="rows[' . $ids['weak'] . '][key_page]" value="0"', 'form="seo-bulk"']);
        $before = $site->rows("SELECT seo_title, description, visible FROM tl_pages WHERE slug = 'strong'")[0];
        $site->exec("UPDATE tl_pages SET seo_score = NULL, visible = FALSE WHERE slug = 'weak'");

        $response = $this->adminPost('/admin.php?module=pages&action=seo_bulk_save', ['rows' => [
            $ids['plain'] => ['seo_title' => 'Plain title', 'description' => 'Plain description.', 'focus_keywords' => 'plain', 'key_page' => '0', 'noindex' => '0'],
            $ids['weak'] => ['seo_title' => 'Weak title', 'description' => '', 'focus_keywords' => "weak words\nweak", 'key_page' => '1', 'noindex' => '1'],
        ]], '/admin.php?module=pages&seo_edit=1');
        $this->assertSame(302, $response->status);

        $plain = $site->rows("SELECT * FROM tl_pages WHERE slug = 'plain'")[0];
        $this->assertSame(['Plain title', 'Plain description.', 'plain', 0, 0], [$plain['seo_title'], $plain['description'], $plain['focus_keywords'], (int) $plain['key_page'], (int) $plain['noindex']]);
        $weak = $site->rows("SELECT * FROM tl_pages WHERE slug = 'weak'")[0];
        $this->assertSame(['Weak title', "weak words\nweak", 1, 1, 0], [$weak['seo_title'], $weak['focus_keywords'], (int) $weak['key_page'], (int) $weak['noindex'], (int) $weak['visible']], 'a hidden page stays hidden');
        $this->assertNotNull($weak['seo_score'], 'the score is computed again');
        $this->assertSame($before, $site->rows("SELECT seo_title, description, visible FROM tl_pages WHERE slug = 'strong'")[0], 'a row not sent stays as it was');
    }

    public function testListPagesCarriesScoreAndKeyword(): void
    {
        $this->pages();
        $weak = array_values(array_filter($this->site()->mcpResult('list_pages'), fn (array $p): bool => $p['title'] === 'Weak'))[0];
        $this->assertSame(42, $weak['seo_score']);
        $this->assertSame('weak words', $weak['focus_keyword']);
    }

    public function testWithoutPageCheckTheColumnsAndTheFilterAreGone(): void
    {
        $this->pages();
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '0'], '/admin.php?module=seo');
        $this->assertPageLacks('/admin.php?module=pages', ['Search score', 'Links in · out', 'name="score"']);
    }
}
