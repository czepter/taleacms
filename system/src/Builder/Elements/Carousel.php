<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Builder\Build;

/**
 * Carousel: a horizontal strip of slides (nested elements) with scroll snapping – with a finger on a phone, with arrows and dots on a desktop
 * (image/web.js). The strip can be scrolled without the script too. Nothing scrolls by itself – automatic rotation hurts reading and accessibility.
 * The arrows and dots are shared with the gallery slideshow (controls(), sliderCss()). A class or an element's own CSS overrides them (later
 * layers); the --tl-slider-* properties set on the carousel change their colours and sizes.
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
    public const array NAVIGATION = ['arrows-dots' => 'arrows and dots', 'dots' => 'dots', 'arrows' => 'arrows', 'scrollbar' => 'scrollbar and arrows'];
    public const array ARROW_POSITIONS = ['sides' => 'beside the slides', 'overlay' => 'on the slides', 'below' => 'below, at the end'];

    public static function properties(): array
    {
        return [
            'per_view' => ['type' => 'choice', 'label' => 'Slides side by side on desktop', 'default' => '1', 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            'navigation' => ['type' => 'choice', 'label' => 'Navigation', 'default' => 'arrows-dots', 'options' => self::NAVIGATION],
            'arrow_position' => ['type' => 'choice', 'label' => 'Arrow position', 'default' => 'sides', 'options' => self::ARROW_POSITIONS],
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
@media (prefers-reduced-motion: reduce) { .tl-carousel-strip { scroll-behavior: auto; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $description = $o['description'] !== '' ? ' aria-label="' . e($o['description']) . '"' : '';

        return '<' . $p['tag'] . Text::withClass($a, 'tl-carousel ' . self::sliderClass($o)) . ' data-carousel aria-roledescription="' . e(t('carousel')) . '"' . $description . ' style="--tl-per-view:' . (int) $o['per_view'] . '">'
            . '<div class="tl-carousel-strip" tabindex="0">' . $children . '</div>'
            . self::controls($o, $k)
            . '</' . $p['tag'] . '>';
    }

    /**
     * The arrows and dots of the sliders on the page. Without the script they stay hidden and the strip keeps its scrollbar. Arrows beside the
     * slides drop below them on a phone; arrows on the slides sit on the first row of a grid, over the strip.
     */
    public static function sliderCss(): string
    {
        $grid = fn (string $s, string $strip): string => "{$s} { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 0; }
{$s} > * { grid-column: 1 / -1; }
{$s} > :is(.tl-carousel-strip, .tl-gallery-strip) { grid-area: {$strip}; }
{$s} :is(.tl-slider-controls, .tl-slider-arrows) { display: contents; }
{$s} [data-step] { grid-row: 1; }
{$s} [data-step=\"-1\"] { grid-column: 1; }
{$s} [data-step=\"1\"] { grid-column: 3; }
{$s} .tl-slider-dots { grid-column: 1 / -1; justify-self: center; margin-block-start: var(--tl-space-xs); }";

        return '.tl-slider-controls { display: flex; align-items: center; gap: var(--tl-space-2xs); margin-block-start: var(--tl-space-xs); }
.tl-slider:not([data-enabled]) .tl-slider-controls { display: none; }
.tl-slider--no-scrollbar[data-enabled] > :is(.tl-carousel-strip, .tl-gallery-strip) { scrollbar-width: none; }
.tl-slider-arrows { display: flex; gap: var(--tl-space-2xs); margin-inline-start: auto; }
.tl-slider-arrows button { display: grid; place-items: center; width: var(--tl-slider-arrow-size, 2.75rem); height: var(--tl-slider-arrow-size, 2.75rem); padding: 0; border: var(--tl-slider-arrow-border, 1px solid var(--tl-color-line)); border-radius: var(--tl-slider-arrow-radius, 50%); background: var(--tl-slider-arrow-background, var(--tl-color-background)); color: var(--tl-slider-arrow-color, var(--tl-color-text)); font-size: 1.2em; cursor: pointer; }
.tl-slider-arrows button:disabled { opacity: 0.35; cursor: default; }
.tl-slider-dots { display: flex; flex-wrap: wrap; }
.tl-slider-dots:only-child { margin-inline: auto; }
.tl-slider-dots button { display: grid; place-items: center; min-width: 2rem; height: 2.75rem; padding: 0; border: 0; background: none; cursor: pointer; }
.tl-slider-dots button::before { content: ""; width: var(--tl-slider-dot-size, 0.5rem); height: var(--tl-slider-dot-size, 0.5rem); border-radius: 1rem; background: var(--tl-slider-dot-color, var(--tl-color-muted)); transition: width 0.2s, background-color 0.2s; }
.tl-slider-dots button[aria-current="true"]::before { width: var(--tl-slider-dot-active-width, 1.5rem); background: var(--tl-slider-dot-active-color, var(--tl-color-primary)); }
' . $grid('.tl-slider--overlay[data-enabled]', '1 / 1 / 2 / -1') . '
.tl-slider--overlay[data-enabled] [data-step] { margin-inline: var(--tl-space-xs); border: var(--tl-slider-arrow-border, 0); background: var(--tl-slider-arrow-background, color-mix(in oklch, var(--tl-color-background) 85%, transparent)); }
.tl-slider--overlay[data-enabled] [data-step]:disabled { visibility: hidden; }
@media (min-width: 768px) {
' . $grid('.tl-slider--sides[data-enabled]', '1 / 2') . '
.tl-slider--sides[data-enabled] { column-gap: var(--tl-space-s); }
}
@media (prefers-reduced-motion: reduce) { .tl-slider-dots button::before { transition: none; } }';
    }

    /** The classes of a slider (carousel, gallery slideshow) for its navigation: without a scrollbar the strip hides it (only once the script runs). */
    public static function sliderClass(array $o): string
    {
        $navigation = isset(self::NAVIGATION[$o['navigation'] ?? '']) ? $o['navigation'] : 'arrows-dots';
        $position = isset(self::ARROW_POSITIONS[$o['arrow_position'] ?? '']) ? $o['arrow_position'] : 'sides';

        return 'tl-slider' . ($navigation === 'scrollbar' ? '' : ' tl-slider--no-scrollbar') . ($navigation === 'dots' ? '' : ' tl-slider--' . $position);
    }

    /** Arrows and/or dots under a slider strip; image/web.js adds one dot per page of slides (the number depends on the width) and shows them. */
    public static function controls(array $o, Context $k): string
    {
        $k->slider = true;
        $navigation = isset(self::NAVIGATION[$o['navigation'] ?? '']) ? $o['navigation'] : 'arrows-dots';
        $dots = in_array($navigation, ['arrows-dots', 'dots'], true) ? '<div class="tl-slider-dots" role="group" aria-label="' . e(t('Choose slides')) . '"></div>' : '';
        $arrows = $navigation === 'dots' ? '' : '<div class="tl-slider-arrows"><button type="button" data-step="-1" aria-label="' . e(t('Previous')) . '">‹</button><button type="button" data-step="1" aria-label="' . e(t('Next')) . '">›</button></div>';

        return '<div class="tl-slider-controls">' . $dots . $arrows . '</div>';
    }
}
