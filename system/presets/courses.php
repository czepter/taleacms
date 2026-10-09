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
        ['price', 'Price', 'number'],
        ['capacity', 'Capacity', 'number'],
        ['description', 'Description', 'html'],
        ['image', 'Image', 'image'],
    ],
    'schema' => ['type' => 'Event', 'pole' => ['startDate' => 'start', 'endDate' => 'end', 'location' => 'place', 'price' => 'price']],
    'claude' => 'One item per date of a course – a course held again is a new item with a new start. The start and end are a date and time ("2026-11-02 09:00") or a whole day ("2026-11-02"). '
        . 'The Collection list on the courses page shows only the upcoming ones (by date = upcoming, start field start, end field end) sorted by the start field ascending, so the nearest comes first and a past course drops out by itself; '
        . 'the card shows the start and the place. The item template shows the image, the start and end, the place, the price, the capacity and the description. '
        . 'The structured data is Event with the dates, the place and the price (set the currency with update_collection structured_data). '
        . 'Like events (Core\\Calendar): {{when}}, {{where}}, {{event_status}}, {{places_left}} and {{ical}} on the item page, /<collection>.ics for all courses, and the registration form closes when Capacity is taken or the course has ended.',
    'calendar' => ['start' => 'start', 'end' => 'end', 'place' => 'place', 'capacity' => 'capacity'],
    'list' => ['period' => 'upcoming', 'period_start_field' => 'start', 'period_end_field' => 'end', 'sort' => 'field', 'sort_field' => 'start'],
    'card' => ['start', 'place'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'popisek', 'key');

        return [
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>{{when}}</strong></p><p>{{where}}</p><p>{{event_status}}</p>']),
            $n('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
            $n('text', ['html' => '<p><strong>' . e($label['price']) . ':</strong> {{price}} · <strong>' . e($label['capacity']) . ':</strong> {{capacity}}</p>']),
            $n('text', ['html' => '{{description}}']),
            $n('button', ['text' => t('Add to calendar'), 'link' => '{{ical}}', 'variant' => 'outline']),
            ['tag' => 'h2'] + $n('heading', ['text' => t('Registration')]),
            $n('form', ['name' => t('Registration'), 'button_text' => t('Register'),
                'thank_you' => t('Thank you – you are registered. We will send you the details before the event.'),
                'fields' => [
                    ['label' => t('Name'), 'type' => 'text', 'required' => true, 'options' => ''],
                    ['label' => t('Email'), 'type' => 'email', 'required' => true, 'options' => ''],
                    ['label' => t('Phone'), 'type' => 'tel', 'required' => false, 'options' => ''],
                    ['label' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'type' => 'checkbox', 'required' => true, 'options' => ''],
                ]]),
        ];
    },
];
