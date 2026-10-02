<?php

// Courses and training (2.11): a start and an end, the place, the price and the capacity, each with its own page; the list
// shows only the courses still to come, the nearest first.
return [
    'name' => 'Courses and training',
    'button' => 'New courses and training',
    'description' => 'A page for each course with its start and end, the place, the price, the capacity and a description; the list shows only the courses still to come, the nearest first.',
    'order' => 80,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['start', 'Start', 'termin'],
        ['end', 'End', 'termin'],
        ['place', 'Place', 'text'],
        ['price', 'Price', 'cislo'],
        ['capacity', 'Capacity', 'cislo'],
        ['description', 'Description', 'html'],
        ['image', 'Image', 'obrazek'],
    ],
    'schema' => ['typ' => 'Event', 'pole' => ['startDate' => 'start', 'endDate' => 'end', 'location' => 'place', 'price' => 'price']],
    'claude' => 'One item per date of a course – a course held again is a new item with a new start. The start and end are a date and time ("2026-11-02 09:00") or a whole day ("2026-11-02"). '
        . 'The Collection list on the courses page shows only the upcoming ones (by date = upcoming, start field start, end field end) sorted by the start field ascending, so the nearest comes first and a past course drops out by itself; '
        . 'the card shows the start and the place. The item template shows the image, the start and end, the place, the price, the capacity and the description. '
        . 'The structured data is Event with the dates, the place and the price (set the currency with update_collection structured_data).',
    'list' => ['obdobi' => 'nadchazejici', 'obdobi_od' => 'start', 'obdobi_do' => 'end', 'razeni' => 'pole', 'razeni_pole' => 'start'],
    'card' => ['start', 'place'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'popisek', 'klic');

        return [
            ['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}']),
            $n('text', ['html' => '<p><strong>{{start}}</strong> – {{end}}<br>' . e($label['place']) . ': {{place}}</p>']),
            $n('obrazek', ['src' => '{{image}}', 'alt' => '{{nazev}}']),
            $n('text', ['html' => '<p><strong>' . e($label['price']) . ':</strong> {{price}} · <strong>' . e($label['capacity']) . ':</strong> {{capacity}}</p>']),
            $n('text', ['html' => '{{description}}']),
        ];
    },
];
