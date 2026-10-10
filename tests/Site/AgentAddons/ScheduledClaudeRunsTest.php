<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Scheduled Claude runs, Core\AgentSchedules (was: section 93, 2.17): the site keeps the schedule, a routine in Claude does the runs as
 * drafts. Uses the drafts-only token of section 44 (see AgentHelpers). The tests run in order.
 */
#[Group('site')]
final class ScheduledClaudeRunsTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $schedule = 0;
    private static int $run = 0;

    public function testThePanelHasTheRoutinePromptAndASavedScheduleIsDueOnMonday(): void
    {
        $base = $this->site()->base;
        $page = $this->site()->admin()->get('/admin.php?module=schedules');
        $this->assertStringContainsString('get_due_agent_runs', $page->body, 'schedules: the Set up in Claude panel has the routine prompt');
        $this->assertStringContainsString("$base/mcp", $page->body, 'schedules: the panel has the MCP address');
        $this->assertStringContainsString('action=account#claude', $page->body, 'schedules: the panel links the tokens');

        $csrf = $page->csrf();
        $admin = $this->site()->admin();
        $admin->post('/admin.php?module=schedules&action=save', ['_csrf' => $csrf, 'id' => 0, 'name' => 'Weekly review', 'task' => 'review', 'text' => 'Only the Services pages.', 'cadence' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'time' => '07:00', 'active' => 1]);
        self::$schedule = (int) $this->sq("SELECT id FROM tl_agent_schedules WHERE name = 'Weekly review'");
        $this->assertSame('1|weekly|1|07:00|1|2|07:00:00', $this->sq("SELECT CONCAT(active, '|', cadence, '|', day, '|', time, '|', next_due > NOW(), '|', DAYOFWEEK(next_due), '|', TIME(next_due)) FROM tl_agent_schedules WHERE id = ?", [self::$schedule]), 'schedules: saved, active, next due the coming Monday 07:00');

        $admin->post('/admin.php?module=schedules&action=save', ['_csrf' => $csrf, 'id' => 0, 'name' => 'Bad', 'task' => 'custom', 'text' => '', 'cadence' => 'monthly', 'weekday' => 1, 'monthday' => 31, 'time' => '07:00', 'active' => 1]);
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_agent_schedules WHERE name = 'Bad'"), 'schedules: custom instructions without a text are refused');
    }

    public function testADueScheduleIsHandedOutOnce(): void
    {
        $token = $this->draftsToken();
        $this->assertSame('0', $this->pick($this->mcpData('get_due_agent_runs', [], $token), 'count'), 'schedules: nothing due - the drafts-only connection gets an empty list');

        $this->site()->exec('UPDATE tl_agent_schedules SET next_due = NOW() - INTERVAL 1 HOUR WHERE id = ?', [self::$schedule]);
        $data = $this->mcpData('get_due_agent_runs', [], $token);
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::$run = (int) $this->pick($data, 'runs', 0, 'id');
        $this->assertStringContainsString('"name":"Weekly review"', (string) $text, 'schedules: the run carries the schedule name');
        $this->assertStringContainsString('Run site_audit', (string) $text, 'schedules: the task text');
        $this->assertStringContainsString('Also: Only the Services pages.', (string) $text, 'schedules: the administrator\'s extra');
        $this->assertStringContainsString('never publish', (string) $text, 'schedules: the rules');
        $this->assertStringContainsString('"connection":"drafts only', (string) $text, 'schedules: the connection');

        $again = $this->mcpData('get_due_agent_runs', [], $token);
        $this->assertSame(self::$run . '|1|running|Claude drafts', $this->pick($again, 'runs', 0, 'id') . '|' . $this->sq("SELECT CONCAT(COUNT(*), '|', MAX(status), '|', MAX(connection)) FROM tl_agent_runs WHERE schedule_id = ?", [self::$schedule]), 'schedules: a second call returns the same open run - one row, running, the connection remembered');
    }

    public function testTheRunIsReportedOnceAndTheScheduleMovesOn(): void
    {
        $base = $this->site()->base;
        $token = $this->draftsToken();

        $report = $this->mcpText('report_agent_run', ['id' => self::$run, 'status' => 'ok', 'summary' => 'Audit clean, two descriptions drafted.', 'links' => [['label' => 'Services – draft', 'url' => "$base/sluzby"], ['url' => 'javascript:alert(1)']]], $token);
        $this->assertStringContainsString('"status":"ok"', $report, 'schedules: report_agent_run finishes the run');
        $this->assertStringContainsString('"next_due":"', $report, 'schedules: and tells the next due time');

        $this->assertSame(
            'ok|1|1|1|1|2|07:00:00',
            $this->sq("SELECT CONCAT(r.status, '|', r.finished_at IS NOT NULL, '|', r.links LIKE '%$base/sluzby%' AND r.links NOT LIKE '%javascript%', '|', s.last_run_at IS NOT NULL, '|', s.next_due > NOW() AND s.next_due <= NOW() + INTERVAL 7 DAY, '|', DAYOFWEEK(s.next_due), '|', TIME(s.next_due)) FROM tl_agent_runs r JOIN tl_agent_schedules s ON s.id = r.schedule_id WHERE r.id = ?", [self::$run]),
            'schedules: the run is ok with the web link only, last_run_at set, next_due moved on to the next Monday 07:00',
        );
        $this->assertStringContainsString('already reported', $this->mcpRawText('report_agent_run', ['id' => self::$run, 'status' => 'ok', 'summary' => 'again'], $token), 'schedules: a run is reported once');
        $this->assertStringContainsString('can only save drafts', $this->mcpRawText('publish_build', ['id' => 1], $token), 'schedules: the same drafts-only connection cannot publish');
    }

    public function testAMissedRunIsMarkedAndWarnedAbout(): void
    {
        $this->site()->exec('UPDATE tl_agent_schedules SET next_due = NOW() - INTERVAL 7 HOUR WHERE id = ?', [self::$schedule]);
        $this->site()->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'agent_runs'");
        $this->assertStringContainsString('agent_runs: missed 1', $this->site()->runTasks(), 'schedules: the job reports the missed run');

        $id = self::$schedule;
        $this->assertSame(
            '1|1|1',
            $this->sq("SELECT CONCAT((SELECT COUNT(*) FROM tl_agent_runs WHERE schedule_id = $id AND status = 'missed'), '|', (SELECT next_due > NOW() FROM tl_agent_schedules WHERE id = $id), '|', (SELECT COUNT(*) FROM tl_events WHERE type = 'agent_run.missed' AND severity = 'warning' AND data LIKE '%\"schedule\":$id,%'))"),
            'schedules: a missed run, next_due in the future, the event agent_run.missed as a warning with the schedule id',
        );
    }

    public function testTheAdminShowsTheStatusAndTheHistory(): void
    {
        $base = $this->site()->base;
        $this->assertPage('/admin.php?module=schedules', 200, 'Missed', message: 'schedules: the list shows the last run status');
        $history = $this->assertPage('/admin.php?module=schedules&action=history&id=' . self::$schedule, 200, 'Audit clean, two descriptions drafted.', message: 'schedules: the history shows the summary of the reported run');
        $this->assertStringContainsString("href=\"$base/sluzby\"", $history->body, 'schedules: the history links the draft');
        $this->assertStringNotContainsString('javascript:', $history->body, 'schedules: the bad link never got in');

        $list = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $this->draftsToken()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertSame(1, substr_count((string) $list, '"name":"report_agent_run"'), 'schedules: tools/list of a drafts-only connection offers report_agent_run (catalog: draft)');
    }
}
