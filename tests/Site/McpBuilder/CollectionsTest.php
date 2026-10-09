<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Collections: fields, items, listing element, item pages, MCP, several languages, filters (was: section 13 "kolekce"). */
#[Group('site')]
final class CollectionsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private static int $idk = 0;
    private static int $idTym = 0;

    private function saveItem(array $fields): void
    {
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => self::$idk, 'item_id' => 0] + $fields);
    }

    private function listBuild(array $children): array
    {
        return ['id' => $this->zPage(), 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => $children]]]];
    }

    public function testCollectionIsCreatedWithItsFields(): void
    {
        $this->zPage();
        $this->assertPage('/admin.php?module=collections', 200, 'Kolekce', message: 'collections');
        $this->adminPost('/admin.php?module=collections&action=save', [
            'collection_id' => 0, 'name' => 'Tým', 'detail' => 1,
            'fields' => [['popisek' => 'Funkce', 'type' => 'text'], ['popisek' => 'Foto', 'type' => 'image'], ['popisek' => 'Medailonek', 'type' => 'html']],
        ], '/admin.php?module=collections');
        self::$idk = (int) $this->site()->value("SELECT collection_id FROM ka_collections WHERE slug = 'tym'");

        $this->assertSame('1', (string) $this->site()->value("SELECT fields LIKE '%\"funkce\"%' AND fields LIKE '%\"medailonek\"%' FROM ka_collections WHERE collection_id = ?", [self::$idk]), 'collection created with fields');
    }

    public function testItemsAndTheListingElementOnAPage(): void
    {
        $this->saveItem(['name' => 'Jana Nováková', 'data' => ['features' => 'Jednatelka', 'medailonek' => '<p>Dvacet let <b>v oboru</b>.</p><script>x</script>'], 'sort_order' => 1, 'visible' => 1]);
        $this->saveItem(['name' => 'Skrytý Člen', 'data' => ['features' => 'Tajný'], 'sort_order' => 2]);
        $this->assertPage('/admin.php?module=collections&action=items&id=' . self::$idk, 200, 'Jana Nováková', message: 'collection items');

        $text = $this->rawText('stavba_uloz', $this->listBuild([['id' => 'smy1', 'type' => 'collection_list', 'content' => ['collection' => 'tym'], 'children' => [
            ['id' => 'kar1', 'type' => 'container', 'style' => ['base' => ['background' => 'surface']], 'children' => [
                ['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{name}}']],
                ['type' => 'text', 'content' => ['html' => '<p>{{funkce}}</p>{{medailonek}}']],
                ['type' => 'button', 'content' => ['text' => 'Profil', 'link' => '{{url}}']],
            ]],
        ]]]));
        $this->assertStringContainsString('publikováno', $text, 'MCP page with a collection listing is published');
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');
        $this->assertStringContainsString('<h3>Jana Nováková</h3>', $body, 'name filled in');
        $this->assertStringContainsString('<p>Jednatelka</p>', $body, 'text field filled in');
        $this->assertMatchesRegularExpression('/^<p>Dvacet let <b>v oboru<\/b>\.<\/p>/m', $body, 'html field filled in and cleaned');
        $this->assertStringContainsString('href="/tym/jana-novakova"', $body, 'link to the item page');
        $this->assertStringNotContainsString('Skrytý', $body, 'only published items');
        $this->assertStringContainsString('class="s-kar1"', $body, 'repeated element styled through a class');
        $this->assertStringNotContainsString('id="s-kar1"', $body, 'no duplicate ids');
        $this->assertStringContainsString('.s-kar1 { background-color', $body, 'the class carries the style');
        $this->assertStringNotContainsString('<script>x', $body, 'script removed from the html field');
    }

    public function testItemPages(): void
    {
        $this->assertPage('/tym/jana-novakova', 200, 'Jednatelka', message: 'item detail');
        $this->assertPage('/tym/jana-novakova', 200, '<h1>Jana Nováková</h1>', message: 'detail has the item heading');
        $this->assertSame(404, $this->visitor()->get('/tym/skryty-clen')->status, 'a hidden item has no detail');
        $this->assertPage('/sitemap.xml', 200, '/tym/jana-novakova', message: 'sitemap has the item page');
        $this->assertPage('/admin.php?module=collections&action=builder&id=' . self::$idk, 200, 'id="stavitel-data"', message: 'item template in the builder');
    }

    public function testMcpCollectionTools(): void
    {
        $this->assertStringContainsString('"kolekce":"tym"', $this->mcpText('seznam_kolekci'), 'MCP lists the collection');
        $this->assertStringContainsString('medailonek', $this->mcpText('seznam_kolekci'), 'MCP lists its fields');

        $this->site()->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Petr Svoboda', 'data' => ['features' => 'Mistr truhlář'], 'visible' => true]);
        $this->site()->mcp('save_collection_item', ['collection' => 'tym', 'name' => 'Text JSON', 'values' => '{"features":"Z textu"}']);
        $this->assertStringContainsString('Text JSON', $this->mcpText('seznam_polozek_kolekce', ['kolekce' => 'tym', 'pole' => 'features', 'value' => 'Z textu']), 'data sent as a JSON text are saved');

        $this->assertStringContainsString('musí být seznam', $this->mcpText('uloz_menu', ['location' => 'main', 'items' => 'nejde precist']), 'unreadable menu items are an error, the menu does not fall back to automatic');
        $this->assertStringContainsString('unknown_parameters', $this->mcpText('save_classes', ['classes' => [['name' => 'x']]]), 'an unknown parameter is in the result, not silently dropped');
        $this->assertStringContainsString('musí být objekt', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Spatna data', 'data' => 'funkce=x']), 'unreadable item data are an error');
        $this->assertStringContainsString('tym/zdenek', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zdenek Zeman', 'adresa' => 'zdenek', 'visible' => true]), 'own address of an item');
    }

    public function testItemTemplateDraftPreviewAndPublishing(): void
    {
        $build = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profil: {{name}}']]]]]];
        $preview = (string) ($this->mcpData('stavba_uloz', ['kolekce' => 'tym', 'build' => $build])['nahled'] ?? '');

        $this->assertNotSame('', $preview, 'a signed preview link is returned');
        $this->assertStringContainsString('Profil: ', $this->visit($preview), 'the item template as a draft with a signed preview');
        $this->assertStringNotContainsString('Profil: ', $this->visit('/tym/zdenek'), 'the visitor does not see the draft template');

        $this->site()->mcp('publikuj_stavbu', ['kolekce' => 'tym']);
        $this->site()->clearPageCache();
        $this->assertPage('/tym/zdenek', 200, 'Profil: Zdenek Zeman', message: 'published item template');
        $this->assertPage('/llms.txt', 200, '/tym/zdenek', message: 'llms.txt lists collection items with a detail');
    }

    public function testRelatedItems(): void
    {
        $this->site()->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zuzana Zelena', 'data' => ['features' => 'Jednatelka'], 'visible' => true]);
        $this->site()->mcp('stavba_uloz', ['kolekce' => 'tym', 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profil: {{name}}']],
            ['type' => 'collection_list', 'content' => ['collection' => 'tym', 'filter_field' => 'features', 'filter_value' => '{{funkce}}', 'exclude_current' => true], 'children' => [
                ['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => 'Kolega: {{name}}']],
            ]],
        ]]]]]);
        $this->site()->clearPageCache();

        $body = $this->visit('/tym/jana-novakova');

        $this->assertStringContainsString('Kolega: Zuzana Zelena', $body, 'the colleague with the same function is related');
        $this->assertStringNotContainsString('Kolega: Jana', $body, 'not the item itself');
        $this->assertStringNotContainsString('Kolega: Petr', $body, 'not an item with another function');
        $this->assertStringNotContainsString('Kolega: Zuzana', $this->visit('/tym/petr-svoboda'), 'the filter follows the shown item');
    }

    public function testCollectionInSeveralLanguages(): void
    {
        $site = $this->site();
        // earlier old sections had switched the English version on and published the English home page (language links appear only then)
        $site->setting('additional_languages', 'en');
        // ... and set the logo (section 10) and a header with the logo element (section 11)
        $site->setting('logo', 'image/kaleta-logo.svg');
        $site->mcp('stavba_uloz', ['part' => 'hlavicka', 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'header', 'children' => [['type' => 'logo']]]]]]);
        $site->mcp('vytvor_stranku', ['title' => 'Home', 'adresa' => 'home-en', 'language' => 'en', 'translation_of' => (int) $site->settingValue('home_page'), 'text' => '<p>Home</p>', 'visible' => true]);
        $site->mcp('vytvor_stranku', ['title' => 'Náš tým', 'adresa' => 'tym', 'text' => '<p>Tým</p>', 'visible' => true]);
        self::$idTym = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'tym'");
        $site->mcp('vytvor_stranku', ['title' => 'Our team', 'adresa' => 'team', 'language' => 'en', 'translation_of' => self::$idTym, 'text' => '<p>Team</p>', 'visible' => true]);

        $first = $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zdenek Zeman EN', 'adresa' => 'zdenek', 'language' => 'en', 'data' => ['features' => 'Workshop lead'], 'visible' => true]);
        $this->assertStringContainsString('en/tym/zdenek"', $first, 'the translation of an item keeps the same slug');
        $this->assertStringContainsString('tym/zdenek-2', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Druhy Zdenek', 'adresa' => 'zdenek', 'language' => 'en']), 'the address is unique within a language');

        $enBuild = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'breadcrumbs'], ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profile: {{name}}']]]]]];
        $site->mcp('stavba_uloz', ['kolekce' => 'tym', 'language' => 'en', 'build' => $enBuild]);
        $site->clearPageCache();
        $this->assertStringContainsString('Profil: Zdenek Zeman EN', $this->visit('/en/tym/zdenek'), 'a language without its own template uses the default language template, the draft is hidden');

        $site->mcp('publikuj_stavbu', ['kolekce' => 'tym', 'language' => 'en']);
        $site->mcp('stavba_uloz', ['kolekce' => 'tym', 'language' => 'en', 'publikovat' => true, 'build' => $enBuild]);
        $site->clearPageCache();
        $body = $this->visit('/en/tym/zdenek');
        $this->assertStringContainsString('<h1>Profile: Zdenek Zeman EN</h1>', $body, 'the item template of the language');
        $this->assertStringContainsString('href="/en/team">Our team</a>', $body, 'breadcrumbs lead through the translation of the hub page');
        $this->assertMatchesRegularExpression('/hreflang="cs" href="[^"]*\/tym\/zdenek"/', $body, 'hreflang to the Czech item');
        $cs = $this->visit('/tym/zdenek');
        $this->assertStringContainsString('Profil: Zdenek Zeman<', $cs, 'the Czech item keeps its template');
        $this->assertMatchesRegularExpression('/hreflang="en" href="[^"]*\/en\/tym\/zdenek"/', $cs, 'hreflang to the English item');
        $this->assertMatchesRegularExpression('/class="logo"[^>]*><img src="\/image\/kaleta-logo.svg"/', $body, 'the logo of the language version');
        $this->assertStringNotContainsString('src="/en/image/', $body, 'images of the template get no language prefix');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_build_revisions WHERE part = ?", ['kolekce:' . self::$idk . ':en']), 'versions of the language template are kept apart');
        $this->assertStringContainsString('en/team', $this->mcpText('seznam_stranek'), 'the page list shows the address with the language prefix');
        $this->assertPage('/admin.php?module=collections&action=builder&id=' . self::$idk . '&language=en', 200, 'en/tym/zdenek', message: 'language item template in the builder');
    }

    public function testTranslatingAPageFromTheBuild(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('potřebuje preklad_z', $this->mcpText('vytvor_stranku', ['title' => 'Bez originalu', 'adresa' => 'bez-originalu', 'kopie_stavby' => true]), 'a build copy needs the original');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_pages WHERE slug = 'bez-originalu'"), 'a build copy without the original creates no page');

        $site->mcp('vytvor_stranku', ['title' => 'From HTML', 'adresa' => 'from-html', 'language' => 'en', 'translation_of' => $this->zPage(), 'kopie_stavby' => true]);
        $idEn = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'from-html'");
        $this->assertSame('1', (string) $site->value('SELECT n.build_draft = COALESCE(o.build_draft, o.build) FROM ka_pages n JOIN ka_pages o ON o.page_id = n.translation_of WHERE n.page_id = ?', [$idEn]), 'the translation starts as a copy of the original build');

        $texts = $this->mcpText('get_build', ['id' => $idEn, 'texts_only' => true]);
        $this->assertStringContainsString('texts', $texts, 'only texts');
        $this->assertStringContainsString('{{name}}', $texts, 'the texts of the build');
        $this->assertStringNotContainsString('"build"', $texts, 'without the structure');
        $this->assertStringNotContainsString('"kolekce":"tym"', $texts, 'without technical fields');
    }

    public function testMcpSmallThingsAndHousekeeping(): void
    {
        $site = $this->site();
        $this->assertStringMatchesFormat('%Anezname_klice%Anazev%A', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Klic navic', 'data' => ['features' => 'x', 'nazev' => 'Jinak']]), 'a key the collection does not have is in the result');

        $site->mcp('vytvor_stranku', ['title' => 'Skryta textem', 'adresa' => 'skryta-textem', 'visible' => 'false']);
        $this->assertSame('0', (string) $site->value("SELECT visible FROM ka_pages WHERE slug = 'skryta-textem'"), 'zobrazit sent as the text "false" keeps the page hidden');

        // the default footer exists (the old section 11 had saved one)
        $site->mcp('stavba_uloz', ['part' => 'footer', 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']]]]]]]);
        $site->mcp('stavba_nacti', ['part' => 'footer', 'language' => 'en']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_site_parts WHERE type = 'paticka' AND language = 'en'"), 'reading a part that does not exist yet creates nothing');
        $site->mcp('stavba_uprav', ['part' => 'footer', 'language' => 'en', 'operace' => []]);
        $this->assertSame('1', (string) $site->value("SELECT e.build_draft = COALESCE(c.build_draft, c.build) FROM ka_site_parts e JOIN ka_site_parts c ON c.type = e.type AND c.language = '' AND c.variant = '' WHERE e.type = 'paticka' AND e.language = 'en' AND e.variant = ''"), 'the footer of a new language starts as a copy of the default footer');

        $site->setting('tasks_token', 'testtoken123');
        $site->exec("INSERT INTO ka_consents (visitor_token, created_at, categories) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NOW() - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW(), 'nic')");
        $site->runTasks();
        $this->assertSame('b', (string) $site->value("SELECT GROUP_CONCAT(LEFT(visitor_token, 1) ORDER BY visitor_token) FROM ka_consents WHERE visitor_token IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')"), 'the clean-up deletes old cookie consent records');
    }

    private function migrate(): string
    {
        return (string) shell_exec('cd ' . escapeshellarg($this->site()->root) . ' && ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1');
    }

    private function pendingTables(): string
    {
        return (string) $this->site()->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ka_test_pending'");
    }

    /** Migrations never run on a page request: a pending one leaves the site up, the administration says so, bin/migrate applies it. */
    public function testPendingMigrationIsAppliedOnlyByBinMigrate(): void
    {
        $site = $this->site();
        $this->assertSame((string) count(glob($site->path('system/database/migrations/[0-9]*_*.php'))), (string) $site->value('SELECT COUNT(*) FROM ka_migrations'), 'the installation recorded all migrations in ka_migrations');
        $this->assertStringContainsString('up to date', $this->migrate(), 'bin/migrate: nothing pending, the second run does nothing');

        $file = $site->path('system/database/migrations/20991231000000_test_pending.php');
        file_put_contents($file, <<<'PHP'
<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;
final class TestPending extends AbstractMigration
{
    public function change(): void
    {
        $this->table('test_pending', ['id' => false, 'primary_key' => ['n']])->addColumn('n', 'integer', ['null' => false])->create();
    }
}
PHP);
        try {
            $site->clearPageCache();
            $this->assertPage('/', 200, message: 'a pending migration does not take the site down');
            $this->assertSame('0', $this->pendingTables(), 'a pending migration is not run by a request');
            $this->assertPage('/admin.php', 200, 'php bin/migrate', message: 'the administration reports the pending migration');
            $this->assertStringContainsString('20991231000000_test_pending.php', $this->migrate(), 'bin/migrate applies the pending migration');
            $this->assertSame('1', $this->pendingTables(), 'the migration created the prefixed table');
        } finally {
            unlink($file);
        }
    }

    public function testCollectionEditKeepsFieldsAndListingFiltersAndPaginates(): void
    {
        $site = $this->site();
        $text = $this->mcpText('uprav_kolekci', ['kolekce' => 'tym', 'nazev' => 'Nas tym']);
        $this->assertStringContainsString('Nas tym', $text, 'MCP: the collection is renamed');
        $this->assertStringContainsString('medailonek', $text, 'MCP: editing a collection keeps the fields');

        $site->clearPageCache();
        $this->assertPage('/z-html', 200, 'Mistr truhlář', message: 'a new item from MCP is in the listing');

        $this->site()->mcp('stavba_uloz', $this->listBuild([['id' => 'vyp1', 'type' => 'collection_list',
            'content' => ['collection' => 'tym', 'count' => 1, 'sort' => 'name', 'filter_field' => 'features', 'filters' => true, 'pagination' => true],
            'children' => [['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{name}}']]]]]));
        $site->clearPageCache();

        $body = $this->visit('/z-html');
        preg_match_all('/<h3>[^<]*<\/h3>/', $body, $headings);
        $this->assertSame('<h3>Jana Nováková</h3>', implode('', $headings[0]), 'the listing shows one item, sorted by name');
        $this->assertStringContainsString('href="/z-html?s-vyp1=2"', $body, 'pagination');
        $this->assertStringContainsString('href="/z-html" aria-current="true">Vše', $body, 'the filter button for all');
        $this->assertStringContainsString('f-vyp1=Mistr', $body, 'filter buttons by field value');

        $this->assertPage('/z-html?s-vyp1=2', 200, '<h3>Petr Svoboda</h3>', message: 'the second page of the listing');

        $filtered = $this->visit('/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99');
        $this->assertStringContainsString('<h3>Petr Svoboda</h3>', $filtered, 'visitor filter shows the match');
        $this->assertStringNotContainsString('<h3>Jana', $filtered, 'visitor filter hides the rest');
        $this->assertStringContainsString('aria-current="true">Mistr truhlář', $filtered, 'the active filter is marked');
    }
}
