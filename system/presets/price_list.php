<?php

// Price list (2.11): rows with a price, a unit and a note, grouped by category with filter buttons; no item pages.
return [
    'name' => 'Price list',
    'button' => 'New price list',
    'description' => 'Rows with a price, a unit and a note, grouped by category with filter buttons for visitors; the rows have no pages of their own.',
    'order' => 40,
    'detail' => false,
    'redirect_hidden' => false,
    'fields' => [
        ['category', 'Category', 'text'],
        ['price', 'Price', 'number'],
        ['unit', 'Unit', 'text'],
        ['note', 'Note', 'text'],
    ],
    'schema' => null,
    'claude' => 'One item per row of the price list – the name is what is priced, the price a number, the unit e.g. "per hour" or "per m²", the note a short condition. '
        . 'Fill the category the same way for rows that belong together (e.g. "Cleaning", "Painting") – the Collection list on the price list page sorts by order in the administration '
        . 'and shows filter buttons by the category field, so the same list serves one category at a time. The rows have no pages; the card shows the price, the unit and the note.',
    'list' => ['sort' => 'poradi', 'filter_field' => 'category', 'filters' => true],
    'card' => ['price', 'unit', 'note'],
];
