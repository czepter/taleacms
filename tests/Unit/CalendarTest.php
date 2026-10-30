<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Calendar;

final class CalendarTest extends TestCase
{
    /** 2.11: events calendar (Core\Calendar) */
    public function testEvents(): void
    {
        $this->assertSame([['2026-10-06 18:00', '2026-10-06 19:30'], ['2026-10-06 18:00', '2026-10-06 19:30'], null, ['2026-10-13', ''], null, null], [
            Calendar::nextOccurrence('2026-09-29 18:00', '2026-09-29 19:30', 'weekly', '', '2026-10-02 12:00'),
            Calendar::nextOccurrence('2026-09-01 18:00', '2026-09-01 19:30', 'weekly', '', '2026-10-02 12:00'),
            Calendar::nextOccurrence('2026-10-06 18:00', '', 'weekly', '', '2026-10-02 12:00'),                  // not ended yet
            Calendar::nextOccurrence('2026-09-29', '', 'every 2 weeks', '', '2026-10-02 12:00'),                  // a whole day stays a day
            Calendar::nextOccurrence('2026-09-29 18:00', '', 'weekly', '2026-10-05', '2026-10-02 12:00'),         // the next one is after the last day
            Calendar::nextOccurrence('2026-09-29 18:00', '', 'never', '', '2026-10-02 12:00'),
        ], '2.11 Calendar::nextOccurrence – a weekly class that ended moves by whole weeks to the next one not ended yet');
        $this->assertSame([['2026-02-28 10:00', ''], ['2026-03-31 10:00', ''], ['2026-10-02', '']], [
            Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-02-10 00:00'), Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-03-10 00:00'),
            Calendar::nextOccurrence('2025-10-02', '', 'yearly', '', '2026-10-01 00:00')], '2.11 Calendar::nextOccurrence – monthly on the 31st ends on the last day of a shorter month, yearly keeps the day');
        $this->assertSame(['2026-10-02 23:59', '2026-10-02 20:00', 'open', 'full', 'open', 'closed', 'open', 'closed', 'closed'], [
            Calendar::endsAt('2026-10-02', ''), Calendar::endsAt('2026-10-02 18:00', '2026-10-02 20:00'),
            Calendar::registrationState(20, 5, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 20, '', '2026-10-02 20:00', '2026-10-02 12:00'),
            Calendar::registrationState(0, 500, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '', '2026-10-01 20:00', '2026-10-02 12:00'),
            Calendar::registrationState(20, 5, '2026-10-02', '2026-10-09 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '2026-10-01', '2026-10-09 20:00', '2026-10-02 12:00'),
            Calendar::registrationState(20, 5, '2026-10-02 10:00', '2026-10-09 20:00', '2026-10-02 12:00')], '2.11 Calendar::endsAt and registrationState: closed after the event or the deadline day, full at capacity, unlimited without');
        $this->assertSame([format_date('2026-11-02 17:00', true) . '–19:00', format_date('2026-11-02') . ' – ' . format_date('2026-11-04'), format_date('2026-11-02 17:00', true), ''], [Calendar::when('2026-11-02 17:00', '2026-11-02 19:00'), Calendar::when('2026-11-02', '2026-11-04'), Calendar::when('2026-11-02 17:00', ''), Calendar::when('', '')], '2.11 Calendar::when – one day with times, several days, the start alone');
        $this->assertSame(// check-english: allow
            ['a\\;b\\,c\\\\d\\nnext', [74, 59], 'SUMMARY:short'], [Calendar::escape("a;b,c\\d\nnext"),
            array_map('strlen', explode("\r\n", Calendar::fold('DESCRIPTION:' . str_repeat('č', 60)))), Calendar::fold('SUMMARY:short')], '2.11 Calendar::escape and fold – RFC 5545 text, lines of at most 75 octets, never inside a character'); // check-english: allow
        $icsCollection = ['collection_id' => 1, 'slug' => 'action', 'preset' => 'events', 'detail' => 1, 'fields' => \Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'options' => $f[3]['options'] ?? []],
            (array) (\Talea\Builder\Presets::get('events')['fields'] ?? [])))];
        $ics = Calendar::ics($icsCollection, [[['item_id' => 7, 'public_id' => '0b1c2d3e-0000-4000-8000-000000000007', 'name' => 'Jóga, pro začátečníky', 'updated_at' => '2026-10-01 10:00:00', 'data' => ['start' => '2026-10-06 18:00', 'end' => '2026-10-06 19:30', 'venue' => 'Sál', 'address' => 'Hlavní 1, Brno', 'repeat' => 'weekly', 'repeat_until' => '2026-12-15', 'summary' => 'Přineste podložku.']], 'https://example.cz/akce/joga'], // check-english: allow
            [['item_id' => 8, 'name' => 'Den otevřených dveří', 'data' => ['start' => '2026-11-02']], ''], [['item_id' => 9, 'name' => 'Bez data', 'data' => []], '']], 'Web – Akce', 'example.cz'); // check-english: allow
        $utc = fn (string $local): string => (new \DateTimeImmutable($local))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $this->assertSame(// check-english: allow
            [true, true, true, true, true, true, true, 2, true], [str_starts_with($ics, "BEGIN:VCALENDAR\r\n"), str_contains($ics, "UID:talea-0b1c2d3e-0000-4000-8000-000000000007@example.cz\r\n"),
            str_contains($ics, 'DTSTART:' . $utc('2026-10-06 18:00') . "\r\n"), str_contains($ics, 'RRULE:FREQ=WEEKLY;UNTIL=' . $utc('2026-12-15 23:59')), str_contains($ics, "SUMMARY:Jóga\\, pro začátečníky\r\n"), // check-english: allow
            str_contains($ics, 'LOCATION:Sál\\, Hlavní 1\\, Brno'), str_contains($ics, "DTSTART;VALUE=DATE:20261102\r\nDTEND;VALUE=DATE:20261103\r\n"), substr_count($ics, 'BEGIN:VEVENT'), str_ends_with($ics, "END:VCALENDAR\r\n")], '2.11 Calendar::ics – a timed series with RRULE in UTC, a whole day as DATE, an event without a date left out'); // check-english: allow
        $this->assertSame(['repeat', null, null], [Calendar::fields($icsCollection)['repeat'] ?? null, Calendar::fields(['preset' => 'events', 'fields' => []]), Calendar::fields(['preset' => 'people', 'fields' => $icsCollection['fields']])], '2.11 Calendar::fields – only a collection made from the events preset with its start field');
    }

    /** @return array<string, mixed> a collection made from the courses preset */
    private function courses(): array
    {
        return ['collection_id' => 1, 'slug' => 'courses', 'preset' => 'courses', 'fields' => \Talea\Builder\Collections::sanitizeFields(array_map(
            fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'options' => $f[3]['options'] ?? []], (array) (\Talea\Builder\Presets::get('courses')['fields'] ?? [])))];
    }

    public function testRemovingMappedFieldIsRefused(): void
    {
        $k = $this->courses();
        $this->assertArrayHasKey('capacity', Calendar::protectedKeys($k));
        $without = array_values(array_filter($k['fields'], fn (array $f): bool => $f['key'] !== 'capacity'));
        $this->assertStringContainsString('cannot be removed', (string) Calendar::refuseProtected($k, $without));
        $renamedKey = array_map(fn (array $f): array => $f['key'] === 'capacity' ? ['key' => 'seats'] + $f : $f, $k['fields']);
        $this->assertNotNull(Calendar::refuseProtected($k, $renamedKey), 'a changed key counts as removed');
    }

    public function testChangingMappedTypeIsRefused(): void
    {
        $k = $this->courses();
        $changed = array_map(fn (array $f): array => $f['key'] === 'capacity' ? ['type' => 'text'] + $f : $f, $k['fields']);
        $this->assertStringContainsString('type of the field', (string) Calendar::refuseProtected($k, $changed));
    }

    public function testRenamingMappedFieldIsAllowed(): void
    {
        $k = $this->courses();
        $renamed = array_map(fn (array $f): array => $f['key'] === 'capacity' ? ['label' => 'Places', 'help' => 'x'] + $f : $f, $k['fields']);
        $this->assertNull(Calendar::refuseProtected($k, array_reverse($renamed)));
    }

    public function testUnmappedFieldCanBeRemoved(): void
    {
        $k = $this->courses();
        $unmapped = array_values(array_diff(array_column($k['fields'], 'key'), array_keys(Calendar::protectedKeys($k))));
        $this->assertNotSame([], $unmapped, 'the preset has a field registration does not use');
        $without = array_values(array_filter($k['fields'], fn (array $f): bool => $f['key'] !== $unmapped[0]));
        $this->assertNull(Calendar::refuseProtected($k, $without));
        $this->assertSame([], Calendar::protectedKeys(['preset' => '', 'fields' => $k['fields']]));
    }
}
