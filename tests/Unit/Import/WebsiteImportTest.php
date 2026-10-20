<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;

final class WebsiteImportTest extends TestCase
{
    /** 2.6: import from a website */
    public function testImportFromAWebsite(): void
    {
        $this->assertSame([[['https://a.cz/', 'https://a.cz/o-nas'], []], [[], ['https://a.cz/page-sitemap.xml']], [[], []], [[], []]], [
            \Talea\Core\WebImport::sitemap('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.cz/</loc></url><url><loc> https://a.cz/o-nas </loc></url></urlset>'),
            \Talea\Core\WebImport::sitemap('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.cz/page-sitemap.xml</loc></sitemap></sitemapindex>'),
            \Talea\Core\WebImport::sitemap('<html>not a sitemap'), \Talea\Core\WebImport::sitemap('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>'),
        ], '2.6 WebImport::sitemap: pages and nested sitemaps'); // an external entity is never loaded
        $this->assertSame(['https://a.cz/o-nas/?id=5', '', 'https://a.cz/dir/b/c', 'https://cdn.a.cz/x.jpg', ['https://a.cz/x']], [
            \Talea\Core\WebImport::normalize('HTTPS://A.cz/o-nas/index.html?utm_source=x&id=5#top'), \Talea\Core\WebImport::normalize('ftp://a.cz/x'),
            \Talea\Core\WebImport::absolute('b/c', 'https://a.cz/dir/page'), \Talea\Core\WebImport::absolute('//cdn.a.cz/x.jpg', 'https://a.cz/'),
            \Talea\Core\WebImport::links('<a href="/x">1</a><a href="mailto:a@a.cz">2</a><a href="javascript:alert(1)">3</a><a href="#top">4</a>', 'https://a.cz/'),
        ], '2.6 WebImport: addresses – normalized, absolute, links without mail and scripts');
        $webPage = \Talea\Core\WebImport::extract('<html><head><title>About us | Acme</title><meta name="description" content="Who we are"></head><body>'
            . '<header><nav><a href="/">Home</a></nav></header><main><h1>About us</h1><p>We build oak furniture since 1990, for homes and offices across the region. ' . str_repeat('More text. ', 10) . '</p>'
            . '<img data-src="/img/team.jpg" src="data:image/gif;base64,x" alt="Our team"><p><a href="https://www.a.cz/contact/">Contact</a> <a href="https://other.cz/">Partner</a></p>'
            . '<div class="cookie-banner">We use cookies</div><script>alert(1)</script><form><input name="q"></form></main><footer>© Acme</footer></body></html>', 'https://www.a.cz/about-us/');
        $this->assertSame(['About us', 'Who we are', true, false, false, false, false, false, false, true, true, true, false], [
            $webPage['title'], $webPage['description'], str_contains($webPage['content'], 'oak furniture'), str_contains($webPage['content'], 'Home'), str_contains($webPage['content'], '©'),
            str_contains($webPage['content'], 'cookies'), str_contains($webPage['content'], 'alert'), str_contains($webPage['content'], '<form'), str_contains($webPage['content'], '<h1'),
            str_contains($webPage['content'], 'src="https://www.a.cz/img/team.jpg"'), str_contains($webPage['content'], 'href="/contact"'), str_contains($webPage['content'], 'href="https://other.cz/"'), $webPage['article'],
        ], '2.6 WebImport::extract: the main content without the header, footer, cookie bar, script and form');
        $webArticle = \Talea\Core\WebImport::extract('<html><head><title>New workshop</title><meta property="article:published_time" content="2025-03-04T10:00:00+01:00"></head><body><article><p>' . str_repeat('We opened a new workshop. ', 6) . '</p></article></body></html>', 'https://a.cz/2025/03/new-workshop');
        $this->assertSame(['New workshop', '2025-03-04', true], [$webArticle['title'], substr($webArticle['date'], 0, 10), $webArticle['article']], '2.6 WebImport::extract: an article with its date, the title from <title> without the site name');
        $this->assertSame([true, false, false], [(new \Talea\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://cdn.wix.example/x.jpg'),
            (new \Talea\Core\ImageDownloader('https://a.cz'))->isAllowedUrl('https://cdn.wix.example/x.jpg'), (new \Talea\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://user:pw@cdn.example/x.jpg')], '2.6 ImageDownloader: images from any public host only when the import allows it');
    }
}
