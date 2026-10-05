<?php

// Pricing plans (3.3): the plans of a subscription or a package offer side by side – the price and its period, who the
// plan is for, what it includes and a button; one plan can carry a badge ("Most popular"). The plans have no pages.
return [
    'name' => 'Pricing plans',
    'button' => 'New pricing plans',
    'description' => 'Plans or packages side by side with the price and its period, who each is for, what it includes and a button; one can be marked as recommended. The plans have no pages of their own.',
    'order' => 45,
    'detail' => false,
    'redirect_hidden' => false,
    'fields' => [
        ['badge', 'Badge', 'text'],
        ['price', 'Price', 'cislo'],
        ['price_period', 'Billing period', 'text'],
        ['price_note', 'Price note', 'text'],
        ['summary', 'Who it is for', 'radky'],
        ['features', 'What is included', 'radky'],
        ['link', 'Button link', 'odkaz'],
    ],
    'schema' => null,
    'claude' => 'One item per plan, in the order the plans should appear (cheapest first is usual) – the name is the plan ("Starter", "Team"), the price a number '
        . '(0 for a free plan), the billing period e.g. "per month" or "per user / month", the price note a condition ("billed yearly", "excl. VAT"). '
        . 'What is included: one feature per line, the same wording across plans so they compare. Badge only on the plan to recommend ("Most popular") – '
        . 'leave it empty on the others. Button link: the sign-up, trial or contact address. Never invent prices, limits or discounts – ask the owner; '
        . 'when the price depends on the order, put 0 or leave it empty and say "on request" in the price note. The plans have no pages; the cards show everything.',
    'list' => ['razeni' => 'poradi'],
    'card' => ['badge', 'price', 'price_period', 'price_note', 'summary', 'features'],
    // the button of each plan leads to its link (sign-up, trial, contact); a plan without a link shows none (Button on a card)
    'card_extra' => fn (): array => [\Kaleta\Builder\Build::fresh('tlacitko', ['text' => t('Choose this plan'), 'odkaz' => '{{link}}'])],
];
