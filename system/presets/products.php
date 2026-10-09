<?php

use Kaleta\Builder\Build;

// Products without a checkout (2.11, Builder\Products): parameters to compare, variants, a datasheet and an enquiry basket –
// the visitor collects products and sends one enquiry.
$basketForm = fn (): array => Build::fresh('form', ['name' => t('Product enquiry'), 'button_text' => t('Send enquiry'),
    'thank_you' => t('Thank you, we have received your enquiry. We will get back to you with prices and availability.'),
    'fields' => [
        ['label' => t('Products'), 'type' => 'basket', 'required' => true, 'options' => ''],
        ['label' => t('Name'), 'type' => 'text', 'required' => true, 'options' => ''],
        ['label' => t('Email'), 'type' => 'email', 'required' => true, 'options' => ''],
        ['label' => t('Phone'), 'type' => 'tel', 'required' => false, 'options' => ''],
        ['label' => t('How can we help you?'), 'type' => 'textarea', 'required' => false, 'options' => ''],
        ['label' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'type' => 'checkbox', 'required' => true, 'options' => ''],
    ]]);

return [
    'name' => 'Products',
    'button' => 'New product catalogue',
    'description' => 'Products with parameters to compare, variants and a datasheet; visitors collect them in an enquiry basket instead of a checkout.',
    'order' => 25,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['image', 'Image', 'image'],
        ['code', 'Code', 'text'],
        ['brand', 'Brand', 'text'],
        ['category', 'Category', 'text'],
        ['summary', 'Summary', 'lines'],
        ['description', 'Description', 'html'],
        ['parameters', 'Parameters', 'parameters'],
        ['variants', 'Variants', 'variants'],
        ['price', 'Price', 'number'],
        ['price_note', 'Price note', 'text'],
        ['availability', 'Availability', 'text'],
        ['datasheet', 'Datasheet', 'file'],
    ],
    'schema' => ['type' => 'Product', 'fields' => ['brand' => 'brand', 'sku' => 'code', 'price' => 'price']],
    'list' => ['filter_field' => 'category', 'filters' => true, 'pagination' => true],
    'card' => ['summary', 'price', 'availability'],
    'card_extra' => fn (): array => [Build::fresh('enquiry_button', ['quantity' => false])],
    'page_extra' => fn (): array => [['tag' => 'h2'] + Build::fresh('heading', ['text' => t('Your enquiry')]), $basketForm()],
    'template' => fn (array $fields): array => [
        Build::fresh('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
        ['tag' => 'h1'] + Build::fresh('heading', ['text' => '{{name}}']),
        Build::fresh('text', ['html' => '<p>{{summary}}</p><p><strong>{{price}}</strong> {{price_note}}</p><p>{{availability}}</p>']),
        Build::fresh('enquiry_button'),
        Build::fresh('text', ['html' => '<p>{{variants}}</p><p>{{parameters}}</p><p>{{description}}</p>']),
        Build::fresh('button', ['text' => t('Datasheet') . ' ({{datasheet_name}})', 'link' => '{{datasheet}}', 'variant' => 'outline']),
    ],
    'claude' => 'One item per product. Parameters: one "Name: value" per line (the same names across products make the comparison useful); '
        . 'Variants: one "name | code | price" per line (price as text, e.g. "from 1 200 Kč"); Price is a number for search engines (set the '
        . 'currency in the collection\'s structured data). The Add to enquiry element (do_poptavky) on the item page and the cards puts products '
        . 'into the visitor\'s enquiry basket; a Form with a field of type kosik (basket) sends them – the created list page has one under the '
        . 'list (#poptavka). Visitors compare up to four products at /<collection>/_compare. {{parameters}} and {{variants}} are tables.',
];
