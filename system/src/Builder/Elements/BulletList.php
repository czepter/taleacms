<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

final class BulletList extends Element
{
    public const string TYPE = 'list';
    public const string NAME = 'List';
    public const string DESCRIPTION = 'A list of points – with bullets, numbers or ticks.';
    public const string ICON = 'list';
    public const array HTML_TAGS = ['ul', 'ol'];

    public static function properties(): array
    {
        return [
            'items' => ['type' => 'lines', 'label' => 'Items (one per line)', 'default' => t('First benefit') . "\n" . t('Second benefit') . "\n" . t('Third benefit'), 'max' => 4000],
            'style' => ['type' => 'choice', 'label' => 'Bullets', 'default' => 'bullets', 'options' => ['bullets' => 'normal', 'checks' => 'checks', 'none' => 'no bullets']],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-list--checks, .tl-list--plain { list-style: none; padding-inline-start: 0; }
.tl-list--checks li { position: relative; padding-inline-start: 1.6em; }
.tl-list--checks li::before { content: ""; position: absolute; left: 0.1em; top: 0.35em; width: 0.9em; height: 0.5em; border: solid var(--tl-color-primary); border-width: 0 0 0.16em 0.16em; transform: rotate(-45deg); }
.tl-list li + li { margin-block-start: 0.4em; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $items = array_filter(array_map(trim(...), preg_split('/\R/', $p['content']['items']) ?: []), fn (string $r): bool => $r !== '');

        return '<' . $p['tag'] . Text::withClass($a, 'tl-list tl-list--' . $p['content']['style']) . '>'
            . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $items)) . '</' . $p['tag'] . '>';
    }
}
