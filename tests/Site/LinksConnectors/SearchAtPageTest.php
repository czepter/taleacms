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

    #[Depends('testSearchDataAtThePageInTheListAndOverMcp')]
    public function testKeywordsThatDoNotFitAndPagesNotInSearch(): void
    {
        $site = $this->site();
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'services'");
        $tab = '/admin.php?module=pages&action=website&id=' . $uuid . '&tab=seo';
        $site->exec("UPDATE tl_pages SET focus_keywords = 'talea services' WHERE slug = 'services'");

        $this->adminPost('/admin.php?module=pages&action=seo_keyword_add', ['id' => $uuid, 'keyword' => 'Talea Services'], $tab);
        $this->assertSame('talea services', (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'"), 'keyword: a stored keyword is not added twice');
        $this->assertPage($tab, 200, 'This is already a focus keyword of the page.', message: 'keyword: with a notice');

        // a search with a comma would be stored as two keywords: no button, and the action refuses it
        $day = (string) $site->value("SELECT MAX(day) FROM tl_search_stats WHERE engine = 'google'");
        $site->exec('INSERT INTO tl_search_stats (day, engine, kind, `key`, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$day, 'google', 'page_query', 'plumber berlin, cheap', rtrim($site->base, '/') . '/services', 3, 30, 10, 7]);
        $body = $this->assertPage($tab, 200, 'plumber berlin, cheap', message: 'keyword: the search with a comma is listed')->body;
        $this->assertStringNotContainsString('<span class="visually-hidden">: plumber berlin, cheap</span>', $body, 'keyword: but has no button');
        $this->assertStringContainsString('<span class="visually-hidden">: company website free</span>', $body, 'keyword: the button names its search for screen readers');
        $this->adminPost('/admin.php?module=pages&action=seo_keyword_add', ['id' => $uuid, 'keyword' => 'plumber berlin, cheap'], $tab);
        $this->assertSame('talea services', (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'"), 'keyword: a search with a comma is refused');

        // a published page the sitemap lists that Google has not shown
        $this->assertArrayHasKey('result', $site->mcp('create_page', ['title' => 'Prices', 'slug' => 'prices', 'content' => '<p>Text</p>', 'visible' => 1]), 'list: the page is created');
        $list = $this->assertPage('/admin.php?module=pages', 200, message: 'list: a page not in search')->body;
        $this->assertMatchesRegularExpression('#>/prices (?:(?!</tr>).)*Not in search yet#s', $list, 'list: "Not in search yet" on its row');
        $this->assertDoesNotMatchRegularExpression('#>/services (?:(?!</tr>).)*Not in search yet#s', $list, 'list: not on a page with numbers');
    }

    #[Depends('testSearchDataAtThePageInTheListAndOverMcp')]
    public function testTheOverviewComparesWithFourWeeksEarlier(): void
    {
        $site = $this->site();
        $insert = 'INSERT INTO tl_search_stats (day, engine, kind, `key`, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $earlier = [];
        foreach (['google' => 41, 'bing' => 9] as $engine => $clicks) {
            $day = (string) $site->value('SELECT MAX(day) FROM tl_search_stats WHERE engine = ?', [$engine]);
            $earlier[$engine] = trim($site->php('echo date("Y-m-d", strtotime(' . var_export($day . ' -28 days', true) . '));'));
            $site->exec($insert, [$earlier[$engine], $engine, 'total', '', '', $clicks, 500, 1, 0]);
        }
        $this->assertPage('/admin.php?module=seo', 200, 'Clicks from search, 28 days · +18 %</span>', message: 'overview: 59 clicks against 50 four weeks earlier');

        $site->exec("UPDATE tl_search_stats SET clicks = 0 WHERE kind = 'total' AND day IN (?, ?)", array_values($earlier));
        $this->assertPage('/admin.php?module=seo', 200, 'Clicks from search, 28 days</span>', message: 'overview: no change against 0 clicks');
    }

    #[Depends('testSearchDataAtThePageInTheListAndOverMcp')]
    public function testWithoutTheStatisticsRightNoSearchData(): void
    {
        $site = $this->site();
        $uuid = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'services'");
        $tab = '/admin.php?module=pages&action=website&id=' . $uuid . '&tab=seo';
        $before = (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'");
        // an author: the Pages right, no Statistics, may not change a published page
        $site->exec("INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('search-author', ?, 'Search author', 0, NOW(), NOW())", [password_hash($site->password, PASSWORD_DEFAULT)]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = 'search-author'");
        $author = $site->client('search-author');
        $site->signIn($author, 'search-author');

        $this->assertPageLacks($tab, 'In search · 28 days', as: $author, message: 'rights: no search block without Statistics');
        $this->assertPageLacks('/admin.php?module=pages', 'Clicks · place', as: $author, message: 'rights: no list column without Statistics');
        $token = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'author', ?, NOW() FROM tl_users WHERE username = 'search-author'", [hash('sha256', $token)]);
        $this->assertStringContainsString('"search":null', json_encode($site->mcpResult('get_page', ['id' => $uuid], $token)), 'rights: get_page has no search data');
        $this->assertStringContainsString('Statistics', json_encode($site->mcp('get_search_performance', [], $token)), 'rights: get_search_performance is refused');

        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'stats' FROM tl_users WHERE username = 'search-author'");
        $body = $this->assertPage($tab, 200, 'In search · 28 days', as: $author, message: 'rights: with Statistics the block shows')->body;
        $this->assertStringNotContainsString('Add as keyword', $body, 'rights: no button for a page the author may not change');
        $author->post('/admin.php?module=pages&action=seo_keyword_add', ['_csrf' => $author->get($tab)->csrf(), 'id' => $uuid, 'keyword' => 'company website free']);
        $this->assertSame($before, (string) $site->value("SELECT focus_keywords FROM tl_pages WHERE slug = 'services'"), 'rights: the author\'s post is refused');
    }
}
