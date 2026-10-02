<?php

// Team (2.10): a page for each person; the page of someone who left leads to the team page. E-mail signatures come from it.
return [
    'name' => 'Team',
    'button' => 'New team (people)',
    'description' => 'Photo, role, languages, phone, e-mail, absence and a page for each person; the page of someone who left leads to the team page.',
    'order' => 10,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['photo', 'Photo', 'obrazek'],
        ['role', 'Role', 'text'],
        ['languages', 'Languages', 'text'],
        ['phone', 'Phone', 'text'],
        ['email', 'E-mail', 'text'],
        ['on_leave', 'On leave', 'text'],
        ['about', 'About', 'html'],
        ['branch', 'Branch', 'polozka', ['preset' => 'branches']],
    ],
    'schema' => ['typ' => 'Person', 'pole' => ['jobTitle' => 'role', 'email' => 'email', 'telephone' => 'phone']],
    'claude' => 'One item per person. A Collection list of it on the team page (sorted by order); the item template shows the photo, role, contacts and about. '
        . 'Hide or delete a person who left – their page then leads to the team page. get_email_signature gives each person an e-mail signature. '
        . 'The branch field links a person to a branch when the site has a Branches collection.',
];
