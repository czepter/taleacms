<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\PagesNews;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pages and their addresses, from one installed site where each part builds on the previous one (was: sections 23-27 of tools/test.sh):
 * redirects after a category/page address change (23), page SEO, trash, duplication, language switcher and header scroll (24),
 * subpages, schedule, history, templates, page export/import (25), copy and paste between sites and display conditions (26),
 * builder custom CSS, attributes, animation, my sections and class rename (27).
 * The page 'kontakty' (from 23) is what 24 works on; 'nase-sluzby' (25) is what 27 edits.
 */
#[Group('site')]
final class PagesFlowTest extends SiteTestCase
{
    private static int $kontakty = 0;
    private static int $nabidka = 0;
    private static int $outer = 0;
    private static int $services = 0;

    private function visitor(): Http
    {
        return $this->site()->client('visitor');
    }

    private function noCache(): void
    {
        $this->site()->clearPageCache();
    }

    private function cachedPages(): int
    {
        return count(glob($this->site()->path('storage/cache/stranky/*.html')) ?: []);
    }

    /** POST pages&action=save as the administrator (the old save_page). @param array<string, mixed> $fields */
    private function savePage(array $fields): Response
    {
        return $this->adminPost('/admin.php?module=pages&action=save', $fields, '/admin.php?module=pages');
    }

    /** @param array<string, mixed> $fields */
    private function pageAction(string $action, int $id, array $fields = []): Response
    {
        return $this->adminPost("/admin.php?module=pages&action=$action&id=$id", $fields, '/admin.php?module=pages');
    }

    private function idOf(string $seo): int
    {
        return (int) $this->site()->value('SELECT page_id FROM ka_pages WHERE slug = ?', [$seo]);
    }

    private function assertRedirect(string $path, string $target, string $message): void
    {
        $response = $this->visitor()->get($path);
        $this->assertSame(301, $response->status, "$message: status");
        $this->assertSame($this->site()->base . $target, $response->redirect, "$message: target");
    }

    // ---- 23

    public function testChangedAddressesOfACategoryAndAPageRedirect(): void
    {
        $this->assertPage('/admin.php?module=categories', 200, 'Kategorie', message: 'category form');
        $idt = (int) $this->site()->value("SELECT category_id FROM ka_categories WHERE slug = 'novinky'");
        $this->adminPost('/admin.php?module=categories&action=save', ['category_id' => $idt, 'name' => 'Aktuality', 'slug' => 'aktuality-firmy', 'weight' => 100], '/admin.php?module=categories');
        $this->assertRedirect('/news/category/novinky', '/news/category/aktuality-firmy', 'the old address of a category redirects to the new one');

        $ids = $this->idOf('kontakt');
        $this->savePage(['page_id' => $ids, 'title' => 'Kontakt', 'slug' => 'kontakty', 'visible' => 1, 'in_menu' => 1, 'text' => '<p>Adresa.</p>']);
        $this->assertRedirect('/kontakt', '/kontakty', 'the old address of a page redirects to the new one');
        self::$kontakty = $this->idOf('kontakty');
        $this->assertGreaterThan(0, self::$kontakty);
    }

    // ---- 24

    #[Depends('testChangedAddressesOfACategoryAndAPageRedirect')]
    public function testPageSeoDuplicateAndTrash(): void
    {
        $site = $this->site();
        $ids = self::$kontakty;
        $this->savePage(['page_id' => $ids, 'title' => 'Kontakt', 'slug' => 'kontakty', 'visible' => 1, 'in_menu' => 1, 'text' => '<p>Adresa.</p>',
            'seo_title' => 'Kontakt na truhlárnu', 'image' => 'media/2026/01/sdileni.jpg', 'noindex' => 1]);
        $this->noCache();
        $body = $this->visitor()->get('/kontakty')->body;
        $this->assertStringContainsString('<title>Kontakt na truhlárnu', $body, 'page: own title');
        $this->assertMatchesRegularExpression('#og:image" content="http[^"]*/media/2026/01/sdileni.jpg"#', $body, 'page: full address of the sharing image');
        $this->assertStringContainsString('noindex, follow', $body, 'page: noindex');

        $this->adminPost('/admin.php?module=pages&action=duplicate', ['page_id' => $ids], '/admin.php?module=pages');
        $this->assertSame('0/kontakty-copy', (string) $site->value("SELECT CONCAT(visible, '/', slug) FROM ka_pages ORDER BY page_id DESC LIMIT 1"), 'the duplicate is hidden and has a free address');

        $this->adminPost('/admin.php?module=pages&action=delete', ['page_id' => $ids], '/admin.php?module=pages');
        $this->assertPage('/kontakty', 404, message: 'a page in the trash is not on the web');
        $this->assertPage('/admin.php?module=pages&status=trash', 200, 'Kontakt', message: 'Trash tab of pages');
        $this->adminPost('/admin.php?module=pages&action=restore', ['page_id' => $ids], '/admin.php?module=pages');
        $this->assertSame('0/1', (string) $site->value("SELECT CONCAT(visible, '/', deleted_at IS NULL) FROM ka_pages WHERE page_id = ?", [$ids]), 'a restored page is hidden');

        $home = $this->idOf('o-nas');
        $site->setting('home_page', (string) $home);
        $this->adminPost('/admin.php?module=pages&action=delete', ['page_id' => $home], '/admin.php?module=pages');
        $this->assertSame('1', (string) $site->value('SELECT deleted_at IS NULL FROM ka_pages WHERE page_id = ?', [$home]), 'the home page cannot be deleted');
    }

    #[Depends('testPageSeoDuplicateAndTrash')]
    public function testLanguageVersionsSwitcherAndHeaderScroll(): void
    {
        $site = $this->site();
        $home = $this->idOf('o-nas');
        $site->setting('additional_languages', 'en'); // the old run had English switched on by the administration section
        // a language version in progress (without a published translation of the home page) is not offered anywhere
        $site->exec('UPDATE ka_pages SET visible = 1, deleted_at = NULL WHERE page_id = ?', [$home]);
        $this->noCache();
        $page = $this->visitor()->get('/')->body;
        $map = $this->visitor()->get('/sitemap.xml')->body;
        $this->assertStringNotContainsString('hreflang="en"', $page, 'a language without a translated home page is not in hreflang');
        $this->assertStringNotContainsString('/en/</loc>', $map, 'a language without a translated home page is not in the sitemap');

        $site->mcp('create_page', ['title' => 'About home', 'slug' => 'about-home', 'language' => 'en', 'translation_of' => $home, 'content' => '<p>Home</p>', 'visible' => 1]);
        $this->noCache();
        $this->assertStringContainsString('hreflang="en"', $this->visitor()->get('/')->body, 'with a published translation of the home page the language is offered (hreflang)');
        $this->assertStringContainsString('/en/</loc>', $this->visitor()->get('/sitemap.xml')->body, 'with a published translation of the home page the language is in the sitemap');

        $site->mcp('save_build', ['part' => 'footer', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']], ['type' => 'language_switcher']]]]]]);
        $this->noCache();
        $body = $this->visitor()->get('/')->body;
        $this->assertStringContainsString('ka-languages-select--up ka-languages-element', $body, 'language switcher element in the footer (menu upwards)');
        $this->assertStringContainsString('hreflang="en" lang="en"', $body, 'language switcher links the translation');
        $this->assertStringContainsString('image/web.js', $body, 'web.js for the browser language');

        $site->mcp('save_build', ['part' => 'header', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'header', 'children' => [['type' => 'navigation', 'content' => ['language_switcher' => false]], ['type' => 'navigation', 'content' => ['menu' => 'footer']]]]]]]);
        $this->noCache();
        $this->assertSame(1, substr_count($this->visitor()->get('/')->body, '<nav class="ka-languages"'), 'navigation with the language switcher off has none, the other one does');

        // 2.7: a header transparent at the top and smaller after scrolling (English vocabulary)
        $site->mcp('save_build', ['part' => 'header', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'header', 'content' => ['on_scroll' => 'transparent_shrink', 'text_at_top' => 'light'],
            'style' => ['base' => ['position' => 'sticky', 'background' => 'background']], 'children' => [['type' => 'navigation', 'content' => ['mega_menu' => true]]]]]]]);
        $this->noCache();
        $body = $this->visitor()->get('/')->body;
        $this->assertMatchesRegularExpression('/<header id="s-[a-z0-9]*" class="ka-header-scroll ka-header-scroll--transparent">/', $body, 'header carries the scroll classes');
        $this->assertStringContainsString('position: sticky; background-color: var(--ka-color-background); position: fixed; top: 0; inset-inline: 0; animation: ka-header-light linear both, ka-header-smaller linear both; animation-timeline: scroll(root); animation-range: 0 120px;', $body, 'header animation follows the page scroll');
        $this->assertStringContainsString('@keyframes ka-header-light', $body, 'header keyframes');
        $this->assertStringContainsString('prefers-reduced-motion: reduce) { .ka-header-scroll { animation: none !important; } }', $body, 'reduced motion switches the header animation off');

        $site->exec("DELETE FROM ka_site_parts WHERE type = 'header'");
        $this->noCache();
        $this->assertStringNotContainsString('ka-header-', $this->visitor()->get('/')->body, 'without such a header the scroll CSS is not printed');

        $site->mcp('save_build', ['part' => 'footer', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']]]]]]]);
        $site->exec("DELETE FROM ka_pages WHERE slug = 'about-home'");
        $site->setting('home_page', '0');
    }

    // ---- 25

    #[Depends('testLanguageVersionsSwitcherAndHeaderScroll')]
    public function testSubpagesScheduleHistoryAndTemplates(): void
    {
        $site = $this->site();
        $this->savePage(['page_id' => 0, 'title' => 'Služby firmy', 'slug' => 'sluzby-firmy', 'visible' => 1, 'in_menu' => 0, 'text' => '<p>S</p>']);
        $idr = $this->idOf('sluzby-firmy');
        $this->savePage(['page_id' => 0, 'title' => 'Kuchyně', 'parent_id' => $idr, 'visible' => 1, 'in_menu' => 0, 'text' => '<p>Kuchyně na míru</p>']);
        $this->assertSame('sluzby-firmy/kuchyne', (string) $site->value('SELECT slug FROM ka_pages WHERE parent_id = ?', [$idr]), 'a subpage has an address under its parent');
        $this->noCache();
        $this->assertPage('/sluzby-firmy/kuchyne', 200, 'Kuchyně na míru', message: 'subpage on the web');

        $this->savePage(['page_id' => $idr, 'title' => 'Služby firmy', 'slug' => 'nase-sluzby', 'visible' => 1, 'in_menu' => 0, 'text' => '<p>S2</p>']);
        $this->assertSame('nase-sluzby/kuchyne', (string) $site->value('SELECT slug FROM ka_pages WHERE parent_id = ?', [$idr]), 'changing the parent address moves the subpage');
        $this->assertRedirect('/sluzby-firmy/kuchyne', '/nase-sluzby/kuchyne', 'the old address of a subpage redirects');
        $this->assertSame('<p>S</p>', (string) $site->value('SELECT text FROM ka_page_revisions WHERE page_id = ? ORDER BY revision_id DESC LIMIT 1', [$idr]), 'a text change saves the previous version');
        self::$services = $idr;

        $this->savePage(['page_id' => 0, 'title' => 'Akce', 'in_menu' => 0, 'text' => '<p>A</p>', 'publish_at' => date('Y-m-d\TH:i', strtotime('+1 day'))]);
        $this->assertSame('0/1', (string) $site->value("SELECT CONCAT(visible, '/', publish_at IS NOT NULL) FROM ka_pages WHERE slug = 'akce'"), 'a scheduled page waits hidden');
        $site->exec("UPDATE ka_pages SET publish_at = NOW() - INTERVAL 1 MINUTE WHERE slug = 'akce'");
        // the visit starts the job after its page is sent (at most once a minute); a visit that loses a race with a running job is repeated
        for ($i = 0; $i < 16; $i++) {
            $site->exec("UPDATE ka_settings SET value = '0' WHERE name = 'notification_check'");
            $this->visitor()->get('/news?x=' . random_int(1, 99999));
            if ((string) $site->value("SELECT visible FROM ka_pages WHERE slug = 'akce'") === '1') {
                break;
            }
            usleep(500_000);
        }
        $this->assertSame('1', (string) $site->value("SELECT visible FROM ka_pages WHERE slug = 'akce'"), 'a scheduled page publishes itself in time');

        $location = $this->savePage(['page_id' => 0, 'title' => 'Nabídka', 'template' => 'landing', 'visible' => 0, 'in_menu' => 0, 'text' => ''])->redirect;
        $this->assertStringContainsString('action=builder', $location, 'a new page from a template goes straight to the builder');
        $this->assertSame('1', (string) $site->value("SELECT build_draft LIKE '%\"type\":\"section\"%' FROM ka_pages WHERE slug = 'nabidka'"), 'the template builds a draft from sections');
        self::$nabidka = $this->idOf('nabidka');
        $this->assertGreaterThan(0, self::$nabidka);
    }

    #[Depends('testSubpagesScheduleHistoryAndTemplates')]
    public function testPageExportAndImportCarryClassesAndComponents(): void
    {
        $site = $this->site();
        $dir = $site->workDir('pages');
        $export = $site->admin()->get('/admin.php?module=pages&action=export&id=' . self::$nabidka)->body;
        $this->assertStringContainsString('"format": "kaleta-page"', $export, 'page export to JSON');
        file_put_contents("$dir/stranka.json", $export);
        $import = fn (string $file) => $site->admin()->upload('/admin.php?module=pages&action=import', ['_csrf' => $site->csrf()], ['file' => $file]);
        $import("$dir/stranka.json");
        $this->assertSame('0/1', (string) $site->value("SELECT CONCAT(visible, '/', build_draft IS NOT NULL) FROM ka_pages WHERE slug = 'nabidka-2'"), 'a page import creates a hidden copy with a build');

        // 1.8: the export carries the classes and components of the build (a component inside a component too)
        $d = json_decode($export, true);
        $d['title'] = 'Balíček';
        $d['build']['children'][] = ['type' => 'section', 'classes' => ['balicek-karta', 'balicek-vlastni'], 'children' => [['type' => 'component', 'content' => ['component' => '901', 'values' => []]]]];
        $d['classes'] = [['name' => 'balicek-karta', 'style' => ['base' => ['odsazeni' => 'l']], 'css' => 'color: red; behavior: url(x)'], ['name' => 'balicek-vlastni', 'style' => ['base' => ['background' => 'primary']], 'css' => '']];
        $d['components'] = [['id' => 901, 'name' => 'Balíček vnější', 'properties' => [], 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'component', 'content' => ['component' => '902']]]]]]],
            ['id' => 902, 'name' => 'Balíček vnitřní', 'properties' => [['key' => 'heading', 'label' => 'Nadpis', 'type' => 'text', 'default' => 'Ahoj']], 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'content' => ['text' => '{{nadpis}}']]]]]];
        file_put_contents("$dir/balicek.json", json_encode($d, JSON_UNESCAPED_UNICODE));
        $site->exec("INSERT INTO ka_classes (name, style, css, updated_at) VALUES ('balicek-vlastni', '{}', 'color: blue', NOW())");
        $import("$dir/balicek.json");
        self::$outer = (int) $site->value("SELECT component_id FROM ka_components WHERE name = 'Balíček vnější'");
        $inner = (int) $site->value("SELECT component_id FROM ka_components WHERE name = 'Balíček vnitřní'");

        $this->assertSame('1/0', (string) $site->value("SELECT CONCAT(css LIKE '%color: red%', '/', css LIKE '%behavior%') FROM ka_classes WHERE name = 'balicek-karta'"), 'page import creates the missing class (cleaned)');
        $this->assertSame('1:{}:color: blue', (string) $site->value("SELECT CONCAT(COUNT(*), ':', style, ':', css) FROM ka_classes WHERE name = 'balicek-vlastni'"), "page import keeps the site's own class");
        $this->assertSame('1/1', (string) $site->value("SELECT CONCAT((SELECT build LIKE ? FROM ka_components WHERE component_id = ?), '/', build_draft LIKE ?) FROM ka_pages WHERE title = 'Balíček'",
            ['%"component":"' . $inner . '"%', self::$outer, '%"component":"' . self::$outer . '"%']), 'page import creates both components and points the uses at them');

        $import("$dir/balicek.json");
        $this->assertSame('2', (string) $site->value("SELECT COUNT(*) FROM ka_components WHERE name LIKE 'Balíček%'"), 'a second import of the same page reuses the components');
        $idb = (int) $site->value("SELECT MIN(page_id) FROM ka_pages WHERE title = 'Balíček'");
        $out = json_decode($site->admin()->get('/admin.php?module=pages&action=export&id=' . $idb)->body, true);
        $this->assertSame('2|balicek-karta,balicek-vlastni,card|Balíček vnější,Balíček vnitřní',
            $out['version'] . '|' . implode(',', preg_grep('/^(balicek|card$)/', array_column($out['classes'], 'name'))) . '|' . implode(',', array_column($out['components'], 'name')),
            'the export lists the used classes and both components');
    }

    // ---- 26

    #[Depends('testPageExportAndImportCarryClassesAndComponents')]
    public function testCopyAndPasteBetweenSites(): void
    {
        $site = $this->site();
        $base = $site->base;
        $copy = $this->pageAction('build_package', self::$nabidka, ['elements' => json_encode([['type' => 'section', 'classes' => ['balicek-karta'], 'children' => [['type' => 'component', 'content' => ['component' => (string) self::$outer]]]]], JSON_UNESCAPED_UNICODE)]);
        $c = $copy->json()['clipboard'] ?? [];
        $this->assertSame("200|elements/1/$base/balicek-karta/Balíček vnější,Balíček vnitřní",
            $copy->status . '|' . ($c['kaleta'] ?? '') . '/' . ($c['v'] ?? '') . '/' . ($c['site'] ?? '') . '/' . implode(',', array_column($c['classes'] ?? [], 'name')) . '/' . implode(',', array_column($c['components'] ?? [], 'name')),
            'copy packs the elements with their classes and components for the clipboard');

        $foreign = '{"kaleta":"elements","v":1,"site":"https://jiny.example","elements":[{"id":"cizi1","type":"section","anchor":"cizi","classes":["schranka-nova","balicek-vlastni"],"children":[{"id":"cizi2","type":"image","content":{"src":"media/2026/x.jpg","alt":"x"}},{"id":"cizi3","type":"component","content":{"component":"950","values":{}}}]}],"classes":[{"name":"schranka-nova","style":{"base":{"background":"primary"}},"css":"color: red"},{"name":"balicek-vlastni","style":{},"css":"color: green"}],"components":[{"id":950,"name":"Schránka komponenta","properties":[],"build":{"v":1,"children":[{"type":"heading","content":{"text":"Ze schránky"}}]}}]}';
        $paste = $this->pageAction('build_paste', self::$nabidka, ['clipboard' => $foreign]);
        $pasted = (string) $site->value("SELECT component_id FROM ka_components WHERE name = 'Schránka komponenta'");
        $d = $paste->json();
        $p = $d['elements'][0] ?? [];
        $messages = implode(' ', $d['notes'] ?? []);
        $got = $paste->status . '|' . ($d['ok'] ? 'ok' : '') . '/' . (($p['id'] ?? '') !== 'cizi1' && preg_match('/^[a-z0-9]{3,16}$/', $p['id'] ?? '') ? 'new-id' : 'old-id') . '/' . (isset($p['anchor']) ? 'anchor' : 'no-anchor')
            . '/' . ($p['children'][0]['content']['src'] ?? '') . '/' . ($p['children'][1]['content']['component'] ?? '') . '/' . ((int) str_contains($messages, '1 ') + (int) str_contains($messages, 'https://jiny.example')) . '/' . (isset($d['classes']['schranka-nova']) ? 'class' : '');
        $this->assertSame("200|ok/new-id/no-anchor/https://jiny.example/media/2026/x.jpg/$pasted/2/class", $got, 'paste from another site: new ids, no anchor, the image points at the https source, the component use at the new component');
        $this->assertSame('color: red;|color: blue', (string) $site->value("SELECT CONCAT((SELECT css FROM ka_classes WHERE name = 'schranka-nova'), '|', (SELECT css FROM ka_classes WHERE name = 'balicek-vlastni'))"), "paste creates the missing class and keeps the site's own");
        $this->assertSame(400, $this->pageAction('build_paste', self::$nabidka, ['clipboard' => 'just some text'])->status, 'paste of plain text is refused');
        $this->assertSame(400, $site->admin()->post('/admin.php?module=pages&action=build_paste&id=' . self::$nabidka, ['clipboard' => $foreign])->status, 'paste without the form token is refused');

        $own = $this->pageAction('build_paste', self::$nabidka, ['clipboard' => '{"kaleta":"elements","v":1,"site":"' . $base . '","elements":[{"id":"svuj1","type":"heading","classes":["schranka-stejny"],"content":{"text":"Odsud"}}],"classes":[{"name":"schranka-stejny","style":{},"css":""}],"components":[]}']);
        $this->assertSame('200|1|0', $own->status . '|' . (int) str_contains($own->body, '"text":"Odsud"') . '|' . $site->value("SELECT COUNT(*) FROM ka_classes WHERE name = 'schranka-stejny'"), 'paste from this site inserts the elements without importing anything');

        // display conditions on the site: a URL parameter switches the element and takes the page out of the cache, a language version does not
        $conditions = '{"v":1,"children":[{"id":"pod1","type":"section","children":[{"id":"pod2","type":"heading","content":{"text":"Jarní sleva"},"conditions":{"url_parameter":{"name":"utm_campaign","value":"jaro"}}},{"id":"pod3","type":"heading","content":{"text":"Nur Deutsch"},"conditions":{"languages":["en"]}},{"id":"pod4","type":"heading","content":{"text":"Pro všechny"}}]}]}';
        $saved = $this->pageAction('build_save', self::$nabidka, ['build' => $conditions]);
        $this->assertStringContainsString('"conditions":{"url_parameter":{"name":"utm_campaign","value":"jaro"}}', $saved->body, 'the validator keeps the URL parameter condition');
        $this->assertStringContainsString('"conditions":{"languages":["en"]}', $saved->body, 'the validator keeps the language condition');
        $this->pageAction('build_publish', self::$nabidka);
        $site->exec('UPDATE ka_pages SET visible = 1 WHERE page_id = ?', [self::$nabidka]);
        $this->noCache();
        $with = $this->visitor()->get('/nabidka?utm_campaign=jaro')->body;
        $this->assertStringContainsString('Jarní sleva', $with, 'the element shows with ?utm_campaign=jaro');
        $this->assertStringNotContainsString('Nur Deutsch', $with, 'the element for another language version is not shown');
        $this->assertStringContainsString('Pro všechny', $with, 'an element without conditions is shown');
        $other = $this->visitor()->get('/nabidka?utm_campaign=podzim')->body;
        $this->assertStringNotContainsString('Jarní sleva', $other, 'another value of the parameter hides the element');
        $this->assertStringContainsString('Pro všechny', $other, 'another value: an element without conditions is shown');
        $this->assertStringNotContainsString('Jarní sleva', $this->visitor()->get('/nabidka')->body, 'without the parameter the element is not on the page');
        $this->assertSame(0, $this->cachedPages(), 'a page with a URL parameter condition stays out of the page cache');

        $this->pageAction('build_save', self::$nabidka, ['build' => '{"v":1,"children":[{"id":"pod1","type":"section","children":[{"id":"pod3","type":"heading","content":{"text":"Nur Deutsch"},"conditions":{"languages":["en"]}},{"id":"pod4","type":"heading","content":{"text":"Pro všechny"}}]}]}']);
        $this->pageAction('build_publish', self::$nabidka);
        $this->noCache();
        $this->visitor()->get('/nabidka');
        $this->assertSame(1, $this->cachedPages(), 'a page with only a language condition is cached (each language version has its own address)');
    }

    // ---- 27

    #[Depends('testSubpagesScheduleHistoryAndTemplates')]
    public function testBuilderCustomCssAttributesAnimationMySectionsAndClassRename(): void
    {
        $site = $this->site();
        $idv = $this->idOf('nase-sluzby');
        // the class 'karta' of the build (the old run had it from the builder section)
        $this->pageAction('build_class', $idv, ['name' => 'karta', 'style' => '{"base":{"background":"surface","padding_y":"l"}}', 'css' => 'letter-spacing: 0.01em']);

        $stavba = '{"v":1,"children":[{"id":"sv1","type":"section","classes":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","attributes":{"data-track":"cta","onclick":"x"},"style":{"base":{"animation":"ka-slide-in","gradient":"linear-gradient(135deg, var(--ka-color-primary), var(--ka-color-secondary))","margin_left":"auto"},"active":{"opacity":"0.8"}},"children":[{"type":"heading","content":{"text":"Test"}}]}]}';
        $saved = $this->pageAction('build_save', $idv, ['build' => $stavba]);
        $this->assertStringContainsString('Disallowed declaration', $saved->body, 'custom CSS of an element is cleaned');
        $this->assertStringContainsString('An attribute can only be', $saved->body, 'element attributes are cleaned');
        $this->pageAction('build_publish', $idv);
        $this->noCache();
        $body = $this->visitor()->get('/nase-sluzby')->body;
        $this->assertStringContainsString('data-track="cta"', $body, 'allowed attribute on the web');
        $this->assertStringNotContainsString('onclick="x"', $body, 'onclick is not printed');
        $this->assertStringContainsString('backdrop-filter: blur(4px)', $body, 'custom CSS on the web');
        $this->assertStringContainsString('animation-timeline: view()', $body, 'animation on the web');
        $this->assertStringContainsString('@keyframes ka-slide-in', $body, 'animation keyframes');
        $this->assertStringContainsString(':active {', $body, 'pressed state');
        $this->assertStringContainsString('margin-inline-start: auto', $body, 'left margin');

        $section = $this->pageAction('build_save_section', $idv, ['name' => 'Moje karta', 'element' => '{"type":"section","children":[{"type":"heading","content":{"text":"Z knihovny"}}]}']);
        $this->assertSame(200, $section->status, 'saving to my sections');
        $this->assertStringContainsString('"name":"Moje karta"', $section->body, 'my section in the list');
        $usage = $this->pageAction('build_class', $idv, ['name' => 'karta', 'usage' => 1]);
        $this->assertStringContainsString('Služby firmy', $usage->body, 'class usage overview');
        $this->assertSame(200, $this->pageAction('build_class', $idv, ['name' => 'karta', 'new_name' => 'karta-sluzby'])->status, 'class rename');
        $this->assertSame('1', (string) $site->value('SELECT build LIKE \'%"karta-sluzby"%\' AND build NOT LIKE \'%"karta"%\' FROM ka_pages WHERE page_id = ?', [$idv]), 'the renamed class in the builds');
    }
}
