<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Share images drawn by the site (was: section 71,). */
#[Group('site')]
final class ShareImagesTest extends SiteTestCase
{
    use McpHelpers;

    private static int $page = 0;
    private static string $imageUrl = '';

    private function ogImage(string $body): string
    {
        return preg_match('/property="og:image" content="([^"]*)"/', $body, $m) === 1 ? $m[1] : '';
    }

    private function fetchPage(): \Kaleta\Tests\Site\Support\Response
    {
        $this->site()->clearPageCache();

        return $this->site()->client()->get('/wooden-stairs');
    }

    public function testAPageWithoutAnImagePointsOgImageToAGeneratedPng(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            $this->markTestSkipped('The PHP used by the test has no GD – the image checks are skipped.');
        }
        $this->site()->exec("DELETE FROM ka_settings WHERE name IN ('share_image', 'share_image_auto')");
        self::$page = $this->createPage(['title' => 'Custom wooden stairs', 'slug' => 'wooden-stairs', 'visible' => true, 'content' => '<p>Stairs.</p>']);

        $response = $this->fetchPage();
        self::$imageUrl = $this->ogImage($response->body);

        $this->assertMatchesRegularExpression('#^' . preg_quote($this->site()->base, '#') . '/og/[a-f0-9]+\.png$#', self::$imageUrl, 'og:image of a page without an image is /og/<hash>.png');
        $this->assertTrue($response->contains('og:image:width" content="1200"'), 'the size is announced');
        $this->assertTrue($response->contains('twitter:card" content="summary_large_image"'), 'the Twitter card is the large one');
    }

    public function testThePictureIsA1200x630PngCachedForAYear(): void
    {
        $picture = $this->site()->client()->get(self::$imageUrl);
        $this->assertSame(200, $picture->status, 'the picture is served');
        $this->assertSame('image/png', $picture->headers['content-type'] ?? '', 'the picture is served as PNG');

        $file = $this->site()->workDir('og') . '/og.png';
        file_put_contents($file, $picture->body);
        $size = @getimagesize($file);
        $this->assertSame('1200x630', $size ? $size[0] . 'x' . $size[1] : 'none', 'the PNG is 1200×630');
        $this->assertStringStartsWith('public, max-age=31536000', strtolower($picture->headers['cache-control'] ?? ''), 'cached for a year (the address changes with the content)');
    }

    public function testATamperedHashOrAnotherExtensionIsNotFound(): void
    {
        $hash = substr(self::$imageUrl, (int) strrpos(self::$imageUrl, '/og/') + 4, -4);
        $visitor = $this->site()->client();

        $this->assertSame(404, $visitor->get('/og/' . substr($hash, 1) . '0.png')->status, 'a tampered hash is 404 – nobody makes the site draw their own text');
        $this->assertSame(404, $visitor->get('/og/' . $hash . '.jpg')->status, 'only the PNG address exists');
    }

    public function testMcpShowsTheGeneratedAddress(): void
    {
        $page = $this->site()->mcpResult('get_page', ['id' => self::$page]);

        $this->assertSame(self::$imageUrl, $page['share_image_generated'] ?? null, 'get_page shows the generated address as share_image_generated');
    }

    public function testAChangedTitleIsANewAddress(): void
    {
        $this->site()->exec("UPDATE ka_pages SET title = 'Stone stairs' WHERE page_id = ?", [self::$page]);
        $url = $this->ogImage($this->fetchPage()->body);

        $this->assertNotSame(self::$imageUrl, $url, 'the address changed with the title (no stale copies at the social networks)');
        $this->assertStringStartsWith($this->site()->base . '/og/', $url);
        $this->assertStringEndsWith('.png', $url);
    }

    public function testAPageWithItsOwnImageKeepsIt(): void
    {
        $this->site()->exec("UPDATE ka_pages SET image = 'media/2026/01/sharing.jpg' WHERE page_id = ?", [self::$page]);
        $response = $this->fetchPage();

        $this->assertTrue($response->matches('#og:image" content="http[^"]*/media/2026/01/sharing.jpg"#'), "a page's own share image is used");
        $this->assertFalse($response->contains('/og/'), 'no generated image next to it');
    }

    public function testWithTheSettingOffThereIsNoGeneratedImage(): void
    {
        $this->site()->exec("UPDATE ka_pages SET image = '' WHERE page_id = ?", [self::$page]);
        $this->site()->setting('share_image_auto', '0');
        $response = $this->fetchPage();

        $this->assertFalse($response->contains('og:image'), 'the setting off – no og:image, as before');
        $this->assertTrue($response->contains('twitter:card" content="summary"'), 'the Twitter card is the small one');
        $this->assertSame(404, $this->site()->client()->get(self::$imageUrl)->status, 'the setting off – the picture is not served either');
        $page = $this->site()->mcpResult('get_page', ['id' => self::$page]);
        $this->assertNull($page['share_image_generated'] ?? null, 'get_page without share_image_generated when the setting is off');
        $this->assertPage('/admin.php?module=settings&tab=seo', 200, 'name="share_image_auto"', message: 'settings → SEO offers the switch');

        $this->site()->exec("DELETE FROM ka_settings WHERE name = 'share_image_auto'");
        $this->mcpText('trash_page', ['id' => self::$page]);
    }
}
