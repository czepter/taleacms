<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.11 events calendar (was: section 57 of tools/test.sh). Needs from section 56 only a collection that is not a calendar
 * (typy-poli), created here.
 */
#[Group('site')]
final class EventsCalendarTest extends SiteTestCase
{
    use CollectionsHelpers;

    private static string $idk = '';

    public function testPresetCreatesTheCalendar(): void
    {
        $this->mcpText('create_collection', ['name' => 'Typy polí', 'slug' => 'typy-poli', 'item_pages' => true, 'fields' => [['label' => 'Začátek', 'type' => 'datetime']]]);
        $text = $this->mcpText('create_collection', ['name' => 'Akce test', 'preset' => 'events']);
        self::$idk = $this->sq("SELECT collection_id FROM ka_collections WHERE slug = 'akce-test' AND preset = 'events'");

        $this->assertNotSame('', self::$idk, 'events: the preset creates the calendar');
        $this->assertStringContainsString('list_page', $text, 'events: ... and its list page');
        $this->assertSame('5|1|1', $this->sq("SELECT CONCAT(JSON_LENGTH(JSON_EXTRACT(fields, '\$[12].moznosti')), '|', build LIKE '%\"typ\":\"formular\"%', '|', build LIKE '%{{ical}}%') FROM ka_collections WHERE collection_id = " . self::$idk), 'the repetition is a choice of known options and the item template has the registration form');
    }

    public function testRepetitionOutsideTheOptionsIsRefusedAndTheJobMovesAnEndedWeeklyEvent(): void
    {
        $tomorrow = $this->siteDate('tomorrow');
        $eightAgo = $this->siteDate('-8 days');
        $sixAhead = $this->siteDate('+6 days');
        $yesterday = $this->siteDate('yesterday');
        $this->mcpText('save_collection_item', ['collection' => 'akce-test', 'name' => 'Jóga, pro začátečníky', 'slug' => 'joga', 'values' => [
            'start' => "$tomorrow 18:00", 'end' => "$tomorrow 19:30", 'venue' => 'Sál', 'address' => 'Hlavní 1, Brno', 'capacity' => '1', 'repeat' => 'weekly', 'summary' => 'Přineste si podložku.'], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'akce-test', 'name' => 'Minulá přednáška', 'slug' => 'minula', 'values' => ['start' => "$yesterday 10:00"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'akce-test', 'name' => 'Seriál', 'slug' => 'serial', 'values' => ['start' => "$eightAgo 18:00", 'end' => "$eightAgo 19:30", 'repeat' => 'weekly'], 'visible' => true]);
        $refused = $this->mcpText('save_collection_item', ['collection' => 'akce-test', 'name' => 'Nesmysl', 'slug' => 'nesmysl', 'values' => ['repeat' => 'každé úterý']]);
        $this->assertMatchesRegularExpression('/invalid_fields.*repeat/s', $refused, 'a repetition outside the options is refused');
        $this->assertSame('', $this->sq("SELECT data->>'\$.repeat' FROM ka_collection_items WHERE collection_id = " . self::$idk . " AND seo_link = 'nesmysl'"), 'the refused item was not stored with the value');

        $this->site()->exec("INSERT INTO ka_jobs (name, last_run) VALUES ('events', NULL) ON DUPLICATE KEY UPDATE last_run = NULL");
        $tasks = $this->site()->runTasks();
        $this->assertSame("$sixAhead 18:00|$sixAhead 19:30", $this->sq("SELECT CONCAT(data->>'\$.start', '|', data->>'\$.end') FROM ka_collection_items WHERE collection_id = " . self::$idk . " AND seo_link = 'serial'"), 'the job moves an ended weekly event to its next date, keeping the time');
        $this->assertStringContainsString('events: moved 1', $tasks, 'the job reports what it moved');
    }

    public function testListPageEventPageAndCalendarFiles(): void
    {
        $this->mcpText('update_page', ['id' => (int) $this->sq("SELECT page_id FROM ka_pages WHERE slug = 'akce-test'"), 'visible' => true]);
        $this->site()->clearPageCache();
        $list = $this->visitor()->get('/akce-test');
        $this->assertStringContainsString('Jóga, pro začátečníky', $list->body, 'the list page shows an upcoming event');
        $this->assertStringContainsString('Seriál', $list->body, '... the moved series');
        $this->assertStringNotContainsString('Minulá přednáška', $list->body, '... and not the past one');
        $this->assertStringContainsString('Sál, Hlavní 1, Brno', $list->body, '... with its place');

        $event = $this->visitor()->get('/akce-test/joga');
        $this->assertStringContainsString('"@type":"Event"', $event->body, 'the event page has Event data');
        $this->assertStringContainsString('OfflineEventAttendanceMode', $event->body);
        $this->assertMatchesRegularExpression('#href="[^"]*akce-test/joga.ics"#', $event->body, '... an Add to calendar link');
        $this->assertStringContainsString('class="ka-formular"', $event->body, '... and the registration form');

        $ics = $this->visitor()->get('/akce-test.ics');
        $this->assertStringContainsStringIgnoringCase('text/calendar', $ics->headers['content-type'] ?? '', '/<collection>.ics is a calendar');
        $this->assertStringContainsString('SUMMARY:Jóga\, pro začátečníky', $ics->body, 'the calendar has the summary');
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY', $ics->body, '... the repetition');
        $this->assertStringContainsString('LOCATION:Sál\, Hlavní 1\, Brno', $ics->body, '... and the place');

        $one = $this->visitor()->get('/akce-test/joga.ics');
        $this->assertSame('attachment; filename="joga.ics"', strtolower($one->headers['content-disposition'] ?? ''), 'one event as a file to add');
        $this->assertSame(1, substr_count($one->body, 'BEGIN:VEVENT'), 'one event in the file');

        $this->assertSame(404, $this->visitor()->get('/typy-poli.ics')->status, 'a collection that is not a calendar has no .ics');
    }

    /** Capacity 1: the first registration fills it, the form closes and the server refuses another one. */
    public function testRegistrationFillsTheEvent(): void
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'formular'");
        $page = $visitor->get('/akce-test/joga');
        $fields = ['source' => $page->field('source'), 'element' => $page->field('element'), 'zpet' => '/akce-test/joga', 'as_cas' => $page->field('as_cas'), 'as_podpis' => $page->field('as_podpis'), 'p0' => 'Eva', 'p4' => '1'];
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam), as the old script waited
        $register = static fn (string $email): string => $visitor->post('/formular', $fields + ['p1' => $email])->redirect;

        $this->assertStringContainsString('result=ok', $register('eva@example.cz'), 'a registration is accepted');
        $this->assertSame('kolekce:' . self::$idk . '|/akce-test/joga', $this->sq('SELECT CONCAT(source, \'|\', page) FROM ka_enquiries ORDER BY enquiry_id DESC LIMIT 1'), "the registration is an enquiry from the event's page");
        $this->assertStringContainsString('result=plno', $register('petr@example.cz'), 'a full event refuses another registration on the server');

        $full = $this->visitor()->get('/akce-test/joga');
        $this->assertStringContainsString('Akce je plně obsazená.', $full->body, 'the page of a full event shows it is full');
        $this->assertStringNotContainsString('class="ka-formular"', $full->body, '... instead of the form');

        $items = $this->mcpText('list_collection_items', ['collection' => 'akce-test']);
        $this->assertStringContainsString('state":"full', $items, 'Claude sees that it is full');
        $this->assertStringContainsString('places_left":0', $items, '... with no places left');

        $past = $this->visitor()->get('/akce-test/minula');
        $this->assertStringContainsString('Akce už skončila.', $past->body, 'a past event says it has ended');
        $this->assertStringNotContainsString('class="ka-formular"', $past->body, '... and takes no registrations');
    }
}
