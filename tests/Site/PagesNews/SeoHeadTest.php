<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;

/** Title templates, robots, canonical and sharing tags of the "Search engines" section. */
#[Group('site')]
final class SeoHeadTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    public function testHeadTagsFollowTheSeoFields(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'My website');
        $site->setting('seo_separator', '|');
        $site->mcp('create_page', ['title' => 'About', 'slug' => 'about', 'visible' => true, 'in_menu' => false, 'content' => '<p>About us.</p>']);
        $site->exec("UPDATE tl_pages SET seo_title = '', robots = 'nofollow', canonical_url = 'https://example.org/x', share_title = 'Share me', breadcrumb_title = 'About crumb' WHERE slug = 'about'");
        $site->clearPageCache();

        $body = $site->client('visitor')->get('/about')->body;
        $this->assertStringContainsString('<title>About | My website</title>', $body);
        $this->assertStringContainsString('<meta name="robots" content="index, nofollow">', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.org/x">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.org/x">', $body, 'og:url follows the canonical override');
        $this->assertStringContainsString('<meta property="og:title" content="Share me">', $body);

        $site->setting('seo_card', 'small');
        $site->clearPageCache();
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $site->client('visitor')->get('/about')->body);

        $site->exec("UPDATE tl_pages SET noindex = TRUE WHERE slug = 'about'");
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/about')->body;
        $this->assertStringNotContainsString('rel="canonical"', $body);
        $this->assertStringNotContainsString('/about</loc>', $site->client('visitor')->get('/sitemap.xml')->body);
    }

    public function testAnOwnTitleIsUsedAsBefore(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'My website');
        $site->mcp('create_page', ['title' => 'Prices', 'slug' => 'prices', 'visible' => true, 'in_menu' => false, 'content' => '<p>Prices.</p>']);
        $site->exec("UPDATE tl_pages SET seo_title = 'Our prices' WHERE slug = 'prices'");
        $site->clearPageCache();
        $this->assertStringContainsString('<title>Our prices – My website</title>', $site->client('visitor')->get('/prices')->body);
    }

    public function testAnOwnTitleIsLiteral(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'My website');
        $site->mcp('create_page', ['title' => 'Offer', 'slug' => 'offer', 'visible' => true, 'in_menu' => false, 'content' => '<p>Offer.</p>']);
        $site->exec("UPDATE tl_pages SET seo_title = 'Offer %sitename% %year%', share_title = '' WHERE slug = 'offer'");
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/offer')->body;
        $this->assertStringContainsString('<title>Offer %sitename% %year% – My website</title>', $body, 'no variables in an own title');
        $this->assertMatchesRegularExpression('/<meta property="og:title" content="Offer %sitename% %year%[^"]*">/', $body);
    }

    /** @return list<array<string, mixed>> the itemListElement of the page's BreadcrumbList */
    private function breadcrumbs(string $body): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m);
        foreach ($m[1] as $json) {
            foreach (json_decode($json, true)['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? '') === 'BreadcrumbList') {
                    return $node['itemListElement'];
                }
            }
        }

        return [];
    }

    public function testBreadcrumbTitleNamesTheLastItem(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Team', 'slug' => 'team', 'visible' => true, 'in_menu' => false, 'content' => '<p>Team.</p>']);
        $site->exec("UPDATE tl_pages SET breadcrumb_title = 'Crumb name' WHERE slug = 'team'");
        $site->clearPageCache();
        $crumbs = $this->breadcrumbs($site->client('visitor')->get('/team')->body);
        $this->assertSame('Crumb name', end($crumbs)['name']);
    }

    public function testNewsItem(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_categories (name, slug, description, language) VALUES ('Stories', 'stories', '', '')");
        $site->exec("INSERT INTO tl_news (slug, title, intro, text, category_id, published_at, visible, language) VALUES ('first-post', 'First post', '<p>The lead of the first post.</p>', '<p>Hello</p>', ?, '2026-01-01 10:00:00', TRUE, '')",
            [(int) $site->value("SELECT category_id FROM tl_categories WHERE slug = 'stories'")]);
        $site->exec("UPDATE tl_news SET robots = 'noarchive', canonical_url = 'https://example.org/post', share_title = 'Share post', breadcrumb_title = 'Post crumb' WHERE slug = 'first-post'");
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/news/first-post')->body;
        $this->assertStringContainsString('<meta name="robots" content="index, noarchive">', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.org/post">', $body);
        $this->assertStringContainsString('<meta property="og:title" content="Share post">', $body);
        $crumbs = $this->breadcrumbs($body);
        $this->assertSame('Post crumb', end($crumbs)['name']);

        // %excerpt% is the start of the description the item ends up with; a description template fills an item without its own
        $site->setting('seo_separator', '–');
        $site->setting('seo_title_news', '%title% %sep% %excerpt%');
        $site->setting('seo_description_news', '%title% on %sitename%');
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/news/first-post')->body;
        $this->assertStringContainsString('<title>First post – The lead of the first post.</title>', $body);
        $this->assertStringContainsString('<meta name="description" content="First post on ', $body);
        $this->assertStringContainsString('<meta property="og:description" content="First post on ', $body);
        $site->setting('seo_title_news', '%title% %sep% %sitename%');
        $site->setting('seo_description_news', '');
    }

    public function testCollectionItem(): void
    {
        $site = $this->site();
        $site->admin()->post('/admin.php?module=collections&action=save', ['_csrf' => $site->csrf(), 'collection_id' => 0, 'name' => 'Crew', 'detail' => 1, 'fields' => [['label' => 'Role', 'type' => 'text']]]);
        $site->mcp('save_collection_item', ['collection' => 'crew', 'name' => 'Jane Doe', 'values' => ['role' => 'Lead'], 'visible' => true]);
        $site->exec("UPDATE tl_collection_items SET robots = 'nosnippet', canonical_url = 'https://example.org/jane' WHERE slug = 'jane-doe'");
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/crew/jane-doe')->body;
        $this->assertStringContainsString('<meta name="robots" content="index, nosnippet">', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.org/jane">', $body);

        // a collection without a parent page: the trail is the collection, then the item (or its breadcrumb title)
        $crumbs = $this->breadcrumbs($body);
        $this->assertSame(['Home', 'Crew', 'Jane Doe'], array_column($crumbs, 'name'));
        $site->exec("UPDATE tl_collection_items SET breadcrumb_title = 'Jane' WHERE slug = 'jane-doe'");
        $site->clearPageCache();
        $crumbs = $this->breadcrumbs($site->client('visitor')->get('/crew/jane-doe')->body);
        $this->assertSame(['Home', 'Crew', 'Jane'], array_column($crumbs, 'name'));
    }

    public function testHomePageUsesTheHomeTemplate(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'My website');
        $site->setting('site_description', 'Joinery in Jena');
        $site->setting('seo_separator', '–');
        $site->clearPageCache();
        $this->assertStringContainsString('<title>My website – Joinery in Jena</title>', $site->client('visitor')->get('/')->body);
    }

    public function testEmptyCategoriesAndTagsAreNoindexUnlessSwitchedOff(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_categories (name, slug, description, language) VALUES ('Empty cat', 'empty-cat', '', '')");
        $site->exec("INSERT INTO tl_tags (public_id, name, slug) VALUES (?, 'Empty tag', 'empty-tag')", ['6c1f0a52-3b7e-4c5d-9a10-2f4e8d7b1c33']);
        foreach (['1' => true, '0' => false] as $setting => $noindex) {
            $site->setting('seo_hide_empty_terms', (string) $setting);
            $site->clearPageCache();
            foreach (['/news/category/empty-cat', '/news/tag/empty-tag'] as $path) {
                $body = $site->client('visitor')->get($path)->body;
                $this->assertSame($noindex, str_contains($body, '<meta name="robots" content="noindex, follow">'), "$path with seo_hide_empty_terms=$setting");
            }
        }
    }

    public function testDescriptionTemplateFillsAPageWithoutItsOwn(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Offers', 'slug' => 'offers', 'visible' => true, 'in_menu' => false, 'content' => '<p>Short.</p>']);
        $site->exec("UPDATE tl_pages SET description = '' WHERE slug = 'offers'");
        $site->setting('seo_description_page', '%title% by %sitename%');
        $site->clearPageCache();
        $this->assertMatchesRegularExpression('#<meta name="description" content="Offers by [^"]+">#', $site->client('visitor')->get('/offers')->body);
        $site->exec("UPDATE tl_pages SET description = 'Own text' WHERE slug = 'offers'");
        $site->clearPageCache();
        $this->assertStringContainsString('<meta name="description" content="Own text">', $site->client('visitor')->get('/offers')->body);
        $site->setting('seo_description_page', '');
    }

    public function testSavingTheSeoTabKeepsTheTitleSettings(): void
    {
        $site = $this->site();
        $site->setting('seo_hide_empty_terms', '1');
        $site->setting('seo_capitalize', '1');
        $site->admin()->post('/admin.php?module=settings&action=save', ['_csrf' => $site->csrf(), 'tab' => 'seo', 'indexing' => '1']);
        $this->assertSame('1', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_hide_empty_terms'"));
        $this->assertSame('1', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'seo_capitalize'"));
    }

    public function testWithIndexingOffThereIsExactlyOneRobotsTag(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Quiet', 'slug' => 'quiet', 'visible' => true, 'in_menu' => false, 'content' => '<p>Quiet.</p>']);
        $site->exec("UPDATE tl_pages SET robots = 'nofollow' WHERE slug = 'quiet'");
        $site->setting('indexing', '0');
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/quiet')->body;
        $site->setting('indexing', '1');
        $this->assertSame(1, substr_count($body, '<meta name="robots"'));
    }

    public function testAForeignCanonicalLeavesTheSitemap(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Moved', 'slug' => 'moved', 'visible' => true, 'in_menu' => false, 'content' => '<p>Moved.</p>']);
        $site->mcp('create_page', ['title' => 'Stays', 'slug' => 'stays', 'visible' => true, 'in_menu' => false, 'content' => '<p>Stays.</p>']);
        $site->exec("UPDATE tl_pages SET canonical_url = 'https://example.org/elsewhere' WHERE slug = 'moved'");
        $site->clearPageCache();
        $sitemap = $site->client('visitor')->get('/sitemap.xml')->body;
        $this->assertStringNotContainsString('/moved</loc>', $sitemap);
        $this->assertStringContainsString('/stays</loc>', $sitemap);
        $this->assertStringNotContainsString("\n\n", $sitemap, 'a left-out row leaves no empty line');

        // its own address in another spelling (upper-case host, a trailing slash) is no foreign canonical
        $site->exec("INSERT INTO tl_categories (name, slug, description, language) VALUES ('Notes', 'notes', '', '')");
        $site->exec("INSERT INTO tl_news (slug, title, intro, text, category_id, published_at, visible, language) VALUES ('own-post', 'Own post', '', '<p>Own.</p>', ?, '2026-01-01 10:00:00', TRUE, '')",
            [(int) $site->value("SELECT category_id FROM tl_categories WHERE slug = 'notes'")]);
        $own = (string) preg_replace_callback('#^(https?://)([^/]+)#', fn (array $m): string => $m[1] . strtoupper($m[2]), rtrim($site->base, '/')) . '/news/own-post/';
        $site->exec('UPDATE tl_news SET canonical_url = ? WHERE slug = ?', [$own, 'own-post']);
        $site->clearPageCache();
        $this->assertStringContainsString('/news/own-post</loc>', $site->client('visitor')->get('/sitemap.xml')->body);
    }
}
