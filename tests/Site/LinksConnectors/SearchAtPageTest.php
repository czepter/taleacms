<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Search data next to each page: the SEO tab, the pages list, the Search engines overview and MCP (Core\SearchInsights). */
#[Group('site')]
final class SearchAtPageTest extends SiteTestCase
{
    use FakeServices;

    public function testWithoutAConnectionNothingIsShown(): void
    {
        $this->assertPage('/admin.php?module=seo', 200, ['Search performance', 'not connected'], message: 'overview: the card says nothing is connected');
        $this->assertPageLacks('/admin.php?module=pages', 'Clicks · place', message: 'pages list: no search column without data');
        $result = $this->mcpJson('get_search_performance');
        $this->assertStringContainsString('"google":null', $result, 'mcp: Google gives null without a connection');
        $this->assertStringContainsString('"note":"google: not connected', $result, 'mcp: with a note saying why');
        $this->assertStringNotContainsString('"summary"', $result, 'mcp: no numbers without data');
    }

    #[Depends('testWithoutAConnectionNothingIsShown')]
    public function testSearchDataAtThePageInTheListAndOverMcp(): void
    {
        $site = $this->site();
        $this->connectFake('google');
        $this->adminPost('/admin.php?module=connectors&action=properties', [], '/admin.php?module=connectors');
        $this->adminPost('/admin.php?module=connectors&action=property', ['site' => 'sc-domain:example.com'], '/admin.php?module=connectors');
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'bing', 'client_id' => '', 'account' => '', 'secret' => 'bing-test-key', 'config' => ['site_url' => 'https://example.com/']], '/admin.php?module=connectors');
        $this->runJob('search_data');
        // the fake services report example.com: their addresses become this site's
        $base = rtrim($site->base, '/');
        $site->exec("UPDATE tl_search_stats SET `key` = REPLACE(`key`, 'https://example.com', ?), page = REPLACE(page, 'https://example.com', ?)", [$base, $base]);
        $site->exec("UPDATE tl_pages SET visible = 1, focus_keywords = '' WHERE slug = 'services'");
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'services'");
        $tab = '/admin.php?module=pages&action=website&id=' . $uuid . '&tab=seo';

        $overview = $this->assertPage('/admin.php?module=seo', 200, ['Clicks from search, 28 days', 'Average place in search', '2 connected'], message: 'overview: the search tiles and the card');
        $this->assertStringContainsString('<strong>59</strong>', $overview->body, 'overview: 50 Google + 9 Bing clicks');

        $this->assertPage('/admin.php?module=pages', 200, ['Clicks · place', '31 · 6.2'], message: 'pages list: clicks and place of the page');

        $this->assertPage($tab, 200, ['In search · 28 days', 'talea services', 'Add as keyword', 'From Google Search Console.'], message: 'SEO tab: the searches that led to the page');
        $this->adminPost('/admin.php?module=pages&action=seo_keyword_add', ['id' => $uuid, 'keyword' => 'talea services'], $tab);
        $this->assertSame('talea services', (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'"), 'SEO tab: the search became a focus keyword');
        $this->assertNotNull($site->value("SELECT seo_score FROM tl_pages WHERE slug = 'services'"), 'SEO tab: the page got a score for it');
        $this->assertPage($tab, 200, 'Focus keyword</span>', message: 'SEO tab: the search is now tagged as a focus keyword');

        $five = "talea services\none\ntwo\nthree\nfour";
        $site->exec('UPDATE tl_pages SET focus_keywords = ? WHERE slug = ?', [$five, 'services']);
        $this->adminPost('/admin.php?module=pages&action=seo_keyword_add', ['id' => $uuid, 'keyword' => 'company website free'], $tab);
        $this->assertSame($five, (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'"), 'SEO tab: a sixth keyword is refused');
        $this->assertPage($tab, 200, 'A page has up to five focus keywords.', message: 'SEO tab: with a notice');

        $result = $this->mcpJson('get_search_performance', ['page' => $uuid]);
        $this->assertStringContainsString('"queries":[{"query":', $result, 'mcp: the top searches');
        $this->assertStringContainsString('"page_id":"' . $uuid . '"', $result, 'mcp: a top page is named by its public id');
        $this->assertStringContainsString('"page":{"totals":{"clicks":31', $result, 'mcp: the numbers of the page asked for');
        $this->assertStringContainsString('"query":"talea services"', $result, 'mcp: and its searches');
        $this->assertStringNotContainsString('bing-test-key', $result, 'mcp: never the Bing key');
        $this->assertStringContainsString('"search":{"totals":{"clicks":31', $this->mcpJson('get_page', ['id' => $uuid]), 'get_page: the search block');
    }
}
