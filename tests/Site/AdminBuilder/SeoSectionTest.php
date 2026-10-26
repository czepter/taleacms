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
    }

    public function testSwitchingPageCheckOffRemovesTheScoreFromTheWebsiteSidebar(): void
    {
        $site = $this->site();
        $id = $site->publicId('pages', (int) $site->value('SELECT MIN(page_id) FROM tl_pages WHERE deleted_at IS NULL'));
        $url = '/admin.php?module=pages&action=website&tab=seo&id=' . $id;
        $this->assertPage($url, 200, ['website-score', 'check-content'], message: 'the score is there while the feature is on');

        $this->assertSame(302, $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '0'], '/admin.php?module=seo')->status);
        $this->assertPageLacks($url, ['website-score', 'check-content'], message: 'no score pill and no checks with the feature off');

        $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'page_check', 'on' => '1'], '/admin.php?module=seo');
        $this->assertPage($url, 200, 'website-score');
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
        $this->assertSame(302, $this->adminPost('/admin.php?module=seo&action=feature', ['feature' => 'nonsense', 'on' => '0'], '/admin.php?module=seo')->status, 'an unknown feature changes nothing');
    }

    public function testOnlyAdministratorsSwitchFeatures(): void
    {
        $author = $this->authorClient();
        $response = $author->post('/admin.php?module=seo&action=feature', ['_csrf' => $this->site()->csrf($author), 'feature' => 'page_check', 'on' => '0']);
        $this->assertSame(403, $response->status);
        $this->assertSame(403, $author->get('/admin.php?module=seo')->status);
    }
}
