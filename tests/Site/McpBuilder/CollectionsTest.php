<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Collections: fields, items, listing element, item pages, MCP, several languages, filters (was: section 13). */
#[Group('site')]
final class CollectionsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private static int $idk = 0;
    private static int $idTym = 0;

    private function saveItem(array $fields): void
    {
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => $this->site()->publicId('collections', self::$idk), 'item_id' => 0] + $fields);
    }

    private function listBuild(array $children): array
    {
        return ['id' => $this->site()->publicId('pages', $this->zPage()), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => $children]]]];
    }

    public function testCollectionIsCreatedWithItsFields(): void
    {
        $this->zPage();
        $this->assertPage('/admin.php?module=collections', 200, 'Collections', message: 'collections');
        $this->adminPost('/admin.php?module=collections&action=save', [
            'collection_id' => 0, 'name' => 'Team', 'detail' => 1,
            'fields' => [['label' => 'Role', 'type' => 'text'], ['label' => 'Photo', 'type' => 'image'], ['label' => 'Bio', 'type' => 'html']],
        ], '/admin.php?module=collections');
        self::$idk = (int) $this->site()->value("SELECT collection_id FROM tl_collections WHERE slug = 'team'");

        $this->assertSame('1', (string) $this->site()->value("SELECT fields LIKE '%\"role\"%' AND fields LIKE '%\"bio\"%' FROM tl_collections WHERE collection_id = ?", [self::$idk]), 'collection created with fields');
    }

    public function testItemsAndTheListingElementOnAPage(): void
    {
        $this->saveItem(['name' => 'Jane Novak', 'data' => ['role' => 'Managing director', 'bio' => '<p>Twenty years <b>in the field</b>.</p><script>x</script>'], 'sort_order' => 1, 'visible' => 1]);
        $this->saveItem(['name' => 'Hidden Member', 'data' => ['role' => 'Secret'], 'sort_order' => 2]);
        $this->assertPage('/admin.php?module=collections&action=items&id=' . $this->site()->publicId('collections', self::$idk), 200, 'Jane Novak', message: 'collection items');

        $text = $this->rawText('save_build', $this->listBuild([['id' => 'smy1', 'type' => 'collection_list', 'content' => ['collection' => 'team'], 'children' => [
            ['id' => 'kar1', 'type' => 'container', 'style' => ['base' => ['background' => 'surface']], 'children' => [
                ['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{name}}']],
                ['type' => 'text', 'content' => ['html' => '<p>{{role}}</p>{{bio}}']],
                ['type' => 'button', 'content' => ['text' => 'Profile', 'link' => '{{url}}']],
            ]],
        ]]]));
        $this->assertStringContainsString('"status":"published"', $text, 'MCP page with a collection listing is published');
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');
        $this->assertStringContainsString('<h3>Jane Novak</h3>', $body, 'name filled in');
        $this->assertStringContainsString('<p>Managing director</p>', $body, 'text field filled in');
        $this->assertMatchesRegularExpression('/^<p>Twenty years <b>in the field<\/b>\.<\/p>/m', $body, 'html field filled in and cleaned');
        $this->assertStringContainsString('href="/team/jane-novak"', $body, 'link to the item page');
        $this->assertStringNotContainsString('Hidden', $body, 'only published items');
        $this->assertStringContainsString('class="s-kar1"', $body, 'repeated element styled through a class');
        $this->assertStringNotContainsString('id="s-kar1"', $body, 'no duplicate ids');
        $this->assertStringContainsString('.s-kar1 { background-color', $body, 'the class carries the style');
        $this->assertStringNotContainsString('<script>x', $body, 'script removed from the html field');
    }

    public function testItemPages(): void
    {
        $this->assertPage('/team/jane-novak', 200, 'Managing director', message: 'item detail');
        $this->assertPage('/team/jane-novak', 200, '<h1>Jane Novak</h1>', message: 'detail has the item heading');
        $this->assertSame(404, $this->visitor()->get('/team/hidden-member')->status, 'a hidden item has no detail');
        $this->assertPage('/sitemap.xml', 200, '/team/jane-novak', message: 'sitemap has the item page');
        $this->assertPage('/admin.php?module=collections&action=builder&id=' . $this->site()->publicId('collections', self::$idk), 200, 'id="builder-data"', message: 'item template in the builder');
    }

    public function testMcpCollectionTools(): void
    {
        $this->assertStringContainsString('"collection":"team"', $this->mcpText('list_collections'), 'MCP lists the collection');
        $this->assertStringContainsString('bio', $this->mcpText('list_collections'), 'MCP lists its fields');

        $this->site()->mcp('save_collection_item', ['collection' => 'team', 'name' => 'Peter Smith', 'values' => ['role' => 'Master carpenter'], 'visible' => true]);
        $this->site()->mcp('save_collection_item', ['collection' => 'team', 'name' => 'Text JSON', 'values' => '{"role":"From text"}']);
        $this->assertStringContainsString('Text JSON', $this->mcpText('list_collection_items', ['collection' => 'team', 'field' => 'role', 'value' => 'From text']), 'data sent as a JSON text are saved');

        $this->assertStringContainsString('must be a list', $this->mcpText('save_menu', ['location' => 'main', 'items' => 'unreadable']), 'unreadable menu items are an error, the menu does not fall back to automatic');
        $this->assertStringContainsString('unknown_parameters', $this->mcpText('save_classes', ['classes' => [['name' => 'x']]]), 'an unknown parameter is in the result, not silently dropped');
        $this->assertStringContainsString('must be an object', $this->mcpText('save_collection_item', ['collection' => 'team', 'name' => 'Bad data', 'values' => 'role=x']), 'unreadable item data are an error');
        $this->assertStringContainsString('team/zdenek', $this->mcpText('save_collection_item', ['collection' => 'team', 'name' => 'Zdenek Zeman', 'slug' => 'zdenek', 'visible' => true]), 'own address of an item');
    }

    public function testItemTemplateDraftPreviewAndPublishing(): void
    {
        $build = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profile: {{name}}']]]]]];
        $preview = (string) ($this->mcpData('save_build', ['collection' => 'team', 'build' => $build])['preview'] ?? '');

        $this->assertNotSame('', $preview, 'a signed preview link is returned');
        $this->assertStringContainsString('Profile: ', $this->visit($preview), 'the item template as a draft with a signed preview');
        $this->assertStringNotContainsString('Profile: ', $this->visit('/team/zdenek'), 'the visitor does not see the draft template');

        $this->site()->mcp('publish_build', ['collection' => 'team']);
        $this->site()->clearPageCache();
        $this->assertPage('/team/zdenek', 200, 'Profile: Zdenek Zeman', message: 'published item template');
        $this->assertPage('/llms.txt', 200, '/team/zdenek', message: 'llms.txt lists collection items with a detail');
    }

    public function testRelatedItems(): void
    {
        $this->site()->mcp('save_collection_item', ['collection' => 'team', 'name' => 'Susan Green', 'values' => ['role' => 'Managing director'], 'visible' => true]);
        $this->site()->mcp('save_build', ['collection' => 'team', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profile: {{name}}']],
            ['type' => 'collection_list', 'content' => ['collection' => 'team', 'filter_field' => 'role', 'filter_value' => '{{role}}', 'exclude_current' => true], 'children' => [
                ['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => 'Colleague: {{name}}']],
            ]],
        ]]]]]);
        $this->site()->clearPageCache();

        $body = $this->visit('/team/jane-novak');

        $this->assertStringContainsString('Colleague: Susan Green', $body, 'the colleague with the same function is related');
        $this->assertStringNotContainsString('Colleague: Jana', $body, 'not the item itself');
        $this->assertStringNotContainsString('Colleague: Petr', $body, 'not an item with another function');
        $this->assertStringNotContainsString('Colleague: Zuzana', $this->visit('/team/peter-smith'), 'the filter follows the shown item');
    }

    public function testCollectionInSeveralLanguages(): void
    {
        $site = $this->site();
        // earlier old sections had switched the German version on and published the German home page (language links appear only then)
        $site->setting('additional_languages', 'de');
        // ... and set the logo (section 10) and a header with the logo element (section 11)
        $site->setting('logo', 'image/talea-logo.svg');
        $site->mcp('save_build', ['part' => 'header', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'header', 'children' => [['type' => 'logo']]]]]]);
        $site->mcp('create_page', ['title' => 'Startseite', 'slug' => 'startseite', 'language' => 'de', 'translation_of' => $site->publicId('pages', (int) $site->settingValue('home_page')), 'content' => '<p>Startseite</p>', 'visible' => true]);
        $site->mcp('create_page', ['title' => 'Our team', 'slug' => 'team', 'content' => '<p>Team</p>', 'visible' => true]);
        self::$idTym = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'team'");
        $site->mcp('create_page', ['title' => 'Unser Team', 'slug' => 'unser-team', 'language' => 'de', 'translation_of' => $site->publicId('pages', self::$idTym), 'content' => '<p>Team</p>', 'visible' => true]);

        $first = $this->mcpText('save_collection_item', ['collection' => 'team', 'name' => 'Zdenek Zeman DE', 'slug' => 'zdenek', 'language' => 'de', 'values' => ['role' => 'Workshop lead'], 'visible' => true]);
        $this->assertStringContainsString('de/team/zdenek"', $first, 'the translation of an item keeps the same slug');
        $this->assertStringContainsString('team/zdenek-2', $this->mcpText('save_collection_item', ['collection' => 'team', 'name' => 'Second Zdenek', 'slug' => 'zdenek', 'language' => 'de']), 'the address is unique within a language');

        $deBuild = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'breadcrumbs'], ['type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Profile: {{name}}']]]]]];
        $site->mcp('save_build', ['collection' => 'team', 'language' => 'de', 'build' => $deBuild]);
        $site->clearPageCache();
        $this->assertStringContainsString('Profile: Zdenek Zeman DE', $this->visit('/de/team/zdenek'), 'a language without its own template uses the default language template, the draft is hidden');

        $site->mcp('publish_build', ['collection' => 'team', 'language' => 'de']);
        $site->mcp('save_build', ['collection' => 'team', 'language' => 'de', 'publish' => true, 'build' => $deBuild]);
        $site->clearPageCache();
        $body = $this->visit('/de/team/zdenek');
        $this->assertStringContainsString('<h1>Profile: Zdenek Zeman DE</h1>', $body, 'the item template of the language');
        $this->assertStringContainsString('href="/de/unser-team">Unser Team</a>', $body, 'breadcrumbs lead through the translation of the hub page');
        $this->assertMatchesRegularExpression('/hreflang="en" href="[^"]*\/team\/zdenek"/', $body, 'hreflang to the default-language item');
        $en = $this->visit('/team/zdenek');
        $this->assertStringContainsString('Profile: Zdenek Zeman<', $en, 'the default-language item keeps its template');
        $this->assertMatchesRegularExpression('/hreflang="de" href="[^"]*\/de\/team\/zdenek"/', $en, 'hreflang to the German item');
        $this->assertMatchesRegularExpression('/class="logo"[^>]*><img src="\/image\/talea-logo.svg"/', $body, 'the logo of the language version');
        $this->assertStringNotContainsString('src="/de/image/', $body, 'images of the template get no language prefix');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_build_revisions WHERE part = ?", ['collection:' . self::$idk . ':de']), 'versions of the language template are kept apart');
        $this->assertStringContainsString('de/unser-team', $this->mcpText('list_pages'), 'the page list shows the address with the language prefix');
        $this->assertPage('/admin.php?module=collections&action=builder&id=' . $this->site()->publicId('collections', self::$idk) . '&language=de', 200, 'de/team/zdenek', message: 'language item template in the builder');
    }

    public function testTranslatingAPageFromTheBuild(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('needs translation_of', $this->mcpText('create_page', ['title' => 'Without original', 'slug' => 'without-original', 'copy_build' => true]), 'a build copy needs the original');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'without-original'"), 'a build copy without the original creates no page');

        $site->mcp('create_page', ['title' => 'From HTML', 'slug' => 'from-html', 'language' => 'de', 'translation_of' => $site->publicId('pages', $this->zPage()), 'copy_build' => true]);
        $idEn = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'from-html'");
        $this->assertSame('1', (string) $site->value('SELECT n.build_draft = COALESCE(o.build_draft, o.build) FROM tl_pages n JOIN tl_pages o ON o.page_id = n.translation_of WHERE n.page_id = ?', [$idEn]), 'the translation starts as a copy of the original build');

        $texts = $this->mcpText('get_build', ['id' => $this->site()->publicId('pages', $idEn), 'texts_only' => true]);
        $this->assertStringContainsString('texts', $texts, 'only texts');
        $this->assertStringContainsString('{{name}}', $texts, 'the texts of the build');
        $this->assertStringNotContainsString('"build"', $texts, 'without the structure');
        $this->assertStringNotContainsString('"collection":"team"', $texts, 'without technical fields');
    }

    public function testMcpSmallThingsAndHousekeeping(): void
    {
        $site = $this->site();
        $this->assertStringMatchesFormat('%Aunknown_keys%Atitle%A', $this->mcpText('save_collection_item', ['collection' => 'team', 'name' => 'Extra key', 'values' => ['role' => 'x', 'title' => 'Other']]), 'a key the collection does not have is in the result');

        $site->mcp('create_page', ['title' => 'Hidden by text', 'slug' => 'hidden-by-text', 'visible' => 'false']);
        $this->assertSame('0', (string) $site->value("SELECT visible FROM tl_pages WHERE slug = 'hidden-by-text'"), 'visible sent as the text "false" keeps the page hidden');

        // the default footer exists (the old section 11 had saved one)
        $site->mcp('save_build', ['part' => 'footer', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'content' => ['detail' => 'copyright']]]]]]]);
        $site->mcp('get_build', ['part' => 'footer', 'language' => 'de']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_site_parts WHERE type = 'footer' AND language = 'de'"), 'reading a part that does not exist yet creates nothing');
        $site->mcp('edit_build', ['part' => 'footer', 'language' => 'de', 'operations' => []]);
        $this->assertSame('1', (string) $site->value("SELECT e.build_draft = COALESCE(c.build_draft, c.build) FROM tl_site_parts e JOIN tl_site_parts c ON c.type = e.type AND c.language = '' AND c.variant = '' WHERE e.type = 'footer' AND e.language = 'de' AND e.variant = ''"), 'the footer of a new language starts as a copy of the default footer');

        $site->setting('tasks_token', 'testtoken123');
        $site->exec("INSERT INTO tl_consents (visitor_token, created_at, categories) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NOW() - INTERVAL 40 MONTH, 'none'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW(), 'nic')");
        $site->runTasks();
        $this->assertSame('b', (string) $site->value("SELECT GROUP_CONCAT(LEFT(visitor_token, 1) ORDER BY visitor_token) FROM tl_consents WHERE visitor_token IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')"), 'the clean-up deletes old cookie consent records');
    }

    private function migrate(): string
    {
        return (string) shell_exec('cd ' . escapeshellarg($this->site()->root) . ' && ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1');
    }

    private function pendingTables(): string
    {
        return (string) $this->site()->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tl_test_pending'");
    }

    /** Migrations never run on a page request: a pending one leaves the site up, the administration says so, bin/migrate applies it. */
    public function testPendingMigrationIsAppliedOnlyByBinMigrate(): void
    {
        $site = $this->site();
        $this->assertSame((string) count(glob($site->path('system/database/migrations/[0-9]*_*.php'))), (string) $site->value('SELECT COUNT(*) FROM tl_migrations'), 'the installation recorded all migrations in tl_migrations');
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
        $text = $this->mcpText('update_collection', ['collection' => 'team', 'name' => 'Our team']);
        $this->assertStringContainsString('Our team', $text, 'MCP: the collection is renamed');
        $this->assertStringContainsString('bio', $text, 'MCP: editing a collection keeps the fields');

        $site->clearPageCache();
        $this->assertPage('/z-html', 200, 'Master carpenter', message: 'a new item from MCP is in the listing');

        $this->site()->mcp('save_build', $this->listBuild([['id' => 'vyp1', 'type' => 'collection_list',
            'content' => ['collection' => 'team', 'count' => 1, 'sort' => 'name', 'filter_field' => 'role', 'filters' => true, 'pagination' => true],
            'children' => [['type' => 'heading', 'tag' => 'h3', 'content' => ['text' => '{{name}}']]]]]));
        $site->clearPageCache();

        $body = $this->visit('/z-html');
        preg_match_all('/<h3>[^<]*<\/h3>/', $body, $headings);
        $this->assertSame('<h3>Jane Novak</h3>', implode('', $headings[0]), 'the listing shows one item, sorted by name');
        $this->assertStringContainsString('href="/z-html?s-vyp1=2"', $body, 'pagination');
        $this->assertStringContainsString('href="/z-html" aria-current="true">All', $body, 'the filter button for all');
        $this->assertStringContainsString('f-vyp1=Master', $body, 'filter buttons by field value');

        $this->assertPage('/z-html?s-vyp1=2', 200, '<h3>Peter Smith</h3>', message: 'the second page of the listing');

        $filtered = $this->visit('/z-html?f-vyp1=Master+carpenter');
        $this->assertStringContainsString('<h3>Peter Smith</h3>', $filtered, 'visitor filter shows the match');
        $this->assertStringNotContainsString('<h3>Jana', $filtered, 'visitor filter hides the rest');
        $this->assertStringContainsString('aria-current="true">Master carpenter', $filtered, 'the active filter is marked');
    }
}
