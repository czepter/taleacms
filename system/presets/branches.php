<?php

// Branches (2.11): a page for each branch or store with LocalBusiness structured data; the Store locator element lists them
// with a search box, "Nearest to me" and a map. Created before the team, a team then gets a field linking a person to a branch.
return [
    'name' => 'Branches',
    'button' => 'New branches (store locator)',
    'description' => 'Address, location, phone, e-mail, opening hours, photo and a page for each branch or store; the Store locator element lists them with search, the nearest one and a map.',
    'order' => 15,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['address', 'Address', 'text'],
        ['location', 'Location', 'poloha'],
        ['phone', 'Phone', 'text'],
        ['email', 'E-mail', 'text'],
        ['hours', 'Opening hours', 'radky'],
        ['photo', 'Photo', 'obrazek'],
        ['note', 'Note', 'text'],
    ],
    'schema' => ['typ' => 'LocalBusiness', 'pole' => ['address' => 'address', 'telephone' => 'phone', 'email' => 'email', 'geo' => 'location', 'openingHours' => 'hours']],
    'claude' => 'One item per branch or store; location is "latitude, longitude", opening hours one rule per line like Business details (Mo-Fr 9-17). '
        . 'Put the store_locator element on a page (it finds the first Branches collection by itself): a list with tel: links and directions, a search box, '
        . '"Nearest to me" and a map that loads after a click. Item pages carry LocalBusiness structured data with the geo and the hours. '
        . 'Create the branches before the team – a team created afterwards gets a field linking a person to a branch.',
    'card' => ['address', 'phone'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $labels = array_column($fields, 'popisek', 'klic');
        $has = fn (string $key): bool => isset($labels[$key]);
        $line = fn (string $key, string $inner): string => $has($key) ? '<p><strong>' . e($labels[$key]) . ':</strong> ' . $inner . '</p>' : '';
        $children = [['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}'])];
        if ($has('photo')) {
            $children[] = $n('obrazek', ['src' => '{{photo}}', 'alt' => '{{nazev}}']);
        }
        $children[] = $n('text', ['html' => $line('address', '{{address}}') . $line('phone', '{{phone}}') . $line('email', '{{email}}')
            . ($has('hours') ? '<p><strong>' . e($labels['hours']) . '</strong></p><p>{{hours}}</p>' : '') . ($has('note') ? '<p>{{note}}</p>' : '')]);
        if ($has('address')) {
            $children[] = $n('mapa', ['adresa' => '{{address}}']); // the Map element loads only after a click
        }

        return $children;
    },
];
