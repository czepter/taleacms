<?php

// Property listings (3.3): a page for each property for sale or to let with the price, the location, the areas, the layout,
// the energy rating, parameters and a map of the location; filter buttons by offer, and a sold or let property leads to the list.
return [
    'name' => 'Property listings',
    'button' => 'New property listings',
    'description' => 'A page for each property for sale or to let with a photo, the price, the location, the floor and plot area, the layout, the energy rating, parameters and a map; the list filters by offer.',
    'order' => 48,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['image', 'Image', 'image'],
        ['offer', 'Offer', 'text'],
        ['status', 'Status', 'text'],
        ['price', 'Price', 'number'],
        ['price_note', 'Price note', 'text'],
        ['location', 'Location', 'text'],
        ['floor_area', 'Floor area (m²)', 'number'],
        ['plot_area', 'Plot area (m²)', 'number'],
        ['layout', 'Room layout', 'text'],
        ['energy_rating', 'Energy rating', 'text'],
        ['summary', 'Summary', 'lines'],
        ['description', 'Description', 'html'],
        ['parameters', 'Parameters', 'parameters'],
    ],
    'schema' => null,
    'claude' => 'One item per property. Offer: the same words for all listings ("For sale", "To let") – the list page shows filter buttons by offer. '
        . 'Status: "Available", "Reserved" or "Sold"/"Let"; hide a listing once it is sold or let – its page then leads to the list. Price a number with the note '
        . '("per month + utilities", "incl. commission", "price on request" with 0). Location as buyers search for it (district, town); floor and plot area in m²; '
        . 'layout as the local market writes it ("3+kk", "2 bedrooms"); energy rating as on the certificate. Parameters: one "Name: value" per line (floor, '
        . 'parking, heating…). Never invent prices, areas, ratings or parameters – take them from the owner or the listing documents.',
    'list' => ['sort' => 'order', 'filter_field' => 'offer', 'filters' => true, 'pagination' => true],
    'card' => ['offer', 'status', 'price', 'location', 'floor_area'],
    'template' => function (array $fields): array {
        $n = \Talea\Builder\Build::fresh(...);
        $label = array_column($fields, 'label', 'key');

        return [
            $n('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>{{offer}} · {{status}}</strong></p><p><strong>' . e($label['price']) . ':</strong> {{price}} {{price_note}}</p>'
                . '<p>' . e($label['location']) . ': {{location}} · ' . e($label['floor_area']) . ': {{floor_area}} · ' . e($label['plot_area']) . ': {{plot_area}}</p>'
                . '<p>' . e($label['layout']) . ': {{layout}} · ' . e($label['energy_rating']) . ': {{energy_rating}}</p>']),
            $n('text', ['html' => '<p>{{summary}}</p>{{description}}<p>{{parameters}}</p>']),
            $n('map', ['address' => '{{location}}']), // the Map element loads only after a click
        ];
    },
];
