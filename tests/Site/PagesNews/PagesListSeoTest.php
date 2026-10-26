<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
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
            $site->exec("UPDATE tl_pages SET title = ?, seo_title = '', description = '', visible = TRUE, focus_keywords = ?, seo_score = ?, key_page = TRUE, noindex = FALSE WHERE slug = ?", [ucfirst($slug), $keyword, ['plain' => null, 'weak' => 42, 'strong' => 91][$slug], $slug]);
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

    /** A signed-in user with the Pages section: level 1 = editor, 0 = author (may not publish). */
    private function user(string $name, int $level): Http
    {
        $site = $this->site();
        if ((int) $site->value('SELECT COUNT(*) FROM tl_users WHERE username = ?', [$name]) === 0) {
            $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$name, password_hash($site->password, PASSWORD_DEFAULT), $name, $level]);
            $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = ?", [$name]);
        }
        $client = $site->client($name);
        $site->signIn($client, $name);

        return $client;
    }

    public function testBulkSaveRefusesAFormThatReachesMaxInputVars(): void
    {
        $ids = $this->pages();
        $limit = (int) ini_get('max_input_vars'); // the CI image runs the test and the site with one php.ini
        $row = ['seo_title' => 'Lost title', 'description' => '', 'focus_keywords' => 'weak words', 'key_page' => '1', 'noindex' => '0'];
        // _csrf + the five fields of the row + score + padding = exactly the limit: PHP still delivers all of them
        $response = $this->adminPost('/admin.php?module=pages&action=seo_bulk_save', ['score' => 'low', 'rows' => [$ids['weak'] => $row],
            'pad' => array_fill(0, $limit - 7, 'x')], '/admin.php?module=pages&seo_edit=1');
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('seo_edit=1', $response->redirect, 'back to the edit mode');
        $this->assertStringContainsString('score=low', $response->redirect, 'with its filter');
        $this->assertSame('', (string) $this->site()->value("SELECT seo_title FROM tl_pages WHERE slug = 'weak'"), 'nothing is saved half');
        $this->assertStringContainsString('nothing was saved', $this->site()->admin()->get(str_replace($this->site()->base, '', $response->redirect))->body);

        $response = $this->adminPost('/admin.php?module=pages&action=seo_bulk_save', ['rows' => [$ids['weak'] => $row], 'pad' => array_fill(0, $limit - 8, 'x')], '/admin.php?module=pages&seo_edit=1');
        $this->assertSame('Lost title', (string) $this->site()->value("SELECT seo_title FROM tl_pages WHERE slug = 'weak'"), 'one field under the limit saves');
    }

    public function testTheEditModeShowsFiftyRowsAPage(): void
    {
        $site = $this->site();
        for ($i = 1; $i <= 55; $i++) {
            $site->exec('INSERT INTO tl_pages (public_id, slug, title, text, visible, updated_at) VALUES (?, ?, ?, ?, FALSE, NOW())', [\Talea\Core\Uuid::v4(), sprintf('bulk-%02d', $i), sprintf('Bulk %02d', $i), '']);
        }
        $total = (int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE deleted_at IS NULL');
        $first = $this->assertPage('/admin.php?module=pages&seo_edit=1&status=hidden', 200, ['class="pagination"', 'seo_edit=1&amp;page=2'])->body;
        $this->assertSame(50, substr_count($first, 'class="seo-edit-row"'), 'one page of edit rows');
        $this->assertStringContainsString('name="status" value="hidden"', $first, 'the save comes back to the same filter');
        $second = $this->assertPage('/admin.php?module=pages&seo_edit=1&status=hidden&page=2', 200, 'name="page" value="2"')->body;
        $this->assertSame((int) $site->value('SELECT COUNT(*) FROM tl_pages WHERE deleted_at IS NULL AND visible = FALSE') - 50, substr_count($second, 'class="seo-edit-row"'));
        $this->assertPageLacks('/admin.php?module=pages', 'class="pagination"', message: "the list without edit mode shows all $total pages");
    }

    public function testBulkSaveNamesTheRowsItCouldNotSave(): void
    {
        $ids = $this->pages();
        $site = $this->site();
        $site->exec("UPDATE tl_pages SET title = '' WHERE slug = 'plain'");
        $response = $this->adminPost('/admin.php?module=pages&action=seo_bulk_save', ['score' => 'none', 'rows' => [
            $ids['plain'] => ['seo_title' => 'Plain title', 'description' => '', 'focus_keywords' => '', 'key_page' => '1', 'noindex' => '0'],
            $ids['strong'] => ['seo_title' => 'Strong title', 'description' => '', 'focus_keywords' => 'strong words', 'key_page' => '1', 'noindex' => '0'],
        ]], '/admin.php?module=pages&seo_edit=1');
        $this->assertStringContainsString('seo_edit=1', $response->redirect);
        $this->assertStringContainsString('score=none', $response->redirect);
        $body = $site->admin()->get(str_replace($site->base, '', $response->redirect))->body;
        $this->assertStringContainsString('notice-error', $body);
        $this->assertStringContainsString('Not saved – /plain: Enter the page title.', $body, 'the row and what is wrong');
        $this->assertStringContainsString('Pages saved: 1.', $body);
        $this->assertSame('Strong title', (string) $site->value("SELECT seo_title FROM tl_pages WHERE slug = 'strong'"));
    }

    public function testAnAuthorCannotChangeAPublishedPageInTheList(): void
    {
        $ids = $this->pages();
        $site = $this->site();
        $site->exec("UPDATE tl_pages SET visible = FALSE WHERE slug = 'plain'");
        $author = $this->user('list-author', 0);
        $response = $author->post('/admin.php?module=pages&action=seo_bulk_save', ['_csrf' => $site->csrf($author), 'rows' => [
            $ids['weak'] => ['seo_title' => 'By the author', 'description' => '', 'focus_keywords' => 'weak words', 'key_page' => '1', 'noindex' => '1'],
            $ids['plain'] => ['seo_title' => 'Hidden by the author', 'description' => '', 'focus_keywords' => '', 'key_page' => '1', 'noindex' => '0'],
        ]]);
        $this->assertSame(302, $response->status);
        $this->assertSame(['', 0], [(string) $site->value("SELECT seo_title FROM tl_pages WHERE slug = 'weak'"), (int) $site->value("SELECT noindex FROM tl_pages WHERE slug = 'weak'")], 'a published page stays as it was');
        $this->assertSame('Hidden by the author', (string) $site->value("SELECT seo_title FROM tl_pages WHERE slug = 'plain'"), 'a hidden page takes the change');
        $this->assertStringContainsString('Not saved – Weak: Only an editor or administrator', $author->get(str_replace($site->base, '', $response->redirect))->body);
    }

    public function testCraftedRowsChangeNothing(): void
    {
        $ids = $this->pages();
        $site = $this->site();
        $before = $site->rows('SELECT slug, seo_title, description, focus_keywords, key_page, noindex, visible FROM tl_pages ORDER BY slug');
        foreach (['rows=x', 'rows[not-a-uuid][seo_title]=a', 'rows[12][seo_title]=a', 'rows[' . $ids['weak'] . ']=x', 'rows[' . $ids['weak'] . '][seo_title][]=a&rows[' . $ids['weak'] . '][focus_keywords][x][]=b',
            'rows[' . $ids['weak'] . '][key_page]=1&rows[' . $ids['weak'] . '][unknown]=1'] as $body) {
            $response = $site->admin()->post('/admin.php?module=pages&action=seo_bulk_save', '_csrf=' . urlencode($site->csrf()) . '&' . $body, ['Content-Type: application/x-www-form-urlencoded']);
            $this->assertSame(302, $response->status, $body);
        }
        $this->assertSame($before, $site->rows('SELECT slug, seo_title, description, focus_keywords, key_page, noindex, visible FROM tl_pages ORDER BY slug'), 'no row changed');
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
