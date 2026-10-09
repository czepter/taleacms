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
        $this->adminPost('/admin.php?module=collections&action=save_item', ['idk' => self::$idk, 'idp' => 0] + $fields);
    }

    private function listBuild(array $children): array
    {
        return ['id' => $this->zPage(), 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => $children]]]];
    }

    public function testCollectionIsCreatedWithItsFields(): void
    {
        $this->zPage();
        $this->assertPage('/admin.php?module=collections', 200, 'Kolekce', message: 'collections');
        $this->adminPost('/admin.php?module=collections&action=save', [
            'idk' => 0, 'nazev' => 'Tým', 'detail' => 1,
            'pole' => [['popisek' => 'Funkce', 'typ' => 'text'], ['popisek' => 'Foto', 'typ' => 'obrazek'], ['popisek' => 'Medailonek', 'typ' => 'html']],
        ], '/admin.php?module=collections');
        self::$idk = (int) $this->site()->value("SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'");

        $this->assertSame('1', (string) $this->site()->value("SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = ?", [self::$idk]), 'collection created with fields');
    }

    public function testItemsAndTheListingElementOnAPage(): void
    {
        $this->saveItem(['nazev' => 'Jana Nováková', 'data' => ['funkce' => 'Jednatelka', 'medailonek' => '<p>Dvacet let <b>v oboru</b>.</p><script>x</script>'], 'poradi' => 1, 'zobrazit' => 1]);
        $this->saveItem(['nazev' => 'Skrytý Člen', 'data' => ['funkce' => 'Tajný'], 'poradi' => 2]);
        $this->assertPage('/admin.php?module=collections&action=items&id=' . self::$idk, 200, 'Jana Nováková', message: 'collection items');

        $text = $this->rawText('stavba_uloz', $this->listBuild([['id' => 'smy1', 'typ' => 'kolekce', 'obsah' => ['kolekce' => 'tym'], 'deti' => [
            ['id' => 'kar1', 'typ' => 'kontejner', 'styl' => ['zaklad' => ['pozadi' => 'plocha']], 'deti' => [
                ['typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => '{{nazev}}']],
                ['typ' => 'text', 'obsah' => ['html' => '<p>{{funkce}}</p>{{medailonek}}']],
                ['typ' => 'tlacitko', 'obsah' => ['text' => 'Profil', 'odkaz' => '{{url}}']],
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

        $this->site()->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Petr Svoboda', 'data' => ['funkce' => 'Mistr truhlář'], 'zobrazit' => true]);
        $this->site()->mcp('save_collection_item', ['collection' => 'tym', 'name' => 'Text JSON', 'values' => '{"funkce":"Z textu"}']);
        $this->assertStringContainsString('Text JSON', $this->mcpText('seznam_polozek_kolekce', ['kolekce' => 'tym', 'pole' => 'funkce', 'hodnota' => 'Z textu']), 'data sent as a JSON text are saved');

        $this->assertStringContainsString('musí být seznam', $this->mcpText('uloz_menu', ['umisteni' => 'hlavni', 'polozky' => 'nejde precist']), 'unreadable menu items are an error, the menu does not fall back to automatic');
        $this->assertStringContainsString('unknown_parameters', $this->mcpText('save_classes', ['classes' => [['name' => 'x']]]), 'an unknown parameter is in the result, not silently dropped');
        $this->assertStringContainsString('musí být objekt', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Spatna data', 'data' => 'funkce=x']), 'unreadable item data are an error');
        $this->assertStringContainsString('tym/zdenek', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zdenek Zeman', 'adresa' => 'zdenek', 'zobrazit' => true]), 'own address of an item');
    }

    public function testItemTemplateDraftPreviewAndPublishing(): void
    {
        $build = ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Profil: {{nazev}}']]]]]];
        $preview = (string) ($this->mcpData('stavba_uloz', ['kolekce' => 'tym', 'stavba' => $build])['nahled'] ?? '');

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
        $this->site()->mcp('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zuzana Zelena', 'data' => ['funkce' => 'Jednatelka'], 'zobrazit' => true]);
        $this->site()->mcp('stavba_uloz', ['kolekce' => 'tym', 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [
            ['typ' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Profil: {{nazev}}']],
            ['typ' => 'kolekce', 'obsah' => ['kolekce' => 'tym', 'filtr_pole' => 'funkce', 'filtr_hodnota' => '{{funkce}}', 'bez_aktualni' => true], 'deti' => [
                ['typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => 'Kolega: {{nazev}}']],
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
        $site->mcp('stavba_uloz', ['cast' => 'hlavicka', 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'znacka' => 'header', 'deti' => [['typ' => 'logo']]]]]]);
        $site->mcp('vytvor_stranku', ['titulek' => 'Home', 'adresa' => 'home-en', 'jazyk' => 'en', 'preklad_z' => (int) $site->settingValue('home_page'), 'text' => '<p>Home</p>', 'zobrazit' => true]);
        $site->mcp('vytvor_stranku', ['titulek' => 'Náš tým', 'adresa' => 'tym', 'text' => '<p>Tým</p>', 'zobrazit' => true]);
        self::$idTym = (int) $site->value("SELECT ids FROM ka_stranky WHERE seo_link = 'tym'");
        $site->mcp('vytvor_stranku', ['titulek' => 'Our team', 'adresa' => 'team', 'jazyk' => 'en', 'preklad_z' => self::$idTym, 'text' => '<p>Team</p>', 'zobrazit' => true]);

        $first = $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Zdenek Zeman EN', 'adresa' => 'zdenek', 'jazyk' => 'en', 'data' => ['funkce' => 'Workshop lead'], 'zobrazit' => true]);
        $this->assertStringContainsString('en/tym/zdenek"', $first, 'the translation of an item keeps the same slug');
        $this->assertStringContainsString('tym/zdenek-2', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Druhy Zdenek', 'adresa' => 'zdenek', 'jazyk' => 'en']), 'the address is unique within a language');

        $enBuild = ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'drobecky'], ['typ' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Profile: {{nazev}}']]]]]];
        $site->mcp('stavba_uloz', ['kolekce' => 'tym', 'jazyk' => 'en', 'stavba' => $enBuild]);
        $site->clearPageCache();
        $this->assertStringContainsString('Profil: Zdenek Zeman EN', $this->visit('/en/tym/zdenek'), 'a language without its own template uses the default language template, the draft is hidden');

        $site->mcp('publikuj_stavbu', ['kolekce' => 'tym', 'jazyk' => 'en']);
        $site->mcp('stavba_uloz', ['kolekce' => 'tym', 'jazyk' => 'en', 'publikovat' => true, 'stavba' => $enBuild]);
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
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = ?", ['kolekce:' . self::$idk . ':en']), 'versions of the language template are kept apart');
        $this->assertStringContainsString('en/team', $this->mcpText('seznam_stranek'), 'the page list shows the address with the language prefix');
        $this->assertPage('/admin.php?module=collections&action=builder&id=' . self::$idk . '&language=en', 200, 'en/tym/zdenek', message: 'language item template in the builder');
    }

    public function testTranslatingAPageFromTheBuild(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('potřebuje preklad_z', $this->mcpText('vytvor_stranku', ['titulek' => 'Bez originalu', 'adresa' => 'bez-originalu', 'kopie_stavby' => true]), 'a build copy needs the original');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'bez-originalu'"), 'a build copy without the original creates no page');

        $site->mcp('vytvor_stranku', ['titulek' => 'From HTML', 'adresa' => 'from-html', 'jazyk' => 'en', 'preklad_z' => $this->zPage(), 'kopie_stavby' => true]);
        $idEn = (int) $site->value("SELECT ids FROM ka_stranky WHERE seo_link = 'from-html'");
        $this->assertSame('1', (string) $site->value('SELECT n.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba) FROM ka_stranky n JOIN ka_stranky o ON o.ids = n.preklad_z WHERE n.ids = ?', [$idEn]), 'the translation starts as a copy of the original build');

        $texts = $this->mcpText('get_build', ['id' => $idEn, 'texts_only' => true]);
        $this->assertStringContainsString('texts', $texts, 'only texts');
        $this->assertStringContainsString('{{nazev}}', $texts, 'the texts of the build');
        $this->assertStringNotContainsString('"build"', $texts, 'without the structure');
        $this->assertStringNotContainsString('"kolekce":"tym"', $texts, 'without technical fields');
    }

    public function testMcpSmallThingsAndHousekeeping(): void
    {
        $site = $this->site();
        $this->assertStringMatchesFormat('%Anezname_klice%Anazev%A', $this->mcpText('uloz_polozku_kolekce', ['kolekce' => 'tym', 'nazev' => 'Klic navic', 'data' => ['funkce' => 'x', 'nazev' => 'Jinak']]), 'a key the collection does not have is in the result');

        $site->mcp('vytvor_stranku', ['titulek' => 'Skryta textem', 'adresa' => 'skryta-textem', 'zobrazit' => 'false']);
        $this->assertSame('0', (string) $site->value("SELECT zobrazit FROM ka_stranky WHERE seo_link = 'skryta-textem'"), 'zobrazit sent as the text "false" keeps the page hidden');

        // the default footer exists (the old section 11 had saved one)
        $site->mcp('stavba_uloz', ['cast' => 'paticka', 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'znacka' => 'footer', 'deti' => [['typ' => 'udaje', 'obsah' => ['udaj' => 'copyright']]]]]]]);
        $site->mcp('stavba_nacti', ['cast' => 'paticka', 'jazyk' => 'en']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en'"), 'reading a part that does not exist yet creates nothing');
        $site->mcp('stavba_uprav', ['cast' => 'paticka', 'jazyk' => 'en', 'operace' => []]);
        $this->assertSame('1', (string) $site->value("SELECT e.stavba_koncept = COALESCE(c.stavba_koncept, c.stavba) FROM ka_casti e JOIN ka_casti c ON c.typ = e.typ AND c.jazyk = '' AND c.varianta = '' WHERE e.typ = 'paticka' AND e.jazyk = 'en' AND e.varianta = ''"), 'the footer of a new language starts as a copy of the default footer');

        $site->setting('tasks_token', 'testtoken123');
        $site->exec("INSERT INTO ka_souhlasy (id_souhlasu, cas, kategorie) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NOW() - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW(), 'nic')");
        $site->runTasks();
        $this->assertSame('b', (string) $site->value("SELECT GROUP_CONCAT(LEFT(id_souhlasu, 1) ORDER BY id_souhlasu) FROM ka_souhlasy WHERE id_souhlasu IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')"), 'the clean-up deletes old cookie consent records');
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

        $this->site()->mcp('stavba_uloz', $this->listBuild([['id' => 'vyp1', 'typ' => 'kolekce',
            'obsah' => ['kolekce' => 'tym', 'pocet' => 1, 'razeni' => 'nazev', 'filtr_pole' => 'funkce', 'filtry' => true, 'strankovani' => true],
            'deti' => [['typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => '{{nazev}}']]]]]));
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
