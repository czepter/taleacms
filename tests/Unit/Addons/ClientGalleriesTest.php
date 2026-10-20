<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

final class ClientGalleriesTest extends TestCase
{
    /** the client galleries add-on (issue #34); database and HTTP: tests/Site/AgentAddons/ClientGalleriesAddonTest.php */
    public function testExpiryThumbnailsCsvAndDemoPictures(): void
    {
        require_once TALEA_ROOT . '/extensions/client_galleries/DemoImages.php';
        require_once TALEA_ROOT . '/extensions/client_galleries/Galleries.php';

        $galleries = \TaleaAddon\ClientGalleries\Galleries::class;
        $this->assertSame(['', '2027-02-28 23:59:59', null, null], [$galleries::expiry(''), $galleries::expiry('2027-02-28'), $galleries::expiry('2027-02-30'), $galleries::expiry('tomorrow')], 'Client galleries: expiry parses a day to its end and refuses anything else');
        $this->assertSame([false, true, false], [$galleries::expired(['expires_at' => '']), $galleries::expired(['expires_at' => '2000-01-01 23:59:59']), $galleries::expired(['expires_at' => '2999-01-01 23:59:59'])], 'Client galleries: expired');
        $this->assertSame([[640, 320], [300, 200], [1, 1]], [$galleries::thumbSize(1280, 640), $galleries::thumbSize(300, 200), $galleries::thumbSize(0, 0)], 'Client galleries: thumbSize keeps the ratio and never enlarges');
        $this->assertTrue(str_contains($galleries::csv([['image' => '=HYPERLINK("x")', 'image_id' => 'a', 'by' => 'link', 'at' => '2027-01-01 10:00:00']]), "\"'=HYPERLINK(\"\"x\"\")\",\"a\",\"link\""), 'Client galleries: CSV neutralises formulas and doubles quotes');
        if (extension_loaded('gd')) {
            $sizes = [];
            for ($n = 0; $n < 3; $n++) {
                $info = getimagesizefromstring(\TaleaAddon\ClientGalleries\DemoImages::jpeg($n));
                $sizes[] = $info === false ? null : [$info[0], $info[1], $info[2]];
            }
            $this->assertSame([[1600, 1067, IMAGETYPE_JPEG], [1067, 1600, IMAGETYPE_JPEG], [1400, 1400, IMAGETYPE_JPEG]], $sizes, 'Client galleries: the demo pictures are JPEGs of three shapes');
            $this->assertTrue(\TaleaAddon\ClientGalleries\DemoImages::jpeg(5) === \TaleaAddon\ClientGalleries\DemoImages::jpeg(5), 'Client galleries: a demo picture is always the same');
        }
        $this->assertNull($galleries::zip(['public_id' => 'x'], array_fill(0, $galleries::ZIP_MAX_IMAGES + 1, ['public_id' => 'y', 'ext' => 'jpg', 'name' => 'a']), 'web'), 'Client galleries: a zip over the image limit is refused without touching a file');
    }
}
