<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Icons;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Icon from the built-in set (Builder\Icons) as inline SVG: color = the element's text color, size = the font size.
 * Without a description it is decorative (screen readers skip it), with a description they read it.
 */
final class Icon extends Element
{
    public const string TYPE = 'icon';
    public const string NAME = 'Icon';
    public const string DESCRIPTION = 'A simple icon (check, phone, star…) – set its colour and size with the style.';
    public const string ICON = 'icon';
    public const array HTML_TAGS = ['span', 'div'];

    public static function properties(): array
    {
        return [
            'icon' => ['type' => 'vyber', 'popisek' => 'Icon', 'vychozi' => 'check-circle', 'options' => Icons::options()],
            'shape' => ['type' => 'vyber', 'popisek' => 'Podklad', 'vychozi' => '', 'options' => ['' => 'no background', 'circle' => 'circle', 'square' => 'rounded square']],
            'popis' => ['type' => 'text', 'popisek' => 'Description for screen readers (empty = decorative only)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function baseCss(): string
    {
        // default size and color (the element's style overrides them); an element inserted via AI or MCP thus looks the same as from the editor
        return '.ka-ikona { display: inline-grid; place-items: center; flex: none; width: 1em; height: 1em; line-height: 1; font-size: var(--ka-krok-3); color: var(--ka-barva-primarni); }
.ka-ikona svg { display: block; width: 100%; height: 100%; }
.ka-ikona--kruh, .ka-ikona--ctverec { width: 1.9em; height: 1.9em; padding: 0.45em; background: var(--ka-barva-primarni-jemna); }
.ka-ikona--kruh { border-radius: 50%; }
.ka-ikona--ctverec { border-radius: var(--ka-zaobleni-m); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $className = 'ka-ikona' . ($o['shape'] !== '' ? ' ka-ikona--' . $o['shape'] : '');
        $description = $o['popis'] !== '' ? ' role="img" aria-label="' . e($o['popis']) . '"' : ' aria-hidden="true"';

        return '<' . $p['tag'] . Text::withClass($a, $className) . $description . '>' . Icons::svg($o['icon']) . '</' . $p['tag'] . '>';
    }
}
