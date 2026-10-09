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
        return '.ka-carousel { position: relative; }
.ka-carousel-strip { display: flex; gap: var(--ka-space-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--ka-space-2xs); }
.ka-carousel-strip > * { flex: 0 0 calc((100% - (var(--ka-per-view) - 1) * var(--ka-space-m)) / var(--ka-per-view)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .ka-carousel-strip > * { flex-basis: 85%; } }
.ka-carousel-arrows { display: flex; justify-content: flex-end; gap: var(--ka-space-2xs); margin-block-start: var(--ka-space-xs); }
.ka-carousel-arrows button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--ka-color-line); border-radius: 50%; background: var(--ka-color-background); color: var(--ka-color-text); font-size: 1.2em; cursor: pointer; }
.ka-carousel-arrows button:disabled { opacity: 0.35; cursor: default; }
.ka-carousel:not([data-enabled]) .ka-carousel-arrows { display: none; }
@media (prefers-reduced-motion: reduce) { .ka-carousel-strip { scroll-behavior: auto; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $description = $o['description'] !== '' ? ' aria-label="' . e($o['description']) . '"' : '';

        return '<' . $p['tag'] . Text::withClass($a, 'ka-carousel') . ' data-carousel aria-roledescription="' . e(t('carousel')) . '"' . $description . ' style="--ka-per-view:' . (int) $o['per_view'] . '">'
            . '<div class="ka-carousel-strip" tabindex="0">' . $children . '</div>'
            . '<div class="ka-carousel-arrows"><button type="button" data-step="-1" aria-label="' . e(t('Previous')) . '">‹</button><button type="button" data-step="1" aria-label="' . e(t('Next')) . '">›</button></div>'
            . '</' . $p['tag'] . '>';
    }
}
