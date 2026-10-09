<?php

// Services (2.11): a page for each service with a summary, an image, a description and a price from; the page of a service
// no longer offered leads to the services page.
return [
    'name' => 'Services',
    'button' => 'New services',
    'description' => 'A page for each service with a summary, an image, a description and a price from; the list shows cards with the summary.',
    'order' => 20,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['summary', 'Summary', 'lines'],
        ['image', 'Image', 'image'],
        ['description', 'Description', 'html'],
        ['price_from', 'Price from', 'number'],
        ['price_note', 'Price note', 'text'],
    ],
    'schema' => ['type' => 'Service', 'fields' => ['price' => 'price_from']],
    'claude' => 'One item per service. A Collection list of it on the services page (sorted by order in the administration – put the main services first); '
        . 'the item template shows the image, the summary as the lead, the description and the price from with its note (e.g. "per hour"). '
        . 'Hide a service you no longer offer – its page then leads to the services page. The structured data is Service with the price from; '
        . 'set the currency with update_collection structured_data when the price should reach search engines.',
    'list' => [],
    'card' => ['summary'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'label', 'key');

        return [
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>{{summary}}</strong></p>']),
            $n('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
            $n('text', ['html' => '{{description}}']),
            $n('text', ['html' => '<p><strong>' . e($label['price_from']) . ':</strong> {{price_from}} {{price_note}}</p>']),
        ];
    },
];
