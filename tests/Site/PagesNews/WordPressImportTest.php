<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\PagesNews;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Import from WordPress (posts, pages, SEO plugin data, a custom post type as a collection, repeat import) and the content export
 * (was: section 21 of tools/test.sh).
 */
#[Group('site')]
final class WordPressImportTest extends SiteTestCase
{
    private const string TRANSFER = '/admin.php?module=transfer';
    private static string $lastBatch = '';

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 3) . '/tools/fixtures/' . $name;
    }

    private function visitor(): Http
    {
        return $this->site()->client('visitor');
    }

    private function upload(string $file): void
    {
        $this->site()->admin()->upload(self::TRANSFER . '&action=upload', ['_csrf' => $this->site()->csrf()], ['file' => $this->fixture($file)]);
    }

    private function batch(string $file): Response
    {
        $response = $this->adminPost(self::TRANSFER . '&action=progress&file=' . $file);
        self::$lastBatch = $response->body;

        return $response;
    }

    /** Preview (reading the file), options, import; the sample files fit into one batch. */
    private function runImport(string $file, array $options): void
    {
        $this->batch($file);
        $this->adminPost(self::TRANSFER . '&action=run', ['file' => $file] + $options);
        $this->batch($file);
    }

    private function select(string $file): void
    {
        $this->adminPost(self::TRANSFER . '&action=select', ['file' => $file]);
    }

    private function seo(string $sql): string
    {
        return (string) $this->site()->value($sql);
    }

    public function testImportOfAWordPressExport(): void
    {
        $this->assertPage(self::TRANSFER, 200, 'WordPress', message: 'import and export');
        $this->upload('wordpress-sample.xml');
        $this->batch('wordpress-sample.xml');
        $this->assertPage(self::TRANSFER . '&action=preview&file=wordpress-sample.xml', 200, 'nav_menu_item', message: 'the preview warns about a type that cannot be converted');
        $this->assertPage(self::TRANSFER . '&action=preview&file=wordpress-sample.xml', 200, 'Rank Math', message: 'the preview reports SEO data of plugins');
        $this->runImport('wordpress-sample.xml', ['drafts' => 1, 'pages' => 1, 'builder' => 1, 'redirects' => 1, 'category' => 0]);
        $this->assertStringContainsString('The content import is finished', self::$lastBatch, 'the import finished');
    }

    #[Depends('testImportOfAWordPressExport')]
    public function testImportedContentIsOnTheSite(): void
    {
        $this->assertPage('/news/lavka-pres-bystrinu', 200, 'Lávka přes Bystřinu', message: 'imported news'); // check-english: allow (Czech WordPress fixture)
        $this->assertPage('/news/lavka-pres-bystrinu', 200, 'class="gallery"', message: 'imported news – gallery and video');
        $this->assertPage('/o-zpravodaji', 200, 'Kontakt', message: 'imported page'); // check-english: allow (Czech WordPress fixture)
        $this->assertPage('/o-zpravodaji', 200, '<main id="main" class="build">', message: 'the imported page is in the builder at once');
        $this->assertPage('/o-zpravodaji', 200, '<h1>O zpravodaji</h1>', message: 'the imported page has the WordPress heading');
    }

    #[Depends('testImportOfAWordPressExport')]
    public function testSeoDataOfPluginsIsImported(): void
    {
        $this->assertSame('1|Po roce oprav se lávka v Horní Lhotě otevřela chodcům i cyklistům.|0', // check-english: allow (Czech WordPress fixture)
            $this->seo("SELECT CONCAT(seo_title LIKE 'Lávka přes Bystřinu znovu otevřena – %', '|', seo_description, '|', noindex) FROM ka_news WHERE slug = 'lavka-pres-bystrinu'"), // check-english: allow (Czech WordPress fixture)
            'SmartCrawl: title with the site name, description, no noindex');
        $this->assertSame('|Rekordní slavnosti sýra: tři tisíce lidí a vítězná farma z Dolní Lhoty.|1', // check-english: allow (Czech WordPress fixture)
            $this->seo("SELECT CONCAT(seo_title, '|', seo_description, '|', noindex) FROM ka_news WHERE slug = 'slavnosti-syra'"),
            'Yoast: the default title pattern is not imported, description and noindex are');
        $this->assertSame('1|1',
            $this->seo("SELECT CONCAT(seo_title LIKE 'Fotografie čtenářů: lávka přes Bystřinu – %', '|', noindex) FROM ka_news WHERE slug = 'lavka-pres-bystrinu-2'"), // check-english: allow (Czech WordPress fixture)
            'Rank Math: title with variables, noindex from a serialized array');
        $this->assertSame('O Podhorském zpravodaji – kdo jsme a kde nás najdete|Podhorský zpravodaj vychází od roku 1998 – redakce, kontakt a historie.|0', // check-english: allow (Czech WordPress fixture)
            $this->seo("SELECT CONCAT(seo_title, '|', description, '|', noindex) FROM ka_pages WHERE slug = 'o-zpravodaji'"),
            'SmartCrawl on a page: title and description');
        $this->assertPage('/news/slavnosti-syra', 200, 'noindex', message: 'imported news with noindex from the plugin prints it');
    }

    #[Depends('testImportOfAWordPressExport')]
    public function testImportedContentIsCleanedAndOldAddressesRedirect(): void
    {
        $this->assertStringNotContainsString('wp-block', $this->visitor()->get('/o-zpravodaji')->body, 'WordPress classes without a style are dropped from the build');
        $this->assertDoesNotMatchRegularExpression('/podvrh|onclick|kontaktni-formular|posta\.example/', $this->visitor()->get('/news/lavka-pres-bystrinu')->body,
            'imported news holds no script, plugin shortcode or commenter e-mail');
        $old = $this->visitor()->get('/2026/05/lavka-pres-bystrinu/');
        $this->assertSame(301, $old->status, 'the old WordPress address redirects');
        $this->assertSame($this->site()->base . '/news/lavka-pres-bystrinu', $old->redirect, 'the old WordPress address points to the news');
        $this->assertSame(301, $this->visitor()->get('/?p=102')->status, 'the old /?p=102 address redirects');
    }

    #[Depends('testImportedContentIsOnTheSite')]
    public function testASecondImportDuplicatesNothing(): void
    {
        $this->select('wordpress-sample.xml');
        $this->runImport('wordpress-sample.xml', ['drafts' => 1, 'pages' => 1, 'builder' => 1, 'redirects' => 1, 'category' => 0]);

        $this->assertSame('4/1', $this->seo("SELECT CONCAT((SELECT COUNT(*) FROM ka_news WHERE slug LIKE 'lavka-pres-bystrinu%' OR slug LIKE 'slavnosti-syra%' OR slug LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM ka_pages WHERE slug LIKE 'o-zpravodaji%'))"),
            'a repeated import duplicated nothing (news/pages)');
    }

    #[Depends('testASecondImportDuplicatesNothing')]
    public function testCustomPostTypeBecomesACollection(): void
    {
        $options = ['drafts' => 1, 'pages' => 1, 'redirects' => 1, 'category' => 0, 'collections' => 1];
        $this->upload('wordpress-cpt.xml');
        $this->batch('wordpress-cpt.xml');
        $this->assertPage(self::TRANSFER . '&action=preview&file=wordpress-cpt.xml', 200, 'reference', message: 'the preview shows the custom post type as a collection');
        $this->runImport('wordpress-cpt.xml', $options);

        $this->assertSame('reference|1|["klient", "rok_dokonceni", "datum_predani", "web_klienta", "fotka", "content"]|["text", "number", "date", "link", "image", "html"]',
            $this->seo('SELECT CONCAT(slug, \'|\', detail, \'|\', JSON_EXTRACT(fields, \'$[*].key\'), \'|\', JSON_EXTRACT(fields, \'$[*].type\')) FROM ka_collections WHERE name = \'Reference\''),
            'a custom post type became a collection with fields by values');
        $this->assertSame('kuchyne-novak:1:Rodina Novákových:2024-03-15|pekarna-u-mlyna:0:Pekárna U Mlýna:2023-11-01', // check-english: allow (Czech WordPress fixture)
            $this->seo('SELECT GROUP_CONCAT(CONCAT(slug, \':\', visible, \':\', JSON_UNQUOTE(JSON_EXTRACT(data, \'$.klient\')), \':\', JSON_UNQUOTE(JSON_EXTRACT(data, \'$.datum_predani\'))) ORDER BY item_id SEPARATOR \'|\') FROM ka_collection_items WHERE collection_id = (SELECT collection_id FROM ka_collections WHERE slug = \'reference\')'),
            'collection items: field values, the draft is hidden');
        $this->assertPage('/reference/kuchyne-novak', 200, 'Rodina Novákových', message: 'collection item on the old address'); // check-english: allow (Czech WordPress fixture)
        $old = $this->visitor()->get('/?p=401');
        $this->assertSame(301, $old->status, 'the old /?p=401 address redirects');
        $this->assertSame($this->site()->base . '/reference/kuchyne-novak', $old->redirect, 'the old /?p=401 address points to the item');

        $this->select('wordpress-cpt.xml');
        $this->runImport('wordpress-cpt.xml', $options);
        $this->assertSame('1/1', $this->seo("SELECT CONCAT((SELECT COUNT(*) FROM ka_collections WHERE name LIKE 'Reference%'), '/', (SELECT COUNT(*) FROM ka_collection_items WHERE slug LIKE 'kuchyne-novak%'))"),
            'a repeated import of the custom type duplicated nothing');
        $this->assertPage('/storage/import/wordpress-sample.xml', 403, message: 'the import folder is not reachable from the web');
    }

    #[Depends('testCustomPostTypeBecomesACollection')]
    public function testExportHasContentButNoSecrets(): void
    {
        $site = $this->site();
        // what the export must carry or leave out: a collection item, a site part, a pop-up with counters, an enquiry
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Team', 'detail' => 1,
            'fields' => [['label' => 'Role', 'type' => 'text'], ['label' => 'Photo', 'type' => 'image'], ['label' => 'Bio', 'type' => 'html']]], '/admin.php?module=collections');
        $idk = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'team'");
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => $idk, 'item_id' => 0, 'name' => 'Jane Novak', 'data' => ['role' => 'Managing director'], 'sort_order' => 1, 'visible' => 1], '/admin.php?module=collections');
        $site->mcpResult('save_build', ['part' => 'footer', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']]]]]]]);
        $popup = $site->mcpResult('save_popup', ['template' => 'blank', 'name' => 'Event window']);
        $site->exec('UPDATE ka_popups SET impressions = 5 WHERE slug = ?', ['event-window']);
        $site->exec("INSERT INTO ka_enquiries (created_at, form, data) VALUES (NOW(), 'contact', 'I want a custom kitchen.')");
        $this->assertNotSame('', (string) ($popup['id'] ?? ''), 'a pop-up was created for the export');

        $this->adminPost(self::TRANSFER . '&action=export');
        $list = $this->assertPage(self::TRANSFER, 200, 'action=download', message: 'the export is in the list');
        $this->assertSame(1, preg_match('/export-[0-9]*-[0-9]*\.[a-z]*/', $list->body, $m), 'the export has a file name');
        $file = $m[0];
        $download = $site->admin()->get(self::TRANSFER . '&action=download&file=' . $file);
        $json = $download->body;
        if (str_ends_with($file, '.zip')) {
            $zip = $site->workDir('export') . '/' . $file;
            file_put_contents($zip, $download->body);
            $archive = new \ZipArchive();
            $this->assertTrue($archive->open($zip) === true, 'the export archive opens');
            $json = (string) $archive->getFromName('content.json');
            $archive->close();
        }

        $this->assertStringContainsString('"format":"kaleta-export"', $json, 'export format');
        $this->assertStringContainsString('"news"', $json, 'export carries news');
        $this->assertDoesNotMatchRegularExpression('/"password"|smtp_password|secret_key|ai_key/', $json, 'export holds no secrets');
        $this->assertStringContainsString('"collection_items":[', $json, 'export carries collection items');
        $this->assertStringContainsString('Jane Novak', $json, 'export carries the item');
        $this->assertStringContainsString('"classes":[', $json, 'export carries classes');
        $this->assertStringContainsString('"site_parts":[', $json, 'export carries site parts');
        $this->assertStringNotContainsString('I want a custom', $json, 'enquiries are not exported');
        $this->assertStringContainsString('"slug":"event-window"', $json, 'export carries pop-ups');
        $this->assertStringNotContainsString('"impressions":', $json, 'pop-up counters are not exported');

        $anonymous = $site->client('anonymous')->get(self::TRANSFER . '&action=download&file=' . $file);
        $this->assertStringContainsString('Password', $anonymous->body, 'the export is for the signed-in administrator only');
    }
}
