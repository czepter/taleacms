<?php

// Machines and equipment (2.11): for manufacturers and rental – a photo, the model, a parameters table, the year, a datasheet
// and the availability, each with its own page; the page of a machine that is gone leads to the list.
return [
    'name' => 'Machines and equipment',
    'button' => 'New machines and equipment',
    'description' => 'A page for each machine with a photo, the model, a table of parameters, the year, a datasheet to download and its availability.',
    'order' => 70,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['photo', 'Photo', 'image'],
        ['model', 'Model', 'text'],
        ['parameters', 'Parameters', 'html'],
        ['year', 'Year', 'number'],
        ['datasheet', 'Datasheet', 'file'],
        ['availability', 'Availability', 'text'],
    ],
    'schema' => ['type' => 'Product', 'pole' => ['sku' => 'model']],
    'claude' => 'One item per machine – the name is what people call it, the model its type designation, the parameters a table (rows of parameter and value), '
        . 'the datasheet a PDF from Media (upload_file), the availability a short text ("in stock", "rented until 12 May", "sold"). '
        . 'A Collection list of it on the machines page (sorted by order; filter buttons by the availability field when the site rents); the card shows the model and the availability. '
        . 'The item template shows the photo, the model, the availability, the parameters table, the year and a button to the datasheet. '
        . 'Hide a machine that is gone – its page then leads to the list. The structured data is Product with the model as the SKU.',
    'list' => [],
    'card' => ['model', 'availability'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'popisek', 'key');

        return [
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>' . e($label['model']) . ':</strong> {{model}} · <strong>' . e($label['availability']) . ':</strong> {{availability}}</p>']),
            $n('image', ['src' => '{{photo}}', 'alt' => '{{name}}']),
            $n('text', ['html' => '{{parameters}}']),
            $n('text', ['html' => '<p><strong>' . e($label['year']) . ':</strong> {{year}}</p>']),
            $n('button', ['text' => $label['datasheet'] . ' ({{datasheet_name}})', 'link' => '{{datasheet}}', 'variant' => 'outline']),
        ];
    },
];
