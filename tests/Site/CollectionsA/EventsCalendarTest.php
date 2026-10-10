<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.11 events calendar (was: section 57 of tools/test.sh). Needs from section 56 only a collection that is not a calendar
 * (field-types), created here.
 */
#[Group('site')]
final class EventsCalendarTest extends SiteTestCase
{
    use CollectionsHelpers;

    private static string $idk = '';

    public function testPresetCreatesTheCalendar(): void
    {
        $this->mcpText('create_collection', ['name' => 'Field types', 'slug' => 'field-types', 'item_pages' => true, 'fields' => [['label' => 'Start', 'type' => 'datetime']]]);
        $text = $this->mcpText('create_collection', ['name' => 'Events test', 'preset' => 'events']);
        self::$idk = $this->sq("SELECT collection_id FROM tl_collections WHERE slug = 'events-test' AND preset = 'events'");

        $this->assertNotSame('', self::$idk, 'events: the preset creates the calendar');
        $this->assertStringContainsString('list_page', $text, 'events: ... and its list page');
        $this->assertSame('5|1|1', $this->sq("SELECT CONCAT(JSON_LENGTH(JSON_EXTRACT(fields, '\$[12].options')), '|', build LIKE '%\"type\":\"form\"%', '|', build LIKE '%{{ical}}%') FROM tl_collections WHERE collection_id = " . self::$idk), 'the repetition is a choice of known options and the item template has the registration form');
    }

    public function testRepetitionOutsideTheOptionsIsRefusedAndTheJobMovesAnEndedWeeklyEvent(): void
    {
        $tomorrow = $this->siteDate('tomorrow');
        $eightAgo = $this->siteDate('-8 days');
        $sixAhead = $this->siteDate('+6 days');
        $yesterday = $this->siteDate('yesterday');
        $this->mcpText('save_collection_item', ['collection' => 'events-test', 'name' => 'Yoga, for beginners', 'slug' => 'yoga', 'values' => [
            'start' => "$tomorrow 18:00", 'end' => "$tomorrow 19:30", 'venue' => 'Hall', 'address' => 'Main Street 1, Brno', 'capacity' => '1', 'repeat' => 'weekly', 'summary' => 'Bring a mat.'], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'events-test', 'name' => 'Past lecture', 'slug' => 'past', 'values' => ['start' => "$yesterday 10:00"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'events-test', 'name' => 'Series', 'slug' => 'series', 'values' => ['start' => "$eightAgo 18:00", 'end' => "$eightAgo 19:30", 'repeat' => 'weekly'], 'visible' => true]);
        $refused = $this->mcpText('save_collection_item', ['collection' => 'events-test', 'name' => 'Nonsense', 'slug' => 'nonsense', 'values' => ['repeat' => 'every Tuesday']]);
        $this->assertMatchesRegularExpression('/invalid_fields.*repeat/s', $refused, 'a repetition outside the options is refused');
        $this->assertSame('', $this->sq("SELECT data->>'\$.repeat' FROM tl_collection_items WHERE collection_id = " . self::$idk . " AND slug = 'nonsense'"), 'the refused item was not stored with the value');

        $this->site()->exec("INSERT INTO tl_jobs (name, last_run) VALUES ('events', NULL) ON DUPLICATE KEY UPDATE last_run = NULL");
        $tasks = $this->site()->runTasks();
        $this->assertSame("$sixAhead 18:00|$sixAhead 19:30", $this->sq("SELECT CONCAT(data->>'\$.start', '|', data->>'\$.end') FROM tl_collection_items WHERE collection_id = " . self::$idk . " AND slug = 'series'"), 'the job moves an ended weekly event to its next date, keeping the time');
        $this->assertStringContainsString('events: moved 1', $tasks, 'the job reports what it moved');
    }

    public function testListPageEventPageAndCalendarFiles(): void
    {
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'events-test'")), 'visible' => true]);
        $this->site()->clearPageCache();
        $list = $this->visitor()->get('/events-test');
        $this->assertStringContainsString('Yoga, for beginners', $list->body, 'the list page shows an upcoming event');
        $this->assertStringContainsString('Series', $list->body, '... the moved series');
        $this->assertStringNotContainsString('Past lecture', $list->body, '... and not the past one');
        $this->assertStringContainsString('Hall, Main Street 1, Brno', $list->body, '... with its place');

        $event = $this->visitor()->get('/events-test/yoga');
        $this->assertStringContainsString('"@type":"Event"', $event->body, 'the event page has Event data');
        $this->assertStringContainsString('OfflineEventAttendanceMode', $event->body);
        $this->assertMatchesRegularExpression('#href="[^"]*events-test/yoga.ics"#', $event->body, '... an Add to calendar link');
        $this->assertStringContainsString('class="tl-form"', $event->body, '... and the registration form');

        $ics = $this->visitor()->get('/events-test.ics');
        $this->assertStringContainsStringIgnoringCase('text/calendar', $ics->headers['content-type'] ?? '', '/<collection>.ics is a calendar');
        $this->assertStringContainsString('SUMMARY:Yoga\, for beginners', $ics->body, 'the calendar has the summary');
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY', $ics->body, '... the repetition');
        $this->assertStringContainsString('LOCATION:Hall\, Main Street 1\, Brno', $ics->body, '... and the place');

        $one = $this->visitor()->get('/events-test/yoga.ics');
        $this->assertSame('attachment; filename="yoga.ics"', strtolower($one->headers['content-disposition'] ?? ''), 'one event as a file to add');
        $this->assertSame(1, substr_count($one->body, 'BEGIN:VEVENT'), 'one event in the file');

        $this->assertSame(404, $this->visitor()->get('/field-types.ics')->status, 'a collection that is not a calendar has no .ics');
    }

    /** Capacity 1: the first registration fills it, the form closes and the server refuses another one. */
    public function testRegistrationFillsTheEvent(): void
    {
        $visitor = $this->visitor();
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $page = $visitor->get('/events-test/yoga');
        $fields = ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/events-test/yoga', 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'), 'p0' => 'Eva', 'p4' => '1'];
        sleep(4); // the form cannot be sent sooner than a few seconds after it was drawn (Core\Antispam), as the old script waited
        $register = static fn (string $email): string => $visitor->post('/form', $fields + ['p1' => $email])->redirect;

        $this->assertStringContainsString('result=ok', $register('eva@example.cz'), 'a registration is accepted');
        $this->assertSame('collection:' . self::$idk . '|/events-test/yoga', $this->sq('SELECT CONCAT(source, \'|\', page) FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), "the registration is an enquiry from the event's page");
        $this->assertStringContainsString('result=full', $register('petr@example.cz'), 'a full event refuses another registration on the server');

        $full = $this->visitor()->get('/events-test/yoga');
        $this->assertStringContainsString('This event is fully booked.', $full->body, 'the page of a full event shows it is full');
        $this->assertStringNotContainsString('class="tl-form"', $full->body, '... instead of the form');

        $items = $this->mcpText('list_collection_items', ['collection' => 'events-test']);
        $this->assertStringContainsString('state":"full', $items, 'Claude sees that it is full');
        $this->assertStringContainsString('places_left":0', $items, '... with no places left');

        $past = $this->visitor()->get('/events-test/past');
        $this->assertStringContainsString('This event has ended.', $past->body, 'a past event says it has ended');
        $this->assertStringNotContainsString('class="tl-form"', $past->body, '... and takes no registrations');
    }
}
