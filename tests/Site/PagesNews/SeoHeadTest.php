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

    public function testBreadcrumbTitleNamesTheLastItem(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Team', 'slug' => 'team', 'visible' => true, 'in_menu' => false, 'content' => '<p>Team.</p>']);
        $site->exec("UPDATE tl_pages SET breadcrumb_title = 'Crumb name' WHERE slug = 'team'");
        $site->clearPageCache();
        $this->assertStringContainsString('"name":"Crumb name"', $site->client('visitor')->get('/team')->body);
    }
}
