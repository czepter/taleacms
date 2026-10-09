<?php

// Questions and answers (2.11): the question is the name, the answer formatted text, grouped by category; no item pages.
return [
    'name' => 'Questions and answers',
    'button' => 'New questions and answers',
    'description' => 'Questions with a formatted answer, grouped by category with filter buttons; the answers are kept in one place for the FAQ page and the pages that need them.',
    'order' => 50,
    'detail' => false,
    'redirect_hidden' => false,
    'fields' => [
        ['answer', 'Answer', 'html'],
        ['category', 'Category', 'text'],
    ],
    'schema' => ['type' => 'FAQPage', 'pole' => ['answer' => 'answer']],
    'claude' => 'One item per question – the name is the question, the answer formatted text (a few sentences, a list when there are steps). '
        . 'A Collection list of it on the FAQ page or under the services (sorted by order, filter buttons by the category field when there are several topics); the card shows the question as the heading and the answer. '
        . 'The questions have no pages of their own. The FAQPage structured data is written only on item pages – switch them on with update_collection item_pages when each question should have its own address; '
        . 'for structured data on an ordinary page use the Questions and answers element of the builder.',
    'list' => ['sort' => 'poradi', 'filter_field' => 'category', 'filters' => true],
    'card' => ['answer'],
];
