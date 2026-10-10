<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 ready-made collections and the date-time, file and location fields (was: section 56 of tools/test.sh). */
#[Group('site')]
final class ReadyMadeCollectionsTest extends SiteTestCase
{
    use CollectionsHelpers;

    public function testPresetsAreListedAndCreateACollection(): void
    {
        $list = $this->mcpText('list_collection_presets');
        $this->assertStringContainsString('preset":"people', $list, 'list_collection_presets lists the presets');
        $this->assertStringContainsString('how_to_use', $list, '... with their instructions');
        $this->assertStringContainsString('existing_collection":"', $list, '... and the existing collection');

        $text = $this->mcpText('create_collection', ['name' => 'Our team', 'preset' => 'people']);
        $this->assertStringContainsString('how_to_use', $text);
        $this->assertSame('people|phone|Our team', $this->sq("SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[3].key')), '|', name) FROM ka_collections WHERE slug = 'our-team'"), 'the collection remembers its preset and gets English field keys');
        $this->assertSame('0|1|1', $this->sq("SELECT CONCAT(visible, '|', build LIKE '%\"collection\":\"our-team\"%', '|', build LIKE '%{{photo}}%') FROM ka_pages WHERE slug = 'our-team'"), 'a hidden page lists the new collection');
        $this->assertStringContainsString('list_page', $text, 'Claude is told about the hidden list page');
        $this->assertSame('phone', $this->sq("SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.telephone')) FROM ka_collections WHERE slug = 'our-team'"), 'the team gets Person structured data mapped to its fields');

        $this->assertStringContainsString('list_collection_presets', $this->mcpText('create_collection', ['name' => 'Nonsense', 'preset' => 'nothing-like-it']), 'an unknown preset names the known ones');
        $this->assertPage('/admin.php?module=collections', 200, 'name="preset" value="people"', message: 'the collections list offers the ready-made collections');
    }

    public function testDateTimeFileAndLocationFields(): void
    {
        $this->mcpText('create_collection', ['name' => 'Field types', 'slug' => 'field-types', 'item_pages' => true, 'fields' => [
            ['label' => 'Start', 'type' => 'datetime'], ['label' => 'Brochure', 'type' => 'file'], ['label' => 'Place', 'type' => 'location'],
        ]]);
        $this->assertSame('datetime,file,location', $this->sq("SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(fields, CONCAT('\$[', n.i, '].type'))) ORDER BY n.i) FROM ka_collections, (SELECT 0 i UNION SELECT 1 UNION SELECT 2) n WHERE slug = 'field-types'"), "Claude's datetime, file and location types");

        $this->mcpText('save_collection_item', ['collection' => 'field-types', 'name' => 'Open day', 'slug' => 'open-day',
            'values' => ['start' => '2026-11-02T17:00', 'brochure' => '/media/brochure-2026.pdf', 'place' => '49.1951;16.6068'], 'visible' => true]);
        $row = $this->site()->rows("SELECT data->>'\$.start' a, data->>'\$.brochure' b, data->>'\$.place' c FROM ka_collection_items WHERE slug = 'open-day'")[0];
        $this->assertSame('2026-11-02 17:00|/media/brochure-2026.pdf|49.1951, 16.6068', implode('|', $row), 'the date and time, the file and the location are stored clean');

        $page = $this->visitor()->get('/field-types/open-day');
        $this->assertStringContainsString('2 Nov 2026 17:00', $page->body, 'the item page shows the day and time');
        $this->assertMatchesRegularExpression('#href="[^"]*media/brochure-2026.pdf"#', $page->body, '... a button to the file');
        $this->assertStringContainsString('brochure-2026.pdf)', $page->body, '... with its name');

        $idk = $this->sq("SELECT collection_id FROM ka_collections WHERE slug = 'field-types'");
        $idp = $this->sq("SELECT item_id FROM ka_collection_items WHERE slug = 'open-day'");
        $form = $this->assertPage("/admin.php?module=collections&action=item&id=$idk&item=$idp", 200, message: 'the item form');
        $this->assertMatchesRegularExpression('/type="datetime-local" id="field-start" name="data\[start\]" value="2026-11-02T17:00"/', $form->body, 'the item form has a date-time input');
        $this->assertStringContainsString('data-file', $form->body, 'the file field opens Media');
    }

    /** The period of a Collection list: upcoming, current and past by a start and an end field – the SQL condition run on real rows. */
    public function testPeriodConditionOnRealRows(): void
    {
        $this->mcpText('create_collection', ['name' => 'Period', 'slug' => 'period-test', 'fields' => [['label' => 'Begin', 'type' => 'datetime'], ['label' => 'End', 'type' => 'datetime']]]);
        $yesterday = $this->siteDate('yesterday');
        $today = $this->siteDate('today');
        $tomorrow = $this->siteDate('tomorrow');
        foreach ([['yesterday', "$yesterday 10:00", ''], ['today-all-day', $today, ''], ['tomorrow', "$tomorrow 09:00", ''], ['ongoing', $yesterday, $tomorrow], ['pinned', $yesterday, ''], ['no-date', '', '']] as [$slug, $begin, $end]) {
            $this->mcpText('save_collection_item', ['collection' => 'period-test', 'name' => $slug, 'slug' => $slug, 'values' => ['begin' => $begin, 'end' => $end], 'visible' => true]);
        }

        $this->assertSame('ongoing,today-all-day,tomorrow', $this->inPeriod('upcoming', 'end'), "upcoming – not ended (today's whole day counts, an event with an end that has not passed too)");
        $this->assertSame('pinned,yesterday', $this->inPeriod('past', 'end'), 'past – ended yesterday');
        $this->assertSame('ongoing,pinned,today-all-day,yesterday', $this->inPeriod('current', 'end'), 'current – started and not ended; without an end it stays up');
        $this->assertSame('today-all-day', $this->inPeriod('current', ''), "current without an end field – only the start's day");
    }

    private function inPeriod(string $period, string $endField): string
    {
        $out = $this->site()->php('[$sql, $p] = Kaleta\Builder\Collections::periodCondition(' . var_export($period, true) . ', "begin", ' . var_export($endField, true) . ', date("Y-m-d H:i")); echo json_encode([$sql, $p]);');
        [$sql, $params] = json_decode($out, true);

        return $this->sq("SELECT GROUP_CONCAT(slug ORDER BY slug) FROM ka_collection_items WHERE collection_id = (SELECT collection_id FROM ka_collections WHERE slug = 'period-test') AND $sql", $params);
    }
}
