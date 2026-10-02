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
        ['online', 'Online link', 'odkaz'],
        ['image', 'Image', 'obrazek'],
        ['summary', 'Summary', 'radky'],
        ['description', 'Description', 'html'],
        ['category', 'Category', 'text'],
        ['price', 'Price', 'cislo'],
        ['capacity', 'Capacity', 'cislo'],
        ['registration_until', 'Registration until', 'termin'],
        ['repeat', 'Repeats', 'volba', ['options' => array_keys(Kaleta\Core\Calendar::REPEATS)]],
        ['repeat_until', 'Repeats until', 'datum'],
    ],
    'schema' => ['typ' => 'Event', 'pole' => ['startDate' => 'start', 'endDate' => 'end', 'location' => 'venue', 'address' => 'address', 'online' => 'online', 'price' => 'price']],
    'calendar' => ['start' => 'start', 'end' => 'end', 'place' => 'venue', 'address' => 'address', 'summary' => 'summary', 'online' => 'online',
        'repeat' => 'repeat', 'repeat_until' => 'repeat_until', 'capacity' => 'capacity', 'registration_until' => 'registration_until'],
    'list' => ['razeni' => 'pole', 'razeni_pole' => 'start', 'obdobi' => 'nadchazejici', 'obdobi_od' => 'start', 'obdobi_do' => 'end', 'filtr_pole' => 'category', 'filtry' => true],
    'card' => ['when', 'where', 'summary'],
    // past events stay findable: a second hidden page lists them, newest first
    'extra_pages' => [
        ['suffix' => 'archive', 'name' => '%s – archive', 'list' => ['razeni' => 'pole_sestupne', 'razeni_pole' => 'start', 'obdobi' => 'minule', 'obdobi_od' => 'start', 'obdobi_do' => 'end', 'strankovani' => true]],
    ],
    'template' => fn (array $fields): array => [
        Build::fresh('obrazek', ['src' => '{{image}}', 'alt' => '{{nazev}}']),
        ['znacka' => 'h1'] + Build::fresh('nadpis', ['text' => '{{nazev}}']),
        Build::fresh('text', ['html' => '<p><strong>{{when}}</strong></p><p>{{where}}</p><p>{{event_status}}</p><p>{{summary}}</p><p>{{description}}</p>']),
        Build::fresh('tlacitko', ['text' => t('Add to calendar'), 'odkaz' => '{{ical}}', 'varianta' => 'obrys']),
        ['znacka' => 'h2'] + Build::fresh('nadpis', ['text' => t('Registration')]),
        Build::fresh('formular', ['nazev' => t('Registration'), 'tlacitko' => t('Register'),
            'dekujeme' => t('Thank you – you are registered. We will send you the details before the event.'),
            'pole' => [
                ['popisek' => t('Jméno'), 'typ' => 'text', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true, 'moznosti' => ''],
                ['popisek' => t('Phone'), 'typ' => 'tel', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('Note'), 'typ' => 'textarea', 'povinne' => false, 'moznosti' => ''],
                ['popisek' => t('I agree to the processing of my personal data for the purpose of handling this enquiry.'), 'typ' => 'souhlas', 'povinne' => true, 'moznosti' => ''],
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
