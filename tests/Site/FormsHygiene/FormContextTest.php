<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Forms that know where they are, thank-you with next steps (was: section 72). The events calendar of section 57 is recreated here. */
#[Group('site')]
final class FormContextTest extends SiteTestCase
{
    use McpHelpers;

    private static int $eventsCollection = 0;
    private static int $page = 0;
    private static int $enquiry = 0;
    /** @var array<string, string> */
    private static array $eventForm = [];
    /** @var array<string, string> */
    private static array $pageForm = [];

    public function testARegistrationFromAnEventPageRecordsTheCalendarAndTheEvent(): void
    {
        // section 57: an events calendar with one event on which the registration form sits
        $this->mcpText('create_collection', ['name' => 'Events test', 'preset' => 'events']);
        self::$eventsCollection = (int) $this->site()->value("SELECT collection_id FROM ka_collections WHERE slug = 'events-test' AND preset = 'events'");
        $this->assertGreaterThan(0, self::$eventsCollection, 'the events preset creates the calendar');
        $tomorrow = date('Y-m-d', strtotime('+2 days'));
        $this->mcpText('save_collection_item', ['collection' => 'events-test', 'name' => 'Yoga, for beginners', 'slug' => 'yoga',
            'values' => ['start' => "$tomorrow 18:00", 'end' => "$tomorrow 19:30", 'venue' => 'Hall', 'address' => 'Main Street 1, Brno', 'capacity' => '1', 'summary' => 'Bring a mat.'], 'visible' => true]);

        // section 72: a form on an ordinary page with the next steps
        self::$page = $this->createPage(['title' => 'Bathrooms F7', 'visible' => true]);
        $this->mcpText('save_build', ['id' => self::$page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Enquiry F7', 'fields' => [['label' => 'Email', 'type' => 'email', 'required' => true]],
            'next_steps' => "We will call you\nWe will visit to measure", 'reply_within_hours' => 4, 'who_replies' => 'Jane from the office']]]]]]]);
        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");
        $this->site()->clearPageCache();

        $visitor = $this->site()->client();
        foreach (['eventForm' => '/events-test/yoga', 'pageForm' => '/bathrooms-f7'] as $property => $path) {
            $form = $visitor->get($path);
            self::${$property} = ['source' => $form->field('source'), 'element' => $form->field('element'), 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature')];
        }
        sleep(4); // the antispam does not accept a form sent sooner than four seconds

        $registration = $visitor->post('/form', self::$eventForm + ['back' => '/events-test/yoga', 'p0' => 'Eva', 'p1' => 'eva@example.com', 'p4' => '1']);
        $this->assertStringContainsString('result=ok', $registration->redirect, 'the registration was accepted');
        $this->assertSame('Events test – Yoga, for beginners', (string) $this->site()->value('SELECT topic FROM ka_enquiries WHERE source = ? ORDER BY enquiry_id LIMIT 1', ['collection:' . self::$eventsCollection]),
            "topic: a registration from an event's page records the calendar and the event");
    }

    public function testTheFormKeepsTheNextStepsTheWorkingHoursAndWhoReplies(): void
    {
        $this->assertSame('1|1|1', (string) $this->site()->value("SELECT CONCAT(build LIKE '%\"next_steps\":\"We will c%', '|', build LIKE '%\"reply_within_hours\":4%', '|', build LIKE '%\"who_replies\":\"Jane from the off%') FROM ka_pages WHERE page_id = ?", [self::$page]),
            'next steps: the form keeps the steps, the working hours and who replies');
    }

    public function testTheTopicOnAPageIsThePageTitleWhateverWasPosted(): void
    {
        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");
        $result = $this->site()->client()->post('/form', self::$pageForm + ['back' => '/bathrooms-f7', 'p0' => 'f7@example.com', 'topic' => 'Forged', 'about' => 'Forged']);
        $this->assertStringContainsString('result=ok', $result->redirect, 'topic: the form on the page was sent');

        self::$enquiry = (int) $this->site()->value("SELECT MAX(enquiry_id) FROM ka_enquiries WHERE source = ?", ['page:' . self::$page]);
        $this->assertSame('Bathrooms F7', (string) $this->site()->value('SELECT topic FROM ka_enquiries WHERE enquiry_id = ?', [self::$enquiry]), 'topic: on a page the topic is the page title – what was posted for it is ignored');
    }

    public function testTheTopicIsShownInTheEnquiriesAdminAndOverMcp(): void
    {
        $this->assertPage('/admin.php?module=enquiries', 200, 'Topic: <a href="/bathrooms-f7"', message: 'topic: the Enquiries list shows it with a link to the page');
        $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . self::$enquiry, 200, '<dt>Topic</dt><dd><a href="/bathrooms-f7"', message: 'topic: the enquiry detail shows it');

        $list = $this->site()->mcpResult('list_enquiries', ['limit' => 1]);
        $this->assertSame('Bathrooms F7', $list[0]['about'] ?? null, 'MCP: list_enquiries has about');
    }

    public function testTheThankYouListsTheStepsTheDeadlineAndWhoReplies(): void
    {
        $response = $this->assertPage('/bathrooms-f7?form=' . self::$pageForm['element'] . '&result=ok', 200,
            '<ol class="ka-steps"><li>We will call you</li><li>We will visit to measure</li></ol>', message: 'next steps: the thank-you lists the steps');

        $this->assertTrue($response->matches('#class="ka-steps-deadline">We will reply .*by [0-9]*:[0-9][0-9]\.</p>#'), 'next steps: the thank-you says by when');
        $this->assertTrue($response->contains('<p class="ka-steps-who">Jane from the office will reply.</p>'), 'next steps: the thank-you says who replies');
    }
}
