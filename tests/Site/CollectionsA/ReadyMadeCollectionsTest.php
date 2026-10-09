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

        $text = $this->mcpText('create_collection', ['name' => 'Náš tým', 'preset' => 'people']);
        $this->assertStringContainsString('how_to_use', $text);
        $this->assertSame('people|phone|Náš tým', $this->sq("SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[3].klic')), '|', name) FROM ka_collections WHERE slug = 'nas-tym'"), 'the collection remembers its preset and gets English field keys');
        $this->assertSame('0|1|1', $this->sq("SELECT CONCAT(visible, '|', build LIKE '%\"kolekce\":\"nas-tym\"%', '|', build LIKE '%{{photo}}%') FROM ka_pages WHERE slug = 'nas-tym'"), 'a hidden page lists the new collection');
        $this->assertStringContainsString('list_page', $text, 'Claude is told about the hidden list page');
        $this->assertSame('phone', $this->sq("SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.telephone')) FROM ka_collections WHERE slug = 'nas-tym'"), 'the team gets Person structured data mapped to its fields');

        $this->assertStringContainsString('list_collection_presets', $this->mcpText('create_collection', ['name' => 'Nesmysl', 'preset' => 'nothing-like-it']), 'an unknown preset names the known ones');
        $this->assertPage('/admin.php?module=collections', 200, 'name="preset" value="people"', message: 'the collections list offers the ready-made collections');
    }

    public function testDateTimeFileAndLocationFields(): void
    {
        $this->mcpText('create_collection', ['name' => 'Typy polí', 'slug' => 'typy-poli', 'item_pages' => true, 'fields' => [
            ['label' => 'Začátek', 'type' => 'datetime'], ['label' => 'Ceník', 'type' => 'file'], ['label' => 'Místo', 'type' => 'location'],
        ]]);
        $this->assertSame('termin,soubor,poloha', $this->sq("SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(fields, CONCAT('\$[', n.i, '].type'))) ORDER BY n.i) FROM ka_collections, (SELECT 0 i UNION SELECT 1 UNION SELECT 2) n WHERE slug = 'typy-poli'"), "Claude's datetime, file and location types");

        $this->mcpText('save_collection_item', ['collection' => 'typy-poli', 'name' => 'Den otevřených dveří', 'slug' => 'den-otevrenych-dveri',
            'values' => ['zacatek' => '2026-11-02T17:00', 'cenik' => '/media/cenik-2026.pdf', 'misto' => '49.1951;16.6068'], 'visible' => true]);
        $row = $this->site()->rows("SELECT data->>'\$.zacatek' a, data->>'\$.cenik' b, data->>'\$.misto' c FROM ka_collection_items WHERE slug = 'den-otevrenych-dveri'")[0];
        $this->assertSame('2026-11-02 17:00|/media/cenik-2026.pdf|49.1951, 16.6068', implode('|', $row), 'the date and time, the file and the location are stored clean');

        $page = $this->visitor()->get('/typy-poli/den-otevrenych-dveri');
        $this->assertStringContainsString('2. 11. 2026 17:00', $page->body, 'the item page shows the day and time');
        $this->assertMatchesRegularExpression('#href="[^"]*media/cenik-2026.pdf"#', $page->body, '... a button to the file');
        $this->assertStringContainsString('cenik-2026.pdf)', $page->body, '... with its name');

        $idk = $this->sq("SELECT collection_id FROM ka_collections WHERE slug = 'typy-poli'");
        $idp = $this->sq("SELECT item_id FROM ka_collection_items WHERE slug = 'den-otevrenych-dveri'");
        $form = $this->assertPage("/admin.php?module=collections&action=item&id=$idk&item=$idp", 200, message: 'the item form');
        $this->assertMatchesRegularExpression('/type="datetime-local" id="pole-zacatek" name="data\[zacatek\]" value="2026-11-02T17:00"/', $form->body, 'the item form has a date-time input');
        $this->assertStringContainsString('data-soubor', $form->body, 'the file field opens Media');
    }

    /** The period of a Collection list: upcoming, current and past by a start and an end field – the SQL condition run on real rows. */
    public function testPeriodConditionOnRealRows(): void
    {
        $this->mcpText('create_collection', ['name' => 'Období', 'slug' => 'obdobi-test', 'fields' => [['label' => 'Od', 'type' => 'datetime'], ['label' => 'Do', 'type' => 'datetime']]]);
        $yesterday = $this->siteDate('yesterday');
        $today = $this->siteDate('today');
        $tomorrow = $this->siteDate('tomorrow');
        foreach ([['vcera', "$yesterday 10:00", ''], ['dnes-cely-den', $today, ''], ['zitra', "$tomorrow 09:00", ''], ['probiha', $yesterday, $tomorrow], ['vyveseno', $yesterday, ''], ['bez-data', '', '']] as [$slug, $od, $do]) {
            $this->mcpText('save_collection_item', ['collection' => 'obdobi-test', 'name' => $slug, 'slug' => $slug, 'values' => ['od' => $od, 'do' => $do], 'visible' => true]);
        }

        $this->assertSame('dnes-cely-den,probiha,zitra', $this->inPeriod('nadchazejici', 'do'), "upcoming – not ended (today's whole day counts, an event with an end that has not passed too)");
        $this->assertSame('vcera,vyveseno', $this->inPeriod('minule', 'do'), 'past – ended yesterday');
        $this->assertSame('dnes-cely-den,probiha,vcera,vyveseno', $this->inPeriod('probihajici', 'do'), 'current – started and not ended; without an end it stays up');
        $this->assertSame('dnes-cely-den', $this->inPeriod('probihajici', ''), "current without an end field – only the start's day");
    }

    private function inPeriod(string $period, string $endField): string
    {
        $out = $this->site()->php('[$sql, $p] = Kaleta\Builder\Collections::periodCondition(' . var_export($period, true) . ', "od", ' . var_export($endField, true) . ', date("Y-m-d H:i")); echo json_encode([$sql, $p]);');
        [$sql, $params] = json_decode($out, true);

        return $this->sq("SELECT GROUP_CONCAT(slug ORDER BY slug) FROM ka_collection_items WHERE collection_id = (SELECT collection_id FROM ka_collections WHERE slug = 'obdobi-test') AND $sql", $params);
    }
}
