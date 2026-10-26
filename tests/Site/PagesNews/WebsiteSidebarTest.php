<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/** Website mode: the framed preview and the settings sidebar whose tabs each save only their own fields (pages/settings_save). */
#[Group('site')]
final class WebsiteSidebarTest extends SiteTestCase
{
    private static string $about = '';

    /** The public id of "About us", a published text page with its own search title and description. */
    private function about(): string
    {
        if (self::$about === '') {
            $site = $this->site();
            $site->mcp('create_page', ['title' => 'About us', 'slug' => 'about-us', 'visible' => true, 'in_menu' => false, 'content' => '<p>About us.</p>']);
            $site->exec("UPDATE tl_pages SET seo_title = 'Search about', description = 'All about us.' WHERE slug = 'about-us'");
            self::$about = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'about-us'");
        }

        return self::$about;
    }

    private function column(string $column): mixed
    {
        return $this->site()->value("SELECT $column FROM tl_pages WHERE public_id = ?", [$this->about()]);
    }

    /** @param array<string, mixed> $fields */
    private function save(array $fields, ?Http $as = null): Response
    {
        $client = $as ?? $this->site()->admin();
        $csrf = $client->get('/admin.php?module=pages&action=website&id=' . $this->about())->csrf();

        return $client->post('/admin.php?module=pages&action=settings_save', ['_csrf' => $csrf, 'id' => $this->about()] + $fields);
    }

    private function user(string $name, int $level): Http
    {
        $site = $this->site();
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$name, password_hash($site->password, PASSWORD_DEFAULT), $name, $level]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = ?", [$name]);
        $client = $site->client($name);
        $site->signIn($client, $name);

        return $client;
    }

    public function testTheFrameAndTheSidebarWithItsFiveTabs(): void
    {
        $body = $this->assertPage('/admin.php?module=pages&action=website&id=' . $this->about(), 200, ['data-settings-panel', 'data-settings-toggle', '<iframe', '>' . 'Save' . '<'])->body;
        foreach (['seo', 'page', 'publishing', 'sharing', 'advanced'] as $tab) {
            $this->assertStringContainsString('tab=' . $tab . '"', $body, "the tab $tab is a link");
        }
        $this->assertSame(3, substr_count($body, 'name="device"'), 'desktop, tablet and phone');
        $this->assertPage('/admin.php?module=pages&action=website&id=' . $this->about() . '&tab=seo', 200, ['name="focus_keywords"', 'name="key_page"']);
        $this->assertPage('/admin.php?module=pages&action=website&id=' . $this->about() . '&tab=advanced', 200, ['name="canonical_url"', 'name="robots[]"']);
    }

    public function testThePageTabChangesTheAddressAndLeavesTheSearchFields(): void
    {
        $this->save(['tab' => 'page', 'title' => 'About us', 'slug' => 'about-us-new', 'parent_id' => '0', 'in_menu' => '0', 'sort_order' => '100']);
        $this->assertSame('about-us-new', $this->column('slug'));
        $this->assertSame(1, (int) $this->site()->value("SELECT COUNT(*) FROM tl_redirects WHERE from_path = 'about-us'"), 'the old address redirects');
        $this->assertSame('All about us.', $this->column('description'));
        $this->assertSame('Search about', $this->column('seo_title'));
    }

    public function testThePublishingTabHidesDatesAndLocksThePage(): void
    {
        $this->save(['tab' => 'publishing', 'visible' => '0', 'valid_until' => '2027-01-31', 'page_password' => 'secret1234']);
        $this->assertSame(0, (int) $this->column('visible'));
        $this->assertSame('2027-01-31', (string) $this->column('valid_until'));
        $hash = (string) $this->column('password_hash');
        $this->assertNotSame('', $hash);
        $this->assertNotSame('secret1234', $hash);
        $this->assertTrue(password_verify('secret1234', $hash));
        $this->assertSame('All about us.', $this->column('description'), 'a field the tab does not show stays');
    }

    public function testTheAdvancedTabStoresRobotsAndTheCanonicalAddress(): void
    {
        $this->save(['tab' => 'advanced', 'noindex' => '0', 'robots' => ['nofollow'], 'canonical_url' => 'https://example.org/x']);
        $this->assertSame('nofollow', $this->column('robots'));
        $this->assertSame('https://example.org/x', $this->column('canonical_url'));

        // the page's own address is no override: stored empty
        $this->save(['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => strtoupper($this->site()->base) . '/about-us-new/']);
        $this->assertSame('', $this->column('canonical_url'));
        $this->assertSame('', $this->column('robots'), 'the empty marker clears the unticked robots');
    }

    public function testTheSeoTabClearsAnUntickedKeyPage(): void
    {
        $this->save(['tab' => 'seo', 'seo_title' => 'Search about', 'description' => 'All about us.', 'focus_keywords' => "team\nhistory", 'key_page' => '1']);
        $this->assertSame("team\nhistory", $this->column('focus_keywords'));
        $this->assertSame(1, (int) $this->column('key_page'));
        $this->save(['tab' => 'seo', 'seo_title' => 'Search about', 'description' => 'All about us.', 'focus_keywords' => 'team', 'key_page' => '0']);
        $this->assertSame(0, (int) $this->column('key_page'));
    }

    public function testHeadCodeOnlyFromAnAdministrator(): void
    {
        $this->save(['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => '', 'head_code' => '<meta name="admin-tag" content="1">']);
        $this->assertSame('<meta name="admin-tag" content="1">', $this->column('head_code'));

        $editor = $this->user('web-editor', 1);
        $this->save(['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => '', 'breadcrumb_title' => 'Crumb', 'head_code' => '<script>alert(1)</script>'], $editor);
        $this->assertSame('<meta name="admin-tag" content="1">', $this->column('head_code'), "an editor's head code is ignored");
        $this->assertSame('Crumb', $this->column('breadcrumb_title'), 'the rest of the tab is saved');
    }

    public function testAnAuthorIsRefusedThePublishingTab(): void
    {
        $author = $this->user('web-author', 0);
        $this->save(['tab' => 'publishing', 'visible' => '0', 'valid_until' => '2027-05-01', 'page_password' => ''], $author);
        $this->assertSame('2027-01-31', (string) $this->column('valid_until'), 'nothing changed');
        $this->save(['tab' => 'sharing', 'share_title' => 'Shared by the author', 'share_description' => '', 'image' => ''], $author);
        $this->assertSame('Shared by the author', $this->column('share_title'), 'the hidden page takes other tabs from an author');
    }

    public function testEditRedirectsToWebsiteModeAndNewKeepsTheForm(): void
    {
        $response = $this->site()->admin()->get('/admin.php?module=pages&action=edit&id=' . $this->about());
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('website', $response->redirect);
        $this->assertStringContainsString($this->about(), $response->redirect);
        $this->assertStringContainsString('tab=page', $response->redirect);
        $this->assertPage('/admin.php?module=pages&action=new', 200, ['name="title"', 'name="template"']);
    }

    public function testAddingAStructuredDataType(): void
    {
        $refused = $this->adminPost('/admin.php?module=pages&action=seo_add_type', ['id' => $this->about(), 'type' => 'Event']);
        $this->assertNull($this->column('build_draft'), 'a text page gets no build from here');
        $this->assertStringNotContainsString('builder', (string) $refused->redirect);

        $this->site()->exec('UPDATE tl_pages SET build_draft = ? WHERE public_id = ?', ['{"v":1,"children":[]}', $this->about()]);
        $response = $this->adminPost('/admin.php?module=pages&action=seo_add_type', ['id' => $this->about(), 'type' => 'Event']);
        $draft = json_decode((string) $this->column('build_draft'), true);
        $this->assertSame('structured_data', $draft['children'][0]['type'] ?? null);
        $this->assertSame('Event', $draft['children'][0]['content']['data']['type'] ?? null);
        $this->assertStringContainsString('builder', (string) $response->redirect);
        $this->assertStringContainsString('select=' . $draft['children'][0]['id'], (string) $response->redirect);
    }
}
