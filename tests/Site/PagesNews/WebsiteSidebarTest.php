<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Website mode: the framed preview and the settings sidebar whose tabs each save only their own fields (pages/settings_save).
 * Every test works on a page of its own, so each one runs alone too.
 */
#[Group('site')]
final class WebsiteSidebarTest extends SiteTestCase
{
    /** A published text page with its own search title and description; returns its public id. */
    private function page(string $slug, bool $visible = true): string
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'About ' . $slug, 'slug' => $slug, 'visible' => $visible, 'in_menu' => false, 'content' => '<p>About us.</p>']);
        $site->exec("UPDATE tl_pages SET seo_title = 'Search about', description = 'All about us.' WHERE slug = ?", [$slug]);

        return (string) $site->value('SELECT public_id FROM tl_pages WHERE slug = ?', [$slug]);
    }

    private function column(string $id, string $column): mixed
    {
        return $this->site()->value("SELECT $column FROM tl_pages WHERE public_id = ?", [$id]);
    }

    /** @param array<string, mixed> $fields */
    private function save(string $id, array $fields, ?Http $as = null): Response
    {
        $client = $as ?? $this->site()->admin();
        $csrf = $client->get('/admin.php?module=pages&action=website&id=' . $id)->csrf();

        return $client->post('/admin.php?module=pages&action=settings_save', ['_csrf' => $csrf, 'id' => $id] + $fields);
    }

    /** A signed-in user with the Pages section: level 1 = editor, 0 = author (no permission to publish). */
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

    public function testTheFrameAndTheSidebarWithItsFiveTabs(): void
    {
        $id = $this->page('frame');
        $body = $this->assertPage('/admin.php?module=pages&action=website&id=' . $id, 200, ['data-settings-panel', 'data-settings-toggle', '<iframe', '>Save<'])->body;
        foreach (['seo', 'page', 'publishing', 'sharing', 'advanced'] as $tab) {
            $this->assertStringContainsString('tab=' . $tab . '"', $body, "the tab $tab is a link");
        }
        $this->assertSame(3, substr_count($body, 'name="device"'), 'desktop, tablet and phone');
        $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&tab=seo', 200, ['name="focus_keywords"', 'name="key_page"']);
        $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&tab=advanced', 200, ['name="canonical_url"', 'name="robots[]"']);
    }

    public function testThePageTabChangesTheAddressAndLeavesTheSearchFields(): void
    {
        $id = $this->page('move-me');
        $this->save($id, ['tab' => 'page', 'title' => 'About us', 'slug' => 'move-me-new', 'parent_id' => '0', 'in_menu' => '0', 'sort_order' => '100']);
        $this->assertSame('move-me-new', $this->column($id, 'slug'));
        $this->assertSame(1, (int) $this->site()->value("SELECT COUNT(*) FROM tl_redirects WHERE from_path = 'move-me'"), 'the old address redirects');
        $this->assertSame('All about us.', $this->column($id, 'description'));
        $this->assertSame('Search about', $this->column($id, 'seo_title'));
    }

    public function testThePublishingTabHidesDatesAndLocksThePage(): void
    {
        $id = $this->page('locked');
        $this->save($id, ['tab' => 'publishing', 'visible' => '0', 'valid_until' => '2027-01-31', 'page_password' => 'secret1234']);
        $this->assertSame(0, (int) $this->column($id, 'visible'));
        $this->assertSame('2027-01-31', (string) $this->column($id, 'valid_until'));
        $hash = (string) $this->column($id, 'password_hash');
        $this->assertNotSame('secret1234', $hash);
        $this->assertTrue(password_verify('secret1234', $hash));
        $this->assertSame('All about us.', $this->column($id, 'description'), 'a field the tab does not show stays');
    }

    public function testOtherTabsKeepTheSchedule(): void
    {
        $id = $this->page('scheduled', false);
        $this->site()->exec("UPDATE tl_pages SET publish_at = '2030-05-01 09:00:00' WHERE public_id = ?", [$id]);
        $this->save($id, ['tab' => 'sharing', 'share_title' => 'Shared', 'share_description' => '', 'image' => '']);
        $this->assertSame('2030-05-01 09:00:00', (string) $this->column($id, 'publish_at'), 'an administrator saving another tab');
        $this->save($id, ['tab' => 'sharing', 'share_title' => 'Shared again', 'share_description' => '', 'image' => ''], $this->user('web-author', 0));
        $this->assertSame('Shared again', $this->column($id, 'share_title'));
        $this->assertSame('2030-05-01 09:00:00', (string) $this->column($id, 'publish_at'), 'an author saving another tab');
    }

    public function testTheAdvancedTabStoresRobotsAndTheCanonicalAddress(): void
    {
        $id = $this->page('advanced');
        $this->save($id, ['tab' => 'advanced', 'noindex' => '0', 'robots' => ['nofollow'], 'canonical_url' => 'https://example.org/x']);
        $this->assertSame('nofollow', $this->column($id, 'robots'));
        $this->assertSame('https://example.org/x', $this->column($id, 'canonical_url'));

        // the page's own address is no override: stored empty
        $this->save($id, ['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => strtoupper($this->site()->base) . '/advanced/']);
        $this->assertSame('', $this->column($id, 'canonical_url'));
        $this->assertSame('', $this->column($id, 'robots'), 'the empty marker clears the unticked robots');
    }

    public function testTheSeoTabClearsAnUntickedKeyPage(): void
    {
        $id = $this->page('key');
        $this->save($id, ['tab' => 'seo', 'seo_title' => 'Search about', 'description' => 'All about us.', 'focus_keywords' => "team\nhistory", 'key_page' => '1']);
        $this->assertSame("team\nhistory", $this->column($id, 'focus_keywords'));
        $this->assertSame(1, (int) $this->column($id, 'key_page'));
        $this->save($id, ['tab' => 'seo', 'seo_title' => 'Search about', 'description' => 'All about us.', 'focus_keywords' => 'team', 'key_page' => '0']);
        $this->assertSame(0, (int) $this->column($id, 'key_page'));
    }

    public function testHeadCodeOnlyFromAnAdministrator(): void
    {
        $id = $this->page('head-code');
        $this->save($id, ['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => '', 'head_code' => '<meta name="admin-tag" content="1">']);
        $this->assertSame('<meta name="admin-tag" content="1">', $this->column($id, 'head_code'));

        $this->save($id, ['tab' => 'advanced', 'noindex' => '0', 'robots' => [''], 'canonical_url' => '', 'breadcrumb_title' => 'Crumb', 'head_code' => '<script>alert(1)</script>'], $this->user('web-editor', 1));
        $this->assertSame('<meta name="admin-tag" content="1">', $this->column($id, 'head_code'), "an editor's head code is ignored");
        $this->assertSame('Crumb', $this->column($id, 'breadcrumb_title'), 'the rest of the tab is saved');
    }

    public function testAnAuthorIsRefusedThePublishingTabAndAMove(): void
    {
        $id = $this->page('author-page', false);
        $author = $this->user('web-author', 0);
        $this->save($id, ['tab' => 'publishing', 'visible' => '0', 'valid_until' => '2027-05-01', 'page_password' => ''], $author);
        $this->assertNull($this->column($id, 'valid_until'), 'the publishing tab is refused');
        $this->save($id, ['tab' => 'page', 'title' => 'Renamed by the author', 'slug' => 'Author-Page ', 'parent_id' => '0', 'in_menu' => '0', 'sort_order' => '7'], $author);
        $this->assertSame('Renamed by the author', $this->column($id, 'title'), 'a title, menu and order change is no move');
        $this->assertSame(7, (int) $this->column($id, 'sort_order'));
        $this->save($id, ['tab' => 'page', 'title' => 'Renamed by the author', 'slug' => 'author-moved', 'parent_id' => '0', 'in_menu' => '0', 'sort_order' => '7'], $author);
        $this->assertSame('author-page', $this->column($id, 'slug'), 'a new address is refused');
        $this->save($id, ['tab' => 'sharing', 'share_title' => 'Shared by the author', 'share_description' => '', 'image' => ''], $author);
        $this->assertSame('Shared by the author', $this->column($id, 'share_title'), 'the hidden page takes other tabs from an author');
    }

    public function testEditOpensWebsiteModeForABuildPageAndTheFormForATextPage(): void
    {
        $text = $this->page('text-page');
        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $text, 200, ['name="page_id" value="' . $text . '"', 'name="text"']);

        $built = $this->page('built-page');
        $this->site()->exec('UPDATE tl_pages SET build_draft = ? WHERE public_id = ?', ['{"v":1,"children":[]}', $built]);
        $response = $this->site()->admin()->get('/admin.php?module=pages&action=edit&id=' . $built);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('website', $response->redirect);
        $this->assertStringContainsString($built, $response->redirect);
        $this->assertStringContainsString('tab=page', $response->redirect);
        $this->assertPage('/admin.php?module=pages&action=new', 200, ['name="title"', 'name="template"']);
    }

    public function testThePrivacyTemplateStaysOutOfABuiltMenu(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM tl_menus WHERE location = 'main' AND language = ''");
        $site->exec("INSERT INTO tl_menus (location, language, items) VALUES ('main', '', ?)", ['[{"type":"link","text":"Home","url":"/"}]']);
        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => '', 'title' => 'Privacy notes', 'template' => 'privacy-policy', 'visible' => 1, 'in_menu' => 1, 'text' => '', 'sort_order' => 100], '/admin.php?module=pages&action=new');
        $privacy = (int) $site->value("SELECT page_id FROM tl_pages WHERE title = 'Privacy notes'");
        $this->assertGreaterThan(0, $privacy);
        $this->assertStringNotContainsString('"page_id":' . $privacy, (string) $site->value("SELECT items FROM tl_menus WHERE location = 'main' AND language = ''"), 'as before: the outline is not added to the menu');

        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => '', 'title' => 'Menu page', 'template' => '', 'visible' => 1, 'in_menu' => 1, 'text' => '<p>x</p>', 'sort_order' => 100], '/admin.php?module=pages&action=new');
        $plain = (int) $site->value("SELECT page_id FROM tl_pages WHERE title = 'Menu page'");
        $this->assertStringContainsString('"page_id":' . $plain, (string) $site->value("SELECT items FROM tl_menus WHERE location = 'main' AND language = ''"), 'a plain new page is appended');
        $site->exec("DELETE FROM tl_menus WHERE location = 'main' AND language = ''");
    }

    public function testAddingAStructuredDataType(): void
    {
        $id = $this->page('structured');
        $refused = $this->adminPost('/admin.php?module=pages&action=seo_add_type', ['id' => $id, 'type' => 'Event']);
        $this->assertNull($this->column($id, 'build_draft'), 'a text page gets no build from here');
        $this->assertStringNotContainsString('builder', $refused->redirect);

        $this->site()->exec('UPDATE tl_pages SET build_draft = ? WHERE public_id = ?', ['{"v":1,"children":[]}', $id]);
        $response = $this->adminPost('/admin.php?module=pages&action=seo_add_type', ['id' => $id, 'type' => 'Event']);
        $draft = json_decode((string) $this->column($id, 'build_draft'), true);
        $this->assertSame('structured_data', $draft['children'][0]['type'] ?? null);
        $this->assertSame('Event', $draft['children'][0]['content']['data']['type'] ?? null);
        $this->assertStringContainsString('builder', $response->redirect);
        $this->assertStringContainsString('select=' . $draft['children'][0]['id'], $response->redirect);
    }
}
