<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * "Add to enquiry" (2.11, Builder\Products): on a product's page or card, a variant, a quantity and a button that puts the
 * product into the visitor's enquiry basket (their browser only), and a box to compare it with others. The basket is sent
 * with a Form that has a basket field – by default on the products list page (#poptavka).
 *
 * Without JavaScript the button opens the basket page with the product in the address, and the basket field takes it from
 * there; with it the product goes to the basket and the visitor stays on the page.
 */
final class EnquiryButton extends Element
{
    public const string TYPE = 'enquiry_button';
    public const string NAME = 'Add to enquiry';
    public const string DESCRIPTION = 'A product\'s variant, quantity and a button that adds it to the enquiry basket, with a box to compare products.';
    public const string ICON = 'basket';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'text' => ['type' => 'text', 'label' => 'Button text', 'default' => t('Add to enquiry'), 'max' => 60],
            'basket_page' => ['type' => 'link', 'label' => 'Page with the enquiry form (empty = the products list page)', 'default' => ''],
            'quantity' => ['type' => 'boolean', 'label' => 'Quantity', 'default' => true],
            'compare' => ['type' => 'boolean', 'label' => 'Compare box', 'default' => true],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-enquiry-button { display: flex; flex-wrap: wrap; align-items: end; gap: var(--tl-space-s); }
.tl-enquiry-button label { display: grid; gap: 0.25em; font-size: 0.9em; }
.tl-enquiry-button input[type=number] { width: 6em; }
.tl-enquiry-button .tl-compare { display: flex; align-items: center; gap: 0.4em; }
.tl-enquiry-button-status { flex-basis: 100%; margin: 0; font-size: 0.9em; }
.tl-enquiry-button-status:empty { display: none; }
.tl-compare { width: 100%; border-collapse: collapse; }
.tl-compare th, .tl-compare td { padding: 0.6em 0.8em; border-bottom: 1px solid var(--tl-color-line); text-align: left; vertical-align: top; }
.tl-compare thead img { display: block; width: 100%; max-width: 12rem; height: auto; margin-bottom: 0.5em; }
.tl-compare-wrap { overflow-x: auto; }
.tl-system-page { display: grid; gap: var(--tl-space-m); max-width: var(--tl-width); margin-inline: auto; padding: var(--tl-space-xl) var(--tl-space-m); }
.tl-bar-compare { position: fixed; inset: auto 1rem 1rem auto; z-index: 60; display: flex; gap: 0.5em; align-items: center; padding: 0.5em 0.75em; border-radius: var(--tl-radius-m);
	background: var(--tl-color-text); color: var(--tl-color-background); box-shadow: var(--tl-shadow-m); font-size: 0.9em; }
.tl-bar-compare a, .tl-bar-compare button { color: inherit; font: inherit; }
.tl-bar-compare button { background: none; border: 0; text-decoration: underline; cursor: pointer; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $product = json_decode((string) ($k->item['_product'][0] ?? ''), true);
        if (!is_array($product)) {
            return $k->editor ? '<p' . $a . '><small>' . e(t('Add to enquiry – shows on the pages and cards of a products collection.')) . '</small></p>' : '';
        }
        $basket = (string) $o['basket_page'] !== '' ? (string) $o['basket_page'] : $k->url((string) $product['c']) . '#poptavka';
        $id = 'p-' . $p['id'] . '-' . substr(md5((string) $product['i']), 0, 6); // unique also in a list of several products
        $variants = (array) ($product['v'] ?? []);
        $html = '<input type="hidden" name="product" value="' . e($product['c'] . '/' . $product['i']) . '">';
        if ($variants !== []) {
            $html .= '<label for="' . $id . '-v">' . e(t('Variant')) . '<select id="' . $id . '-v" name="variant">'
                . implode('', array_map(fn (mixed $v): string => '<option>' . e((string) $v) . '</option>', $variants)) . '</select></label>';
        }
        if ($o['quantity']) {
            $html .= '<label for="' . $id . '-q">' . e(t('Quantity')) . '<input id="' . $id . '-q" name="quantity" type="number" value="1" min="1" max="9999" inputmode="numeric"></label>';
        }
        $html .= '<button class="tl-button tl-button--primary" type="submit">' . e($o['text']) . '</button>';
        if ($o['compare']) {
            $html .= '<label class="tl-compare"><input type="checkbox" data-compare> ' . e(t('Compare')) . '</label>';
        }
        $k->types['button'] = true;

        return '<form' . Text::withClass($a, 'tl-enquiry-button') . ' method="get" action="' . e(preg_replace('/#.*$/', '', $basket) ?? $basket) . '" data-product="' . e((string) json_encode(
            ['c' => $product['c'], 'i' => $product['i'], 'n' => $product['n']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '" data-basket="' . e($basket) . '" data-compare-url="' . e($k->url($product['c'] . '/_compare')) . '">'
            . $html . '<p class="tl-enquiry-button-status" role="status" aria-live="polite"></p></form>';
    }
}
