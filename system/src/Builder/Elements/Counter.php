<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Core\Facts;

/**
 * Animated counter ("1,200 happy customers"): the number is complete in the HTML (search engines, screen readers, site without a script),
 * web.js counts it up from zero once when it appears on screen. Whoever does not want motion (system setting) sees the result right away.
 * The number may be a fact or a computed token (2.10: {{fact.projects}}, {{years_since:fact.founded}}, {{count:reference}}), so it never
 * goes stale – the site audit asks for one where digits are typed in.
 */
final class Counter extends Element
{
    public const string TYPE = 'counter';
    public const string NAME = 'Counter';
    public const string DESCRIPTION = 'A big number with a label that counts up when shown (years of experience, customers, projects). The number may be a fact or a computed token, so it stays true.';
    public const string ICON = 'counter';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'number' => ['type' => 'text', 'label' => 'Number – or a fact or count token ({{fact.projects}}, {{years_since:2004}}, {{count:reference}})', 'default' => '1200', 'max' => 140],
            'prefix' => ['type' => 'text', 'label' => 'Before the number (e.g. “+”)', 'default' => '', 'max' => 10],
            'suffix' => ['type' => 'text', 'label' => 'After the number (e.g. “ %”, “+”, “ years”)', 'default' => '+', 'max' => 20],
            'caption' => ['type' => 'text', 'label' => 'Label', 'default' => t('satisfied customers'), 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-counter { display: grid; gap: var(--tl-space-2xs); }
.tl-counter-number { font: 800 var(--tl-step-5)/1 var(--tl-font-heading); font-variant-numeric: tabular-nums; color: var(--tl-color-primary); }
.tl-counter-caption { color: var(--tl-color-muted); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $raw = trim((string) $o['number']);
        if (preg_match('/^\d{1,9}$/', $raw)) {
            $number = (int) $raw;
            $format = format_count($number); // 1 200 in Czech, 1,200 in English (like Intl.NumberFormat in image/web.js)
        } elseif (preg_match(Facts::NUMBER_TOKEN_PATTERN, $raw) && !$k->editor) {
            // a fact or a computed token: the site fills it in here (not in the editor, which keeps the token); the digits drive the count-up
            $format = Facts::fillText($raw, $k->app);
            $number = (int) mb_substr((string) preg_replace('/\D+/', '', $format), 0, 9);
        } else {
            [$number, $format] = [0, $raw]; // the token in the editor, or something that is not a number – shown as typed, without the count-up
        }

        return '<div' . Text::withClass($a, 'tl-counter') . '><span class="tl-counter-number">' . e($o['prefix'])
            . '<span' . ($number > 0 ? ' data-counter="' . $number . '"' : '') . '>' . e($format) . '</span>' . e($o['suffix']) . '</span>'
            . ($o['caption'] !== '' ? '<span class="tl-counter-caption">' . e($o['caption']) . '</span>' : '') . '</div>';
    }
}
