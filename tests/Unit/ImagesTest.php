<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ImagesTest extends TestCase
{
    /** image sizes: tall screenshots are measured by width, srcset carries the real widths */
    public function testTallImagesAndSrcset(): void
    {
        $this->assertSame([0.5, 0.5], [\Talea\Core\Images::ratio(4000, 3000, 2000), \Talea\Core\Images::ratio(3000, 4000, 2000)], 'Images::ratio: a landscape and a portrait photo by the longer side');
        $this->assertSame([1.0, 0.72, 6000 / 9000], [\Talea\Core\Images::ratio(1440, 5000, 2000), round(\Talea\Core\Images::ratio(1440, 5000, 1200), 3), \Talea\Core\Images::ratio(1000, 9000, 2000)], 'Images::ratio: a full-page screenshot by width, height at most three times');
        $imageFolder = TALEA_ROOT . '/media/' . date('Y/m');
        @mkdir($imageFolder, 0775, true);
        $imageTmp = tempnam(sys_get_temp_dir(), 'obr');
        $imagePng = imagecreatetruecolor(1440, 5000);
        imagepng($imagePng, $imageTmp);
        $imageSaved = \Talea\Core\Images::saveFile($imageTmp, 'celostrankovy-snimek.png');
        $imageSrcset = \Talea\Core\Images::srcset($imageSaved['image_path'], '');
        $this->assertSame([1440, 5000, true], [$imageSaved['image_width'], $imageSaved['image_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $imageSrcset)], 'Images: a tall image keeps its width, srcset has the real variant widths');
        \Talea\Core\Images::delete($imageSaved['image_path'], $imageSaved['thumb_path']);
        @unlink($imageTmp);
    }

    /** a GIF with a valid header and a broken body is refused before anything is written; a good GIF stays byte-identical */
    public function testUndecodableGifLeavesNothingBehind(): void
    {
        $folder = TALEA_ROOT . '/media/' . date('Y/m');
        @mkdir($folder, 0775, true);
        $before = glob($folder . '/*') ?: [];
        $broken = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($broken, 'GIF89a' . pack('vv', 10, 10) . "\x00\x00\x00" . str_repeat("\x00", 16) . 'not an image body');
        try {
            \Talea\Core\Images::saveFile($broken, 'broken.gif');
            $this->fail('A broken GIF must be refused.');
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, glob($folder . '/*') ?: [], 'Images: nothing is left in media/ after a refused GIF');
        @unlink($broken);

        $good = tempnam(sys_get_temp_dir(), 'gif');
        $canvas = imagecreatetruecolor(12, 8);
        imagegif($canvas, $good);
        $saved = \Talea\Core\Images::saveFile($good, 'good.gif');
        $this->assertSame(file_get_contents($good), file_get_contents(TALEA_ROOT . '/' . $saved['image_path']), 'Images: a valid GIF is stored unchanged');
        \Talea\Core\Images::delete($saved['image_path'], $saved['thumb_path']);
        @unlink($good);
    }

    /** dominant image color */
    public function testDominantColour(): void
    {
        if (function_exists('imagecreatetruecolor')) {
            $temporary = tempnam(sys_get_temp_dir(), 'rs') . '.png';
            $canvas = imagecreatetruecolor(40, 20);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 60));
            imagepng($canvas, $temporary);
            $this->assertSame('#c81e3c', \Talea\Core\Images::color($temporary), 'Images::color: a single-colour image');
            unlink($temporary);
            $this->assertNull(\Talea\Core\Images::color($temporary), 'Images::color: a missing file');
        }
    }
}
