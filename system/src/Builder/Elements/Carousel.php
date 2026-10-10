<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Builder\Build;

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
    public const string GROUP = 'Layout';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'section'];

    public static function properties(): array
    {
        return [
            'per_view' => ['type' => 'choice', 'label' => 'Slides side by side on desktop', 'default' => '1', 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            'description' => ['type' => 'text', 'label' => 'Name for screen readers (e.g. Testimonials)', 'default' => '', 'max' => 120],
        ];
    }

    public static function defaultChildren(): array
    {
        $slide = fn (string $n): array => ['style' => ['base' => ['padding_y' => 'l', 'padding_x' => 'l', 'background' => 'surface', 'radius' => 'm']]] + Build::fresh('container', [], [
            ['tag' => 'h3'] + Build::fresh('heading', ['text' => $n]),
            Build::fresh('text', ['html' => '<p>' . t('Slide text.') . '</p>']),
        ]);

        return [$slide(t('First slide')), $slide(t('Second slide')), $slide(t('Third slide'))];
    }

    public static function baseCss(): string
    {
        return '.tl-carousel { position: relative; }
.tl-carousel-strip { display: flex; gap: var(--tl-space-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--tl-space-2xs); }
.tl-carousel-strip > * { flex: 0 0 calc((100% - (var(--tl-per-view) - 1) * var(--tl-space-m)) / var(--tl-per-view)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .tl-carousel-strip > * { flex-basis: 85%; } }
.tl-carousel-arrows { display: flex; justify-content: flex-end; gap: var(--tl-space-2xs); margin-block-start: var(--tl-space-xs); }
.tl-carousel-arrows button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--tl-color-line); border-radius: 50%; background: var(--tl-color-background); color: var(--tl-color-text); font-size: 1.2em; cursor: pointer; }
.tl-carousel-arrows button:disabled { opacity: 0.35; cursor: default; }
.tl-carousel:not([data-enabled]) .tl-carousel-arrows { display: none; }
@media (prefers-reduced-motion: reduce) { .tl-carousel-strip { scroll-behavior: auto; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $description = $o['description'] !== '' ? ' aria-label="' . e($o['description']) . '"' : '';

        return '<' . $p['tag'] . Text::withClass($a, 'tl-carousel') . ' data-carousel aria-roledescription="' . e(t('carousel')) . '"' . $description . ' style="--tl-per-view:' . (int) $o['per_view'] . '">'
            . '<div class="tl-carousel-strip" tabindex="0">' . $children . '</div>'
            . '<div class="tl-carousel-arrows"><button type="button" data-step="-1" aria-label="' . e(t('Previous')) . '">‹</button><button type="button" data-step="1" aria-label="' . e(t('Next')) . '">›</button></div>'
            . '</' . $p['tag'] . '>';
    }
}
