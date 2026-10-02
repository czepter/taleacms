<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** A full-width band of the page; an inner wrapper keeps the content at the site width (or narrow for text, or none). */
final class Section extends Element
{
    public const string TYPE = 'sekce';
    public const string NAME = 'Sekce';
    public const string DESCRIPTION = 'A full-width band with centred content.';
    public const string ICON = 'sekce';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['section', 'header', 'footer', 'aside', 'article', 'div'];

    /** How far the visitor scrolls (px) before a header is fully solid or small. */
    private const int SCROLL_RANGE = 120;

    public static function properties(): array
    {
        return [
            'sirka' => ['typ' => 'vyber', 'popisek' => 'Content width', 'vychozi' => 'obsah', 'moznosti' => ['obsah' => 'site width', 'uzka' => 'narrow (text)', 'plna' => 'full width']],
            'video' => ['typ' => 'odkaz', 'popisek' => 'Background video (MP4 or WebM from Media, no sound)', 'vychozi' => '', 'media' => 'video'], // editor: a pick from Media, not a link
            // header only (site part): transparent over the first section of the page and/or smaller once the visitor scrolls (CSS scroll-driven animation)
            'pri_rolovani' => ['typ' => 'vyber', 'popisek' => 'Header on scroll (header part only)', 'vychozi' => '', 'moznosti' => [
                '' => 'no change', 'pruhledna' => 'transparent at the top, solid after scrolling', 'zmensit' => 'smaller after scrolling', 'pruhledna-zmensit' => 'transparent at the top and smaller after scrolling',
            ]],
            'text_nahore' => ['typ' => 'vyber', 'popisek' => 'Text colour while the header is transparent', 'vychozi' => '', 'moznosti' => ['' => 'as normal', 'svetly' => 'light (over a dark photo)', 'tmavy' => 'dark (over a light photo)']],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['odsazeni_y' => 'xl']];
    }

    public static function baseCss(): string
    {
        return '.ka-obal { width: min(100% - 2 * var(--ka-okraj, var(--ka-mezera-m)), var(--ka-sirka)); margin-inline: auto; }
.ka-obal--uzka { width: min(100% - 2 * var(--ka-okraj, var(--ka-mezera-m)), var(--ka-sirka-textu)); }
:where(.stavba) a:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-obal > * + * { margin-block-start: var(--ka-mezera-m); }
.ka-s-videem { position: relative; isolation: isolate; overflow: hidden; }
.ka-video-pozadi { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; }
@media (prefers-reduced-motion: reduce) { .ka-video-pozadi { display: none; } }';
    }

    /**
     * The header on scroll (Build::css adds it only when a header uses it). A keyframe with only „from“ (or only „to“) animates to
     * the header's own style – its background, border and shadow stay whatever the style says; the defaults below apply when the
     * style has none. A browser without scroll-driven animations finishes the animation at once, so it simply shows the solid header;
     * so does a visitor who asked for less motion.
     */
    public static function scrollCss(): string
    {
        return '.ka-hlavicka-rolovani--pruhledna { z-index: 10; background-color: var(--ka-barva-pozadi); box-shadow: var(--ka-stin-s); }
@keyframes ka-hlavicka-pruhledna { from { background-color: transparent; border-color: transparent; box-shadow: none; } }
@keyframes ka-hlavicka-svetla { from { background-color: transparent; border-color: transparent; box-shadow: none; color: var(--ka-barva-bila); } }
@keyframes ka-hlavicka-tmava { from { background-color: transparent; border-color: transparent; box-shadow: none; color: var(--ka-barva-cerna); } }
@keyframes ka-hlavicka-mensi { to { padding-block: var(--ka-mezera-2xs); } }
@media (prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }';
    }

    /** The chosen behaviour on scroll – only in the header site part (elsewhere a fixed section would cover the page). */
    public static function scrollMode(array $p, Context $k): string
    {
        $mode = (string) ($p['obsah']['pri_rolovani'] ?? '');

        return $mode !== '' && str_starts_with($k->source, 'cast:hlavicka') ? $mode : '';
    }

    public static function behaviourCss(array $p, Context $k): string
    {
        $mode = self::scrollMode($p, $k);
        if ($mode === '') {
            return '';
        }
        $transparent = str_contains($mode, 'pruhledna');
        $animations = [];
        if ($transparent) {
            $text = (string) ($p['obsah']['text_nahore'] ?? '');
            $animations[] = 'ka-hlavicka-' . match ($text) { 'svetly' => 'svetla', 'tmavy' => 'tmava', default => 'pruhledna' } . ' linear both';
        }
        if (str_contains($mode, 'zmensit')) {
            $animations[] = 'ka-hlavicka-mensi linear both';
        }

        // transparent = out of the flow, so that the first section of the page starts at the top of the window; the timeline is the page scroll
        return ($transparent ? 'position: fixed; top: 0; inset-inline: 0; ' : '')
            . 'animation: ' . implode(', ', $animations) . '; animation-timeline: scroll(root); animation-range: 0 ' . self::SCROLL_RANGE . 'px;';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $width = $p['obsah']['sirka'] ?? 'obsah';
        $content = $width === 'plna' ? $children : '<div class="ka-obal' . ($width === 'uzka' ? ' ka-obal--uzka' : '') . '">' . $children . '</div>';

        // background video: only a file from Media (a third-party player would send data without consent); muted, looped, hidden from screen readers
        $video = (string) ($p['obsah']['video'] ?? '');
        if (preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300}\.(mp4|webm))$#i', $video, $m) && !str_contains($m[1], '..')) {
            $content = '<video class="ka-video-pozadi" src="' . e($k->app->request->basePath() . '/' . $m[1]) . '" autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>' . $content;
            $a = Text::withClass($a, 'ka-s-videem');
        }
        $mode = self::scrollMode($p, $k);
        if ($mode !== '') {
            $a = Text::withClass($a, 'ka-hlavicka-rolovani' . (str_contains($mode, 'pruhledna') ? ' ka-hlavicka-rolovani--pruhledna' : ''));
        }

        return '<' . $p['znacka'] . $a . '>' . $content . '</' . $p['znacka'] . '>';
    }
}
