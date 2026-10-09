<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Button = a link that looks like a button. Variants from the design system: primary, secondary, outline, text link. */
final class Button extends Element
{
    public const string TYPE = 'button';
    public const string NAME = 'Button';
    public const string DESCRIPTION = 'Call to action: a link shaped as a button.';
    public const string ICON = 'button';
    public const array HTML_TAGS = ['a'];
    public const array VARIANTS = ['primary' => 'primary', 'secondary' => 'secondary', 'outline' => 'outline', 'link' => 'text link'];

    public static function properties(): array
    {
        return [
            'text' => ['type' => 'text', 'label' => 'Text', 'default' => t('Contact us'), 'max' => 120],
            'link' => ['type' => 'link', 'label' => 'Link', 'default' => '#'],
            'variant' => ['type' => 'choice', 'label' => 'Appearance', 'default' => 'primary', 'options' => self::VARIANTS],
            'new_window' => ['type' => 'boolean', 'label' => 'Open in a new window', 'default' => false],
            'icon' => ['type' => 'choice', 'label' => 'Icon', 'default' => '', 'options' => ['' => 'no icon'] + \Kaleta\Builder\Icons::options()],
            'icon_left' => ['type' => 'boolean', 'label' => 'Icon left of the text', 'default' => false],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-tlacitko { display: inline-flex; align-items: center; justify-content: center; gap: 0.5em; padding: 0.75em 1.35em; border: 2px solid transparent; border-radius: var(--ka-zaobleni); font: 600 var(--ka-krok-0) / 1.2 var(--ka-pismo-text); text-decoration: none; cursor: pointer; transition: background-color 0.15s, color 0.15s, border-color 0.15s; }
.ka-tlacitko--primarni { background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }
.ka-tlacitko--primarni:hover { background: color-mix(in oklch, var(--ka-barva-primarni) 85%, black); }
.ka-tlacitko--sekundarni { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-primarni); }
.ka-tlacitko--sekundarni:hover { background: color-mix(in oklch, var(--ka-barva-primarni) 20%, var(--ka-barva-pozadi)); }
.ka-tlacitko--obrys { border-color: currentColor; color: inherit; background: transparent; }
.ka-tlacitko--obrys:hover { background: color-mix(in oklch, currentColor 8%, transparent); }
.ka-tlacitko--odkaz { padding-inline: 0; color: var(--ka-barva-primarni); text-decoration: underline; text-underline-offset: 0.2em; }
.ka-tlacitko svg { flex: none; width: var(--ka-tlacitko-ikona, 1.15em); height: var(--ka-tlacitko-ikona, 1.15em); }
.ka-tlacitko:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        if ($o['link'] === '' && $k->item !== null && !$k->editor) {
            return ''; // on an item page or a card the link came from a field that is empty (no datasheet, no file) – no dead button (2.11)
        }

        $icon = ($o['icon'] ?? '') !== '' ? \Kaleta\Builder\Icons::svg($o['icon']) : '';

        return '<a' . Text::withClass($a, 'ka-tlacitko ka-tlacitko--' . $o['variant']) . ' href="' . e($o['link'] !== '' ? $o['link'] : '#') . '"'
            . ($o['new_window'] ? ' target="_blank" rel="noopener"' : '') . '>' . (!empty($o['icon_left']) ? $icon . e($o['text']) : e($o['text']) . $icon) . '</a>';
    }
}
