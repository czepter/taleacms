<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Countdown to a date (an event, an opening, a deadline): days, hours, minutes and seconds. The server outputs the state at the moment
 * of rendering, web.js then counts it down every second; once it has passed, the „po skončení“ (after the end) text is shown.
 */
final class Countdown extends Element
{
    public const string TYPE = 'countdown';
    public const string NAME = 'Countdown';
    public const string DESCRIPTION = 'Time remaining until a date – an event, an opening, a registration deadline.';
    public const string ICON = 'countdown';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'target' => ['type' => 'text', 'label' => 'Until (YYYY-MM-DD HH:MM)', 'default' => date('Y-m-d', strtotime('+30 days')) . ' 09:00', 'max' => 16],
            'end_text' => ['type' => 'text', 'label' => 'Text when finished', 'default' => t('The event is on now.'), 'max' => 200],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-countdown { display: flex; flex-wrap: wrap; gap: var(--ka-space-s); margin: 0; }
.ka-countdown > div { display: grid; min-width: 4.5rem; padding: var(--ka-space-s); border-radius: var(--ka-radius); background: var(--ka-color-surface); text-align: center; }
.ka-countdown dd { order: -1; margin: 0; font: 800 var(--ka-step-4)/1 var(--ka-font-heading); font-variant-numeric: tabular-nums; }
.ka-countdown dt { color: var(--ka-color-muted); font-size: var(--ka-step--1); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $target = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', (string) $o['target']) ? strtotime((string) $o['target']) : false;
        if ($target === false) {
            return $k->editor ? '<p' . $a . '>' . e(t('Enter the date as YYYY-MM-DD HH:MM.')) . '</p>' : '';
        }
        $remaining = $target - time();
        if ($remaining <= 0) {
            return '<p' . Text::withClass($a, 'ka-countdown-end') . '>' . e($o['end_text']) . '</p>';
        }
        $parts = ['d' => [intdiv($remaining, 86400), t('days')], 'h' => [intdiv($remaining % 86400, 3600), t('hours')], 'm' => [intdiv($remaining % 3600, 60), t('minutes')], 's' => [$remaining % 60, t('seconds')]];
        $html = '';
        foreach ($parts as $key => [$number, $name]) {
            $html .= '<div><dt>' . e($name) . '</dt><dd data-part="' . $key . '">' . ($key === 'd' ? $number : str_pad((string) $number, 2, '0', STR_PAD_LEFT)) . '</dd></div>';
        }

        return '<dl' . Text::withClass($a, 'ka-countdown') . ' data-countdown="' . e(date('c', $target)) . '" data-end="' . e($o['end_text']) . '" role="timer" aria-live="off">' . $html . '</dl>';
    }
}
