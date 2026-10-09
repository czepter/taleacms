<?php

// Official notice board (2.11, Core\Notices): a notice is posted on a date and taken down on a date; while posted it is on
// the board, afterwards in the permanent archive – never deleted – and every change is in the append-only log.
return [
    'name' => 'Official notice board',
    'button' => 'New notice board',
    'description' => 'Posting and takedown dates, reference number, issuer, category, document and summary; the board shows what is posted now, the archive what was taken down. Notices are never deleted and every change is logged.',
    'order' => 100,
    'detail' => true,
    'redirect_hidden' => false, // a notice is never deleted or hidden once posted – its address stays
    'fields' => [
        ['posted', 'Posted on', 'datum'],
        ['taken_down', 'Taken down on', 'datum'],
        ['reference', 'Reference number', 'text'],
        ['issuer', 'Issuer', 'text'],
        ['category', 'Category', 'text'],
        ['document', 'Document', 'soubor'],
        ['summary', 'Summary', 'radky'],
    ],
    'schema' => null,
    'claude' => 'One item per notice, with the posting and takedown dates (YYYY-MM-DD). Two hidden pages come with it: /<address> is the board (current notices, newest '
        . 'posting first, filter buttons by category) and /<address>-archive lists the notices taken down – publish both with update_page. {{notice_status}} on the item page says '
        . '"Posted from … to …", "Taken down on … – archived" or "To be posted on …". A notice is never deleted (delete_collection_item refuses) and cannot be hidden once '
        . 'its posting date has come – change the takedown date instead; the collection cannot be deleted while it has notices. Every create and change is in the '
        . 'append-only log (list_notice_log), and the hourly job records the day each notice was posted and taken down.',
    // the board: what is posted now, the newest posting first, filter buttons by category
    'list' => ['obdobi' => 'probihajici', 'obdobi_od' => 'posted', 'obdobi_do' => 'taken_down', 'razeni' => 'pole_sestupne', 'razeni_pole' => 'posted', 'filtr_pole' => 'category', 'filtry' => true],
    'card' => ['posted', 'reference', 'summary'],
    // the archive: a second hidden page at /<address>-archive with the notices taken down
    'extra_pages' => [
        ['suffix' => 'archive', 'name' => '%s – archive', 'list' => ['obdobi' => 'minule', 'obdobi_od' => 'posted', 'obdobi_do' => 'taken_down', 'razeni' => 'pole_sestupne', 'razeni_pole' => 'posted', 'filtr_pole' => 'category', 'filtry' => true]],
    ],
    // the item page: the name, the status line, the reference details, the summary and the document
    'template' => function (array $fields): array {
        $n = Kaleta\Builder\Build::fresh(...);
        $label = array_column($fields, 'popisek', 'klic');
        $line = fn (string $key): string => isset($label[$key]) ? '<p><strong>' . e($label[$key]) . ':</strong> {{' . $key . '}}</p>' : '';

        return [
            ['znacka' => 'h1'] + $n('nadpis', ['text' => '{{name}}']),
            $n('text', ['html' => '<p><strong>{{notice_status}}</strong></p>']),
            $n('text', ['html' => $line('reference') . $line('issuer') . $line('category')]),
            $n('text', ['html' => '{{summary}}']),
            $n('tlacitko', ['text' => ($label['document'] ?? 'Document') . ' ({{document_name}})', 'odkaz' => '{{document}}', 'variant' => 'obrys']),
        ];
    },
];
