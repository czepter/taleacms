<?php

use Kaleta\Builder\Build;

// Events (2.11): upcoming events list themselves and archive the past, a repeating event moves to its next date on its
// own (Core\Calendar), each can be added to a calendar (iCal) and has a registration form that closes when it is full.
return [
    'name' => 'Events',
    'button' => 'New events calendar',
    'description' => 'Dates, place, repetition, registration with a capacity and iCal; past events archive themselves and a repeating event moves to its next date.',
    'order' => 50,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['start', 'Start', 'termin'],
        ['end', 'End', 'termin'],
        ['venue', 'Place', 'text'],
        ['address', 'Address', 'text'],
        ['online', 'Online link', 'link'],
        ['image', 'Image', 'image'],
        ['summary', 'Summary', 'radky'],
        ['description', 'Description', 'html'],
        ['category', 'Category', 'text'],
        ['price', 'Price', 'number'],
        ['capacity', 'Capacity', 'number'],
        ['registration_until', 'Registration until', 'termin'],
        ['repeat', 'Repeats', 'radio', ['options' => array_keys(Kaleta\Core\Calendar::REPEATS)]],
        ['repeat_until', 'Repeats until', 'datum'],
    ],
    'schema' => ['type' => 'Event', 'pole' => ['startDate' => 'start', 'endDate' => 'end', 'location' => 'venue', 'address' => 'address', 'online' => 'online', 'price' => 'price']],
    'calendar' => ['start' => 'start', 'end' => 'end', 'place' => 'venue', 'address' => 'address', 'summary' => 'summary', 'online' => 'online',
        'repeat' => 'repeat', 'repeat_until' => 'repeat_until', 'capacity' => 'capacity', 'registration_until' => 'registration_until'],
    'list' => ['sort' => 'pole', 'sort_field' => 'start', 'period' => 'upcoming', 'period_start_field' => 'start', 'period_end_field' => 'end', 'filter_field' => 'category', 'filters' => true],
    'card' => ['when', 'where', 'summary'],
    // past events stay findable: a second hidden page lists them, newest first
    'extra_pages' => [
        ['suffix' => 'archive', 'name' => '%s – archive', 'list' => ['sort' => 'field_descending', 'sort_field' => 'start', 'period' => 'past', 'period_start_field' => 'start', 'period_end_field' => 'end', 'pagination' => true]],
    ],
    'template' => fn (array $fields): array => [
        Build::fresh('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
        ['tag' => 'h1'] + Build::fresh('heading', ['text' => '{{name}}']),
        Build::fresh('text', ['html' => '<p><strong>{{when}}</strong></p><p>{{where}}</p><p>{{event_status}}</p><p>{{summary}}</p><p>{{description}}</p>']),
        Build::fresh('tlacitko', ['text' => t('Add to calendar'), 'link' => '{{ical}}', 'variant' => 'outline']),
        ['tag' => 'h2'] + Build::fresh('heading', ['text' => t('Registration')]),
        Build::fresh('form', ['nazev' => t('Registration'), 'tlacitko' => t('Register'),
            'thank_you' => t('Thank you – you are registered. We will send you the details before the event.'),
            'pole' => [
                ['popisek' => t('Jméno'), 'type' => 'text', 'required' => true, 'options' => ''],
                ['popisek' => t('Email'), 'type' => 'email', 'required' => true, 'options' => ''],
                ['popisek' => t('Phone'), 'type' => 'tel', 'required' => false, 'options' => ''],
                ['popisek' => t('Note'), 'type' => 'textarea', 'required' => false, 'options' => ''],
                ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'type' => 'souhlas', 'required' => true, 'options' => ''],
            ]]),
    ],
    'claude' => 'One item per event; a repeating event (a weekly class) is ONE item with Repeats and Repeats until – after each occurrence it moves '
        . 'to the next date by itself. Start/End as "YYYY-MM-DD HH:MM" (a whole day "YYYY-MM-DD"). The created list page shows upcoming events by '
        . 'start (Collection list period "upcoming" with start/end fields); a second hidden page /<collection>-archive lists the past ones. '
        . 'The item template has {{when}} (the date range for visitors), {{where}}, {{event_status}} (empty, or that it ended / is full), '
        . '{{places_left}} and {{ical}} (add to calendar); /<collection>.ics subscribes to all events. Registrations are enquiries from the '
        . 'item page; with Capacity the form closes when full, with Registration until when the deadline passes, and always after the event. '
        . 'Price needs the currency in the collection\'s structured data for search engines.',
];
