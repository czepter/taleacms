<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Images;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Photo gallery: a grid of thumbnails, a tap opens the photo full screen (the viewer from image/web.js, arrows and swipe).
 * The number of columns is set by the style (Rozložení → Sloupce, i.e. Layout → Columns); on a phone the grid narrows by itself.
 */
final class Gallery extends Element
{
    public const string TYPE = 'gallery';
    public const string NAME = 'Gallery';
    public const string DESCRIPTION = 'A grid of photos that open full screen when clicked.';
    public const string ICON = 'gallery';
    public const array HTML_TAGS = ['figure', 'div'];

    public static function properties(): array
    {
        return [
            'photos' => ['type' => 'items', 'label' => 'Photos', 'max' => 60, 'default' => [], 'fields' => [
                'src' => ['type' => 'image', 'label' => 'Photo', 'default' => ''],
                'alt' => ['type' => 'text', 'label' => 'Description (for blind visitors and under the photo in the viewer)', 'default' => '', 'max' => 300],
            ]],
            'ratio' => ['type' => 'choice', 'label' => 'Thumbnail shape', 'default' => '4 / 3', 'options' => ['4 / 3' => 'landscape 4 : 3', '1 / 1' => 'square', '3 / 4' => 'portrait 3 : 4', '16 / 9' => 'wide 16 : 9']],
            'caption' => ['type' => 'text', 'label' => 'Gallery caption', 'default' => '', 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        // the grid adapts to the width by itself; Styl → Sloupce (Style → Columns) overrides it (the elements layer comes after the builder layer)
        return '.ka-galerie { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(12rem, 45%), 1fr)); gap: var(--ka-mezera-s); margin: 0; }
.ka-galerie img { display: block; width: 100%; height: auto; object-fit: cover; border-radius: var(--ka-zaobleni-s); cursor: zoom-in; }
.ka-galerie figcaption { grid-column: 1 / -1; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $base = $k->app->request->basePath();
        $html = '';
        foreach ($o['photos'] as $f) {
            if ($f['src'] === '') {
                continue;
            }
            $src = $k->image($f['src']);
            $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($base, '#') . '/#', '', $src) ?? $src, '/'), $base);
            $html .= '<img src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="auto, (max-width: 700px) 50vw, 400px"' : '')
                . ' alt="' . e($f['alt']) . '" loading="lazy" style="aspect-ratio:' . e($o['ratio']) . '">';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Add photos in the Content panel.')) . '</div>' : '';
        }
        if ($o['caption'] !== '') {
            $html .= $p['tag'] === 'figure' ? '<figcaption>' . e($o['caption']) . '</figcaption>' : '<p>' . e($o['caption']) . '</p>';
        }

        return '<' . $p['tag'] . Text::withClass($a, 'ka-galerie') . '>' . $html . '</' . $p['tag'] . '>';
    }
}
