<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Tabs: content split under switchable tabs (ARIA tabs, arrow keys on the keyboard – image/web.js).
 * Without JavaScript all panels show one below another with their headings, nothing gets lost.
 */
final class Tabs extends Element
{
    public const string TYPE = 'tabs';
    public const string NAME = 'Tabs';
    public const string DESCRIPTION = 'Content split into switchable tabs – pricing by package, services by field.';
    public const string ICON = 'tabs';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['tabs' => ['type' => 'items', 'label' => 'Tabs', 'max' => 12, 'fields' => [
            'name' => ['type' => 'text', 'label' => 'Tab name', 'default' => '', 'max' => 80],
            'content' => ['type' => 'html', 'label' => 'Content', 'default' => ''],
        ], 'default' => [['name' => t('First tab'), 'content' => '<p>' . t('Content of the first tab.') . '</p>'], ['name' => t('Second tab'), 'content' => '<p>' . t('Content of the second tab.') . '</p>']]]];
    }

    public static function baseCss(): string
    {
        return '.tl-tabs [role="tablist"] { display: flex; flex-wrap: wrap; gap: var(--tl-space-2xs); border-block-end: 1px solid var(--tl-color-line); }
.tl-tabs [role="tab"] { margin-block-end: -1px; padding: 0.6em 1em; border: 1px solid transparent; border-radius: var(--tl-radius-s) var(--tl-radius-s) 0 0; background: none; color: var(--tl-color-muted); font: inherit; font-weight: 600; cursor: pointer; }
.tl-tabs [role="tab"][aria-selected="true"] { border-color: var(--tl-color-line); border-block-end-color: var(--tl-color-background); background: var(--tl-color-background); color: var(--tl-color-text); }
.tl-tabs [role="tab"]:focus-visible { outline: 2px solid var(--tl-color-primary); outline-offset: 2px; }
.tl-tabs [role="tabpanel"] { padding-block: var(--tl-space-m); }
.tl-tabs [role="tabpanel"] > :last-child { margin-block-end: 0; }
.tl-tabs:not([data-enabled]) [role="tablist"] { display: none; }
.tl-tabs[data-enabled] .tl-tabs-heading { display: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $tabList = '';
        $panels = '';
        foreach (array_values(array_filter($p['content']['tabs'], fn (array $x): bool => $x['name'] !== '')) as $i => $card) {
            [$tab, $panel] = ['z-' . $p['id'] . '-' . $i, 'zp-' . $p['id'] . '-' . $i];
            $tabList .= '<button type="button" role="tab" id="' . $tab . '" aria-controls="' . $panel . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '"' . ($i === 0 ? '' : ' tabindex="-1"') . '>' . e($card['name']) . '</button>';
            // without the script all panels with a heading are visible; the script hides the inactive ones and the headings (the data-enabled attribute)
            $panels .= '<div role="tabpanel" id="' . $panel . '" aria-labelledby="' . $tab . '" tabindex="0"><h3 class="tl-tabs-heading">' . e($card['name']) . '</h3>' . $card['content'] . '</div>';
        }

        return '<div' . Text::withClass($a, 'tl-tabs') . ' data-tabs' . ($k->editor ? ' data-enabled' : '') . '><div role="tablist">' . $tabList . '</div>' . $panels . '</div>';
    }
}
