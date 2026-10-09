<?php

// Menu (3.3): dishes and drinks of a restaurant, café or bar with a price, the portion, allergens and diet labels, grouped
// by section with filter buttons; no item pages.
return [
    'name' => 'Food and drink menu',
    'button' => 'New food and drink menu',
    'description' => 'Dishes and drinks with a description, a price, the portion, allergens and diet labels, grouped by section (starters, mains, drinks) with filter buttons; the dishes have no pages of their own.',
    'order' => 42,
    'detail' => false,
    'redirect_hidden' => false,
    'fields' => [
        ['section', 'Menu section', 'text'],
        ['description', 'Description', 'text'],
        ['price', 'Price', 'cislo'],
        ['portion', 'Portion', 'text'],
        ['diet', 'Diet labels', 'text'],
        ['allergens', 'Allergens', 'text'],
        ['image', 'Image', 'image'],
    ],
    'schema' => null,
    'claude' => 'One item per dish or drink – the name is the dish, the description what is in it, the price a number, the portion e.g. "250 g" or "0.5 l". '
        . 'Fill the section the same way for dishes that belong together ("Starters", "Mains", "Desserts", "Drinks") – the menu page sorts by order in the '
        . 'administration and shows filter buttons by section. Diet: short labels such as "vegetarian", "vegan", "gluten-free"; allergens: as the owner '
        . 'states them (the numbers or names the law of the country uses). Never guess allergens, diets or prices – ask the owner, and keep the menu current: '
        . 'hide a dish that is off the menu instead of deleting it. A daily menu is the same collection with a section such as "Today".',
    'list' => ['razeni' => 'poradi', 'filtr_pole' => 'section', 'filtry' => true],
    'card' => ['description', 'price', 'portion', 'diet', 'allergens'],
];
