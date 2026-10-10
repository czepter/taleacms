<?php

declare(strict_types=1);

namespace Talea\Tests\Site\JobsFleetFacts;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 48 "2.8 background jobs, events, alerts" (with the firewall) of tools/test.sh. */
#[Group('site')]
final class JobsTest extends SiteTestCase
{
    use Helpers;

    /** The old section ran after the contact form was sent and a page published: do both, so the events exist. */
    public function testEventsExistAfterAnEnquiryAndAPublication(): void
    {
        $site = $this->site();
        $site->setting('site_email', 'owner@example.test');
        $form = $site->client()->get('/contact');
        $source = $form->field('source');
        $element = $form->field('element');
        $this->assertNotSame('', $element, 'the contact page has an enquiry form');
        $time = (string) (time() - 10);
        $signature = hash_hmac('sha256', "form|$source|$element|$time", $site->settingValue('secret_key'));
        $location = $site->client()->post('/form', [
            'source' => $source, 'element' => $element, 'back' => '/contact', 'as_time' => $time, 'as_signature' => $signature,
            'p0' => 'Jane', 'p1' => 'jane@example.com', 'p2' => '', 'p3' => 'I want a custom kitchen.', 'p4' => '1',
        ])->redirect;
        $this->assertStringContainsString('result=ok', $location, 'the enquiry was accepted');

        $this->mcpText('create_page', ['title' => 'Jobs page', 'slug' => 'jobs-page', 'visible' => true, 'content' => '<p>x</p>']);
        $id = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'jobs-page'");
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $id), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'heading', 'content' => ['text' => 'Hi']]]]]]]);
        $this->assertGreaterThan(0, (int) $site->value("SELECT COUNT(*) FROM tl_events WHERE type = 'build.published'"), 'publishing recorded an event');
    }

    public function testCronEndpointRunsTheSchedulersJobs(): void
    {
        $output = $this->site()->runTasks();

        $this->assertStringContainsString('mail: sent', $output, '/tasks runs the mail job');
        $this->assertStringContainsString('cleanup: ok', $output, '/tasks runs the cleanup job');
        $this->sameValue('1:0', $this->site()->value('SELECT CONCAT(COUNT(*) > 5, \':\', SUM(failures)) FROM tl_jobs'), 'every job that ran is recorded with its result');
    }

    public function testEventsRecordEnquiriesAndPublishingWithoutTheSender(): void
    {
        $site = $this->site();
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) > 0 FROM tl_events WHERE type = 'enquiry.received'"), 'an enquiry event is recorded');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) > 0 FROM tl_events WHERE type = 'build.published'"), 'a publishing event is recorded');
        $this->assertStringNotContainsString('@', (string) $site->value("SELECT GROUP_CONCAT(data) FROM tl_events WHERE type = 'enquiry.received'"), 'the enquiry event carries no e-mail address');
        $this->assertPage('/admin.php?module=status', 200, 'alerts_email', message: 'System status lists the background jobs');
    }

    public function testAnErrorEventGoesOutAsOneAlertEmailAtMostAnHour(): void
    {
        $site = $this->site();
        $problems = "SELECT COUNT(*) FROM tl_mail WHERE subject LIKE '%problem%'";
        $site->exec("UPDATE tl_settings SET value = (SELECT COALESCE(MAX(id), 0) FROM tl_events) WHERE name = 'alerts_cursor'");
        $site->exec("UPDATE tl_settings SET value = '0' WHERE name = 'alerts_last_sent'");
        $site->exec("INSERT INTO tl_settings (name, value) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM tl_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tl_settings WHERE name = 'alerts_cursor')");
        $site->exec("INSERT INTO tl_events (created_at, type, severity, message) VALUES (NOW(), 'backup.failed', 'error', 'Test: the automatic backup failed')");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'alerts'");
        $site->runTasks();
        $this->sameValue('1', $site->value($problems), 'alerts: one e-mail with the error');

        $site->exec("INSERT INTO tl_events (created_at, type, severity, message) VALUES (NOW(), 'mail.failed', 'error', 'Test: second')");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'alerts'");
        $site->runTasks();
        $this->sameValue('1', $site->value($problems), 'alerts: at most one an hour');
    }

    public function testTheUpdateCheckNeedsTheOneTimeCode(): void
    {
        $site = $this->site();
        $this->assertSame(403, $site->client()->get('/tasks?probe=abc')->status, 'update check without the code is refused');
        $site->setting('update_probe', 'probe123');
        $version = trim($site->php('echo TALEA_VERSION;'));
        $this->assertNotSame('', $version);
        $this->assertSame("TALEA-PROBE $version", trim($site->client()->get('/tasks?probe=probe123')->body),'update check with the code answers the running version');
        $site->setting('update_probe', '');
    }

    public function testClaudeReadsHealthAndEvents(): void
    {
        $health = $this->mcpText('get_health');
        $this->assertStringContainsString('talea_version', $health, 'get_health: version');
        $this->assertStringContainsString('alerts', $health, 'get_health: jobs');
        $this->assertStringContainsString('backup.failed', $health, 'get_health: the problems of the week');

        $events = $this->mcpText('list_events', ['types' => ['backup.'], 'min_severity' => 'error']);
        $this->assertStringContainsString('Test: the automatic backup failed', $events, 'list_events: filtered by type and severity');
        $this->assertStringContainsString('next_since_id', $events, 'list_events: with a cursor');
        $this->assertStringNotContainsString('"type":"enquiry.received"', $events, 'list_events: the other types are left out');
    }
}
