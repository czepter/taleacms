<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/** Search field: a form to /search (pages, collection items and news; regardless of diacritics). */
final class Search extends Element
{
    public const string TYPE = 'search';
    public const string NAME = 'Search';
    public const string DESCRIPTION = 'A website search field – for the header, the 404 page or a listing.';
    public const string ICON = 'search';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'placeholder' => ['type' => 'text', 'label' => 'Placeholder text', 'default' => t('Search the website…'), 'max' => 80],
            'button_text' => ['type' => 'text', 'label' => 'Button', 'default' => t('Search'), 'max' => 40],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-search { display: flex; gap: var(--tl-space-xs); max-width: 32rem; }
.tl-search input { flex: 1; min-width: 0; padding: 0.6em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: inherit; font: inherit; }
.tl-search button { padding: 0.6em 1.1em; border: 0; border-radius: var(--tl-radius); background: var(--tl-color-primary); color: var(--tl-color-on-primary); font: inherit; font-weight: 600; cursor: pointer; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $id = 'hl-' . $p['id'];

        return '<form' . Text::withClass($a, 'tl-search') . ' role="search" method="get" action="' . e($k->url('search')) . '">'
            . '<label class="tl-reader-only" for="' . e($id) . '">' . e(t('Search the website')) . '</label>'
            . '<input type="search" id="' . e($id) . '" name="q" minlength="3" maxlength="100" placeholder="' . e($o['placeholder']) . '" required>'
            . '<button type="submit">' . e($o['button_text']) . '</button></form>';
    }
}
