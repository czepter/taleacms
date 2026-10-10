<?php

declare(strict_types=1);

namespace TaleaAddon\ClientGalleries;

/**
 * The demo images: abstract landscapes drawn with PHP GD (a sky gradient, a sun or moon, layered hills). Nothing is downloaded and nobody
 * owns them – a deterministic function of the number, so the same number gives the same picture. Used for the demo gallery.
 */
final class DemoImages
{
    public const int COUNT = 12;

    /** [width, height] of the n-th demo image: landscape, portrait and square in turn. */
    public static function size(int $n): array
    {
        return [[1600, 1067], [1067, 1600], [1400, 1400]][$n % 3];
    }

    /** JPEG bytes of the n-th picture (0-based). */
    public static function jpeg(int $n): string
    {
        [$w, $h] = self::size($n);
        $im = imagecreatetruecolor($w, $h);
        $hue = ($n * 47 + 200) % 360;
        $night = $n % 4 === 3;
        $rgb = fn (float $hue, float $s, float $l): int => imagecolorallocate($im, ...self::hsl($hue, $s, $l));
        // the sky: a vertical gradient drawn in lines
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            imageline($im, 0, $y, $w, $y, $rgb($hue + 40 * $t, $night ? .45 : .6, ($night ? .12 : .55) + ($night ? .18 : .3) * $t));
        }
        // the sun or moon
        $r = (int) ($w * (.05 + ($n % 5) * .012));
        $cx = (int) ($w * (.2 + (($n * 37) % 60) / 100));
        $cy = (int) ($h * (.18 + (($n * 13) % 20) / 100));
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $rgb($night ? 60 : 45, $night ? .1 : .9, $night ? .9 : .8));
        // the hills: three layers, farther ones lighter
        for ($layer = 0; $layer < 3; $layer++) {
            $base = $h * (.58 + $layer * .13);
            $points = [0, $h, 0, (int) $base];
            for ($x = 0; $x <= $w; $x += (int) ($w / 24)) {
                $points[] = $x;
                $points[] = (int) ($base - $h * .09 * (sin($x / $w * (3 + $layer) * M_PI + $n + $layer * 2) + .5 * sin($x / $w * 9 + $n * 1.7)));
            }
            array_push($points, $w, (int) $base, $w, $h);
            imagefilledpolygon($im, $points, $rgb($hue + 120 - $layer * 20, .35 + $layer * .1, ($night ? .1 : .28) - $layer * .06 + (2 - $layer) * .04));
        }
        ob_start();
        imagejpeg($im, null, 88);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function hsl(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360.0) / 360;
        $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = function (float $t) use ($p, $q): int {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            $v = $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < .5 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));

            return (int) round(max(0, min(1, $v)) * 255);
        };

        return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
    }
}
