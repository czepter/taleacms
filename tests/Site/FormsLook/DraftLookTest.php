<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Draft look and whole-site preview (was: section 18 of tools/test.sh). */
#[Group('site')]
final class DraftLookTest extends SiteTestCase
{
    use SiteHelpers;

    private const string PRIMARY = "SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.colors.primary')) FROM ka_settings WHERE name = 'design_system'";

    public function testTheWholeDraftLookLifecycle(): void
    {
        $this->call('discard_look');
        $oldPrimary = $this->sql(self::PRIMARY);

        $answer = $this->call('update_design_system', ['design' => ['colors' => ['primary' => '#123456']]]);
        $sitePreview = $this->pick($answer, 'preview');
        $this->assertSame($oldPrimary . '|#123456', $this->sql(self::PRIMARY) . '|' . $this->sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.design_system.colors.primary')) FROM ka_settings WHERE name = 'look_draft'"), 'MCP: the design system goes to the draft look, the site keeps the published one');

        $this->site()->clearPageCache();
        $this->assertStringNotContainsString('ka-color-primary: #123456', $this->site()->client()->get('/')->body, 'visitors do not see the draft look');

        $this->call('save_classes', ['css' => '.look-test { padding: 1rem }']);
        $classes = $this->call('save_classes', ['css' => '.look-test { padding: 2rem }']);
        $this->assertSame('1|look-test', $this->sql("SELECT style LIKE '%\"odsazeni_y\"%' OR css LIKE '%1rem%' FROM ka_classes WHERE name = 'look-test'") . '|' . $this->pick($classes, 'look_draft', 0), 'MCP: a new class is live at once, a change of it waits in the draft');
        $this->assertSame('1', $this->pick($this->call('list_classes', ['name' => 'look-test']), 0, 'draft'), 'MCP: list_classes shows the draft');

        $this->site()->exec('DROP TABLE IF EXISTS menu_before');
        $this->site()->exec('CREATE TABLE menu_before AS SELECT * FROM ka_menus');
        $this->call('save_menu', ['location' => 'main', 'items' => [['type' => 'link', 'text' => 'Draft link', 'url' => '/draft-link']]]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_menus WHERE location = 'main' AND items LIKE '%draft-link%'"), 'MCP: save_menu goes to the draft look');
        $this->assertSame(1, $this->lines($this->pick($this->call('site_info'), 'look_draft'), 'look-test'), 'MCP: site_info lists the draft look');

        $preview = $this->site()->client('preview');
        $body = $preview->get($sitePreview)->body;
        $this->assertSame('1|1|1|1', $this->lines($body, 'ka-color-primary: #123456') . '|' . $this->lines($body, 'draft-link') . '|' . $this->lines($body, 'ka-preview-bar') . '|' . $this->lines($body, 'noindex'), 'the whole-site preview shows the draft look, the draft menu and the bar, and is not indexed');

        $draftPage = (int) $this->sql('SELECT page_id FROM ka_pages WHERE deleted_at IS NULL AND visible = 1 AND build IS NOT NULL ORDER BY page_id LIMIT 1');
        $slug = $this->sql("SELECT slug FROM ka_pages WHERE page_id = $draftPage");
        $this->call('edit_build', ['id' => $this->site()->publicId('pages', $draftPage), 'operations' => [['op' => 'insert', 'elements' => [['type' => 'heading', 'content' => ['text' => 'Only in the draft']]], 'into' => null, 'position' => 0]]]);
        $body = $preview->get("/$slug")->body;
        $this->assertSame('1|1', $this->lines($body, 'Only in the draft') . '|' . $this->lines($body, 'ka-color-primary: #123456'), 'browsing on in the preview (cookie) shows page drafts too');
        $this->assertStringNotContainsString('Only in the draft', $this->site()->client()->get("/$slug")->body, 'without the preview the page draft stays hidden');
        $preview->get("/$slug?preview_end=1");
        $this->assertStringNotContainsString('Only in the draft', $preview->get("/$slug")->body, 'ending the preview shows the published site again');
        $this->call('discard_draft', ['id' => $this->site()->publicId('pages', $draftPage)]);

        $this->assertPage('/admin.php?module=pages', 200, 'Publish the look', message: 'the admin shows the look bar on every screen');

        $this->call('publish_look');
        $this->assertSame('#123456|1|1||1', implode('|', [
            $this->sql(self::PRIMARY),
            $this->sql("SELECT css LIKE '%2rem%' OR style LIKE '%2rem%' OR style LIKE '%\"xl\"%' FROM ka_classes WHERE name = 'look-test'"),
            $this->sql("SELECT COUNT(*) FROM ka_menus WHERE location = 'main' AND items LIKE '%draft-link%'"),
            $this->sql("SELECT value FROM ka_settings WHERE name = 'look_draft'"),
            $this->sql('SELECT COUNT(*) > 0 FROM ka_look_versions'),
        ]), 'MCP: publish_look publishes everything and keeps the previous look');
        $this->assertSame('1', $this->sql("SELECT description LIKE '%#123456%' FROM ka_change_log WHERE action = 'publish look' ORDER BY log_id DESC LIMIT 1"), 'publishing the look is in the change log with what changed');

        $version = (int) $this->pick($this->call('list_look_versions'), 'versions', 0, 'id');
        $this->call('restore_look_version', ['id' => $version]);
        $this->assertSame($oldPrimary, $this->sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.design_system.colors.primary')) FROM ka_settings WHERE name = 'look_draft'"), 'MCP: an earlier look comes back into the draft');
        $this->assertPage('/admin.php?module=appearance', 200, 'Back to this look', message: 'earlier looks in Site appearance');

        $this->call('discard_look');
        $this->assertSame('|#123456', $this->sql("SELECT value FROM ka_settings WHERE name = 'look_draft'") . '|' . $this->sql(self::PRIMARY), 'MCP: discard_look');

        $this->call('update_design_system', ['design' => ['colors' => ['primary' => $oldPrimary]]]);
        $this->call('publish_look');
        $this->site()->exec('DELETE FROM ka_menus');
        $this->site()->exec('INSERT INTO ka_menus SELECT * FROM menu_before');
        $this->site()->exec('DROP TABLE menu_before');
        $this->site()->clearPageCache();
        $this->assertSame($oldPrimary, $this->sql(self::PRIMARY), 'the original look is published again');
    }
}
