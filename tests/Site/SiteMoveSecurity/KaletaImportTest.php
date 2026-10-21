<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Core\KaletaExport;
use Talea\Tests\Site\Support\Site;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Moving a Kaleta site: uploading a Kaleta export (obsah.json, tests/Fixtures/kaleta) to "Import from Talea" converts it into a
 * Talea export (Core\KaletaExport) and imports it into a new, empty installation with every reference and word in place.
 */
#[Group('site')]
final class KaletaImportTest extends SiteTestCase
{
    private static ?Site $new = null;

    public static function tearDownAfterClass(): void
    {
        self::$new?->close();
        self::$new = null;
        parent::tearDownAfterClass();
    }

    public function testAKaletaExportIsConvertedOnUploadAndImportsWithItsReferences(): void
    {
        $dir = $this->site()->workDir('kaleta');
        $zip = new \ZipArchive();
        $zip->open($dir . '/kaleta.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile(dirname(__DIR__, 2) . '/Fixtures/kaleta/obsah.json', 'obsah.json');
        $zip->addFromString('media/2026/10/fixture.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $zip->close();
        $this->assertSame([], KaletaExport::convert($dir . '/kaleta.zip', $dir . '/talea.zip'), 'the converter knows every word of the fixture');

        $new = self::$new = Site::boot(['web' => 'export', 'siteName' => 'New site', 'extensions' => ['news']]);
        $admin = $new->admin();
        $upload = $admin->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['file' => $dir . '/kaleta.zip']);
        $file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);
        $preview = $admin->get(str_replace($new->base, '', $upload->redirect))->body;
        $this->assertStringContainsString('name="confirmation"', $preview, 'the Kaleta export is converted on upload: ' . $upload->redirect);
        $this->assertStringContainsString('was a Kaleta 3.7.0 export', $preview, 'the preview says it was converted');
        $admin->post('/admin.php?module=transfer&action=talea_run', ['_csrf' => $new->csrf(), 'file' => $file, 'confirmation' => 1]);
        for ($i = 0; $i < 40 && ($result = $admin->post('/admin.php?module=transfer&action=talea', ['_csrf' => $new->csrf(), 'file' => $file]))->contains('data-auto-submit'); $i++) {
            // one batch per request
        }
        $this->assertStringNotContainsString('skipped', $result->text(), 'nothing skipped – the end of a table file is not a skipped row');

        $this->assertSame('2/1/1/1/1/1/1/1/1/1/1', (string) $new->value("SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM tl_pages), (SELECT COUNT(*) FROM tl_news), (SELECT COUNT(*) FROM tl_news_tags),
            (SELECT COUNT(*) FROM tl_categories), (SELECT COUNT(*) FROM tl_collections), (SELECT COUNT(*) FROM tl_collection_items), (SELECT COUNT(*) FROM tl_components),
            (SELECT COUNT(*) FROM tl_popups), (SELECT COUNT(*) FROM tl_redirects), (SELECT COUNT(*) FROM tl_media WHERE folder_id IS NOT NULL), (SELECT COUNT(*) FROM tl_booking_staff))"),
            'pages (not the trash), news with its tag, category, collection, item, component, pop-up, redirect, media in its folder, booking staff');

        $about = (int) $new->value("SELECT page_id FROM tl_pages WHERE slug = 'about'");
        $home = (int) $new->value("SELECT page_id FROM tl_pages WHERE slug = 'home'");
        $this->assertSame((string) $home, $new->settingValue('home_page'), 'the home page setting points at the imported page');
        $this->assertSame((string) $home, (string) $new->value('SELECT parent_id FROM tl_pages WHERE page_id = ?', [$about]), 'parent page');
        $ds = json_decode($new->settingValue('design_system'), true);
        $this->assertSame(['off', 'news,enquiries,stats', '#123456', 'classic', 300], [$new->settingValue('dark_mode'), $new->settingValue('extensions'),
            $ds['colors']['primary'] ?? '', $ds['font_heading'] ?? '', $ds['typography']['lead']['weight'] ?? 0], 'settings: dark mode, extensions and the design system in Talea words');

        $menu = json_decode((string) $new->value("SELECT items FROM tl_menus WHERE location = 'main'"), true);
        $this->assertSame(['page', $about, 'link', true], [$menu[0]['type'] ?? '', $menu[0]['page_id'] ?? 0, $menu[1]['type'] ?? '', isset($menu[1]['description'])], 'menu: a page item points at the page');
        $popup = $new->rows('SELECT type, trigger_type, frequency, rules FROM tl_popups')[0];
        $rules = json_decode($popup['rules'], true);
        $this->assertSame(['bottom_bar', 'time', 'always', 'selected', [$about], 'phone'], [$popup['type'], $popup['trigger_type'], $popup['frequency'], $rules['where'], $rules['pages'], $rules['device']], 'pop-up and its rules');
        $this->assertSame('{"base":{"line_length":"var(--tl-text-width)","margin_bottom":"l"}}|font-family: var(--tl-font-body);', implode('|', $new->rows("SELECT style, css FROM tl_classes WHERE name = 'fixture-section'")[0]), 'class style and CSS');
        $this->assertSame('header', (string) $new->value('SELECT type FROM tl_site_parts'), 'site part');

        $build = json_decode((string) $new->value('SELECT build FROM tl_pages WHERE page_id = ?', [$home]), true);
        $section = $build['children'][0];
        $this->assertSame(['section', 'content', 'xl', 'surface', 'm', 'title', 'primary', 'outline'], [$section['type'], $section['content']['width'], $section['style']['base']['padding_y'], $section['style']['base']['background'],
            $section['style']['mobile']['padding_y'], $section['children'][0]['style']['base']['text_style'], $section['children'][0]['style']['base']['color'], $section['children'][1]['content']['variant']], 'build in Talea words');
        $this->assertSame((string) $new->value('SELECT component_id FROM tl_components'), (string) $section['children'][2]['content']['component'], 'the component element points at the imported component');

        $page = $new->client()->get('/');
        foreach (['Fixture heading', 'Fixture component value', 'Item: Fixture project', 'Fixture about'] as $text) {
            $this->assertStringContainsString($text, $page->body, "the home page shows: $text");
        }
        $this->assertStringContainsString('class="tl-button"', $new->client()->get('/about')->body, 'Kaleta classes in page HTML become Talea classes');
    }
}
