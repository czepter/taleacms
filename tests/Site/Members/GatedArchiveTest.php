<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Members;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Site;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Moving a site with members-only content (Core\Members) through the open archive (Core\SiteExport → Core\SiteImport): the restriction
 * moves with the content. Without it every page, news item and collection item that only members could read would be public on the new
 * site, with nothing that says so. The groups go along (no personal data); the member accounts stay behind and are invited again.
 */
#[Group('site')]
final class GatedArchiveTest extends SiteTestCase
{
    private static ?Site $moved = null;

    public static function tearDownAfterClass(): void
    {
        self::$moved?->close();
        self::$moved = null;
        parent::tearDownAfterClass();
    }

    public function testAMovedSiteKeepsItsMembersOnlyContentForMembers(): void
    {
        $old = $this->site();
        $old->setting('extensions', $old->settingValue('extensions') . ',members');
        $this->adminPost('/admin.php?module=members&action=group_save', ['name' => 'Clients'], '/admin.php?module=members');
        $this->adminPost('/admin.php?module=members&action=group_save', ['name' => 'Unused group'], '/admin.php?module=members');
        $clients = (string) $old->value("SELECT public_id FROM tl_member_groups WHERE name = 'Clients'");
        $old->exec("INSERT INTO tl_members (email, name, created_at, confirmed_at) VALUES ('anna@example.org', 'Anna', NOW(), NOW())");

        $page = $old->mcpResult('create_page', ['title' => 'Club prices', 'slug' => 'club-prices', 'content' => '<p>Secret club price 77</p>', 'visible' => true]);
        $old->mcpResult('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => [$clients]]);
        $old->mcpResult('create_page', ['title' => 'Open page', 'slug' => 'open-page', 'content' => '<p>Everyone reads this open text</p>', 'visible' => true]);
        $category = (string) $old->value('SELECT name FROM tl_categories ORDER BY category_id LIMIT 1');
        $news = $old->mcpResult('create_news', ['title' => 'Members news headline', 'category' => $category, 'content' => '<p>Secret news body 99</p>']);
        $old->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE news_id = ?', [$old->rowId($news['id'])]);
        $old->mcpResult('set_content_groups', ['type' => 'news', 'id' => $news['id'], 'groups' => [$clients]]);
        $newsSlug = (string) $old->value('SELECT slug FROM tl_news WHERE news_id = ?', [$old->rowId($news['id'])]);
        $old->mcpResult('create_collection', ['name' => 'Resources', 'slug' => 'resources', 'item_pages' => true, 'fields' => [['label' => 'Text', 'type' => 'text']]]);
        $item = $old->mcpResult('save_collection_item', ['collection' => 'resources', 'name' => 'Handbook item', 'slug' => 'handbook', 'values' => ['text' => 'Secret item text 55'], 'visible' => true]);
        $old->exec('UPDATE tl_collection_items SET visible = 1 WHERE public_id = ?', [$item['id']]);
        $old->mcpResult('set_content_groups', ['type' => 'item', 'id' => $item['id'], 'groups' => [$clients]]);
        $gated = ['/club-prices' => 'Secret club price', '/news/' . $newsSlug => 'Secret news body', '/resources/handbook' => 'Secret item text'];
        foreach ($gated as $path => $secret) {
            $this->assertSame(403, $old->client('old-visitor')->get($path)->status, "the old site gates $path");
        }

        // the owner exports the site …
        $this->adminPost('/admin.php?module=transfer&action=export', [], '/admin.php?module=transfer');
        preg_match('/export-[0-9]*-[0-9]*\.zip/', $old->admin()->get('/admin.php?module=transfer')->body, $m);
        $this->assertNotSame('', $m[0] ?? '', 'the export is listed');
        $zipFile = $old->workDir('gated-move') . '/site.zip';
        file_put_contents($zipFile, $old->admin()->get('/admin.php?module=transfer&action=download&file=' . $m[0])->body);

        // … and imports it into a new installation
        $new = self::$moved = Site::boot(['web' => 'export', 'siteName' => 'New site', 'extensions' => ['news']]);
        $admin = $new->admin();
        $upload = $admin->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['file' => $zipFile]);
        $file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);
        $admin->post('/admin.php?module=transfer&action=talea_run', ['_csrf' => $new->csrf(), 'file' => $file, 'confirmation' => 1]);
        $result = null;
        for ($i = 0; $i < 80; $i++) {
            $result = $admin->get('/admin.php?module=transfer&action=talea&file=' . rawurlencode($file));
            if ($result->contains('The site has been imported') || $result->contains('The import has stopped')) {
                break;
            }
        }
        $this->assertStringContainsString('The site has been imported', $result->body, 'the import went through: ' . mb_substr($result->text(), 0, 300));

        $visitor = $new->client('new-visitor');
        $this->assertStringContainsString('Everyone reads this open text', $visitor->get('/open-page')->body, 'the moved site shows its public pages');
        foreach ($gated as $path => $secret) {
            $answer = $visitor->get($path);
            $this->assertSame(403, $answer->status, "the moved site still gates $path");
            $this->assertStringNotContainsString($secret, $answer->body, "the moved site does not show the members' text of $path");
        }
        foreach (['/sitemap.xml', '/rss.xml', '/search?q=secret'] as $path) {
            $this->assertStringNotContainsString('Secret', $visitor->get($path)->body, "$path of the moved site leaks gated content");
        }
        $this->assertSame('Clients|Unused group', (string) $new->value("SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR '|') FROM tl_member_groups"), 'the groups came along');
        $this->assertSame($clients, (string) $new->value("SELECT public_id FROM tl_member_groups WHERE name = 'Clients'"), 'with their public ids');
        $this->assertSame('3', (string) $new->value("SELECT COUNT(*) FROM tl_content_groups c JOIN tl_member_groups g ON g.group_id = c.group_id WHERE g.name = 'Clients'"), 'the page, the news item and the item are restricted to them');
        $this->assertSame('0', (string) $new->value('SELECT COUNT(*) FROM tl_members'), 'the member accounts are personal data and stay behind');
    }
}
