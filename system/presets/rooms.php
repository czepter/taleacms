<?php

// Rooms and accommodation (3.3): a page for each room, apartment or cottage with the guests it takes, the beds, the size,
// the price per night from, the amenities and a booking link; a room no longer let leads to the list.
return [
    'name' => 'Rooms and accommodation',
    'button' => 'New rooms',
    'description' => 'A page for each room, apartment or cottage with a photo, how many guests it takes, the beds, the size, the price per night from, the amenities and a booking link.',
    'order' => 47,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['image', 'Image', 'image'],
        ['summary', 'Summary', 'radky'],
        ['guests', 'Guests', 'number'],
        ['beds', 'Beds', 'text'],
        ['size', 'Size (m²)', 'number'],
        ['price_from', 'Price per night from', 'number'],
        ['price_note', 'Price note', 'text'],
        ['amenities', 'Amenities', 'radky'],
        ['description', 'Description', 'html'],
        ['booking_link', 'Booking link', 'link'],
    ],
    'schema' => null,
    'claude' => 'One item per room type, apartment or cottage – the name as guests know it ("Double room with a balcony"). Guests: the most it takes; beds e.g. '
        . '"1 double + 1 sofa bed"; size in m²; price per night from as a number with the note for what it includes ("breakfast included", "min. 2 nights"). '
        . 'Amenities: one per line (Wi-Fi, parking, kitchenette…), the same wording across rooms. Booking link: the booking engine or the contact page – '
        . 'Kaleta takes no payments and has no room availability. Never invent prices, ratings or amenities – ask the owner. A Collection list on the rooms '
        . 'page (sorted by order) shows cards with the summary, guests and price; hide a room that is not let any more – its page then leads to the list.',
    'list' => ['sort' => 'order'],
    'card' => ['summary', 'guests', 'price_from', 'price_note'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'popisek', 'key');

        return [
            $n('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>{{summary}}</strong></p>']),
            $n('text', ['html' => '<p>' . e($label['guests']) . ': {{guests}} · ' . e($label['beds']) . ': {{beds}} · ' . e($label['size']) . ': {{size}}</p>'
                . '<p><strong>' . e($label['price_from']) . ':</strong> {{price_from}} {{price_note}}</p>']),
            ['tag' => 'h2'] + $n('heading', ['text' => $label['amenities']]),
            $n('text', ['html' => '<p>{{amenities}}</p>']),
            $n('text', ['html' => '{{description}}']),
            $n('button', ['text' => t('Book now'), 'link' => '{{booking_link}}']),
        ];
    },
];
