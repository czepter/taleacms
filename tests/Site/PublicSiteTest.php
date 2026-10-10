<?php

declare(strict_types=1);

namespace Talea\Tests\Site;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The public face of a freshly installed site (was: the web section of tools/test.sh). */
#[Group('site')]
final class PublicSiteTest extends SiteTestCase
{
    public function testHomePageComesFromBuilderSectionsAndLinksThePages(): void
    {
        $this->assertPage('/', 200, ['Test Company', 'href="/about-us"', 'class="build"']);
    }

    public function testHomePageHasOnlyOneAddress(): void
    {
        $response = $this->site()->client()->get('/home');

        $this->assertSame(301, $response->status);
        $this->assertSame($this->site()->base . '/', $response->redirect);
    }

    public function testRemovedFeaturesStayGone(): void
    {
        $this->assertPage('/_report', 404, as: $this->site()->client());
        $unknown = $this->site()->admin()->get('/admin.php?module=no_such_module');
        $removed = $this->site()->admin()->get('/admin.php?module=whistleblowing');
        $this->assertSame($unknown->status, $removed->status, 'the removed admin module answers like an unknown one');
        $this->assertContains($removed->status, [403, 404]);
        $this->assertPageLacks('/admin.php?module=extensions', 'whistleblowing');
        $tables = $this->site()->rows("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%whistleblowing%'");
        $this->assertSame([], $tables, 'a fresh install has no whistleblowing table');
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
        $this->assertPage('/services', 200, 'Services', message: 'page');
        $this->assertPage('/services', 200, '"FAQPage"', message: 'questions and answers for search engines');
    }

    public function testNewsListItemCategoryAndSearch(): void
    {
        $this->assertPage('/news', 200, 'Our new website is live');
        $this->assertPage('/news/our-new-website-is-live', 200, ['Our new website', '"BlogPosting"']);
        $this->assertPage('/news/category/news');
        $this->assertPage('/search?q=Contact', 200, 'href="/contact"');
    }

    public function testFeedsAndMachineReadableFiles(): void
    {
        foreach (['/rss.xml', '/feed.json', '/sitemap.xml', '/robots.txt', '/llms.txt', '/news/our-new-website-is-live.md'] as $path) {
            $this->assertPage($path);
        }
        $this->assertPage('/sitemap.xml', 200, '/news/our-new-website-is-live');
        $this->assertPage('/llms.txt', 200, '## Pages');
    }

    public function testPrivacyPolicyIsHiddenUntilTheAdministratorPublishesIt(): void
    {
        $this->assertPage('/privacy-policy', 404);

        $this->site()->exec("UPDATE tl_pages SET visible = 1 WHERE slug = 'privacy-policy'");

        $this->assertPage('/privacy-policy', 200, 'What data we process');
        $this->assertPage('/about-us', 200, 'privacy-policy', message: 'the footer links the policy');
    }

    public function testMissingPagesAndPrivateFilesAreNotServed(): void
    {
        $this->assertPage('/this-does-not-exist', 404);
        $this->assertPage('/system/bootstrap.php', 403);
        $this->assertPage('/config.php', 403);
    }

    public function testACustomLayoutFolderIsIgnored(): void
    {
        mkdir($this->site()->path('layout/custom'), 0775, true);
        file_put_contents($this->site()->path('layout/custom/base.php'), '<?php echo "CUSTOM TEMPLATE";');
        $this->site()->setting('layout', 'custom');
        $this->site()->clearPageCache();

        $response = $this->assertPage('/', 200, 'image/template.css', message: 'the built-in frame is used');

        $this->assertStringNotContainsString('CUSTOM TEMPLATE', $response->body);
    }

    #[\PHPUnit\Framework\Attributes\Depends('testACustomLayoutFolderIsIgnored')]
    public function testWithoutAHomePageTheNewsListIsTheHome(): void
    {
        $this->site()->setting('home_page', '0');
        $this->site()->clearPageCache();

        $this->assertPage('/', 200, 'Our new website is live');
    }
}
