<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The public face of a freshly installed site (was: section "web" of tools/test.sh). */
#[Group('site')]
final class PublicSiteTest extends SiteTestCase
{
    public function testHomePageComesFromBuilderSectionsAndLinksThePages(): void
    {
        $this->assertPage('/', 200, ['Testovací firma', 'href="/o-nas"', 'class="build"']);
    }

    public function testHomePageHasOnlyOneAddress(): void
    {
        $response = $this->site()->client()->get('/uvod');

        $this->assertSame(301, $response->status);
        $this->assertSame($this->site()->base . '/', $response->redirect);
    }

    public function testHealthEndpointForAnOrchestrator(): void
    {
        $response = $this->site()->client()->get('/health');

        $this->assertSame(200, $response->status);
        $this->assertSame('ok', trim($response->body));
        $this->assertSame('no-store', $response->headers['cache-control'] ?? '');
    }

    public function testPagesAndStructuredData(): void
    {
        $this->assertPage('/sluzby', 200, 'Služby', message: 'page');
        $this->assertPage('/sluzby', 200, '"FAQPage"', message: 'questions and answers for search engines');
    }

    public function testNewsListItemCategoryAndSearch(): void
    {
        $this->assertPage('/news', 200, 'Vítejte v Kaletě');
        $this->assertPage('/news/vitejte-v-kalete', 200, ['Vítejte', '"BlogPosting"']);
        $this->assertPage('/news/category/novinky');
        $this->assertPage('/search?q=Kontakt', 200, 'href="/kontakt"');
    }

    public function testFeedsAndMachineReadableFiles(): void
    {
        foreach (['/rss.xml', '/feed.json', '/sitemap.xml', '/robots.txt', '/llms.txt', '/news/vitejte-v-kalete.md'] as $path) {
            $this->assertPage($path);
        }
        $this->assertPage('/sitemap.xml', 200, '/news/vitejte-v-kalete');
        $this->assertPage('/llms.txt', 200, '## Stránky');
    }

    public function testPrivacyPolicyIsHiddenUntilTheAdministratorPublishesIt(): void
    {
        $this->assertPage('/zasady-ochrany-osobnich-udaju', 404);

        $this->site()->exec("UPDATE ka_pages SET visible = 1 WHERE slug = 'zasady-ochrany-osobnich-udaju'");

        $this->assertPage('/zasady-ochrany-osobnich-udaju', 200, 'Jaké údaje zpracováváme');
        $this->assertPage('/o-nas', 200, 'zasady-ochrany-osobnich-udaju', message: 'the footer links the policy');
    }

    public function testMissingPagesAndPrivateFilesAreNotServed(): void
    {
        $this->assertPage('/tohle-neexistuje', 404);
        $this->assertPage('/system/sql/schema.sql', 403);
        $this->assertPage('/config.php', 403);
    }

    public function testACustomLayoutFolderIsIgnored(): void
    {
        mkdir($this->site()->path('layout/vlastni'), 0775, true);
        file_put_contents($this->site()->path('layout/vlastni/base.php'), '<?php echo "VLASTNI SABLONA";');
        $this->site()->setting('layout', 'vlastni');
        $this->site()->clearPageCache();

        $response = $this->assertPage('/', 200, 'image/template.css', message: 'the built-in frame is used');

        $this->assertStringNotContainsString('VLASTNI SABLONA', $response->body);
    }

    #[\PHPUnit\Framework\Attributes\Depends('testACustomLayoutFolderIsIgnored')]
    public function testWithoutAHomePageTheNewsListIsTheHome(): void
    {
        $this->site()->setting('home_page', '0');
        $this->site()->clearPageCache();

        $this->assertPage('/', 200, 'Vítejte v Kaletě');
    }
}
