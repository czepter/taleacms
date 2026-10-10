<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Gallery layouts (issue 26): grid, masonry, justified rows, slideshow, full screen, the Media folder source, names and alt texts. */
#[Group('site')]
final class GalleryLayoutsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private function gallery(string $layout, array $extra = []): array
    {
        $photos = [['src' => 'media/2026/05/gl-a.jpg', 'alt' => 'Workshop'], ['src' => 'media/2026/05/gl-b.jpg', 'alt' => 'Kitchen']];

        return ['type' => 'gallery', 'content' => array_replace(['photos' => $photos, 'layout' => $layout], $extra)];
    }

    public function testEveryLayoutRendersWithDimensionsNamesAndOnlyTheGridNeedsNoScript(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_media (image_path, thumb_path, name, image_width, image_height, created_at) VALUES ('media/2026/05/gl-a.jpg', '', 'A', 1600, 900, NOW()), ('media/2026/05/gl-b.jpg', '', 'B', 900, 1200, NOW())");
        $site->exec("INSERT INTO tl_media_folders (name) VALUES ('Gallery test')");
        $folder = (int) $site->value("SELECT folder_id FROM tl_media_folders WHERE name = 'Gallery test'");
        $site->exec("INSERT INTO tl_media (image_path, thumb_path, name, image_width, image_height, folder_id, created_at) VALUES ('media/2026/05/gl-c.jpg', '', 'From the folder', 800, 800, ?, NOW())", [$folder]);

        $text = $this->rawText('save_build', ['id' => $site->publicId('pages', $this->zPage()), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            $this->gallery('grid'),
            $this->gallery('masonry', ['label' => 'Masonry work']),
            $this->gallery('justified'),
            $this->gallery('slideshow', ['label' => 'Slides', 'caption' => 'Our rooms']),
            $this->gallery('fullscreen', ['photos' => [], 'folder' => $site->publicId('media_folders', $folder), 'label' => 'From a folder']),
        ]]]]]);
        $this->assertStringContainsString('"errors":[]', $text);
        $site->clearPageCache();
        $body = $this->visit('/z-html');

        $this->assertStringContainsString('<figure class="tl-gallery"><img', $body, 'the grid is unchanged: no modifier, no role, no script hook');
        foreach (['tl-gallery tl-gallery--masonry', 'tl-gallery tl-gallery--justified', 'tl-gallery tl-gallery--slideshow', 'data-slideshow', 'class="tl-gallery-strip"', 'tl-gallery tl-gallery--fullscreen" data-fullscreen',
            'aria-label="Masonry work"', 'aria-label="Slides"', 'aria-label="Photo gallery"', 'aria-label="From a folder"', 'alt="From the folder"'] as $pattern) {
            $this->assertStringContainsString($pattern, $body, $pattern);
        }
        // every gallery photo carries its dimensions and an alt attribute (the grid and slideshow keep the thumbnail ratio, masonry and justified the natural one)
        preg_match_all('#<img\b[^>]*gl-[abc][^>]*>#', $body, $images);
        $this->assertGreaterThanOrEqual(9, count($images[0]));
        foreach ($images[0] as $image) {
            $this->assertMatchesRegularExpression('/\bwidth="\d+" height="\d+"/', $image, $image);
            $this->assertStringContainsString(' alt="', $image);
            $this->assertStringContainsString('loading="lazy"', $image);
        }
        $this->assertSame(0, preg_match('#tl-gallery--(masonry|justified)[^>]*>(<img[^>]*aspect-ratio)#', $body), 'natural shapes: no forced aspect ratio');
    }

    public function testBuilderCheckReportsAGalleryWithoutNameOrAlt(): void
    {
        $check = fn (string $alt, string $label): array => array_column(\Talea\Builder\Check::builds(['children' => [
            ['id' => 'g1', 'type' => 'gallery', 'content' => ['photos' => [['src' => 'media/2026/05/gl-a.jpg', 'alt' => $alt]], 'label' => $label, 'folder' => '']],
        ]], false), 'message');
        $this->assertCount(2, $check('', ''), 'no name and no alt: two findings');
        $this->assertCount(0, $check('Fine', 'Named'));
    }
}
