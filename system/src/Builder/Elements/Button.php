<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

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
            'icon' => ['type' => 'choice', 'label' => 'Icon', 'default' => '', 'options' => ['' => 'no icon'] + \Talea\Builder\Icons::options()],
            'icon_left' => ['type' => 'boolean', 'label' => 'Icon left of the text', 'default' => false],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-button { display: inline-flex; align-items: center; justify-content: center; gap: 0.5em; padding: 0.75em 1.35em; border: 2px solid transparent; border-radius: var(--tl-radius); font: 600 var(--tl-step-0) / 1.2 var(--tl-font-body); text-decoration: none; cursor: pointer; transition: background-color 0.15s, color 0.15s, border-color 0.15s; }
.tl-button--primary { background: var(--tl-color-primary); color: var(--tl-color-on-primary); }
.tl-button--primary:hover { background: color-mix(in oklch, var(--tl-color-primary) 85%, black); }
.tl-button--secondary { background: var(--tl-color-primary-soft); color: var(--tl-color-primary); }
.tl-button--secondary:hover { background: color-mix(in oklch, var(--tl-color-primary) 20%, var(--tl-color-background)); }
.tl-button--outline { border-color: currentColor; color: inherit; background: transparent; }
.tl-button--outline:hover { background: color-mix(in oklch, currentColor 8%, transparent); }
.tl-button--link { padding-inline: 0; color: var(--tl-color-primary); text-decoration: underline; text-underline-offset: 0.2em; }
.tl-button svg { flex: none; width: var(--tl-button-icon, 1.15em); height: var(--tl-button-icon, 1.15em); }
.tl-button:focus-visible { outline: 3px solid var(--tl-color-secondary); outline-offset: 2px; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        if ($o['link'] === '' && $k->item !== null && !$k->editor) {
            return ''; // on an item page or a card the link came from a field that is empty (no datasheet, no file) – no dead button (2.11)
        }

        $icon = ($o['icon'] ?? '') !== '' ? \Talea\Builder\Icons::svg($o['icon']) : '';

        return '<a' . Text::withClass($a, 'tl-button tl-button--' . $o['variant']) . ' href="' . e($o['link'] !== '' ? $o['link'] : '#') . '"'
            . ($o['new_window'] ? ' target="_blank" rel="noopener"' : '') . '>' . (!empty($o['icon_left']) ? $icon . e($o['text']) : e($o['text']) . $icon) . '</a>';
    }
}
