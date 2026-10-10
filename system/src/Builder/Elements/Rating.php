<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Star rating ("4.8 out of 5 · 120 reviews"). The stars are one SVG with a clipped fill (half a star too),
 * a screen reader reads the sentence from aria-label. Nothing is calculated – the administrator enters the value from real reviews.
 */
final class Rating extends Element
{
    public const string TYPE = 'rating';
    public const string NAME = 'Rating';
    public const string DESCRIPTION = 'Stars with a score and number of reviews (e.g. from Google).';
    public const string ICON = 'star';
    public const array HTML_TAGS = ['div', 'p'];

    public static function properties(): array
    {
        return [
            'value' => ['type' => 'text', 'label' => 'Rating (0–5, e.g. 4.8)', 'default' => '4,8', 'max' => 4],
            'text' => ['type' => 'text', 'label' => 'Text next to the stars', 'default' => t('out of 5 · 120 reviews'), 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-rating { display: flex; flex-wrap: wrap; align-items: center; gap: var(--tl-space-xs); }
.tl-rating svg { width: 6.5em; height: 1.3em; flex: none; }
.tl-rating-full { fill: #f5a524; }
.tl-rating-empty { fill: var(--tl-color-line); }
.tl-rating strong { font-variant-numeric: tabular-nums; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $value = max(0.0, min(5.0, (float) str_replace(',', '.', (string) $o['value'])));
        // stars side by side every 26 units; the fill is clipped to the value's share
        $stars = '';
        for ($i = 0; $i < 5; $i++) {
            $x = $i * 26;
            $stars .= '<path d="M' . ($x + 12) . ' 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1-6.4-3.4-6.4 3.4 1.4-7.1-5.3-5 7.2-.9z"/>';
        }
        $width = round($value / 5 * 128, 2);
        $number = rtrim(rtrim(format_number($value), '0'), ',.');
        $svg = '<svg viewBox="0 0 128 24" aria-hidden="true" focusable="false"><defs><clipPath id="hv-' . e($p['id']) . '"><rect width="' . $width . '" height="24"/></clipPath></defs>'
            . '<g class="tl-rating-empty">' . $stars . '</g><g class="tl-rating-full" clip-path="url(#hv-' . e($p['id']) . ')">' . $stars . '</g></svg>';

        return '<' . $p['tag'] . Text::withClass($a, 'tl-rating') . ' role="img" aria-label="' . e(t('Rated %s out of 5', $number) . ($o['text'] !== '' ? ' – ' . $o['text'] : '')) . '">'
            . $svg . '<strong aria-hidden="true">' . e($number) . '</strong>' . ($o['text'] !== '' ? '<span aria-hidden="true">' . e($o['text']) . '</span>' : '') . '</' . $p['tag'] . '>';
    }
}
