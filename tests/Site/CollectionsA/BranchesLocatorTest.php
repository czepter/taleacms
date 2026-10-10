<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 branches (LocalBusiness) and the store locator (was: section 62 of tools/test.sh). */
#[Group('site')]
final class BranchesLocatorTest extends SiteTestCase
{
    use CollectionsHelpers;

    public function testBranchesPresetAndLocatorPage(): void
    {
        $text = $this->mcpText('create_collection', ['name' => 'Branches', 'preset' => 'branches']);
        $this->assertStringContainsString('how_to_use', $text);
        $this->assertSame('branches|location|location|location|hours', $this->sq("SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[1].key')), '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[1].type')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.geo')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.openingHours'))) FROM ka_collections WHERE slug = 'branches'"), 'the collection remembers its preset, has a location field and LocalBusiness data from it');
        $this->assertSame('111', $this->sq("SELECT CONCAT(build LIKE '%{{photo}}%', build LIKE '%<p>{{hours}}</p>%', build LIKE '%\"address\":\"{{address}}\"%') FROM ka_collections WHERE slug = 'branches'"), 'the item template brings the photo, the hours and a click-to-load map of the address');

        $this->mcpText('save_collection_item', ['collection' => 'branches', 'name' => 'Brno', 'slug' => 'brno', 'values' => ['address' => 'Main Street 1, 602 00 Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => "Mo-Fr 9-17\nSa 9-12"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'branches', 'name' => 'Praha', 'slug' => 'praha', 'values' => ['address' => 'Station Road 1, 110 00 Prague', 'location' => '50.0813, 14.4275', 'phone' => '+420 987 654 321', 'hours' => 'by appointment'], 'visible' => true]);
        $this->mcpText('create_page', ['title' => 'Find us', 'slug' => 'find-us', 'visible' => true]);
        $page = (int) $this->sq("SELECT page_id FROM ka_pages WHERE slug = 'find-us'");
        $saved = $this->mcpText('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'store_locator']]]]]]);
        $this->assertStringContainsString('published', $saved);
        $this->assertSame('1', $this->sq('SELECT build LIKE \'%"type":"store_locator"%\' FROM ka_pages WHERE page_id = ?', [$page]), 'Claude places the element by its English name, stored under its own type');

        $schema = $this->mcpText('builder_schema', ['elements' => ['store_locator']]);
        $this->assertStringContainsString('location_field', $schema, 'builder_schema describes the element');
        $this->assertStringContainsString('show_map', $schema, '... and its English options');

        $locator = $this->visitor()->get('/find-us');
        $b = $locator->body;
        $this->assertStringContainsString('data-locator', $b, 'the page lists the branches');
        $this->assertMatchesRegularExpression('#href="[^"]*/branches/brno"#', $b, '... with a link to the first');
        $this->assertMatchesRegularExpression('#href="[^"]*/branches/praha"#', $b, '... and the second (no JavaScript needed)');
        $this->assertStringContainsString('href="tel:+420123456789"', $b, 'phones as tel: links');
        $this->assertStringContainsString('href="tel:+420987654321"', $b);
        $this->assertStringContainsString('href="mailto:brno@example.com"', $b, 'the e-mail as mailto:');
        $this->assertStringContainsString('maps/search/?api=1&amp;query=Main%20Street', $b, 'a Directions link to a maps search of the address');
        $this->assertStringContainsString('>Directions<', $b);
        $this->assertStringContainsString('data-lat="49.1951" data-lng="16.6068"', $b, 'data-lat/data-lng for the script');
        $this->assertStringContainsString('data-lat="50.0813" data-lng="14.4275"', $b);
        $this->assertStringContainsString('data-text="brno m', $b, '... and the search text');
        $this->assertStringContainsString('data-search', $b, 'search control');
        $this->assertStringContainsString('data-nearest>Nearest to me<', $b, 'nearest control');
        $this->assertStringContainsString('data-map aria-controls="locator-map-', $b, 'map control');
        $this->assertStringContainsString('class="ka-locator-map" id="locator-map-', $b);
        $this->assertMatchesRegularExpression('#data-leaflet="[^"]*/image/vendor/leaflet/"#', $b, 'the Leaflet path');
        $this->assertStringContainsString('data-attribution="© OpenStreetMap contributors"', $b, '... and attribution');
        $this->assertStringContainsString('image/web.js', $b, 'web.js kept on the page');

        $this->assertPage('/image/vendor/leaflet/leaflet.js', 200, 'Leaflet 1.9.4', message: 'Leaflet 1.9.4 is served from the site itself');
        $this->assertPage('/image/vendor/leaflet/leaflet.css', 200, 'leaflet-marker-icon', message: 'the Leaflet stylesheet and marker are there');
        $this->assertPage('/image/vendor/leaflet/LICENSE', 200, 'BSD 2-Clause', message: 'the Leaflet licence is shipped');
    }

    public function testBranchPagesCarryLocalBusinessData(): void
    {
        $brno = $this->visitor()->get('/branches/brno');
        $node = $this->branchNode($brno);
        $this->assertMatchesRegularExpression('/' . implode('.*?', array_map(static fn (string $p): string => preg_quote($p, '/'), [
            '"geo":{"@type":"GeoCoordinates","latitude":49.1951,"longitude":16.6068}',
            '"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"},{"@type":"OpeningHoursSpecification","dayOfWeek":["Saturday"],"opens":"09:00","closes":"12:00"}]',
            '"parentOrganization":{"@id":',
        ])) . '/s', $node, 'the branch page carries LocalBusiness data with the geo, the opening hours and the company as parent');
        $this->assertMatchesRegularExpression('/"telephone":"\+420 123 456 789".*"email":"brno@example.com"/s', $node, 'address, phone and e-mail in the structured data');
        $this->assertMatchesRegularExpression('#data-insert="https://maps.google.com/maps\?q=Main%20Street#', $brno->body, 'the branch page shows the map of its own address loading after a click');
        $this->assertStringContainsString('+420 123 456 789', $brno->body, '... and the contacts');

        $praha = $this->branchNode($this->visitor()->get('/branches/praha'));
        $this->assertStringNotContainsString('openingHoursSpecification', $praha, 'hours that do not parse must be left out');
        $this->assertStringContainsString('"latitude":50.0813', $praha, '... the geo stays');
    }

    public function testSchemaFormAndATeamLinkedToTheBranches(): void
    {
        $form = $this->assertPage('/admin.php?module=collections&action=edit&id=' . $this->sq("SELECT collection_id FROM ka_collections WHERE slug = 'branches'"), 200, 'value="LocalBusiness"', message: 'the collection form offers the LocalBusiness type with its properties');
        $this->assertStringContainsString('name="schema[fields][openingHours]"', $form->body, 'opening hours can be mapped in the form');
        $this->assertStringContainsString('name="schema[fields][geo]"', $form->body, 'geo can be mapped in the form');

        // a team created after the branches links each person to a branch (system/presets/people.php: branch → preset branches)
        $this->mcpText('create_collection', ['name' => 'Branch team', 'preset' => 'people']);
        $this->assertSame('1', $this->sq('SELECT COUNT(*) FROM ka_collections WHERE slug = \'branch-team\' AND fields LIKE \'%"key":"branch"%"type":"item","collection":"branches"%\''), 'a team made afterwards gets the branch field linked to the branches');
    }

    /** The LocalBusiness node of the page's JSON-LD graph (the company node is a LocalBusiness too, so the old helper printed all of them), as JSON. */
    private function branchNode(Response $page): string
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $page->body, $m);
        $nodes = '';
        foreach (json_decode($m[1] ?? '{}', true)['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? '') === 'LocalBusiness') {
                $nodes .= json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $nodes;
    }
}
