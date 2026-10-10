<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/** A full-width band of the page; an inner wrapper keeps the content at the site width (or narrow for text, or none). */
final class Section extends Element
{
    public const string TYPE = 'section';
    public const string NAME = 'Section';
    public const string DESCRIPTION = 'A full-width band with centred content.';
    public const string ICON = 'section';
    public const string GROUP = 'Layout';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['section', 'header', 'footer', 'aside', 'article', 'div'];

    /** How far the visitor scrolls (px) before a header is fully solid or small. */
    private const int SCROLL_RANGE = 120;

    public static function properties(): array
    {
        return [
            'width' => ['type' => 'choice', 'label' => 'Content width', 'default' => 'content', 'options' => ['content' => 'site width', 'narrow' => 'narrow (text)', 'full' => 'full width']],
            // Compose: a fixed 12-column grid; the children are placed on it (style properties grid_column_start … layer) and may overlap
            'layout' => ['type' => 'choice', 'label' => 'Layout of the content', 'default' => 'stack', 'options' => ['stack' => 'stack (one after another)', 'compose' => 'compose (free placement on a grid)']],
            'stack_from' => ['type' => 'choice', 'label' => 'Compose: stack the content on', 'default' => 'tablet', 'options' => ['tablet' => 'tablet and phone', 'phone' => 'phone only']],
            'background_video' => ['type' => 'link', 'label' => 'Background video (MP4 or WebM from Media, no sound)', 'default' => '', 'media' => 'video'], // editor: a pick from Media, not a link
            // header only (site part): transparent over the first section of the page and/or smaller once the visitor scrolls (CSS scroll-driven animation)
            'on_scroll' => ['type' => 'choice', 'label' => 'Header on scroll (header part only)', 'default' => '', 'options' => [
                '' => 'no change', 'transparent' => 'transparent at the top, solid after scrolling', 'shrink' => 'smaller after scrolling', 'transparent_shrink' => 'transparent at the top and smaller after scrolling',
            ]],
            'text_at_top' => ['type' => 'choice', 'label' => 'Text colour while the header is transparent', 'default' => '', 'options' => ['' => 'as normal', 'light' => 'light (over a dark photo)', 'dark' => 'dark (over a light photo)']],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['padding_y' => 'xl']];
    }

    public static function baseCss(): string
    {
        return '.tl-wrap { width: min(100% - 2 * var(--tl-margin, var(--tl-space-m)), var(--tl-width)); margin-inline: auto; }
.tl-wrap--narrow { width: min(100% - 2 * var(--tl-margin, var(--tl-space-m)), var(--tl-text-width)); }
:where(.build) a:focus-visible { outline: 3px solid var(--tl-color-secondary); outline-offset: 2px; }
.tl-wrap > * + * { margin-block-start: var(--tl-space-m); }
.tl-with-video { position: relative; isolation: isolate; overflow: hidden; }
.tl-video-background { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; }
@media (prefers-reduced-motion: reduce) { .tl-video-background { display: none; } }';
    }

    /**
     * A Compose section (Build::css adds it only when a page has one): 12 fixed columns and a fixed row unit, the children placed by grid lines.
     * An unplaced child spans the whole width (it behaves like a stack). On a smaller screen the content is a plain column in source order,
     * so the reading order is the DOM order; "phone only" keeps the grid on a tablet, where the tablet state of the children places them.
     */
    public static function composeCss(): string
    {
        return '.tl-compose { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); grid-auto-rows: var(--tl-space-l, 1.5rem); column-gap: var(--tl-space-s, 0.75rem); isolation: isolate; }
.tl-compose > *, .tl-compose > * + * { grid-column: 1 / -1; min-width: 0; margin-block-start: 0; }
@media (max-width: 1023px) { .tl-compose:not(.tl-compose--phone) { display: flex; flex-direction: column; gap: var(--tl-space-m, 1rem); } }
@media (max-width: 767px) { .tl-compose { display: flex; flex-direction: column; gap: var(--tl-space-m, 1rem); } }';
    }

    /**
     * The header on scroll (Build::css adds it only when a header uses it). A keyframe with only „from“ (or only „to“) animates to
     * the header's own style – its background, border and shadow stay whatever the style says; the defaults below apply when the
     * style has none. A browser without scroll-driven animations finishes the animation at once, so it simply shows the solid header;
     * so does a visitor who asked for less motion.
     */
    public static function scrollCss(): string
    {
        return '.tl-header-scroll--transparent { z-index: 10; background-color: var(--tl-color-background); box-shadow: var(--tl-shadow-s); }
@keyframes tl-header-transparent { from { background-color: transparent; border-color: transparent; box-shadow: none; } }
@keyframes tl-header-light { from { background-color: transparent; border-color: transparent; box-shadow: none; color: var(--tl-color-white); } }
@keyframes tl-header-dark { from { background-color: transparent; border-color: transparent; box-shadow: none; color: var(--tl-color-black); } }
@keyframes tl-header-smaller { to { padding-block: var(--tl-space-2xs); } }
@media (prefers-reduced-motion: reduce) { .tl-header-scroll { animation: none !important; } }';
    }

    /** The chosen behaviour on scroll – only in the header site part (elsewhere a fixed section would cover the page). */
    public static function scrollMode(array $p, Context $k): string
    {
        $mode = (string) ($p['content']['on_scroll'] ?? '');

        return $mode !== '' && str_starts_with($k->source, 'part:header') ? $mode : '';
    }

    public static function behaviourCss(array $p, Context $k): string
    {
        $mode = self::scrollMode($p, $k);
        if ($mode === '') {
            return '';
        }
        $transparent = str_contains($mode, 'transparent');
        $animations = [];
        if ($transparent) {
            $text = (string) ($p['content']['text_at_top'] ?? '');
            $animations[] = 'tl-header-' . (in_array($text, ['light', 'dark'], true) ? $text : 'transparent') . ' linear both';
        }
        if (str_contains($mode, 'shrink')) {
            $animations[] = 'tl-header-smaller linear both';
        }

        // transparent = out of the flow, so that the first section of the page starts at the top of the window; the timeline is the page scroll
        return ($transparent ? 'position: fixed; top: 0; inset-inline: 0; ' : '')
            . 'animation: ' . implode(', ', $animations) . '; animation-timeline: scroll(root); animation-range: 0 ' . self::SCROLL_RANGE . 'px;';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $width = $p['content']['width'] ?? 'content';
        $compose = ($p['content']['layout'] ?? 'stack') === 'compose' ? 'tl-compose' . (($p['content']['stack_from'] ?? 'tablet') === 'phone' ? ' tl-compose--phone' : '') : '';
        if ($compose !== '') {
            $k->compose = true;
        }
        if ($width === 'full') {
            $content = $children;
            $a = $compose !== '' ? Text::withClass($a, $compose) : $a;
        } else {
            $content = '<div class="tl-wrap' . ($width === 'narrow' ? ' tl-wrap--narrow' : '') . ($compose !== '' ? ' ' . $compose : '') . '">' . $children . '</div>';
        }

        // background video: only a file from Media (a third-party player would send data without consent); muted, looped, hidden from screen readers
        $video = (string) ($p['content']['background_video'] ?? '');
        if (preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300}\.(mp4|webm))$#i', $video, $m) && !str_contains($m[1], '..')) {
            $content = '<video class="tl-video-background" src="' . e($k->app->request->basePath() . '/' . $m[1]) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>' . $content;
            $a = Text::withClass($a, 'tl-with-video');
        }
        $mode = self::scrollMode($p, $k);
        if ($mode !== '') {
            $a = Text::withClass($a, 'tl-header-scroll' . (str_contains($mode, 'transparent') ? ' tl-header-scroll--transparent' : ''));
        }

        return '<' . $p['tag'] . $a . '>' . $content . '</' . $p['tag'] . '>';
    }
}
