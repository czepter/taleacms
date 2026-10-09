<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: section 37 of tools/test.sh – 1.9 collection items as pages, structured data, site audit, the 404 log, the privacy
 * template and the streamed backup download. Needs from earlier sections: the "Tým" collection (13), media (28), a backup (39).
 */
#[Group('site')]
final class CollectionItemPagesTest extends SiteTestCase
{
    use SiteFixtures;

    private function jana(): int
    {
        return (int) $this->site()->value("SELECT item_id FROM ka_collection_items WHERE slug = 'jana-novakova' AND language = ''");
    }

    public function testFixture(): void
    {
        $this->createTeam($this->site());
        $this->uploadPhoto($this->site());

        $this->assertGreaterThan(0, $this->jana(), 'the team member exists');
    }

    #[Depends('testFixture')]
    public function testItemPageHasItsOwnSeoFieldsAndDropsAnUnsafeImage(): void
    {
        $site = $this->site();
        $site->mcp('save_collection_item', ['collection' => 'tym', 'id' => $this->jana(), 'seo_title' => 'Jana Nováková, jednatelka', 'description' => 'Vede dílnu dvacet let.', 'share_image' => 'javascript:x']);
        $site->clearPageCache();
        $body = $site->client()->get('/tym/jana-novakova')->body;

        $this->assertStringContainsString('<title>Jana Nováková, jednatelka – ', $body, 'item page: its own SEO title');
        $this->assertStringContainsString('<meta name="description" content="Vede dílnu dvacet let.">', $body, 'item page: its own description');
        $this->assertStringNotContainsString('javascript:x', $body, 'an unsafe image is dropped');
    }

    #[Depends('testItemPageHasItsOwnSeoFieldsAndDropsAnUnsafeImage')]
    public function testItemVersionsComeBack(): void
    {
        $site = $this->site();
        $jana = $this->jana();
        $versions = $site->mcpResult('list_item_versions', ['collection' => 'tym', 'id' => $jana]);
        $site->mcp('restore_item_version', ['collection' => 'tym', 'id' => $jana, 'version' => $versions['versions'][0]['id']]);

        $this->assertSame('1|1', (string) $site->value("SELECT CONCAT(seo_title = '', '|', (SELECT COUNT(*) FROM ka_build_revisions WHERE part = 'polozka:$jana') >= 2) FROM ka_collection_items WHERE item_id = $jana"),
            'item versions: the earlier version comes back, the newer one goes to the history');
    }

    #[Depends('testItemVersionsComeBack')]
    public function testNoindexItemIsOutOfSearchEnginesSitemapAndLlmsTxt(): void
    {
        $site = $this->site();
        $site->mcp('save_collection_item', ['collection' => 'tym', 'id' => $this->jana(), 'noindex' => true]);
        $site->clearPageCache();
        $visitor = $site->client();

        $this->assertStringContainsString('content="noindex', $visitor->get('/tym/jana-novakova')->body, 'a noindex item says so');
        $this->assertStringNotContainsString('/tym/jana-novakova', $visitor->get('/sitemap.xml')->body, 'a noindex item is out of the sitemap');
        $this->assertStringNotContainsString('/tym/jana-novakova', $visitor->get('/llms.txt')->body, 'a noindex item is out of llms.txt');
        $site->mcp('save_collection_item', ['collection' => 'tym', 'id' => $this->jana(), 'noindex' => false]);
    }

    #[Depends('testNoindexItemIsOutOfSearchEnginesSitemapAndLlmsTxt')]
    public function testScheduledItemWaitsHiddenAndPublishesItself(): void
    {
        $site = $this->site();
        $plan = (int) $site->mcpResult('save_collection_item', ['collection' => 'tym', 'name' => 'Planovany Clen', 'publish_at' => '2099-01-01 08:00'])['id'];

        $this->assertSame('0|1', (string) $site->value("SELECT CONCAT(visible, '|', publish_at IS NOT NULL) FROM ka_collection_items WHERE item_id = ?", [$plan]), 'a scheduled item waits hidden');

        $site->exec('UPDATE ka_collection_items SET publish_at = NOW() - INTERVAL 1 MINUTE WHERE item_id = ?', [$plan]);
        $site->runTasks();

        $this->assertSame('1|1', (string) $site->value("SELECT CONCAT(visible, '|', publish_at IS NULL) FROM ka_collection_items WHERE item_id = ?", [$plan]), 'the scheduled item publishes itself');
    }

    #[Depends('testFixture')]
    public function testItemFormAndCollectionFormOfferTheNewFields(): void
    {
        $site = $this->site();
        $idk = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'tym'");

        $response = $this->assertPage("/admin.php?module=collections&action=item&id=$idk&item={$this->jana()}", 200, 'Historie položky', message: 'the item form has SEO fields, scheduling and the history');
        $this->assertStringContainsString('name="seo_title"', $response->body, 'item form: the SEO title field');
        $this->assertStringContainsString('name="publish_at"', $response->body, 'item form: the scheduling field');
        $this->assertPage("/admin.php?module=collections&action=edit&id=$idk", 200, 'Strukturovaná data pro vyhledávače', message: 'the collection form offers structured data');
    }

    #[Depends('testFixture')]
    public function testStructuredDataOfACollection(): void
    {
        $site = $this->site();
        $site->mcp('update_collection', ['collection' => 'tym', 'structured_data' => ['type' => 'Person', 'fields' => ['jobTitle' => 'features']]]);
        $site->clearPageCache();
        $body = $site->client()->get('/tym/zuzana-zelena')->body;

        $this->assertStringContainsString('"@type":"Person","name":"Zuzana Zelena"', $body, 'structured data of a collection: item pages are a Person');
        $this->assertStringContainsString('"jobTitle":"Jednatelka"', $body, 'the mapped field is the job title');
        $this->assertStringContainsString('Unknown structured data type', $this->mcpRawText($site, 'update_collection', ['collection' => 'tym', 'structured_data' => ['type' => 'Recipe']]), 'MCP: an unknown schema type is refused');
        $this->assertStringContainsString('jobTitle', $this->mcpRawText($site, 'list_collections'), 'MCP: list_collections shows the structured data');
    }

    #[Depends('testStructuredDataOfACollection')]
    public function testSiteAudit(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Audit test', 'text' => '<p><a href="/neexistuje-audit">x</a> <a href="/tym/zuzana-zelena">ok</a></p>', 'visible' => true]);

        $response = $this->assertPage('/admin.php?module=audit', 200, 'Odkaz /neexistuje-audit vede na stránku, která neexistuje', message: 'Administration → Site audit finds a broken internal link');
        $this->assertStringNotContainsString('/tym/zuzana-zelena vede', $response->body, 'a link to an existing item is fine');

        $raw = $this->mcpRawText($site, 'site_audit', ['kind' => 'link']);
        $this->assertStringContainsString('neexistuje-audit', $raw, 'MCP: site_audit names the broken link');
        $this->assertStringContainsString('\"page\":', $raw, 'MCP: site_audit gives the target to fix');

        $tools = array_column($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')['result']['tools'], null, 'name');
        $this->assertTrue($tools['site_audit']['annotations']['readOnlyHint'] ?? false, 'MCP: site_audit is read-only');
        $this->assertFalse($tools['restore_item_version']['annotations']['readOnlyHint'] ?? true, 'MCP: restore_item_version writes');
    }

    #[Depends('testFixture')]
    public function testTheNotFoundLog(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $site->exec('DELETE FROM ka_not_found');
        for ($i = 0; $i < 3; $i++) {
            foreach (['/wp/v2/users', '/_next', '/stara-cenik-2019', '/stary-kontakt'] as $path) {
                $visitor->get($path);
            }
        }
        $site->exec("INSERT INTO ka_not_found (path, count, last_seen_at) VALUES ('o-nas', 9, NOW())");
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_not_found WHERE path IN ('wp/v2/users', '_next')"), '404 log: bot probes are not recorded');

        $start = $this->assertPage('/admin.php', 200, 'opakovaně skončily „stránka nenalezena“: 2.', message: 'the start screen explains the 404 warning and offers to review it');
        $this->assertStringContainsString('module=redirects#nenalezeno', $start->body, 'the warning links to the list');
        $this->assertStringContainsString('action=ignore_all', $start->body, 'the warning can be dismissed');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_not_found WHERE path = 'o-nas'"), 'an address that works again drops out of the log');
        $this->assertPage('/admin.php?module=redirects', 200, 'Ignorovat – nic ji nenahrazuje', message: 'the 404 list says what to do');

        $this->adminPost('/admin.php?module=redirects&action=ignore', ['path' => 'stary-kontakt'], '/admin.php?module=redirects');
        $this->assertSame('1', (string) $site->value("SELECT ignored_at IS NOT NULL FROM ka_not_found WHERE path = 'stary-kontakt'"), 'Ignore hides one address for good');
        $visitor->get('/stary-kontakt');
        $this->assertPage('/admin.php', 200, 'opakovaně skončily „stránka nenalezena“: 1.', message: 'an ignored address does not come back in the warning');

        $this->assertSame('1', (string) $site->mcpResult('ignore_not_found', ['all' => true])['ignored'], 'MCP: ignore_not_found dismisses the rest');
        $this->assertPageLacks('/admin.php', 'skončily „stránka nenalezena“', message: 'after ignoring, the start screen has no 404 warning');

        for ($i = 0; $i < 3; $i++) {
            $visitor->get('/uplne-nova-adresa');
        }
        $this->assertPage('/admin.php', 200, 'opakovaně skončily „stránka nenalezena“: 1.', message: 'a new address brings the warning back');
        $this->adminPost('/admin.php?module=redirects&action=ignore_all', ['zpet' => 'prehled'], '/admin.php?module=redirects');
    }

    #[Depends('testFixture')]
    public function testPrivacyTemplateUsesTheEnabledFeatures(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => 0, 'title' => 'Zásady test', 'sablona' => 'zasady', 'visible' => 0, 'in_menu' => 0, 'text' => ''], '/admin.php?module=pages&action=new');

        $this->assertSame('1|1|1', (string) $site->value("SELECT CONCAT(text LIKE '%nikoli právní rada%', '|', text LIKE '%poptávkovém formuláři%' OR text LIKE '%formuláře%', '|', text LIKE '%[ADDRESS]%' OR text LIKE '%[ADRESA]%' OR text LIKE '%sídlem%') FROM ka_pages WHERE title = 'Zásady test'"),
            'privacy template: a disclaimer and only the enabled features');
    }

    #[Depends('testFixture')]
    public function testBackupDownloadIsStreamedAndMediaZipComesOnlyByPost(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
        $last = $this->newestBackup($site);
        $this->assertNotSame('', $last, 'a backup exists');

        $download = $admin->get('/admin.php?module=settings&action=download_backup&file=' . $last);
        $this->assertSame(filesize($site->path('storage/zalohy/' . $last)), strlen($download->body), 'a backup downloads whole (streamed)');
        $this->assertSame('text/html; charset=utf-8', strtolower($admin->get('/admin.php?module=settings&action=media_backup')->headers['content-type'] ?? ''), 'the media ZIP is not built by a GET');

        $zipResponse = $admin->post('/admin.php?module=settings&action=media_backup', ['_csrf' => $site->csrf($admin, '/admin.php?module=settings&tab=backups')]);
        $this->assertSame('application/zip', strtolower($zipResponse->headers['content-type'] ?? ''), 'the media ZIP by POST');
        $zipFile = $site->workDir('fixtures') . '/media.zip';
        file_put_contents($zipFile, $zipResponse->body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile) === true, 'the answer is a ZIP');
        $media = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $media += str_starts_with((string) $zip->getNameIndex($i), 'media/') ? 1 : 0;
        }
        $this->assertGreaterThan(0, $media, 'the media ZIP holds the originals');
    }
}
