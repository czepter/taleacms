<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Tabs: content split under switchable tabs (ARIA tabs, arrow keys on the keyboard – image/web.js).
 * Without JavaScript all panels show one below another with their headings, nothing gets lost.
 */
final class Tabs extends Element
{
    public const string TYPE = 'zalozky';
    public const string NAME = 'Záložky';
    public const string DESCRIPTION = 'Content split into switchable tabs – pricing by package, services by field.';
    public const string ICON = 'zalozky';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['karty' => ['type' => 'items', 'popisek' => 'Karty', 'max' => 12, 'pole' => [
            'nazev' => ['type' => 'text', 'popisek' => 'Tab name', 'vychozi' => '', 'max' => 80],
            'obsah' => ['type' => 'html', 'popisek' => 'Content', 'vychozi' => ''],
        ], 'vychozi' => [['nazev' => t('First tab'), 'obsah' => '<p>' . t('Content of the first tab.') . '</p>'], ['nazev' => t('Second tab'), 'obsah' => '<p>' . t('Content of the second tab.') . '</p>']]]];
    }

    public static function baseCss(): string
    {
        return '.ka-zalozky [role="tablist"] { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-2xs); border-block-end: 1px solid var(--ka-barva-linka); }
.ka-zalozky [role="tab"] { margin-block-end: -1px; padding: 0.6em 1em; border: 1px solid transparent; border-radius: var(--ka-zaobleni-s) var(--ka-zaobleni-s) 0 0; background: none; color: var(--ka-barva-tlumeny); font: inherit; font-weight: 600; cursor: pointer; }
.ka-zalozky [role="tab"][aria-selected="true"] { border-color: var(--ka-barva-linka); border-block-end-color: var(--ka-barva-pozadi); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); }
.ka-zalozky [role="tab"]:focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 2px; }
.ka-zalozky [role="tabpanel"] { padding-block: var(--ka-mezera-m); }
.ka-zalozky [role="tabpanel"] > :last-child { margin-block-end: 0; }
.ka-zalozky:not([data-zapnuto]) [role="tablist"] { display: none; }
.ka-zalozky[data-zapnuto] .ka-zalozky-nadpis { display: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $tabList = '';
        $panels = '';
        foreach (array_values(array_filter($p['obsah']['karty'], fn (array $x): bool => $x['nazev'] !== '')) as $i => $card) {
            [$tab, $panel] = ['z-' . $p['id'] . '-' . $i, 'zp-' . $p['id'] . '-' . $i];
            $tabList .= '<button type="button" role="tab" id="' . $tab . '" aria-controls="' . $panel . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '"' . ($i === 0 ? '' : ' tabindex="-1"') . '>' . e($card['nazev']) . '</button>';
            // without the script all panels with a heading are visible; the script hides the inactive ones and the headings (the data-zapnuto attribute)
            $panels .= '<div role="tabpanel" id="' . $panel . '" aria-labelledby="' . $tab . '" tabindex="0"><h3 class="ka-zalozky-nadpis">' . e($card['nazev']) . '</h3>' . $card['obsah'] . '</div>';
        }

        return '<div' . Text::withClass($a, 'ka-zalozky') . ' data-zalozky' . ($k->editor ? ' data-zapnuto' : '') . '><div role="tablist">' . $tabList . '</div>' . $panels . '</div>';
    }
}
