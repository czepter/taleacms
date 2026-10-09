<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Builder\Build;

/**
 * Carousel: a horizontal strip of slides (nested elements) with scroll snapping – with a finger on a phone, with arrows on a desktop
 * (image/web.js). The strip can be scrolled without the script too. Nothing scrolls by itself – automatic rotation hurts reading and accessibility.
 */
final class Carousel extends Element
{
    public const string TYPE = 'carousel';
    public const string NAME = 'Carousel';
    public const string DESCRIPTION = 'Slides side by side, moved with arrows or a finger – testimonials, photos, cards.';
    public const string ICON = 'carousel';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'section'];

    public static function properties(): array
    {
        return [
            'per_view' => ['type' => 'vyber', 'popisek' => 'Slides side by side on desktop', 'vychozi' => '1', 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            'popis' => ['type' => 'text', 'popisek' => 'Name for screen readers (e.g. Testimonials)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function defaultChildren(): array
    {
        $slide = fn (string $n): array => ['style' => ['zaklad' => ['padding_y' => 'l', 'padding_x' => 'l', 'background' => 'surface', 'radius' => 'm']]] + Build::fresh('container', [], [
            ['tag' => 'h3'] + Build::fresh('heading', ['text' => $n]),
            Build::fresh('text', ['html' => '<p>' . t('Slide text.') . '</p>']),
        ]);

        return [$slide(t('First slide')), $slide(t('Second slide')), $slide(t('Third slide'))];
    }

    public static function baseCss(): string
    {
        return '.ka-karusel { position: relative; }
.ka-karusel-pas { display: flex; gap: var(--ka-mezera-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--ka-mezera-2xs); }
.ka-karusel-pas > * { flex: 0 0 calc((100% - (var(--ka-naraz) - 1) * var(--ka-mezera-m)) / var(--ka-naraz)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .ka-karusel-pas > * { flex-basis: 85%; } }
.ka-karusel-sipky { display: flex; justify-content: flex-end; gap: var(--ka-mezera-2xs); margin-block-start: var(--ka-mezera-xs); }
.ka-karusel-sipky button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--ka-barva-linka); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font-size: 1.2em; cursor: pointer; }
.ka-karusel-sipky button:disabled { opacity: 0.35; cursor: default; }
.ka-karusel:not([data-zapnuto]) .ka-karusel-sipky { display: none; }
@media (prefers-reduced-motion: reduce) { .ka-karusel-pas { scroll-behavior: auto; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $description = $o['popis'] !== '' ? ' aria-label="' . e($o['popis']) . '"' : '';

        return '<' . $p['tag'] . Text::withClass($a, 'ka-karusel') . ' data-karusel aria-roledescription="' . e(t('carousel')) . '"' . $description . ' style="--ka-naraz:' . (int) $o['per_view'] . '">'
            . '<div class="ka-karusel-pas" tabindex="0">' . $children . '</div>'
            . '<div class="ka-karusel-sipky"><button type="button" data-krok="-1" aria-label="' . e(t('Previous')) . '">‹</button><button type="button" data-krok="1" aria-label="' . e(t('Next')) . '">›</button></div>'
            . '</' . $p['tag'] . '>';
    }
}
