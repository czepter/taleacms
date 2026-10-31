<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class PresetsTest extends TestCase
{
    /** 2.11: ready-made collections (Builder\Presets) and the date-time, file and location fields */
    public function testReadyMadeCollections(): void
    {
        $presets = \Talea\Builder\Presets::all();
        $presetProblems = [];
        foreach ($presets as $presetKey => $p) {
            $keys = array_column($p['fields'], 0);
            $sanitized = array_column(\Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]] + (isset($f[3]['preset']) ? ['collection' => 'x'] : []), $p['fields'])), 'key');
            if ($keys !== $sanitized || count($keys) > 30) {
                $presetProblems[] = $presetKey . ': field keys change when sanitized';
            }
            foreach ($p['fields'] as $f) {
                if (!isset(\Talea\Builder\Collections::FIELD_TYPES[$f[2]]) || ($f[2] === 'item' && !isset($presets[$f[3]['preset'] ?? '']))) {
                    $presetProblems[] = $presetKey . ': ' . $f[0] . ' has an unknown type or linked preset';
                }
            }
            if (is_array($p['schema']) && \Talea\Builder\CollectionSchema::sanitize($p['schema'], \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $p['fields'])))['fields'] != $p['schema']['fields']) {
                $presetProblems[] = $presetKey . ': the schema maps a field that does not exist';
            }
            if (trim((string) $p['claude']) === '' || trim((string) $p['description']) === '') {
                $presetProblems[] = $presetKey . ': no description or instructions for Claude';
            }
        }
        $this->assertSame([true, []], [isset($presets['people']), $presetProblems], '2.11 Presets: every preset is valid (keys survive sanitizing, known types, schema maps existing fields, described)');
        $this->assertSame(['phone', null, null], [
            \Talea\Builder\Presets::field(['preset' => 'people', 'fields' => [['key' => 'phone', 'type' => 'text']]], 'people', 'phone', ['text']),
            \Talea\Builder\Presets::field(['preset' => 'people', 'fields' => [['key' => 'phone', 'type' => 'number']]], 'people', 'phone', ['text']),
            \Talea\Builder\Presets::field(['preset' => '', 'fields' => [['key' => 'phone', 'type' => 'text']]], 'people', 'phone', ['text'])], '2.11 Presets::field – only in a collection made from the preset, and only while the field is there with its type');
        $this->assertSame(['2026-10-24 18:30', '2026-10-24', '2026-10-24 09:05', '2026-10-24', '2026-10-24 09:05', '', null, null, null], array_map(\Talea\Builder\Collections::cleanDateTime(...),
            ['2026-10-24 18:30', '2026-10-24', '2026-10-24T09:05', '2026-10-24T00:00', '2026-10-24 9:05:00', '', '2026-02-30 10:00', '2026-10-24 25:00', 'tomorrow']), '2.11 cleanDateTime: date and time, a whole day, the T of an input, midnight = the whole day, nonsense');
        $this->assertSame(['50.0875, 14.4214', '50.087543, 14.421399', '-33.9, 18.42', '', null, null, null], array_map(\Talea\Builder\Collections::cleanLocation(...),
            ['50.0875, 14.4214', '50.08754321;14.42139876', '-33.9,18.42', '', '91, 10', '50, 181', 'Praha']), '2.11 cleanLocation: latitude, longitude – rounded, also with a semicolon; out of range and text are not valid');
        $fileFields = [['key' => 'start', 'label' => 'Start', 'type' => 'datetime'], ['key' => 'sheet', 'label' => 'Datasheet', 'type' => 'file'], ['key' => 'place', 'label' => 'Place', 'type' => 'location']];
        $fileErrors = [];
        $this->assertSame([['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19, 16.61'], ['start' => '', 'sheet' => '', 'place' => ''], ''], [\Talea\Builder\Collections::sanitizeData($fileFields, ['start' => '2026-11-02T17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19,16.61'], $fileErrors),
            \Talea\Builder\Collections::sanitizeData($fileFields, ['sheet' => '/media/../config.php'], $fileErrors), \Talea\Builder\Collections::sanitizeData($fileFields, ['sheet' => 'javascript:alert(1)'], $fileErrors)['sheet']], '2.11 sanitizeData: a file from Media or https, never a path out of it');
        $fileValues = \Talea\Builder\Collections::values(['slug' => 'action', 'detail' => 1, 'fields' => $fileFields], ['name' => 'Den otevřených dveří', 'slug' => 'day', 'created_at' => '2026-10-02 10:00:00', // check-english: allow
            'data' => ['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/Cen%C3%ADk%202026.pdf', 'place' => '49.19, 16.61']], fn (string $p): string => '/' . $p);
        $this->assertSame([format_date('2026-11-02 17:00', true), '2026-11-02 17:00', ['/media/docs/Cen%C3%ADk%202026.pdf', 'link'], 'Ceník 2026.pdf', '49.19, 16.61'], [$fileValues['start'][0], $fileValues['start_iso'][0], $fileValues['sheet'], $fileValues['sheet_name'][0], $fileValues['place'][0]], '2.11 values: {{start}} for visitors and {{start_iso}}, {{sheet}} a link and {{sheet_name}}'); // check-english: allow
    }

    /** 2.11 F1: six more ready-made collections */
    public function testSixMoreCollections(): void
    {
        $f1Presets = \Talea\Builder\Presets::all();
        $this->assertSame([[20, true, 'Service', true], [30, true, '', true], [40, false, '', false], [50, false, 'FAQPage', false], [70, true, 'Product', true], [80, true, 'Event', true]], array_map(fn (string $k): array => [
            $f1Presets[$k]['order'], (bool) $f1Presets[$k]['detail'], (string) ($f1Presets[$k]['schema']['type'] ?? ''), is_callable($f1Presets[$k]['template'])], ['services', 'references', 'price_list', 'faq', 'machines', 'courses']), '2.11 presets: services, references, price_list, faq, machines and courses in order after people, with their pages and structured data');
        $this->assertSame([['sort' => 'order', 'filter_field' => 'category', 'filters' => true], 'category', ['period' => 'upcoming', 'period_start_field' => 'start', 'period_end_field' => 'end', 'sort' => 'field', 'sort_field' => 'start', 'filter_field' => 'level', 'filters' => true]], [
            $f1Presets['price_list']['list'], $f1Presets['faq']['list']['filter_field'], $f1Presets['courses']['list']], '2.11 presets: the lists – a price list and FAQ filter by category, courses show the upcoming ones by start and end sorted by the start');
        $this->assertSame(['services', ['price' => 'price_from'], ['sku' => 'model'], ['startDate' => 'start', 'endDate' => 'end', 'location' => 'place', 'price' => 'price']], [$f1Presets['references']['fields'][5][3]['preset'], $f1Presets['services']['schema']['fields'],
            $f1Presets['machines']['schema']['fields'], $f1Presets['courses']['schema']['fields']], '2.11 presets: a reference links to the services preset, the schema maps price_from, sku and the event dates');
        $f1Fields = fn (string $k): array => \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'sluzby'] : []), $f1Presets[$k]['fields'])); // check-english: allow
        $f1Template = fn (string $k): string => \Talea\Builder\Build::toJson(\Talea\Builder\Presets::itemTemplate($f1Presets[$k], $f1Fields($k)));
        $this->assertSame([true, true, true, true], [
            str_contains($f1Template('services'), '{{summary}}') && str_contains($f1Template('services'), '{{price_from}} {{price_note}}'),
            str_contains($f1Template('references'), '{{service_url}}') && str_contains($f1Template('references'), '"link":"{{link}}"'),
            str_contains($f1Template('machines'), '{{datasheet_name}}') && str_contains($f1Template('machines'), '{{parameters}}'),
            str_contains($f1Template('courses'), '<strong>{{when}}</strong>') && str_contains($f1Template('courses'), '{{capacity}}') && str_contains($f1Template('courses'), '"type":"form"')], '2.11 presets: the item templates survive sanitizing and show the fields');
        $f1List = \Talea\Builder\Build::toJson(\Talea\Builder\Presets::listPage($f1Presets['courses'], 'Kurzy', 'kurzy', $f1Fields('courses')));
        $this->assertSame([true, true, true, true], [str_contains($f1List, '"period":"upcoming"'), str_contains($f1List, '"sort_field":"start"'), str_contains($f1List, '<p>{{start}}</p>'), str_contains($f1List, '<p>{{price_line}}</p>') && str_contains($f1List, '<p>{{places_left_line}}</p>')], '2.11 presets: the list page of courses lists the upcoming ones with the start, the price with its label and the free places on the card');
    }
}
