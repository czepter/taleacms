<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * MCP: trash, deleting and the rest of the admin (was: section 17 of tools/test.sh).
 *
 * Note for the later sections (55/83/84/92 need a news category, 78 a PNG): the old section 17 created them as a side effect
 * (the first default-language category used by "Do kose", and the uploaded PNG). Those classes must create their own.
 */
#[Group('site')]
final class McpTrashAndAdminTest extends SiteTestCase
{
    use SiteHelpers;

    public function testToolsCarryAnnotationsAndSiteInfoListsExtensionsAndLanguages(): void
    {
        $answer = $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $tools = array_column($answer['result']['tools'], 'annotations', 'name');
        $this->assertTrue($tools['list_pages']['readOnlyHint'] === true, 'list_pages is read-only');
        $this->assertTrue($tools['trash_page']['destructiveHint'] === true, 'trash_page is destructive');
        $this->assertTrue($tools['create_page']['readOnlyHint'] === false, 'create_page is not read-only');
        $this->assertTrue($tools['delete_collection']['destructiveHint'] === true, 'delete_collection is destructive');

        $info = $this->call('site_info');
        $this->assertStringContainsString('novinky', $this->pick($info, 'extensions'), 'MCP: site_info lists extensions');
        $this->assertSame('cs', $this->pick($info, 'languages', 'default'), 'MCP: site_info lists languages');
    }

    public function testBuilderSchemaAndBuildsUseTheEnglishVocabulary(): void
    {
        $schema = $this->call('builder_schema');
        $this->assertStringContainsString('content: text', $this->pick($schema, 'elements', 'heading'), 'MCP: heading element in the English vocabulary');
        $this->assertStringContainsString('gap', $this->pick($schema, 'style', 'gap'), 'MCP: style property gap');
        $this->assertSame('mobile', $this->pick($schema, 'states', 2), 'MCP: states in English');

        $form = $this->call('builder_schema', ['elements' => ['form']]);
        $this->assertSame('form', $this->pick($form, 'elements', 0, 'type'), 'MCP: full definition of an element by its English type');
        $this->assertSame('radio', $this->pick($form, 'elements', 0, 'fields', 'fields', 'item_fields', 'type', 'options', 5), 'MCP: field option list of the form element');

        $page = (int) $this->sql('SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids LIMIT 1');
        $this->call('save_build', ['id' => $page, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [[
            'type' => 'button', 'content' => ['text' => 'Go', 'variant' => 'outline', 'icon' => 'arrow'], 'style' => ['mobile' => ['gap' => 's', 'background' => 'primary-soft']],
        ]]]]]]);
        $this->assertSame('tlacitko|obrys|primarni-jemna', $this->sqlRow("SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.varianta')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.mobil.pozadi')) FROM ka_stranky WHERE ids = $page"), 'MCP: an English build is stored in the Czech keys');

        $build = $this->call('get_build', ['id' => $page]);
        $this->assertSame('button|outline|primary-soft', $this->pick($build, 'build', 'children', 0, 'children', 0, 'type') . '|' . $this->pick($build, 'build', 'children', 0, 'children', 0, 'content', 'variant') . '|' . $this->pick($build, 'build', 'children', 0, 'children', 0, 'style', 'mobile', 'background'), 'MCP: get_build answers in English');
        $button = $this->pick($build, 'build', 'children', 0, 'children', 0, 'id');

        $this->call('edit_build', ['id' => $page, 'operations' => [['op' => 'update', 'id' => $button, 'content' => ['new_window' => true], 'style' => ['base' => ['radius' => 'full']]]]]);
        $this->assertSame('true|plne', $this->sqlRow("SELECT CONCAT(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.nove_okno'), '|', JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.zaklad.zaobleni'))) FROM ka_stranky WHERE ids = $page"), 'MCP: edit_build takes English content and style');
        $this->call('discard_draft', ['id' => $page]);
    }

    public function testCollectionItemsAndCollectionsGoThroughTheTrash(): void
    {
        $this->call('create_collection', ['name' => 'Kos test', 'fields' => [['label' => 'Popis', 'type' => 'text']]]);
        $item = (int) $this->pick($this->call('save_collection_item', ['collection' => 'kos-test', 'name' => 'Polozka', 'visible' => true]), 'id');
        $this->call('delete_collection_item', ['collection' => 'kos-test', 'id' => $item]);
        $this->assertSame('10', $this->sql("SELECT CONCAT(smazano IS NOT NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $item"), 'MCP: a collection item goes to the trash, hidden');

        $collection = $this->sql("SELECT idk FROM ka_kolekce WHERE seo_link = 'kos-test'");
        $this->assertPage("/admin.php?module=collections&action=items&id=$collection&status=kos", 200, 'Polozka', message: 'collection trash in the admin');
        $this->assertSame('Polozka', $this->pick($this->call('list_trash'), 'collection_items', 0, 'name'), 'MCP: list_trash shows the item');

        $this->assertStringContainsString('is in the trash', $this->raw('save_collection_item', ['collection' => 'kos-test', 'id' => $item, 'visible' => true]), 'MCP: saving an item from the trash is refused');
        $this->assertSame('0', $this->sql("SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $item"), 'MCP: an item in the trash cannot be published by saving it (1.9)');
        $this->assertStringNotContainsString('Polozka', $this->raw('list_collection_items', ['collection' => 'kos-test']), 'list_collection_items leaves the trash out');

        $this->call('restore_from_trash', ['type' => 'collection_item', 'id' => $item]);
        $this->assertSame('10', $this->sql("SELECT CONCAT(smazano IS NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $item"), 'MCP: restored from the trash as hidden');

        $this->call('delete_collection', ['collection' => 'kos-test']);
        $this->assertSame('0|0', $this->sql("SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'kos-test'") . '|' . $this->sql("SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idp = $item"), 'MCP: delete_collection removes it with its items');
    }

    public function testNewsAndCategoriesTrashAndDelete(): void
    {
        $category = $this->sql("SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1");
        $this->call('create_news', ['title' => 'Do kose', 'category' => $category]);
        $news = (int) $this->sql("SELECT idc FROM ka_novinky WHERE titulek = 'Do kose'");
        $this->assertGreaterThan(0, $news, 'the news item was created');

        $this->call('trash_news', ['id' => $news]);
        $this->assertSame('1', $this->sql("SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = $news"), 'MCP: trash_news');
        $this->call('restore_from_trash', ['type' => 'news', 'id' => $news]);
        $this->assertSame('10', $this->sql("SELECT CONCAT(smazano IS NULL, visible) FROM ka_novinky WHERE idc = $news"), 'MCP: a news item back from the trash as a draft');

        $this->call('create_category', ['name' => 'Docasna']);
        $cat = (int) $this->sql("SELECT idt FROM ka_kategorie WHERE nazev = 'Docasna'");
        $this->call('update_category', ['id' => $cat, 'name' => 'Docasna 2', 'slug' => 'docasna-2']);
        $this->assertSame('Docasna 2|docasna-2|1', $this->sql("SELECT CONCAT(nazev, '|', seo_link) FROM ka_kategorie WHERE idt = $cat") . '|' . $this->sql("SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%kategorie/docasna'"), 'MCP: update_category renames and redirects the old address');

        $this->assertStringContainsString('still has news items', $this->raw('delete_category', ['id' => (int) $this->sql("SELECT tema FROM ka_novinky WHERE idc = $news")]), 'MCP: a category with news items is not deleted');
        $this->call('delete_category', ['id' => $cat]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_kategorie WHERE idt = $cat"), 'MCP: delete_category');
    }

    public function testComponentsAreBuiltPublishedListedAndDeleted(): void
    {
        $comp = (int) $this->pick($this->call('save_component', ['name' => 'Karta', 'properties' => [['klic' => 'titulek', 'popisek' => 'Titulek', 'typ' => 'text', 'vychozi' => 'Ahoj']]]), 'id');
        $this->call('save_build', ['component' => $comp, 'build' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'nadpis', 'obsah' => ['text' => '{{titulek}}']]]]]]]);
        $this->call('publish_build', ['component' => $comp]);
        $this->assertSame('1', $this->sql("SELECT stavba LIKE '%{{titulek}}%' AND stavba_koncept IS NULL FROM ka_komponenty WHERE idm = $comp"), 'MCP: a component built and published through the component target');
        $list = $this->call('list_components');
        $this->assertSame('Karta|1', $this->pick($list, 0, 'name') . '|' . $this->pick($list, 0, 'published'), 'MCP: list_components');
        $this->call('delete_component', ['id' => $comp]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_komponenty WHERE idm = $comp"), 'MCP: delete_component');
    }

    public function testSavedSectionsAndPopups(): void
    {
        $page = (int) $this->sql('SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND smazano IS NULL ORDER BY ids LIMIT 1');
        $first = json_decode($this->sql("SELECT COALESCE(stavba_koncept, stavba) FROM ka_stranky WHERE ids = $page"), true)['deti'][0]['id'];
        $section = (int) $this->pick($this->call('save_section', ['id' => $page, 'element' => $first, 'name' => 'Moje sekce z MCP']), 'id');
        $this->assertStringContainsString('Moje sekce z MCP', $this->raw('builder_schema'), 'MCP: saved sections in builder_schema');

        $before = (int) $this->sql("SELECT JSON_LENGTH(COALESCE(stavba_koncept, stavba), '$.deti') FROM ka_stranky WHERE ids = $page");
        $this->call('insert_section', ['id' => $page, 'saved_section' => $section]);
        $this->assertSame((string) ($before + 1) . '|1', $this->sql("SELECT JSON_LENGTH(stavba_koncept, '$.deti') FROM ka_stranky WHERE ids = $page") . '|'
            . $this->sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, CONCAT('$.deti[', JSON_LENGTH(stavba_koncept, '$.deti') - 1, '].id'))) <> ? FROM ka_stranky WHERE ids = $page", [$first]), 'MCP: insert_section with a saved section adds it with new ids');
        $this->call('discard_draft', ['id' => $page]);
        $this->call('delete_section', ['id' => $section]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_sekce WHERE idx = $section"), 'MCP: delete_section');

        $this->call('save_popup', ['template' => 'announcement_bar', 'name' => 'Na smazani']);
        $popup = (int) $this->sql("SELECT idpp FROM ka_popupy WHERE nazev = 'Na smazani'");
        $this->call('delete_popup', ['id' => $popup]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_popupy WHERE idpp = $popup"), 'MCP: delete_popup');
    }

    public function testMediaUpdateDeleteAndProtectionOfFilesInUse(): void
    {
        $png = $this->png();
        $this->call('upload_file', ['filename' => 'mcp-smazat.png', 'data' => $png]);
        $media = (int) $this->sql('SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1');
        $this->call('update_media', ['id' => $media, 'alt' => 'Cerny ctverec', 'caption' => 'Popisek']);
        $this->assertSame('Cerny ctverec|Popisek', $this->sql("SELECT CONCAT(nazev, '|', popis) FROM ka_media WHERE ido = $media"), 'MCP: update_media');
        $file = $this->sql("SELECT obr_poloha FROM ka_media WHERE ido = $media");
        $this->call('delete_media', ['id' => $media]);
        $this->assertSame('0|gone', $this->sql("SELECT COUNT(*) FROM ka_media WHERE ido = $media") . '|' . (file_exists($this->site()->path($file)) ? 'file' : 'gone'), 'MCP: delete_media removes the record and the file');

        // the old run only tested this when some page already used a file; a fresh site has none, so make one
        $this->call('upload_file', ['filename' => 'mcp-pouzito.png', 'data' => $png]);
        $mediaUsed = (int) $this->sql('SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1');
        $this->site()->exec("UPDATE ka_stranky SET text = CONCAT(COALESCE(text, ''), ' <img src=\"/', ?, '\">') WHERE seo_link = 'o-nas'", [$this->sql("SELECT obr_poloha FROM ka_media WHERE ido = $mediaUsed")]);
        $used = $this->sql("SELECT ido FROM ka_media m WHERE EXISTS (SELECT 1 FROM ka_stranky s WHERE CONCAT_WS(' ', s.stavba, s.stavba_koncept, s.text) LIKE CONCAT('%', REPLACE(m.obr_poloha, '/', '%'), '%')) LIMIT 1");
        $this->assertNotSame('', $used, 'a file in use exists');
        $this->assertStringContainsString('still used on the site', $this->raw('delete_media', ['id' => (int) $used]), 'MCP: a file in use is not deleted');
    }

    public function testEnquiryReadsAreLoggedAndEnquiriesCanBeUpdatedAndDeleted(): void
    {
        $reads = (int) $this->sql("SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'");
        $this->call('list_enquiries');
        $this->assertSame((string) ($reads + 1), $this->sql("SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'"), 'MCP: every enquiry read is in the change log');

        $this->site()->exec("INSERT INTO ka_poptavky (datum, email, data) VALUES (NOW(), 'mcp@example.cz', '[]')");
        $enquiry = (int) $this->sql('SELECT MAX(idp) FROM ka_poptavky');
        $this->call('update_enquiry', ['id' => $enquiry, 'status' => 'resolved', 'note' => 'Vyrizeno pres Clauda']);
        $this->assertSame('2|Vyrizeno pres Clauda', $this->sql("SELECT CONCAT(stav, '|', poznamka) FROM ka_poptavky WHERE idp = $enquiry"), 'MCP: update_enquiry');
        $this->call('delete_enquiry', ['id' => $enquiry]);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_poptavky WHERE idp = $enquiry"), 'MCP: delete_enquiry');
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(8, 8));

        return base64_encode((string) ob_get_clean());
    }
}
