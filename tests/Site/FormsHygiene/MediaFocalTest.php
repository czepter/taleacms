<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Visual compose phase C: update_media sets the file's focal point; a placement's --tl-focal wins over it on the page. */
#[Group('site')]
final class MediaFocalTest extends SiteTestCase
{
    use McpHelpers;

    public function testFilePointIsTheFallbackOfAPlacementPoint(): void
    {
        $this->mcpText('upload_file', ['filename' => 'focal-c4.png', 'data' => $this->pngBase64()]);
        $id = (int) $this->site()->value("SELECT media_id FROM tl_media WHERE image_path LIKE '%focal-c4%' ORDER BY media_id DESC LIMIT 1");
        $public = $this->site()->publicId('media', $id);
        $path = (string) $this->site()->value('SELECT image_path FROM tl_media WHERE media_id = ?', [$id]);

        $this->mcpText('update_media', ['id' => $public, 'focal_point' => '30% 70%']);
        $this->assertSame('30% 70%', $this->site()->value('SELECT focal_point FROM tl_media WHERE media_id = ?', [$id]), 'MCP: update_media stores the point');
        $this->assertStringContainsString('whole numbers', $this->mcpRawAnswer('update_media', ['id' => $public, 'focal_point' => '120% 5%']), 'MCP: a value outside 0–100 is refused');
        $this->assertSame('30% 70%', $this->site()->value('SELECT focal_point FROM tl_media WHERE media_id = ?', [$id]), 'a refused value changes nothing');

        $page = $this->createPage(['title' => 'Focal', 'slug' => 'focal-c4', 'visible' => true]);
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [
            ['type' => 'section', 'children' => [
                ['id' => 'fca', 'type' => 'image', 'content' => ['src' => $path, 'alt' => 'A'], 'style' => ['base' => ['aspect_ratio' => '16/9', 'object_fit' => 'cover', 'focal_point' => '10% 20%']]],
                ['id' => 'fcb', 'type' => 'image', 'content' => ['src' => $path, 'alt' => 'B', 'caption' => 'Cap']],
            ]],
        ]]]);
        $this->site()->clearPageCache();
        $response = $this->assertPage('/focal-c4', 200, 'object-position:var(--tl-focal, 30% 70%)', message: 'the file\'s point is the fallback of --tl-focal');
        $this->assertTrue($response->contains('#s-fca { aspect-ratio: 16/9; object-fit: cover; object-position: 10% 20%; --tl-focal: 10% 20%; }'), 'the placement sets --tl-focal');

        $this->mcpText('update_media', ['id' => $public, 'focal_point' => '']);
        $this->assertSame('', $this->site()->value('SELECT focal_point FROM tl_media WHERE media_id = ?', [$id]), 'an empty value clears the point');
    }
}
