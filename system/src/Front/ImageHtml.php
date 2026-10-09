<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Images;

/**
 * Less page jumping while loading (CLS): adds dimensions and the dominant color, as a background until the photo loads,
 * to images from Media in the finished HTML. It is done in one place over the resulting HTML, so it applies in all
 * layouts, custom ones included. The color is computed once (on first display) and stored with the image.
 */
final class ImageHtml
{
    /** At most this many colors are computed in one request – older sites are filled in gradually. */
    private const int PER_REQUEST = 6;

    public static function complete(Db $db, string $html): string
    {
        if (!preg_match_all('#<img\b[^>]*?\bsrc="[^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp)"#i', $html, $found, PREG_SET_ORDER)) {
            return $html;
        }
        $paths = array_values(array_unique(array_map(fn (array $m): string => $m[1] . '.' . strtolower($m[2]), $found)));
        $known = [];
        foreach ($db->all('SELECT ido, image_path, image_width, image_height, thumb_path, color, focal_point FROM {media} WHERE image_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')', $paths) as $o) {
            $known[$o['image_path']] = $o;
        }
        $computed = 0;
        foreach ($known as $path => $o) {
            if ($o['color'] === '' && $computed < self::PER_REQUEST) {
                $computed++;
                $known[$path]['color'] = Images::color(KALETA_ROOT . '/' . ($o['thumb_path'] !== '' ? $o['thumb_path'] : $path)) ?: '-';
                $db->update('media', ['color' => $known[$path]['color']], ['ido' => $o['ido']]); // „-“ = cannot be determined, do not try again
            }
        }

        return preg_replace_callback('#<img\b([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[a-z0-9-]+?)(?:-1200|-nahled)?\.(jpg|png|webp))"([^>]*)>#i', function (array $m) use ($known): string {
            $o = $known[$m[3] . '.' . strtolower($m[4])] ?? null;
            $attributes = $m[1] . $m[5];
            if ($o === null) {
                return $m[0];
            }
            $toAdd = '';
            if ((int) $o['image_width'] > 0 && (int) $o['image_height'] > 0 && !preg_match('#\b(width|height)=#i', $attributes)) {
                $toAdd .= ' width="' . (int) $o['image_width'] . '" height="' . (int) $o['image_height'] . '"';
            }
            // background color only for photos: a PNG is often a logo or an illustration with transparency and a colored
            // rectangle would show through behind it;
            // focal point: where the crop centers when the photo fills a different shape (object-fit: cover)
            $style = (strtolower($m[4]) !== 'png' && preg_match('/^#[0-9a-f]{6}$/', (string) $o['color']) ? 'background-color:' . $o['color'] . ';' : '')
                . (preg_match('/^\d{1,3}% \d{1,3}%$/', (string) ($o['focal_point'] ?? '')) ? 'object-position:' . $o['focal_point'] . ';' : '');
            if ($style !== '' && !preg_match('#\bstyle=#i', $attributes)) {
                $toAdd .= ' style="' . $style . '"';
            } elseif ($style !== '' && preg_match('#\bstyle="([^"]*)"#i', $m[1] . $m[5])) {
                // the image already has a style (gallery: aspect ratio) – the focal point is appended
                [$m[1], $m[5]] = array_map(fn (string $x): string => (string) preg_replace('#\bstyle="([^"]*)"#i', 'style="$1;' . $style . '"', $x, 1), [$m[1], $m[5]]);
            }

            return $toAdd === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '"' . $toAdd . $m[5] . '>';
        }, $html) ?? $html;
    }
}
