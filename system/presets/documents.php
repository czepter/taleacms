<?php

// Document library (2.11, Core\Documents): price lists, terms, manuals and forms with a stable download address, versions
// kept for good, download counts and an expiry that hides a document by itself.
return [
    'name' => 'Documents',
    'button' => 'New document library',
    'description' => 'Price lists, terms, manuals and forms: a file with its version, summary and date of issue, a stable download address that survives a new edition, download counts and an expiry date; the previous versions stay on the document’s page.',
    'order' => 90,
    'detail' => true,
    'redirect_hidden' => false,
    'fields' => [
        ['file', 'File', 'file'],
        ['category', 'Category', 'text'],
        ['version', 'Version', 'text'],
        ['summary', 'Summary', 'lines'],
        ['issued', 'Issued', 'date'],
    ],
    'schema' => null,
    'claude' => 'One item per document; the file comes from Media (upload_file), version is free text such as "2.1" or "2026/03", category groups the list (filter buttons). '
        . 'Link to a document with {{latest}} or /<collection>/<item>/latest – the address stays the same when a new edition replaces the file; the previous file and version are kept and {{versions}} lists them on the item page. '
        . 'list_collection_items returns the downloads of each document (last 30 days and total). valid_until hides an expired document by itself and site_audit (kind document) warns 30 days ahead; review_by asks for a check. '
        . 'To hand a file out only for an e-mail address, give a Form element send_file – the visitor gets a signed link that works for 7 days.',
    'list' => ['sort' => 'name', 'filter_field' => 'category', 'filters' => true],
    'card' => ['summary', 'version'],
    'template' => function (array $fields): array {
        $n = \Talea\Builder\Build::fresh(...);

        return [
            ['tag' => 'h1'] + $n('heading', ['text' => '{{name}}']),
            $n('text', ['html' => '<p>{{summary}}</p>']),
            $n('text', ['html' => '<p><strong>' . e(t('Version')) . ':</strong> {{version}} · <strong>' . e(t('Issued')) . ':</strong> {{issued}}</p>']),
            $n('button', ['text' => t('Download') . ' ({{file_name}})', 'link' => '{{latest}}']),
            $n('text', ['html' => '{{versions}}']),
        ];
    },
];
