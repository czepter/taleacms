<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The "Search engines" section: its place in the menu, the overview with its tiles and the feature switches. */
#[Group('site')]
final class SeoSectionTest extends SiteTestCase
{
    use AuthorSession;

    public function testTheMenuHasASectionWithOverviewRedirectsAndAudit(): void
    {
        $body = $this->assertPage('/admin.php?module=seo', 200, ['Search engines', 'Overview', 'Redirects', 'Site audit'], message: 'the section opens on its overview')->body;
        $this->assertStringContainsString('/admin/audit', $body);
        $this->assertStringContainsString('/admin/redirects', $body);
    }

    public function testTheOverviewShowsTilesAndOneSwitchPerFeature(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Plain', 'slug' => 'plain', 'visible' => true, 'in_menu' => false, 'content' => '<p>Plain.</p>']);
        $site->exec("UPDATE tl_pages SET focus_keywords = 'plain', description = '' WHERE slug = 'plain'");
        $body = $this->assertPage('/admin.php?module=seo', 200, ['Pages with a focus keyword', 'Active redirects', 'Missing pages this week', 'Site check', 'have no description'])->body;
        foreach (['page_check', 'schema_org', 'indexnow', 'llms_txt', 'redirects'] as $feature) {
            $this->assertStringContainsString('name="feature" value="' . $feature . '"', $body, "a switch for $feature");
        }
        $this->assertStringNotContainsString('onclick=', $body);
        $this->assertMatchesRegularExpression('#href="[^"]*missing_pages"[^>]*>\s*<strong>\d+</strong><span>Missing pages this week#', $body, 'the missing pages tile opens Missing pages');
        $site->exec("INSERT INTO tl_redirects (public_id, from_path, to_path, active, created_at) VALUES ('" . bin2hex(random_bytes(16)) . "', 'tile-off', 'x', FALSE, NOW())");
        $before = (int) $site->value('SELECT COUNT(*) FROM tl_redirects WHERE active = TRUE');
        $this->assertMatchesRegularExpression('#<strong>' . $before . '</strong><span>Active redirects#', $this->assertPage('/admin.php?module=seo', 200)->body, 'a switched-off rule is not counted');
    }

    public function testSwitchingPageCheckOffRemovesTheScoreFromTheWebsiteSidebar(): void
    {
        $site = $this->site();
        $id = $site->publicId('pages', (int) $site->value('SELECT MIN(page_id) FROM tl_pages WHERE deleted_at IS NULL'));
        $url = '/admin.php?module=pages&action=website&tab=seo&id=' . $id;
        $site->mcpResult('create_news', ['title' => 'Winter opening hours', 'content' => '<p>Winter opening hours are shorter.</p>', 'category' => 'News', 'focus_keywords' => 'winter opening hours']);
        $news = '/admin.php?module=news&action=edit&id=' . $site->publicId('news', (int) $site->value('SELECT news_id FROM tl_news WHERE title = ?', ['Winter opening hours']));
        $this->assertPage($url, 200, ['website-score', 'website-dot', 'data-seo-checks'], message: 'the score is there while the feature is on');
        $this->assertPage($news, 200, ['check-content', 'data-seo-checks']);

        $this->assertSame(302, $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '0'], '/admin.php?module=seo')->status);
        $this->assertPageLacks($url, ['website-score', 'website-dot', 'data-seo-checks'], message: 'no score pill, no tree dots and no checks with the feature off');
        $this->assertPage($url, 200, 'data-seo-serp', message: 'the search result preview stays');
        $this->assertPageLacks($news, 'data-seo-checks', message: 'the news form has no score either');
        $this->assertPage($news, 200, 'check-content', message: 'the general content check stays');

        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '1'], '/admin.php?module=seo');
        $this->assertPage($url, 200, 'website-score');
    }

    public function testWithPageCheckOffTheTextPageFormKeepsTheContentCheck(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Draft text', 'slug' => 'draft-text', 'visible' => false, 'in_menu' => false, 'content' => '<p>Call [Customer’s name] today.</p><img src="/media/a.jpg">']);
        $url = '/admin.php?module=pages&action=edit&id=' . $site->value("SELECT public_id FROM tl_pages WHERE slug = 'draft-text'");
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '0'], '/admin.php?module=seo');
        $this->assertPage($url, 200, ['check-content', 'data-check="sample_content"', 'data-check="images_alt"'], message: 'placeholders and image descriptions are no Page check matter');
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '1'], '/admin.php?module=seo');
    }

    public function testSwitchesWriteTheSettingsAndExtensions(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'schema_org', 'on' => '0'], '/admin.php?module=seo');
        $this->assertSame('0', $site->settingValue('schema_org'));
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'llms_txt', 'on' => '1'], '/admin.php?module=seo');
        $this->assertSame('1', $site->settingValue('llms_txt'));
        $site->setting('indexnow', '0');
        $site->setting('indexnow_key', '');
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'indexnow', 'on' => '1'], '/admin.php?module=seo');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $site->settingValue('indexnow_key'), 'instant indexing gets its key from the overview too');
        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'redirects', 'on' => '0'], '/admin.php?module=seo');
        $this->assertStringNotContainsString('redirects', $site->settingValue('extensions'));
        $this->assertPageLacks('/admin.php?module=seo', ['/admin/redirects', 'module=redirects'], message: 'no card or tile links to a section that is off');
        $this->assertSame(302, $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'nonsense', 'on' => '0'], '/admin.php?module=seo')->status, 'an unknown feature changes nothing');
    }

    public function testInstantIndexingSwitchedOnByClaudeGetsItsKey(): void
    {
        $site = $this->site();
        $site->setting('indexnow', '0');
        $site->setting('indexnow_key', '');
        $site->mcpResult('update_settings', ['settings' => ['indexnow' => '1']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $site->settingValue('indexnow_key'));
    }

    public function testOnlyAdministratorsSwitchFeatures(): void
    {
        $author = $this->authorClient();
        $response = $author->post('/admin.php?module=seo&action=feature', ['_csrf' => $this->site()->csrf($author), 'feature' => 'page_check', 'on' => '0']);
        $this->assertSame(403, $response->status);
        $this->assertSame(403, $author->get('/admin.php?module=seo')->status);

        $site = $this->site();
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, 1, NOW(), NOW())', ['editor', password_hash($site->password, PASSWORD_DEFAULT), 'Editor']);
        $editor = $site->client('editor');
        $site->signIn($editor, 'editor');
        $this->assertSame(403, $editor->get('/admin.php?module=seo')->status, 'an editor has no overview either');
        $this->assertSame(403, $editor->post('/admin.php?module=seo&action=feature', ['_csrf' => $site->csrf($editor), 'feature' => 'page_check', 'on' => '0'])->status);
        $this->assertPage('/admin.php?module=pages', 200, 'Search score', message: 'Page check is still on');
    }
}
