<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Hotspots: an image with numbered points; each point is a <details> whose summary is the button and whose content is a small
 * popover with the label and text – no script, works with the keyboard, closes with a second click, one open at a time (the name
 * attribute). The points are listed under the image as well: for screen readers, search engines and anyone who prefers reading.
 * The editor sets the position as percentages (x from the left, y from the top) – no dragging needed.
 */
final class Hotspots extends Element
{
    public const string TYPE = 'hotspots';
    public const string NAME = 'Hotspots';
    public const string DESCRIPTION = 'An image with numbered points – a tap on a point shows its label and text (a product, a floor plan, a map of premises).';
    public const string ICON = 'hotspots';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'src' => ['type' => 'image', 'label' => 'Image', 'default' => ''],
            'alt' => ['type' => 'text', 'label' => 'Description for blind users (alt)', 'default' => '', 'max' => 300],
            'points' => ['type' => 'items', 'label' => 'Points', 'max' => 20, 'fields' => [
                'x' => ['type' => 'number', 'label' => 'From the left (%)', 'default' => 50, 'min' => 0, 'max' => 100],
                'y' => ['type' => 'number', 'label' => 'From the top (%)', 'default' => 50, 'min' => 0, 'max' => 100],
                'name' => ['type' => 'text', 'label' => 'Label', 'default' => '', 'max' => 80],
                'description' => ['type' => 'lines', 'label' => 'Text', 'default' => '', 'max' => 600],
            ], 'default' => [
                ['x' => 30, 'y' => 40, 'name' => t('First point'), 'description' => t('What is here and why it matters.')],
                ['x' => 70, 'y' => 60, 'name' => t('Second point'), 'description' => t('What is here and why it matters.')],
            ]],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hotspots-image { position: relative; }
.ka-hotspots-image img { display: block; width: 100%; height: auto; border-radius: var(--ka-radius); }
.ka-hotspots-point { position: absolute; inset-block-start: var(--y); inset-inline-start: var(--x); width: 0; height: 0; }
.ka-hotspots-point summary { position: absolute; display: grid; place-items: center; width: 2rem; height: 2rem; translate: -50% -50%; border: 2px solid var(--ka-color-white); border-radius: 50%; background: var(--ka-color-primary); color: var(--ka-color-on-primary); font-size: var(--ka-step--1); font-weight: 700; line-height: 1; list-style: none; cursor: pointer; box-shadow: var(--ka-shadow-m); }
.ka-hotspots-point summary::-webkit-details-marker { display: none; }
.ka-hotspots-point summary:focus-visible { outline: 3px solid var(--ka-color-secondary); outline-offset: 2px; }
.ka-hotspots-point[open] summary { background: var(--ka-color-text); color: var(--ka-color-background); }
@media (prefers-reduced-motion: no-preference) {
	.ka-hotspots-point:not([open]) summary::after { content: ""; position: absolute; inset: -2px; border: 2px solid var(--ka-color-primary); border-radius: 50%; animation: ka-hotspot 2s ease-out infinite; }
	@keyframes ka-hotspot { to { scale: 1.8; opacity: 0; } }
}
.ka-hotspots-description { position: absolute; z-index: 2; inset-block-start: 1.4rem; inset-inline-start: -1rem; width: max-content; max-width: min(18rem, calc(100vw - 2rem)); padding: var(--ka-space-s); border: 1px solid var(--ka-color-line); border-radius: var(--ka-radius); background: var(--ka-color-background); color: var(--ka-color-text); box-shadow: var(--ka-shadow-l); font-size: var(--ka-step--1); text-align: start; }
.ka-hotspots-description p { margin: var(--ka-space-2xs) 0 0; color: var(--ka-color-muted); }
.ka-hotspots-point--left .ka-hotspots-description { inset-inline-start: auto; inset-inline-end: -1rem; }
.ka-hotspots-point--up .ka-hotspots-description { inset-block-start: auto; inset-block-end: 1.4rem; }
.ka-hotspots-list { display: grid; gap: var(--ka-space-2xs); margin: var(--ka-space-s) 0 0; padding-inline-start: 1.5em; color: var(--ka-color-muted); font-size: var(--ka-step--1); }
.ka-hotspots-list strong { color: var(--ka-color-text); }
.ka-hotspots-sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-color-surface)">' . e(t('Choose an image')) . '</div>' : '';
        }
        $points = '';
        $list = '';
        foreach (array_values(array_filter($o['points'], fn (array $b): bool => $b['name'] !== '')) as $i => $point) {
            [$x, $y] = [max(0, min(100, (int) $point['x'])), max(0, min(100, (int) $point['y']))];
            $text = $point['description'] !== '' ? nl2br(e($point['description'])) : '';
            // the popover opens to the side and in the direction where there is room: left of a point on the right, above a point low down
            $points .= '<details class="ka-hotspots-point' . ($x > 50 ? ' ka-hotspots-point--left' : '') . ($y > 60 ? ' ka-hotspots-point--up' : '') . '" name="hs-' . e($p['id']) . '" style="--x:' . $x . '%;--y:' . $y . '%">'
                . '<summary><span aria-hidden="true">' . ($i + 1) . '</span><span class="ka-hotspots-sr">' . e($point['name']) . '</span></summary>'
                . '<div class="ka-hotspots-description"><strong>' . e($point['name']) . '</strong>' . ($text !== '' ? '<p>' . $text . '</p>' : '') . '</div></details>';
            $list .= '<li><strong>' . e($point['name']) . '</strong>' . ($text !== '' ? ' – ' . $text : '') . '</li>';
        }

        return '<div' . Text::withClass($a, 'ka-hotspots') . '><div class="ka-hotspots-image"><img src="' . e($k->image($o['src'])) . '" alt="' . e($o['alt']) . '" loading="lazy">' . $points . '</div>'
            . ($list !== '' ? '<ol class="ka-hotspots-list">' . $list . '</ol>' : '') . '</div>';
    }
}
