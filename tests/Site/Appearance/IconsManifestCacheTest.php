<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Appearance;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Favicon, web manifest and the page cache of anonymous visitors (was: section "ikony, manifest, cache" of tools/test.sh). */
#[Group('site')]
final class IconsManifestCacheTest extends SiteTestCase
{
    public function testWithoutAnIconTheFaviconRequestIsNotA404Page(): void
    {
        $this->assertSame(204, $this->site()->client()->get('/favicon.ico')->status, 'favicon.ico bez ikony nevygeneruje stránku 404');
    }

    public function testTheSiteHasAWebManifest(): void
    {
        $this->assertStringContainsString('"start_url"', $this->site()->client()->get('/manifest.webmanifest')->body, 'manifest webu');
    }

    public function testALinkWithTrackingParametersIsServedFromTheCache(): void
    {
        $visitor = $this->site()->client();
        $this->site()->clearPageCache();
        $visitor->get('/novinky');

        $response = $visitor->get('/novinky?utm_source=newsletter&fbclid=x');

        $this->assertMatchesRegularExpression('/^kaleta/i', $response->headers['x-cache'] ?? '', 'odkaz s utm parametry jde z cache');
    }

    public function testACachedPageAnswers304ToAMatchingEtag(): void
    {
        $visitor = $this->site()->client();
        $etag = $visitor->get('/novinky')->headers['etag'] ?? '';
        $this->assertNotSame('', $etag, 'the cached page has an ETag');

        $this->assertSame(304, $visitor->get('/novinky', ['If-None-Match: ' . $etag])->status, 'stránka z cache odpoví 304 na shodný ETag');
    }
}
