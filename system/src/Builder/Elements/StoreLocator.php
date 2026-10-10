<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Collections;
use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Core\Language;

/**
 * Store locator (2.11): the visible branches of a collection – by default the first one made from the Branches preset – as a
 * list with the address, a tel: link, the e-mail and a "Directions" link, usable without JavaScript. image/web.js adds a
 * search box filtering the list as you type, "Nearest to me" (navigator.geolocation only after the click, sorted by the
 * haversine distance, nothing sent anywhere) and a Leaflet map with OpenStreetMap tiles that loads only after a click, like
 * the Map element. Each branch carries data-lat/data-lng for the script; every text the script shows is rendered here with
 * t(), so the site dictionary translates it.
 */
final class StoreLocator extends Element
{
    public const string TYPE = 'store_locator';
    public const string NAME = 'Store locator';
    public const string DESCRIPTION = 'Branches or stores from a collection: a list with directions, a search box, “Nearest to me” and a map that loads after a click.';
    public const string ICON = 'map';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div', 'section'];

    /** Where the vendored Leaflet lives (image/vendor/leaflet/leaflet.js, leaflet.css, images/); the script loads it after the click. */
    public const string LEAFLET_PATH = 'image/vendor/leaflet/';

    public static function properties(): array
    {
        return [
            'collection' => ['type' => 'text', 'label' => 'Collection (empty = the first Branches collection)', 'default' => '', 'max' => 110],
            'location_field' => ['type' => 'text', 'label' => 'Location field (key)', 'default' => 'location', 'max' => 31],
            'search_box' => ['type' => 'boolean', 'label' => 'Search box', 'default' => true],
            'nearest' => ['type' => 'boolean', 'label' => '“Nearest to me” button', 'default' => true],
            'show_map' => ['type' => 'boolean', 'label' => 'Map (loads after a click)', 'default' => true],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-locator { display: grid; gap: var(--tl-space-m); }
.tl-locator [hidden] { display: none; } /* the display of the controls and the cards below would otherwise beat the hidden attribute */
.tl-locator-controls { display: flex; flex-wrap: wrap; gap: var(--tl-space-xs); align-items: center; }
.tl-locator-controls label { flex: 1 1 12rem; display: grid; gap: 0.25em; min-width: 0; }
.tl-locator-controls input { width: 100%; min-width: 0; padding: 0.55em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius-s); background: var(--tl-color-background); color: inherit; font: inherit; }
.tl-locator-controls button { padding: 0.55em 1em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius-full); background: var(--tl-color-surface); color: inherit; font: inherit; cursor: pointer; }
.tl-locator-controls button:hover { border-color: var(--tl-color-primary); }
.tl-locator-controls button[disabled] { opacity: 0.6; cursor: wait; }
.tl-locator-controls small { flex-basis: 100%; color: var(--tl-color-muted); font-size: var(--tl-step--1); }
.tl-locator-message { margin: 0; color: var(--tl-color-muted); }
.tl-locator-message:empty { display: none; }
.tl-locator-map { height: clamp(16rem, 50vh, 28rem); border-radius: var(--tl-radius-m); overflow: hidden; background: var(--tl-color-surface); }
.tl-locator-map a { color: inherit; }
.tl-locator-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: var(--tl-space-m); margin: 0; padding: 0; list-style: none; }
.tl-locator-list li { display: grid; gap: var(--tl-space-xs); align-content: start; padding: var(--tl-space-m); border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius-m); background: var(--tl-color-surface); overflow-wrap: anywhere; }
.tl-locator-list p { margin: 0; }
.tl-locator-name { font-size: var(--tl-step-1); }
.tl-locator-name a { color: inherit; }
.tl-locator-distance { color: var(--tl-color-muted); font-size: var(--tl-step--1); }
.tl-locator-distance:empty { display: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $db = $k->app->db();
        $collection = $o['collection'] !== '' ? Collections::bySlug($db, (string) $o['collection']) : self::defaultCollection($db);
        if ($collection === null) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">'
                . e(t('Create a Branches collection first (Collections → New branches), or choose a collection in the Content panel.')) . '</div>' : '';
        }
        $field = fn (string $key, array $types): ?string => array_values(array_filter($collection['fields'], fn (array $f): bool => $f['key'] === $key && in_array($f['type'], $types, true)))[0]['key'] ?? null;
        // the location field from the option, or the first location field the collection has; the contact fields by their preset keys
        $locationKey = preg_match(Collections::KEY_PATTERN, (string) $o['location_field']) ? $field((string) $o['location_field'], ['location']) : null;
        $locationKey ??= array_values(array_filter($collection['fields'], fn (array $f): bool => $f['type'] === 'location'))[0]['key'] ?? null;
        $keys = ['address' => $field('address', ['text', 'lines']), 'phone' => $field('phone', ['text']), 'email' => $field('email', ['text'])];
        [$items] = Collections::items($db, (int) $collection['collection_id'], Language::siteColumn(), 100);
        if ($items === []) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">' . e(t('The collection has no visible items yet.')) . '</div>' : '';
        }
        $rows = '';
        foreach ($items as $item) {
            $values = Collections::values($collection, $item, $k->url(...), $db);
            $rows .= self::row($values, $keys, $locationKey);
        }
        $controls = '';
        if ($o['search_box']) {
            $controls .= '<label><span>' . e(t('Search branches')) . '</span><input type="search" data-search placeholder="' . e(t('Name or address')) . '" autocomplete="off"></label>';
        }
        if ($o['nearest']) {
            $controls .= '<button type="button" data-nearest>' . e(t('Nearest to me')) . '</button>';
        }
        $map = '';
        if ($o['show_map']) {
            $mapId = 'locator-map-' . e((string) $p['id']);
            $controls .= '<button type="button" data-map aria-controls="' . $mapId . '">' . e(t('Show map')) . '</button><small>' . e(t('The map loads from OpenStreetMap after a click; “Nearest to me” asks for your location only then and sends it nowhere.')) . '</small>';
            $map = '<div class="tl-locator-map" id="' . $mapId . '" role="region" aria-label="' . e(t('Map of branches')) . '" hidden></div>';
        }
        // the controls work only with the script, so they appear once it runs; the list is the content and is always there
        $html = ($controls !== '' ? '<div class="tl-locator-controls" hidden>' . $controls . '</div>' : '')
            . '<p class="tl-locator-message" role="status" aria-live="polite" data-message></p>'
            . $map
            . '<ul class="tl-locator-list" aria-label="' . e(t('Branches')) . '">' . $rows . '</ul>'
            . '<p class="tl-locator-message" data-empty hidden>' . e(t('No branch matches your search.')) . '</p>';
        $data = ' data-locator data-leaflet="' . e($k->url(self::LEAFLET_PATH)) . '"'
            . ' data-attribution="' . e('© OpenStreetMap contributors') . '"' // plain text: image/web.js puts it in a link to the OSM copyright page
            . ' data-text-sorted="' . e(t('Sorted by distance from you.')) . '"'
            . ' data-text-declined="' . e(t('Location access was refused – the list stays in its usual order.')) . '"'
            . ' data-text-error="' . e(t('Your location could not be determined.')) . '"';

        return '<' . $p['tag'] . Text::withClass($a, 'tl-locator') . $data . '>' . $html . '</' . $p['tag'] . '>';
    }

    /** The first collection made from the Branches preset (what the element shows when no collection is chosen). */
    public static function defaultCollection(\Talea\Core\Db $db): ?array
    {
        $slug = $db->value('SELECT slug FROM {collections} WHERE preset = ? ORDER BY collection_id LIMIT 1', ['branches']);

        return is_string($slug) ? Collections::bySlug($db, $slug) : null;
    }

    /**
     * One branch: the name (linking to its page), the address, phone and e-mail as links, "Directions" to a maps search,
     * and for the script data-lat/data-lng and the text to search in.
     *
     * @param array<string, array{0: string, 1: string}> $values Collections::values
     * @param array{address: ?string, phone: ?string, email: ?string} $keys field keys of the collection (null = no such field)
     */
    private static function row(array $values, array $keys, ?string $locationKey): string
    {
        $text = fn (?string $key): string => $key === null ? '' : trim(html_entity_decode(strip_tags($values[$key][0] ?? ''), ENT_QUOTES | ENT_HTML5));
        [$name, $url] = [$values['name'][0], $values['url'][0]];
        [$address, $phone, $email] = [$text($keys['address']), $text($keys['phone']), $text($keys['email'])];
        $coordinates = $locationKey === null ? null : self::coordinates($values[$locationKey][0] ?? '');
        $html = '<strong class="tl-locator-name">' . ($url !== '' ? '<a href="' . e($url) . '">' . e($name) . '</a>' : e($name)) . '</strong>';
        if ($address !== '') {
            $html .= '<p class="tl-locator-address">' . nl2br(e($address), false) . '</p>';
        }
        $contacts = array_filter([
            self::telHref($phone) !== '' ? '<a href="' . e(self::telHref($phone)) . '">' . e($phone) . '</a>' : e($phone),
            $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : e($email),
        ]);
        if ($contacts !== []) {
            $html .= '<p class="tl-locator-contact">' . implode(' · ', $contacts) . '</p>';
        }
        $directions = self::directionsUrl($address, $coordinates);
        if ($directions !== '') {
            $html .= '<p><a href="' . e($directions) . '" target="_blank" rel="noopener">' . e(t('Directions')) . '</a></p>';
        }
        $html .= '<span class="tl-locator-distance" data-distance></span>';
        $position = $coordinates === null ? '' : ' data-lat="' . $coordinates[0] . '" data-lng="' . $coordinates[1] . '"';

        return '<li' . $position . ' data-text="' . e(mb_strtolower(trim($name . ' ' . $address))) . '">' . $html . '</li>';
    }

    /**
     * Latitude and longitude of a location field value as strings for data attributes ("49.1951", "16.6068"); null when it is
     * empty or not a location.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function coordinates(string $location): ?array
    {
        $clean = Collections::cleanLocation($location);

        return $clean === null || $clean === '' ? null : explode(', ', $clean);
    }

    /** The tel: link of a phone as people write it ("+420 123 456 789"); '' when nothing dialable is left. */
    public static function telHref(string $phone): string
    {
        $digits = (string) preg_replace('/[^+\d]/', '', $phone);
        $digits = (str_starts_with($digits, '+') ? '+' : '') . str_replace('+', '', $digits); // a plus only at the start

        return strlen(ltrim($digits, '+')) >= 3 ? 'tel:' . $digits : '';
    }

    /**
     * A maps search of the address (the same service as the Map element's link); the coordinates when there is no address;
     * '' with neither.
     *
     * @param array{0: string, 1: string}|null $coordinates
     */
    public static function directionsUrl(string $address, ?array $coordinates): string
    {
        $query = $address !== '' ? $address : ($coordinates === null ? '' : $coordinates[0] . ',' . $coordinates[1]);

        return $query === '' ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }
}
