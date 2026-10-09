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
        $text = $this->mcpText('create_collection', ['name' => 'Pobočky', 'preset' => 'branches']);
        $this->assertStringContainsString('how_to_use', $text);
        $this->assertSame('branches|location|poloha|location|hours', $this->sq("SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.geo')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.openingHours'))) FROM ka_kolekce WHERE seo_link = 'pobocky'"), 'the collection remembers its preset, has a location field and LocalBusiness data from it');
        $this->assertSame('111', $this->sq("SELECT CONCAT(stavba LIKE '%{{photo}}%', stavba LIKE '%<p>{{hours}}</p>%', stavba LIKE '%\"adresa\":\"{{address}}\"%') FROM ka_kolekce WHERE seo_link = 'pobocky'"), 'the item template brings the photo, the hours and a click-to-load map of the address');

        $this->mcpText('save_collection_item', ['collection' => 'pobocky', 'name' => 'Brno', 'slug' => 'brno', 'values' => ['address' => 'Náměstí Svobody 1, 602 00 Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => "Mo-Fr 9-17\nSa 9-12"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'pobocky', 'name' => 'Praha', 'slug' => 'praha', 'values' => ['address' => 'Václavské náměstí 1, 110 00 Praha', 'location' => '50.0813, 14.4275', 'phone' => '+420 987 654 321', 'hours' => 'by appointment'], 'visible' => true]);
        $this->mcpText('create_page', ['title' => 'Kde nás najdete', 'slug' => 'kde-nas-najdete', 'visible' => true]);
        $page = (int) $this->sq("SELECT ids FROM ka_stranky WHERE seo_link = 'kde-nas-najdete'");
        $saved = $this->mcpText('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'store_locator']]]]]]);
        $this->assertStringContainsString('published', $saved);
        $this->assertSame('1', $this->sq('SELECT stavba LIKE \'%"typ":"pobocky"%\' FROM ka_stranky WHERE ids = ?', [$page]), 'Claude places the element by its English name, stored under its own type');

        $schema = $this->mcpText('builder_schema', ['elements' => ['store_locator']]);
        $this->assertStringContainsString('location_field', $schema, 'builder_schema describes the element');
        $this->assertStringContainsString('show_map', $schema, '... and its English options');

        $locator = $this->visitor()->get('/kde-nas-najdete');
        $b = $locator->body;
        $this->assertStringContainsString('data-pobocky', $b, 'the page lists the branches');
        $this->assertMatchesRegularExpression('#href="[^"]*/pobocky/brno"#', $b, '... with a link to the first');
        $this->assertMatchesRegularExpression('#href="[^"]*/pobocky/praha"#', $b, '... and the second (no JavaScript needed)');
        $this->assertStringContainsString('href="tel:+420123456789"', $b, 'phones as tel: links');
        $this->assertStringContainsString('href="tel:+420987654321"', $b);
        $this->assertStringContainsString('href="mailto:brno@example.com"', $b, 'the e-mail as mailto:');
        $this->assertStringContainsString('maps/search/?api=1&amp;query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody', $b, 'a Directions link to a maps search of the address');
        $this->assertStringContainsString('>Trasa<', $b);
        $this->assertStringContainsString('data-lat="49.1951" data-lng="16.6068"', $b, 'data-lat/data-lng for the script');
        $this->assertStringContainsString('data-lat="50.0813" data-lng="14.4275"', $b);
        $this->assertStringContainsString('data-text="brno n', $b, '... and the search text');
        $this->assertStringContainsString('data-hledat', $b, 'search control');
        $this->assertStringContainsString('data-nejblizsi>Nejblíže ke mně<', $b, 'nearest control in Czech');
        $this->assertStringContainsString('data-mapa aria-controls="pobocky-mapa-', $b, 'map control');
        $this->assertStringContainsString('class="ka-pobocky-mapa" id="pobocky-mapa-', $b);
        $this->assertMatchesRegularExpression('#data-leaflet="[^"]*/image/vendor/leaflet/"#', $b, 'the Leaflet path');
        $this->assertStringContainsString('data-atribuce="© OpenStreetMap contributors"', $b, '... and attribution');
        $this->assertStringContainsString('image/web.js', $b, 'web.js kept on the page');

        $this->assertPage('/image/vendor/leaflet/leaflet.js', 200, 'Leaflet 1.9.4', message: 'Leaflet 1.9.4 is served from the site itself');
        $this->assertPage('/image/vendor/leaflet/leaflet.css', 200, 'leaflet-marker-icon', message: 'the Leaflet stylesheet and marker are there');
        $this->assertPage('/image/vendor/leaflet/LICENSE', 200, 'BSD 2-Clause', message: 'the Leaflet licence is shipped');
    }

    public function testBranchPagesCarryLocalBusinessData(): void
    {
        $brno = $this->visitor()->get('/pobocky/brno');
        $node = $this->branchNode($brno);
        $this->assertMatchesRegularExpression('/' . implode('.*?', array_map(static fn (string $p): string => preg_quote($p, '/'), [
            '"geo":{"@type":"GeoCoordinates","latitude":49.1951,"longitude":16.6068}',
            '"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"},{"@type":"OpeningHoursSpecification","dayOfWeek":["Saturday"],"opens":"09:00","closes":"12:00"}]',
            '"parentOrganization":{"@id":',
        ])) . '/s', $node, 'the branch page carries LocalBusiness data with the geo, the opening hours and the company as parent');
        $this->assertMatchesRegularExpression('/"telephone":"\+420 123 456 789".*"email":"brno@example.com"/s', $node, 'address, phone and e-mail in the structured data');
        $this->assertMatchesRegularExpression('#data-vlozit="https://maps.google.com/maps\?q=N%C3%A1m%C4%9Bst%C3%AD%20Svobody#', $brno->body, 'the branch page shows the map of its own address loading after a click');
        $this->assertStringContainsString('+420 123 456 789', $brno->body, '... and the contacts');

        $praha = $this->branchNode($this->visitor()->get('/pobocky/praha'));
        $this->assertStringNotContainsString('openingHoursSpecification', $praha, 'hours that do not parse must be left out');
        $this->assertStringContainsString('"latitude":50.0813', $praha, '... the geo stays');
    }

    public function testSchemaFormAndATeamLinkedToTheBranches(): void
    {
        $form = $this->assertPage('/admin.php?module=collections&action=edit&id=' . $this->sq("SELECT idk FROM ka_kolekce WHERE seo_link = 'pobocky'"), 200, 'value="LocalBusiness"', message: 'the collection form offers the LocalBusiness type with its properties');
        $this->assertStringContainsString('name="schema[pole][openingHours]"', $form->body, 'opening hours can be mapped in the form');
        $this->assertStringContainsString('name="schema[pole][geo]"', $form->body, 'geo can be mapped in the form');

        // a team created after the branches links each person to a branch (system/presets/people.php: branch → preset branches)
        $this->mcpText('create_collection', ['name' => 'Tým poboček', 'preset' => 'people']);
        $this->assertSame('1', $this->sq('SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = \'tym-pobocek\' AND pole LIKE \'%"klic":"branch"%"typ":"polozka","kolekce":"pobocky"%\''), 'a team made afterwards gets the branch field linked to the branches');
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
