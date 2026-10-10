<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Appearance;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Favicon, web manifest and the page cache of anonymous visitors (was: section "ikony, manifest, cache" of tools/test.sh). */
#[Group('site')]
final class IconsManifestCacheTest extends SiteTestCase
{
    public function testWithoutAnIconTheFaviconRequestIsNotA404Page(): void
    {
        $this->assertSame(204, $this->site()->client()->get('/favicon.ico')->status, 'favicon.ico without an icon does not generate a 404 page');
    }

    public function testTheSiteHasAWebManifest(): void
    {
        $this->assertStringContainsString('"start_url"', $this->site()->client()->get('/manifest.webmanifest')->body, 'manifest webu');
    }

    public function testALinkWithTrackingParametersIsServedFromTheCache(): void
    {
        $visitor = $this->site()->client();
        $this->site()->clearPageCache();
        $visitor->get('/news');

        $response = $visitor->get('/news?utm_source=newsletter&fbclid=x');

        $this->assertMatchesRegularExpression('/^talea/i', $response->headers['x-cache'] ?? '', 'a link with utm parameters is served from the cache');
    }

    public function testACachedPageAnswers304ToAMatchingEtag(): void
    {
        $visitor = $this->site()->client();
        $etag = $visitor->get('/news')->headers['etag'] ?? '';
        $this->assertNotSame('', $etag, 'the cached page has an ETag');

        $this->assertSame(304, $visitor->get('/news', ['If-None-Match: ' . $etag])->status, 'a cached page answers 304 to a matching ETag');
    }
}
