<?php

use Kaleta\Builder\Build;

// Products without a checkout (2.11, Builder\Products): parameters to compare, variants, a datasheet and an enquiry basket –
// the visitor collects products and sends one enquiry.
$basketForm = fn (): array => Build::fresh('formular', ['nazev' => t('Product enquiry'), 'tlacitko' => t('Send enquiry'),
    'dekujeme' => t('Thank you, we have received your enquiry. We will get back to you with prices and availability.'),
    'pole' => [
        ['popisek' => t('Products'), 'typ' => 'kosik', 'povinne' => true, 'moznosti' => ''],
        ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
        ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
        ['popisek' => t('Phone'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
        ['popisek' => t('How can we help you?'), 'typ' => 'textarea', 'povinne' => false, 'moznosti' => ''],
        ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
    ]]);

return [
    'name' => 'Products',
    'button' => 'New product catalogue',
    'description' => 'Products with parameters to compare, variants and a datasheet; visitors collect them in an enquiry basket instead of a checkout.',
    'order' => 25,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['image', 'Image', 'obrazek'],
        ['code', 'Code', 'text'],
        ['brand', 'Brand', 'text'],
        ['category', 'Category', 'text'],
        ['summary', 'Summary', 'radky'],
        ['description', 'Description', 'html'],
        ['parameters', 'Parameters', 'parametry'],
        ['variants', 'Variants', 'varianty'],
        ['price', 'Price', 'cislo'],
        ['price_note', 'Price note', 'text'],
        ['availability', 'Availability', 'text'],
        ['datasheet', 'Datasheet', 'soubor'],
    ],
    'schema' => ['typ' => 'Product', 'pole' => ['brand' => 'brand', 'sku' => 'code', 'price' => 'price']],
    'list' => ['filtr_pole' => 'category', 'filtry' => true, 'strankovani' => true],
    'card' => ['summary', 'price', 'availability'],
    'card_extra' => fn (): array => [Build::fresh('do_poptavky', ['mnozstvi' => false])],
    'page_extra' => fn (): array => [['znacka' => 'h2'] + Build::fresh('nadpis', ['text' => t('Your enquiry')]), $basketForm()],
    'template' => fn (array $fields): array => [
        Build::fresh('obrazek', ['src' => '{{image}}', 'alt' => '{{nazev}}']),
        ['znacka' => 'h1'] + Build::fresh('nadpis', ['text' => '{{nazev}}']),
        Build::fresh('text', ['html' => '<p>{{summary}}</p><p><strong>{{price}}</strong> {{price_note}}</p><p>{{availability}}</p>']),
        Build::fresh('do_poptavky'),
        Build::fresh('text', ['html' => '<p>{{variants}}</p><p>{{parameters}}</p><p>{{description}}</p>']),
        Build::fresh('tlacitko', ['text' => t('Datasheet') . ' ({{datasheet_name}})', 'odkaz' => '{{datasheet}}', 'varianta' => 'obrys']),
    ],
    'claude' => 'One item per product. Parameters: one "Name: value" per line (the same names across products make the comparison useful); '
        . 'Variants: one "name | code | price" per line (price as text, e.g. "from 1 200 Kč"); Price is a number for search engines (set the '
        . 'currency in the collection\'s structured data). The Add to enquiry element (do_poptavky) on the item page and the cards puts products '
        . 'into the visitor\'s enquiry basket; a Form with a field of type kosik (basket) sends them – the created list page has one under the '
        . 'list (#poptavka). Visitors compare up to four products at /<collection>/_porovnat. {{parameters}} and {{variants}} are tables.',
];
