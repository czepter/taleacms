<?php

/**
 * Unit tests of the client galleries add-on (issue #34), included by tools/unit-tests.php (uses its check()). Database and HTTP are covered by
 * tests/Site/AgentAddons/ClientGalleriesAddonTest.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/DemoImages.php';
require_once dirname(__DIR__) . '/Galleries.php';

$galleries = TaleaAddon\ClientGalleries\Galleries::class;
check('Client galleries: expiry parses a day to its end and refuses anything else', [$galleries::expiry(''), $galleries::expiry('2027-02-28'), $galleries::expiry('2027-02-30'), $galleries::expiry('tomorrow')],
    ['', '2027-02-28 23:59:59', null, null]);
check('Client galleries: expired', [$galleries::expired(['expires_at' => '']), $galleries::expired(['expires_at' => '2000-01-01 23:59:59']), $galleries::expired(['expires_at' => '2999-01-01 23:59:59'])], [false, true, false]);
check('Client galleries: thumbSize keeps the ratio and never enlarges', [$galleries::thumbSize(1280, 640), $galleries::thumbSize(300, 200), $galleries::thumbSize(0, 0)], [[640, 320], [300, 200], [1, 1]]);
check('Client galleries: CSV neutralises formulas and doubles quotes', str_contains($galleries::csv([['image' => '=HYPERLINK("x")', 'image_id' => 'a', 'by' => 'link', 'at' => '2027-01-01 10:00:00']]), "\"'=HYPERLINK(\"\"x\"\")\",\"a\",\"link\""), true);
if (extension_loaded('gd')) {
    $sizes = [];
    for ($n = 0; $n < 3; $n++) {
        $info = getimagesizefromstring(TaleaAddon\ClientGalleries\DemoImages::jpeg($n));
        $sizes[] = $info === false ? null : [$info[0], $info[1], $info[2]];
    }
    check('Client galleries: the demo pictures are JPEGs of three shapes', $sizes, [[1600, 1067, IMAGETYPE_JPEG], [1067, 1600, IMAGETYPE_JPEG], [1400, 1400, IMAGETYPE_JPEG]]);
    check('Client galleries: a demo picture is always the same', TaleaAddon\ClientGalleries\DemoImages::jpeg(5) === TaleaAddon\ClientGalleries\DemoImages::jpeg(5), true);
}
check('Client galleries: a zip over the image limit is refused without touching a file', $galleries::zip(['public_id' => 'x'], array_fill(0, $galleries::ZIP_MAX_IMAGES + 1, ['public_id' => 'y', 'ext' => 'jpg', 'name' => 'a']), 'web'), null);
