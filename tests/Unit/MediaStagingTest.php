<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\MediaStaging;

/** Files waiting for approval (#150, Core\MediaStaging): the pure helpers and the server configurations that route to them. */
final class MediaStagingTest extends TestCase
{
    public function testVariantsNameTheirUpload(): void
    {
        $stems = array_map(MediaStaging::stem(...), ['media/2026/10/photo-ab12cd.jpg', 'media/2026/10/photo-ab12cd-1200.jpg', 'media/2026/10/photo-ab12cd-nahled.jpg.webp',
            'media/2026/10/photo-ab12cd.jpg.avif', 'media/2026/10/photo-ab12cd-nahled.png']);

        $this->assertSame(['media/2026/10/photo-ab12cd'], array_values(array_unique($stems)), 'every variant, sibling and the PNG thumbnail of a GIF has the stem of the upload');
        $this->assertNotSame(MediaStaging::stem('media/2026/10/photo-ab12cd.jpg'), MediaStaging::stem('media/2026/10/photo-ab12cd-ef3456.jpg'), 'another upload whose name starts the same is another stem');
    }

    public function testHeadersMatchWhatTheServerGivesMedia(): void
    {
        $svg = MediaStaging::headers('media/2026/10/logo-ab12cd.svg');
        $this->assertSame('image/svg+xml', $svg['Content-Type']);
        $this->assertStringContainsString('sandbox', $svg['Content-Security-Policy'], 'SVG in a sandbox');
        $this->assertSame(['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff'], MediaStaging::headers('media/2026/10/photo-ab12cd.jpg.webp'));
        $this->assertSame('application/octet-stream|attachment', implode('|', array_intersect_key(MediaStaging::headers('media/2026/10/offer-ab12cd.docx'), ['Content-Type' => 1, 'Content-Disposition' => 1])),
            'a document (and any type not listed) is a download');
        $this->assertSame('attachment', MediaStaging::headers('media/2026/10/list-ab12cd.csv')['Content-Disposition'] ?? '', 'CSV is never shown as text in the site');
    }

    public function testAClassCountsWhereABuildUsesIt(): void
    {
        $build = [['type' => 'section', 'classes' => ['band'], 'children' => [['type' => 'container', 'children' => [['type' => 'image', 'classes' => ['hero-bg']]]]]]];

        $this->assertTrue(MediaStaging::usesClass($build, 'hero-bg'), 'a class deep in the tree');
        $this->assertFalse(MediaStaging::usesClass($build, 'section'), 'a type is not a class');
        $this->assertFalse(MediaStaging::usesClass(null, 'band'), 'no build');
    }

    public function testServersSendAMissingMediaFileToIndexPhp(): void
    {
        $nginx = (string) file_get_contents(TALEA_ROOT . '/system/nginx.example.conf');
        $this->assertStringContainsString('try_files $uri$avif_suffix $uri$webp_suffix $uri /index.php?$query_string;', $nginx, 'nginx: a missing image goes to index.php');
        $this->assertSame(3, substr_count($nginx, 'try_files $uri /index.php?$query_string;'), 'nginx: SVG, documents and every other missing media file too');
        $this->assertMatchesRegularExpression('/@static \{\s*path_regexp[^\n]*\n\s*file\s*\n\s*\}/', (string) file_get_contents(TALEA_ROOT . '/docker/Caddyfile'),
            'Caddy: the long cache lifetime only for files that exist (a waiting file is private)');
    }
}
