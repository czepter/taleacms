<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Core\Images;
use Talea\Core\Db;
use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Photo gallery: a tap opens the photo full screen (the viewer from image/web.js, arrows, swipe, Esc). Layouts: grid (the default, no script
 * needed; the number of columns is set by the style, on a phone it narrows by itself), masonry (CSS columns), justified rows (flex rows that
 * image/web.js evens out from the width/height attributes), slideshow (a scroll-snap strip with arrows) and full-screen (a grid whose viewer
 * asks the browser for real full screen). Photos come from the list and/or from a Media folder (by its public id), newest last.
 */
final class Gallery extends Element
{
    public const string TYPE = 'gallery';
    public const string NAME = 'Gallery';
    public const string DESCRIPTION = 'Photos in a grid, masonry, justified rows or a slideshow; a click opens them full screen. Photos come from a list or a Media folder.';
    public const string ICON = 'gallery';
    public const array HTML_TAGS = ['figure', 'div'];
    public const array LAYOUTS = ['grid' => 'grid', 'masonry' => 'masonry', 'justified' => 'justified rows', 'slideshow' => 'slideshow', 'fullscreen' => 'grid, opens in full screen'];
    private const int MAX_PHOTOS = 60;

    public static function properties(): array
    {
        return [
            'photos' => ['type' => 'items', 'label' => 'Photos', 'max' => 60, 'default' => [], 'fields' => [
                'src' => ['type' => 'image', 'label' => 'Photo', 'default' => ''],
                'alt' => ['type' => 'text', 'label' => 'Description (for blind visitors and under the photo in the viewer)', 'default' => '', 'max' => 300],
            ]],
            'folder' => ['type' => 'text', 'label' => 'Media folder (its photos are added after the list)', 'default' => '', 'max' => 36],
            'layout' => ['type' => 'choice', 'label' => 'Layout', 'default' => 'grid', 'options' => self::LAYOUTS],
            'ratio' => ['type' => 'choice', 'label' => 'Thumbnail shape', 'default' => '4 / 3', 'options' => ['4 / 3' => 'landscape 4 : 3', '1 / 1' => 'square', '3 / 4' => 'portrait 3 : 4', '16 / 9' => 'wide 16 : 9']],
            'caption' => ['type' => 'text', 'label' => 'Gallery caption', 'default' => '', 'max' => 300],
            'label' => ['type' => 'text', 'label' => 'Name for screen readers (e.g. Our workshop)', 'default' => '', 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        // the grid adapts to the width by itself; Styl → Sloupce (Style → Columns) overrides it (the elements layer comes after the builder layer)
        return '.tl-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(12rem, 45%), 1fr)); gap: var(--tl-space-s); margin: 0; }
.tl-gallery img { display: block; width: 100%; height: auto; object-fit: cover; border-radius: var(--tl-radius-s); cursor: zoom-in; }
.tl-gallery figcaption { grid-column: 1 / -1; color: var(--tl-color-muted); font-size: var(--tl-step--1); }
.tl-gallery--masonry { display: block; column-width: 14rem; column-gap: var(--tl-space-s); }
.tl-gallery--masonry img { margin-block-end: var(--tl-space-s); break-inside: avoid; }
.tl-gallery--masonry figcaption { column-span: all; }
.tl-gallery--justified { display: flex; flex-wrap: wrap; }
.tl-gallery--justified img { width: auto; max-width: 100%; height: 12rem; flex: 1 1 auto; }
.tl-gallery--justified figcaption { flex: 1 0 100%; }
@media (max-width: 599px) { .tl-gallery--justified img { height: 8rem; } }
.tl-gallery--slideshow { position: relative; display: block; }
.tl-gallery-strip { display: flex; gap: var(--tl-space-s); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; }
.tl-gallery-strip img { flex: 0 0 100%; min-width: 0; scroll-snap-align: start; }
.tl-gallery-arrows { display: flex; justify-content: flex-end; gap: var(--tl-space-2xs); margin-block-start: var(--tl-space-xs); }
.tl-gallery-arrows button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--tl-color-line); border-radius: 50%; background: var(--tl-color-background); color: var(--tl-color-text); font-size: 1.2em; cursor: pointer; }
.tl-gallery-arrows button:disabled { opacity: 0.35; cursor: default; }
.tl-gallery--slideshow:not([data-enabled]) .tl-gallery-arrows { display: none; }
@media (prefers-reduced-motion: reduce) { .tl-gallery-strip { scroll-behavior: auto; } }';
    }

    /** @return list<array{src: string, alt: string}> photos of the Media folder with the given public id (its name is the alt text) */
    private static function folderPhotos(Db $db, string $folder, int $limit): array
    {
        if ($limit < 1 || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $folder)) {
            return [];
        }
        $rows = $db->all('SELECT m.image_path, m.name FROM {media} m JOIN {media_folders} f ON f.folder_id = m.folder_id WHERE f.public_id = ? ORDER BY m.media_id LIMIT ' . $limit, [$folder]);

        return array_values(array_filter(array_map(
            fn (array $r): array => ['src' => (string) $r['image_path'], 'alt' => (string) $r['name']],
            $rows,
        ), fn (array $r): bool => preg_match('#\.(jpe?g|png|webp|gif)$#i', $r['src']) === 1));
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $layout = (string) $o['layout'];
        $base = $k->app->request->basePath();
        $photos = array_values(array_filter($o['photos'], fn (array $f): bool => $f['src'] !== ''));
        if ($o['folder'] !== '') {
            $photos = [...$photos, ...self::folderPhotos($k->app->db(), (string) $o['folder'], self::MAX_PHOTOS - count($photos))];
        }
        $html = '';
        foreach ($photos as $f) {
            $src = $k->image($f['src']);
            $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($base, '#') . '/#', '', $src) ?? $src, '/'), $base);
            // masonry and justified rows keep the natural shape of every photo (ImageHtml::complete adds width and height, so the space is reserved)
            $shape = in_array($layout, ['masonry', 'justified'], true) ? '' : ' style="aspect-ratio:' . e($o['ratio']) . '"';
            $sizes = $layout === 'slideshow' ? '(max-width: 1200px) 100vw, 1200px' : 'auto, (max-width: 700px) 50vw, 400px';
            $html .= '<img src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="' . ($layout === 'slideshow' ? '(max-width: 1200px) 100vw, 1200px' : $sizes) . '"' : '')
                . ' alt="' . e($f['alt']) . '" loading="lazy"' . $shape . '>';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">' . e(t('Add photos in the Content panel.')) . '</div>' : '';
        }
        $caption = '';
        if ($o['caption'] !== '') {
            $caption = $p['tag'] === 'figure' ? '<figcaption>' . e($o['caption']) . '</figcaption>' : '<p>' . e($o['caption']) . '</p>';
        }
        // the grid stays as it always was; the other layouts add a modifier class and a name for screen readers (the caption or a generic one)
        $label = $o['label'] !== '' ? $o['label'] : ($layout === 'grid' ? '' : ($o['caption'] !== '' ? $o['caption'] : t('Photo gallery')));
        $name = $label !== '' ? ' role="group" aria-label="' . e($label) . '"' : '';
        if ($layout === 'slideshow') {
            return '<' . $p['tag'] . Text::withClass($a, 'tl-gallery tl-gallery--slideshow') . ' data-slideshow aria-roledescription="' . e(t('carousel')) . '"' . $name . '>'
                . '<div class="tl-gallery-strip" tabindex="0" role="group" aria-label="' . e($label) . '">' . $html . '</div>'
                . '<div class="tl-gallery-arrows"><button type="button" data-step="-1" aria-label="' . e(t('Previous')) . '">‹</button><button type="button" data-step="1" aria-label="' . e(t('Next')) . '">›</button></div>'
                . $caption . '</' . $p['tag'] . '>';
        }
        $class = $layout === 'grid' ? 'tl-gallery' : 'tl-gallery tl-gallery--' . $layout;

        return '<' . $p['tag'] . Text::withClass($a, $class) . ($layout === 'fullscreen' ? ' data-fullscreen' : '') . $name . '>' . $html . $caption . '</' . $p['tag'] . '>';
    }
}
