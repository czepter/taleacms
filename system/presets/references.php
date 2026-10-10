<?php

// References and case studies (2.11): the client, a quote, the result and a link, each with its own page; a reference links to
// the service it was for when the site has a Services collection.
return [
    'name' => 'References',
    'button' => 'New references (case studies)',
    'description' => 'Case studies with the client, an image, a quote, the result, a link and the year, each with its own page; a reference can point to the service it was for.',
    'order' => 30,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['client', 'Client', 'text'],
        ['image', 'Image', 'image'],
        ['quote', 'Quote', 'lines'],
        ['result', 'Result', 'text'],
        ['link', 'Link', 'link'],
        ['service', 'Service', 'item', ['preset' => 'services']],
        ['year', 'Year', 'number'],
    ],
    'schema' => null,
    'claude' => 'One item per project or client – the name is the project, the client field the company (never invent clients or quotes; ask for real ones). '
        . 'A Collection list of it on the references page (sorted by order, or by the year field descending with sort = by field – descending); the card shows the client and the quote. '
        . 'The item template shows the image, the client, the quote, the result in one sentence, the year and a button with the link. '
        . 'When the site has a Services collection the service field links a reference to a service; a Collection list filtered by service with the value {{seo}} on a service page lists its references.',
    'list' => [],
    'card' => ['client', 'quote'],
    'template' => function (array $fields): array {
        $n = \Talea\Builder\Build::fresh(...);
        $label = array_column($fields, 'label', 'key');
        $children = [
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>' . e($label['client']) . ':</strong> {{client}}' . (isset($label['year']) ? ' ({{year}})' : '') . '</p>']),
            $n('image', ['src' => '{{image}}', 'alt' => '{{name}}']),
            $n('text', ['html' => '<blockquote><p>{{quote}}</p></blockquote>']),
            $n('text', ['html' => '<p><strong>' . e($label['result']) . ':</strong> {{result}}</p>']),
        ];
        if (isset($label['service'])) {
            $children[] = $n('text', ['html' => '<p>' . e($label['service']) . ': <a href="{{service_url}}">{{service}}</a></p>']);
        }
        $children[] = $n('button', ['text' => $label['link'], 'link' => '{{link}}', 'variant' => 'outline', 'new_window' => true]);

        return $children;
    },
];
