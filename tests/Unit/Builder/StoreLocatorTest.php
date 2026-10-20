<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class StoreLocatorTest extends TestCase
{
    /** 2.11: branches (LocalBusiness) and the Store locator element */
    public function testBranches(): void
    {
        $presets = \Talea\Builder\Presets::all();
        $this->assertSame([
            [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
                ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '09:00', 'closes' => '12:00']], [], []], [
            \Talea\Core\Hours::specification("Mo-Fr 9-17\nSa 9:00-12:00"), \Talea\Core\Hours::specification("Mo-Fr 9-17\nby appointment"), \Talea\Core\Hours::specification('')], '2.11 Hours::specification – opening hours as text to OpeningHoursSpecification; nothing from a line that does not parse or from an empty text');
        $branchFields = [['key' => 'address', 'label' => 'Address', 'type' => 'text'], ['key' => 'location', 'label' => 'Location', 'type' => 'location'], ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'email', 'label' => 'E-mail', 'type' => 'text'], ['key' => 'hours', 'label' => 'Opening hours', 'type' => 'lines']];
        $branchCollection = ['slug' => 'pobocky', 'detail' => 1, 'fields' => $branchFields,
            'schema_org' => json_encode(['type' => 'LocalBusiness', 'fields' => ['address' => 'address', 'telephone' => 'phone', 'email' => 'email', 'geo' => 'location', 'openingHours' => 'hours']])];
        $branch = fn (array $data): ?array => \Talea\Builder\CollectionSchema::forItem($branchCollection, ['name' => 'Brno', 'data' => $data], 'https://example.com/pobocky/brno', 'Our Brno store', '', 'https://example.com#firma');
        $brnoNode = $branch(['address' => 'Náměstí Svobody 1, Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => 'Mo-Fr 9-17']); // check-english: allow
        $this->assertSame(['LocalBusiness', 'Náměstí Svobody 1, Brno', '+420 123 456 789', ['@type' => 'GeoCoordinates', 'latitude' => 49.1951, 'longitude' => 16.6068], 1, ['@id' => 'https://example.com#firma'], false], [ // check-english: allow
            $brnoNode['@type'], $brnoNode['address'], $brnoNode['telephone'], $brnoNode['geo'], count($brnoNode['openingHoursSpecification']), $brnoNode['parentOrganization'], isset($brnoNode['provider'])], '2.11 CollectionSchema: a branch is a LocalBusiness of the company with its address, contacts, geo and opening hours'); // check-english: allow
        $sparseNode = $branch(['address' => 'Somewhere 1', 'location' => 'in the centre', 'hours' => 'always open']);
        $this->assertSame([false, false, 'Somewhere 1'], [isset($sparseNode['geo']), isset($sparseNode['openingHoursSpecification']), $sparseNode['address']], '2.11 CollectionSchema: no geo from text that is not a location and no hours that do not parse – rather left out than guessed');
        $this->assertSame([['@type' => 'GeoCoordinates', 'latitude' => 50.0875, 'longitude' => 14.4214], null, null], [\Talea\Builder\CollectionSchema::geo('50.0875;14.4214'), \Talea\Builder\CollectionSchema::geo(''), \Talea\Builder\CollectionSchema::geo('Praha')], '2.11 CollectionSchema::geo');
        $this->assertSame(['LocalBusiness', 'location', 'hours', true, 'location'], [
            $presets['branches']['schema']['type'], $presets['branches']['schema']['fields']['geo'], $presets['branches']['schema']['fields']['openingHours'], $presets['branches']['order'] < 100,
            \Talea\Builder\Presets::field(['preset' => 'branches', 'fields' => [['key' => 'location', 'type' => 'location']]], 'branches', 'location', ['location'])], '2.11 Presets: branches – LocalBusiness mapped to the location and hours fields, created before the team');
        $branchTemplate = \Talea\Builder\Presets::itemTemplate($presets['branches'], \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $presets['branches']['fields'])));
        $branchTemplateJson = \Talea\Builder\Build::toJson($branchTemplate);
        $this->assertSame([true, true, true, true], [
            str_contains($branchTemplateJson, '{{photo}}'), str_contains($branchTemplateJson, '<p>{{hours}}</p>'), str_contains($branchTemplateJson, '"type":"map"'), str_contains($branchTemplateJson, '"address":"{{address}}"')], '2.11 Presets: the branch item template shows the photo, contacts, hours and a click-to-load map of the address');
        $this->assertSame(['tel:+420123456789', 'tel:00493012345', '', ''], array_map(\Talea\Builder\Elements\StoreLocator::telHref(...), ['+420 123 456 789', '(0049) 30 / 123-45', 'call us', '']), '2.11 StoreLocator::telHref – digits and one leading plus, nothing from text');
        $this->assertSame(// check-english: allow
            [['49.1951', '16.6068'], null, 'https://www.google.com/maps/search/?api=1&query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody%201%2C%20Brno', 'https://www.google.com/maps/search/?api=1&query=49.1951%2C16.6068', ''], [
            \Talea\Builder\Elements\StoreLocator::coordinates('49.1951, 16.6068'), \Talea\Builder\Elements\StoreLocator::coordinates('nowhere'),
            \Talea\Builder\Elements\StoreLocator::directionsUrl('Náměstí Svobody 1, Brno', ['49.1951', '16.6068']), \Talea\Builder\Elements\StoreLocator::directionsUrl('', ['49.1951', '16.6068']), \Talea\Builder\Elements\StoreLocator::directionsUrl('', null)], '2.11 StoreLocator::coordinates and directionsUrl – the address first, then the coordinates, otherwise nothing'); // check-english: allow
        $this->assertSame(['Dynamic', 'store_locator', 'location_field', true, true, true, true], [
            \Talea\Builder\Elements\StoreLocator::GROUP, 'store_locator', 'location_field',
            isset((require TALEA_SYSTEM . '/languages/cs.php')['Nearest to me']), isset((require TALEA_SYSTEM . '/languages/de.php')['Location access was refused – the list stays in its usual order.']),
            is_file(TALEA_ROOT . '/image/vendor/leaflet/leaflet.js') && is_file(TALEA_ROOT . '/image/vendor/leaflet/leaflet.css') && is_file(TALEA_ROOT . '/image/vendor/leaflet/images/marker-icon.png') && is_file(TALEA_ROOT . '/image/vendor/leaflet/LICENSE'),
            preg_match('/Leaflet 1\.9\.4/', (string) file_get_contents(TALEA_ROOT . '/image/vendor/leaflet/leaflet.js')) === 1], '2.11 Store locator: a Dynamic element with an English name, its texts for the script in the site dictionaries, Leaflet vendored');
    }
}
