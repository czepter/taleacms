<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 66 (2.11/3.3 the twenty shipped industry blueprints). */
#[Group('site')]
final class ShippedBlueprintsTest extends SiteTestCase
{
    use Helpers;

    public function testTheTwentyBlueprintsAreListedAndTheMunicipalityCanBeAppliedAndRemoved(): void
    {
        $site = $this->site();

        $listing = $this->mcpText('get_blueprint');
        $listed = 0;
        foreach (['accommodation', 'agency', 'auto_service', 'beauty_wellness', 'clinic', 'craftsman', 'driving_school', 'farm', 'fitness_studio', 'it_services', 'manufacturer', 'municipality', 'nonprofit', 'photographer', 'professional_services', 'real_estate', 'restaurant', 'retail_shop', 'school_courses', 'software_saas'] as $key) {
            $listed += str_contains($listing, 'key":"' . $key) ? 1 : 0;
        }
        $this->assertSame(20, $listed, 'shipped blueprints: get_blueprint lists the twenty as available');

        $idk0 = (int) $site->value('SELECT IFNULL(MAX(collection_id), 0) FROM ka_collections');
        // apply creates only the collections the site lacks
        $missing = (string) $site->value("SELECT 5 - COUNT(DISTINCT preset) FROM ka_collections WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')");
        $text = $this->mcpText('apply_blueprint', ['key' => 'municipality']);
        $this->assertStringContainsString('applied":"municipality', $text, 'shipped blueprints: apply_blueprint answers that the municipality was applied');
        $this->assertSame("5|$missing|4|number|municipality", $site->value("SELECT CONCAT((SELECT COUNT(DISTINCT preset) FROM ka_collections WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')), '|', (SELECT COUNT(*) FROM ka_collections WHERE collection_id > $idk0), '|', (SELECT COUNT(*) FROM ka_facts WHERE fact_key IN ('mayor_name', 'population', 'filing_office_email', 'council_meetings') AND value = ''), '|', (SELECT type FROM ka_facts WHERE fact_key = 'population'), '|', (SELECT bkey FROM ka_blueprints))"),
            "shipped blueprints: the municipality brings the collections the site lacks ($missing of its five) and its facts without values");

        $page = $this->assertPage('/admin.php?module=blueprints', 200, 'name="answer[mayor_name]"', message: "shipped blueprints: the admin page asks the municipality's questions");
        $this->assertStringContainsString('Kdo je starostou nebo starostkou obce?', $page->body, 'shipped blueprints: the question about the mayor is in the admin language');
        $this->assertStringContainsString('Uveďte starostu nebo starostku', $page->body, 'shipped blueprints: the failing check about the mayor is in the admin language');

        // removing it leaves no blueprint; its empty collections and hidden pages go (the facts stay)
        $site->mcp('remove_blueprint', ['key' => 'municipality']);
        foreach ($site->rows('SELECT slug FROM ka_collections WHERE collection_id > ?', [$idk0]) as $row) {
            $slug = $row['slug'];
            $site->mcp('delete_collection', ['collection' => $slug]);
            $site->exec('DELETE FROM ka_pages WHERE slug IN (?, ?) AND visible = 0', [$slug, $slug . '-archive']);
        }
        $this->assertSame('0|0', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_collections WHERE collection_id > $idk0))"), 'shipped blueprints: removed again, the site has no blueprint and no collection of the municipality');
    }
}
