<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 3.0 add-ons through the extension API (was: section 92): the example add-on, a broken one and an old one are written into the
 * site's extensions/ folder; role checks of add-on tools (N39) and of news/categories for limited users (N12, N11). The tests run in order.
 */
#[Group('site')]
final class AddonsExtensionApiTest extends SiteTestCase
{
    use AgentHelpers;

    private static string $category = '';

    private function extension(string $slug, string $manifest, string $code): void
    {
        $dir = $this->site()->path('extensions/' . $slug);
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/extension.json', $manifest);
        file_put_contents($dir . '/Extension.php', $code);
    }

    private function toggle(string $slug, int $on, bool $trust = true): void
    {
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => $slug, 'on' => $on] + ($trust ? ['trust' => 1] : []));
    }

    public function testAddonsListsWhatIsInTheFolderAndSaysWhyAnOldOneCannotRun(): void
    {
        self::$category = $this->sq("SELECT name FROM ka_categories WHERE language = '' ORDER BY category_id LIMIT 1");

        $hello = $this->site()->path('extensions/hello');
        mkdir($hello, 0775, true);
        $source = dirname(__DIR__, 3) . '/docs/examples/extensions/hello';
        copy($source . '/Extension.php', $hello . '/Extension.php');
        // the example asks for Kaleta 3.0; before the version is bumped for a release the tree may still say 2.x
        file_put_contents($hello . '/extension.json', str_replace('">=3.0"', '">=2.0"', (string) file_get_contents($source . '/extension.json')));
        $this->extension('broken', '{"name":"Broken","class":"Broken\\\\Ext","requires":{"api":1}}', '<?php namespace Broken; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void { throw new \RuntimeException("deliberately broken"); } }' . "\n");
        $this->extension('old', '{"name":"Old","class":"Old\\\\Ext","requires":{"api":0}}', '<?php' . "\n");

        $this->assertPage('/admin.php?module=addons', 200, 'written for extension API 0', message: 'add-ons: Add-ons lists what is in extensions/ and says why an old one cannot run');
    }

    public function testTheWebServesOnlyThePublicFolderOfAnAddon(): void
    {
        // 3.3.2 (N36)
        $public = $this->site()->path('extensions/hello/public');
        mkdir($public, 0775, true);
        file_put_contents($public . '/hello.css', 'body{}');
        file_put_contents($public . '/run.php', '<?php echo "ran";');
        $visitor = $this->site()->client('visitor');

        $codes = '';
        foreach (['extensions/hello/Extension.php', 'extensions/hello/extension.json', 'extensions/hello/public/run.php', 'extensions/README.md', 'extensions/hello/public/hello.css'] as $url) {
            $codes .= $visitor->get('/' . $url)->status . ' ';
        }
        $this->assertSame('403 403 403 403 200 ', $codes, '3.3.2 add-ons: Extension.php, extension.json and public/*.php are refused, a file in public/ is served');
    }

    public function testSwitchingOnNeedsTheTrustTickAndABrokenAddonIsSwitchedOff(): void
    {
        $this->toggle('hello', 1, trust: false);
        $this->assertSame('', $this->sq("SELECT value FROM ka_settings WHERE name = 'addons_enabled'"), 'add-ons: switching on needs the trust tick');

        $this->toggle('hello', 1);
        $this->toggle('broken', 1);
        $this->site()->mcp('vytvor_stranku', ['title' => 'Addon page', 'adresa' => 'addon-page', 'text' => '<p>{{ext.hello.greeting name="Jana"}}</p>', 'visible' => true]);

        $page = $this->site()->client('visitor')->get('/addon-page');
        $this->assertStringContainsString('Hello, Jana!', $page->body, 'add-ons: a token in a page');
        $this->assertStringContainsString('<!-- hello add-on -->', $page->body, 'add-ons: a footer filter');

        // 3.3.2 (N38): a token in what a visitor sent (the search query) is never run - with or without attributes
        $withAttributes = $this->site()->client('visitor')->get('/search?q=' . rawurlencode('{{ext.hello.greeting name="Mallory"}}'));
        $without = $this->site()->client('visitor')->get('/search?q=' . rawurlencode('{{ext.hello.greeting}}'));
        $this->assertStringNotContainsString('hello-greeting', $withAttributes->body, 'add-ons: a token in the search query is not run (with attributes)');
        $this->assertStringNotContainsString('hello-greeting', $without->body, 'add-ons: a token in the search query is not run');
        $this->assertStringContainsString('ext.hello.greeting name=&quot;Mallory&quot;', $withAttributes->body, 'add-ons: the search query is shown escaped');

        $this->assertSame('hello|1', $this->sq("SELECT value FROM ka_settings WHERE name = 'addons_enabled'") . '|' . $this->sq("SELECT value LIKE '%deliberately broken%' FROM ka_settings WHERE name = 'addons_error.broken'"), 'add-ons: a broken add-on is switched off at once and its error kept');
        $this->assertPage('/admin.php?module=addons', 200, 'deliberately broken', message: 'add-ons: the error shows in Add-ons');
    }

    public function testClaudeListsAndCallsTheAddonsTool(): void
    {
        $list = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"name":"ext_hello_greet"', (string) $list, 'add-ons: Claude lists the add-on\'s tool');
        $this->assertStringContainsString('Hello, Petr!', $this->mcpRawText('ext_hello_greet', ['name' => 'Petr']), 'add-ons: Claude calls the add-on\'s tool');
    }

    public function testAddonToolsCheckTheRoleOfTheUser(): void
    {
        // 3.3.2 (N39): a write tool needs an editor by default, a tool may require a section; a read tool stays open to every user
        $this->extension('gate', '{"name":"Gate","class":"Gate\\\\Ext","requires":{"api":1}}', <<<'PHP'
<?php namespace Gate; final class Ext implements \Kaleta\Extension\ExtensionInterface { public function register(\Kaleta\Extension\Api $api): void {
    $api->mcpTool('write', 'A write tool without a role.', [], 'write', fn (array $a): array => ['written' => true]);
    $api->mcpTool('leads', 'A read tool for the Enquiries section.', [], 'read', fn (array $a): array => ['leads' => 1], 'enquiries');
    $api->mcpTool('look', 'A read tool without a role.', [], 'read', fn (array $a): array => ['looked' => true]);
} }
PHP . "\n");
        $this->site()->exec("UPDATE ka_settings SET value = 'hello,gate' WHERE name = 'addons_enabled'");
        $this->site()->exec("INSERT INTO ka_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('n39-author', '', 'Author N39', 0, NOW(), NOW())");
        $this->site()->exec("DELETE FROM ka_user_permissions WHERE user_id = (SELECT user_id FROM ka_users WHERE username = 'n39-author') AND module = 'enquiries'");
        $author = $this->tokenOf('n39-author', 'author', 'e');

        $this->assertSame(
            '1|1|1|1|1',
            $this->lines('needs an editor', $this->mcpRawText('ext_gate_write', [], $author)) . '|' . $this->lines('section', $this->mcpRawText('ext_gate_leads', [], $author)) . '|' . $this->lines('looked', $this->mcpRawText('ext_gate_look', [], $author)) . '|' . $this->lines('written', $this->mcpRawText('ext_gate_write')) . '|' . $this->lines('leads', $this->mcpRawText('ext_gate_leads')),
            '3.3.2 add-ons: an author\'s connection cannot call a write tool or a tool of a section it lacks, may call a read tool; the admin may call all',
        );

        $this->site()->exec("UPDATE ka_settings SET value = 'hello' WHERE name = 'addons_enabled'");
        $this->site()->exec("DELETE FROM ka_users WHERE username = 'n39-author'");
        $this->deleteDirectory($this->site()->path('extensions/gate'));
    }

    public function testWithoutTheNewsSectionAnEditorReadsOnlyPublishedNews(): void
    {
        // 3.3.2 (N12)
        $draft = (int) $this->pick($this->mcpData('create_news', ['title' => 'N12 draft only for News', 'category' => self::$category]), 'id');
        $this->site()->exec("INSERT INTO ka_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('n12-editor', '', 'Editor N12', 1, NOW(), NOW())");
        $this->site()->exec("INSERT INTO ka_user_permissions (user_id, module) SELECT user_id, 'pages' FROM ka_users WHERE username = 'n12-editor'");
        $editor = $this->tokenOf('n12-editor', 'editor', 'd');

        $this->assertGreaterThan(0, $draft, 'the draft news was created');
        $this->assertSame(
            '0|1|1',
            $this->lines('N12 draft only', $this->mcpRawText('list_news', ['limit' => 50], $editor)) . '|' . $this->lines('"isError":true', $this->mcpRawText('get_news', ['id' => $draft], $editor)) . '|' . $this->lines('N12 draft only', $this->mcpRawText('list_news', ['limit' => 50])),
            '3.3.2 MCP: list_news and get_news without the News section show no drafts; with it they do',
        );
        $this->site()->mcp('trash_news', ['id' => $draft]);
    }

    public function testAnAuthorLevelRoleWithCategoriesNeitherRenamesNorDeletesACategory(): void
    {
        // 3.3.2 (N11)
        $this->site()->exec('UPDATE ka_users SET admin = 0, password = ? WHERE username = ?', [password_hash($this->site()->password, PASSWORD_DEFAULT), 'n12-editor']);
        $this->site()->exec("INSERT INTO ka_user_permissions (user_id, module) SELECT user_id, 'categories' FROM ka_users WHERE username = 'n12-editor'");
        $client = $this->site()->client('n11');
        $this->site()->signIn($client, 'n12-editor');
        $id = (int) $this->sq('SELECT category_id FROM ka_categories WHERE slug = ? OR name = ? LIMIT 1', [self::$category, self::$category]);
        $name = $this->sq('SELECT name FROM ka_categories WHERE category_id = ?', [$id]);

        $client->post('/admin.php?module=categories&action=save', ['_csrf' => $client->get('/admin.php?module=categories')->csrf(), 'category_id' => $id, 'name' => 'Renamed-by-author', 'slug' => 'renamed-by-author']);
        $client->post('/admin.php?module=categories&action=delete', ['_csrf' => $client->get('/admin.php?module=categories')->csrf(), 'category_id' => $id]);

        $this->assertSame($name, $this->sq('SELECT name FROM ka_categories WHERE category_id = ?', [$id]), '3.3.2 admin: an author-level role with the Categories section neither renames nor deletes a category');
        $this->site()->exec("DELETE FROM ka_users WHERE username = 'n12-editor'");
    }

    public function testTheAddonsAdminPageJobAndSwitchingOff(): void
    {
        $this->assertPage('/admin.php?module=addons&action=page&p=hello.settings', 200, 'Greeting word', message: 'add-ons: the add-on\'s admin page');
        $this->adminPost('/admin.php?module=addons&action=page&p=hello.settings', ['word' => 'Ahoj'], '/admin.php?module=addons&action=page&p=hello.settings');
        $this->site()->clearPageCache();
        $this->assertSame('Ahoj|1', $this->sq("SELECT value FROM ka_settings WHERE name = 'ext.hello.word'") . '|' . $this->lines('Ahoj, Jana!', $this->site()->client('visitor')->get('/addon-page')->body), 'add-ons: the admin page saved the add-on\'s own setting, the page uses it');

        // a daily job: a page view's background run may have done it just before this call, then only the job table shows it
        $output = $this->site()->runTasks();
        $this->assertTrue(str_contains($output, 'ext_hello_daily') || $this->sq("SELECT COUNT(*) FROM ka_jobs WHERE name = 'ext_hello_daily' AND last_ok IS NOT NULL") === '1', 'add-ons: the add-on\'s job runs with the others');

        $this->toggle('hello', 0, trust: false);
        $this->site()->clearPageCache();
        $this->assertSame('1|1', $this->lines('{{ext.hello.greeting', $this->site()->client('visitor')->get('/addon-page')->body) . '|' . $this->lines('isError', $this->mcpRawText('ext_hello_greet')), 'add-ons: switched off, the token is left as it was written and the tool is gone');
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : unlink($file);
        }
        @rmdir($dir);
    }
}
