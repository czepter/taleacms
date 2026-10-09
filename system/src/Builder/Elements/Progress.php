<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Progress bars (skills, progress toward a goal): each row is a <meter> with a label and percentage. The bar slides out on scroll
 * purely in CSS (a scroll-driven animation); without support or with reduced motion it is full right away.
 */
final class Progress extends Element
{
    public const string TYPE = 'progress_bars';
    public const string NAME = 'Progress bars';
    public const string DESCRIPTION = 'Bars with percentages – goal progress, share, skill level.';
    public const string ICON = 'progress_bars';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['items' => ['type' => 'items', 'label' => 'Bars', 'max' => 12, 'fields' => [
            'name' => ['type' => 'text', 'label' => 'Name', 'default' => '', 'max' => 120],
            'value' => ['type' => 'number', 'label' => 'Percent', 'default' => 50, 'min' => 0, 'max' => 100],
        ], 'default' => [['name' => t('Projects delivered on time'), 'value' => 96], ['name' => t('Returning customers'), 'value' => 78]]]];
    }

    public static function baseCss(): string
    {
        return '.ka-progress { display: grid; gap: var(--ka-space-m); }
.ka-progress-row { display: grid; grid-template-columns: 1fr auto; gap: var(--ka-space-2xs) var(--ka-space-s); }
.ka-progress-row meter { grid-column: 1 / -1; width: 100%; height: 0.6rem; border: 0; border-radius: 999px; background: var(--ka-color-surface); appearance: none; }
.ka-progress-row meter::-webkit-meter-bar { height: 0.6rem; border: 0; border-radius: 999px; background: var(--ka-color-surface); }
.ka-progress-row meter::-webkit-meter-optimum-value { border-radius: 999px; background: var(--ka-color-primary); }
.ka-progress-row meter::-moz-meter-bar { border-radius: 999px; background: var(--ka-color-primary); }
.ka-progress-value { font-variant-numeric: tabular-nums; font-weight: 600; }
@supports (animation-timeline: view()) { @media (prefers-reduced-motion: no-preference) {
	.ka-progress-row meter { transform-origin: left; animation: ka-progress linear both; animation-timeline: view(); animation-range: entry 10% cover 35%; }
	@keyframes ka-progress { from { scale: 0 1; } }
} }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        foreach ($p['content']['items'] as $i => $r) {
            $id = 'pr-' . $p['id'] . '-' . $i;
            $html .= '<div class="ka-progress-row"><span id="' . e($id) . '">' . e((string) $r['name']) . '</span><span class="ka-progress-value">' . (int) $r['value'] . ' %</span>'
                . '<meter min="0" max="100" low="0" optimum="100" value="' . (int) $r['value'] . '" aria-labelledby="' . e($id) . '">' . (int) $r['value'] . ' %</meter></div>';
        }

        return '<div' . Text::withClass($a, 'ka-progress') . '>' . $html . '</div>';
    }
}
