<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Structured data of collection item pages (1.9): a collection says which schema.org type its items are – a service,
 * a person, a product, an event, a question with an answer or a local business (a branch, 2.11) – and which of its fields
 * fill which properties. Item pages then carry that JSON-LD next to the site and company data (Front\Seo). Nothing is
 * guessed: a property without a field is left out, and an offer needs both a price and a currency.
 *
 * Stored in ka_kolekce.schema_org as {"typ": "Service", "pole": {"price": "cena", …}, "mena": "EUR"}.
 */
final class CollectionSchema
{
    /** type => [label, property => label] – the name, the address, the description and the image come from the item itself */
    public const array TYPES = [
        'Service' => ['Service', ['serviceType' => 'Kind of service', 'areaServed' => 'Area served', 'price' => 'Price']],
        'Person' => ['Person', ['jobTitle' => 'Job title', 'email' => 'E-mail', 'telephone' => 'Phone', 'sameAs' => 'Profile link']],
        'Product' => ['Product', ['brand' => 'Brand', 'sku' => 'Product code (SKU)', 'price' => 'Price']],
        'Event' => ['Event', ['startDate' => 'Start', 'endDate' => 'End', 'location' => 'Place', 'price' => 'Price']],
        'FAQPage' => ['Question and answer', ['answer' => 'Answer']],
        // a branch or store: the geo comes from a location field, the hours from a text written like the company hours
        'LocalBusiness' => ['Local business (branch, store)', ['address' => 'Address', 'telephone' => 'Phone', 'email' => 'E-mail', 'geo' => 'Location (latitude, longitude)', 'openingHours' => 'Opening hours']],
    ];

    /**
     * The setting from a form or from Claude, checked against the collection's fields; null = no structured data.
     *
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return array{typ: string, pole: array<string, string>, mena: string}|null
     */
    public static function sanitize(mixed $input, array $fields): ?array
    {
        $type = is_array($input) && is_string($input['typ'] ?? null) ? $input['typ'] : '';
        if (!isset(self::TYPES[$type])) {
            return null;
        }
        $keys = array_column($fields, 'klic');
        $map = [];
        foreach (self::TYPES[$type][1] as $property => $_) {
            $key = $input['pole'][$property] ?? '';
            if (is_string($key) && in_array($key, $keys, true)) {
                $map[$property] = $key;
            }
        }
        $currency = is_string($input['mena'] ?? null) ? strtoupper(trim($input['mena'])) : '';

        return ['typ' => $type, 'pole' => $map, 'mena' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : ''];
    }

    /** @return array{typ: string, pole: array<string, string>, mena: string}|null the stored setting of a collection row */
    public static function of(array $collection): ?array
    {
        $stored = json_decode((string) ($collection['schema_org'] ?? ''), true);

        return is_array($stored) ? self::sanitize($stored, (array) ($collection['pole'] ?? [])) : null;
    }

    /**
     * JSON-LD node of one item page, or null when the collection has no type.
     *
     * @param array<string, mixed> $collection with decoded "pole"
     * @param array<string, mixed> $item with decoded "data"
     * @param string $issuerId @id of the company node (the provider of a service, the organiser of an event)
     */
    public static function forItem(array $collection, array $item, string $url, string $description, string $image, string $issuerId): ?array
    {
        $schema = self::of($collection);
        if ($schema === null) {
            return null;
        }
        $value = function (string $property) use ($schema, $item): string {
            $key = $schema['pole'][$property] ?? '';
            $v = $key === '' ? '' : (string) ($item['data'][$key] ?? '');

            return trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5));
        };
        $name = (string) $item['nazev'];
        if ($schema['typ'] === 'FAQPage') {
            $answer = $value('answer');

            return $answer === '' ? null : ['@type' => 'FAQPage', 'url' => $url, 'mainEntity' => [
                ['@type' => 'Question', 'name' => $name, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]],
            ]];
        }
        if ($schema['typ'] === 'Event' && self::date($value('startDate')) === '') {
            return null; // an event without a start is not an event for search engines
        }
        $price = str_replace([' ', ','], ['', '.'], $value('price'));
        $offer = is_numeric($price) && $schema['mena'] !== '' ? ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => $schema['mena'], 'url' => $url] : null;
        $node = ['@type' => $schema['typ'], 'name' => $name, 'url' => $url, 'description' => $description, 'image' => $image];

        return array_filter($node + match ($schema['typ']) {
            'Service' => ['serviceType' => $value('serviceType'), 'areaServed' => $value('areaServed'), 'provider' => ['@id' => $issuerId], 'offers' => $offer],
            'Person' => ['jobTitle' => $value('jobTitle'), 'email' => $value('email'), 'telephone' => $value('telephone'),
                'sameAs' => preg_match('#^https?://#', $value('sameAs')) ? $value('sameAs') : '', 'worksFor' => ['@id' => $issuerId]],
            'Product' => ['brand' => $value('brand') !== '' ? ['@type' => 'Brand', 'name' => $value('brand')] : null, 'sku' => $value('sku'), 'offers' => $offer],
            'Event' => ['startDate' => self::date($value('startDate')), 'endDate' => self::date($value('endDate')),
                'location' => $value('location') !== '' ? ['@type' => 'Place', 'name' => $value('location'), 'address' => $value('location')] : null,
                'organizer' => ['@id' => $issuerId], 'offers' => $offer],
            // the branch belongs to the company; hours that do not parse are left out rather than guessed
            'LocalBusiness' => ['address' => $value('address'), 'telephone' => $value('telephone'), 'email' => $value('email'), 'geo' => self::geo($value('geo')),
                'openingHoursSpecification' => \Kaleta\Core\Hours::specification($value('openingHours')) ?: null, 'parentOrganization' => ['@id' => $issuerId]],
        }, fn (mixed $v): bool => $v !== '' && $v !== null);
    }

    /**
     * GeoCoordinates from a location field ("latitude, longitude", Collections::cleanLocation); null when it is empty or not a location.
     *
     * @return array{'@type': string, latitude: float, longitude: float}|null
     */
    public static function geo(string $location): ?array
    {
        $clean = Collections::cleanLocation($location);
        if ($clean === null || $clean === '') {
            return null;
        }
        [$lat, $lng] = explode(', ', $clean);

        return ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
    }

    /** A date field (YYYY-MM-DD) or a full date and time for schema.org; anything else is left out. */
    private static function date(string $v): string
    {
        $time = preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? strtotime($v) : false;

        return $time === false ? '' : (strlen($v) === 10 ? $v : date('c', $time));
    }
}
