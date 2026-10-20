<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    /** 2.17: undo a whole Claude session (Core\AgentJournal) */
    public function testJournal(): void
    {
        $this->assertSame([true, true, false, false, false], [\Talea\Core\AgentJournal::journaled('pages'), \Talea\Core\AgentJournal::journaled('settings'),
            \Talea\Core\AgentJournal::journaled('change_log'), \Talea\Core\AgentJournal::journaled('api_tokens'), \Talea\Core\AgentJournal::journaled('agent_journal')], '2.17 AgentJournal: content tables are journaled, logs and security tables are not');
        $this->assertSame([true, false, true, false, 86400], [
            \Talea\Core\AgentJournal::same(['ids' => '5', 'title' => 'A', 'x' => null], ['ids' => 5, 'title' => 'A', 'x' => null]), \Talea\Core\AgentJournal::same(['ids' => '5'], ['ids' => '6']),
            \Talea\Core\AgentJournal::same(null, null), \Talea\Core\AgentJournal::same(null, ['ids' => 1]), \Talea\Core\Scheduler::JOBS['agent_journal'][0]], '2.17 AgentJournal::same – rows compare by value (the database gives strings, JSON numbers), a missing row only equals a missing row');
    }

    /** 2.17: scheduled Claude runs (Core\AgentSchedules) – next_due in the site's time zone */
    public function testSchedules(): void
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $due = fn (string $cadence, int $day, string $time, string $after): string => \Talea\Core\AgentSchedules::nextDue($cadence, $day, $time, new \DateTimeImmutable($after, $prague))->format('Y-m-d H:i P');
        $this->assertSame(['2026-01-31 08:00 +01:00', '2026-02-01 08:00 +01:00', '2027-01-01 08:00 +01:00'], [$due('daily', 1, '08:00', '2026-01-31 07:59'), $due('daily', 1, '08:00', '2026-01-31 08:00'), $due('daily', 1, '08:00', '2026-12-31 09:00')], '2.17 nextDue daily: later today, otherwise tomorrow – across a month end and a year end');
        $this->assertSame(['2026-03-02 07:00 +01:00', '2026-03-02 07:00 +01:00', '2026-03-09 07:00 +01:00', '2026-03-08 18:30 +01:00'], [$due('weekly', 1, '07:00', '2026-02-27 10:00'), $due('weekly', 1, '07:00', '2026-03-02 06:00'), $due('weekly', 1, '07:00', '2026-03-02 07:00'), $due('weekly', 7, '18:30', '2026-03-02 07:00')], '2.17 nextDue weekly: the ISO weekday this week when still ahead, otherwise next week – across a month end');
        $this->assertSame(['2026-02-28 06:00 +01:00', '2026-03-28 06:00 +01:00', '2027-01-01 09:00 +01:00', '2026-02-15 09:00 +01:00'], [$due('monthly', 28, '06:00', '2026-02-28 05:00'), $due('monthly', 28, '06:00', '2026-02-28 06:00'), $due('monthly', 1, '09:00', '2026-12-15 12:00'), $due('monthly', 15, '09:00', '2026-01-31 12:00')], '2.17 nextDue monthly: the day of month (1–28) this month when still ahead, otherwise next month – February, December');
        $this->assertSame(['2026-03-29 07:00 +02:00', '2026-10-25 07:00 +01:00', '2026-10-25 07:00 +01:00', '2026-03-29 07:00 +02:00'], [$due('daily', 1, '07:00', '2026-03-28 07:00'), $due('weekly', 7, '07:00', '2026-10-24 12:00'), $due('monthly', 25, '07:00', '2026-10-24 12:00'), $due('daily', 1, '7:00', '2026-03-28 07:30')], '2.17 nextDue keeps the wall-clock time across the daylight-saving changes of 2026 (29 March, 25 October)');
        // under English, as the MCP handler runs it – the admin dictionary in the test is Czech and would translate the task texts
        $instructions = fn (string $task, string $text = ''): string => \Talea\Core\Language::runWith('en', fn (): string => \Talea\Core\AgentSchedules::instructions(['task' => $task, 'text' => $text]));
        $this->assertSame([true, true, true, true, true, true, true, true], [...array_map(fn (string $task): bool => str_contains($instructions($task), 'never publish') && str_contains($instructions($task), 'report_agent_run'), array_keys(\Talea\Core\AgentSchedules::TASKS)),
                str_starts_with($instructions('custom', 'Check the prices.'), 'Check the prices.'), str_contains($instructions('review', 'Only Services.'), 'Also: Only Services.'), str_contains($instructions('review'), 'site_audit')], '2.17 every task text ends with the rules (drafts only, never publish, stop for a person); the administrator\'s text is the task for custom and an addition for the others');
        $this->assertSame([null, true, true, true, true, true, true], [\Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'weekly', 'day' => 1, 'time' => '07:00']), \Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'monthly', 'day' => 31, 'time' => '07:00']) !== null,
                \Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'custom', 'text' => '', 'cadence' => 'daily', 'day' => 1, 'time' => '07:00']) !== null, \Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'daily', 'day' => 1, 'time' => '7:00']) !== null,
                isset(\Talea\Core\Events::TYPES['agent_run.missed']), in_array('agent_run.missed', \Talea\Core\Alerts::WARNINGS, true), isset(\Talea\Core\Scheduler::JOBS['agent_runs'])], '2.17 validate: the day fits the cadence, the time is HH:MM, custom needs a text; the missed run is a known warning the alerts send');
    }
}
