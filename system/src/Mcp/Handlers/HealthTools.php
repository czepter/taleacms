<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Core\Backup;
use Talea\Core\Events;
use Talea\Core\Health;
use Talea\Core\Language;
use Talea\Core\Scheduler;

/**
 * MCP tools for a site that runs itself (2.8): its health and what happened on it. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait HealthTools
{
    /** get_health */
    private function toolGetHealth(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The health of the site is for administrators.');
        }
        $db = $this->app->db();
        $checks = Language::runWith('en', fn (): array => Health::checks($this->app));
        $backup = Backup::listAll()[0] ?? null;
        $cron = $this->app->settings()->int('tasks_last_run');

        return [
            'status' => ['ok' => 'ok', 'warning' => 'warning', 'error' => 'error'][Health::summary($checks)],
            'talea_version' => TALEA_VERSION,
            'problems' => array_values(array_map(fn (array $c): array => ['group' => $c['group'], 'check' => $c['name'], 'status' => $c['status'] === 'error' ? 'error' : 'warning', 'detail' => strip_tags((string) $c['info'])],
                array_filter($checks, fn (array $c): bool => $c['status'] !== 'ok'))),
            'jobs' => array_map(fn (array $j): array => ['job' => $j['name'], 'last_run' => $j['last_run'], 'failures_in_a_row' => $j['failures'], 'last_error' => $j['last_error'] !== '' ? $j['last_error'] : null], Scheduler::overview($db, $this->app->settings())),
            'cron_last_run_minutes' => $cron > 0 ? (int) floor((time() - $cron) / 60) : null,
            'last_backup' => $backup !== null ? date('Y-m-d H:i', (int) $backup['time']) : null,
            'events_last_7_days' => Events::problems($db, 168),
            'next' => 'Fix what you can (e.g. with update_settings or the site audit) and tell the user what needs them: hosting, DNS or a password are theirs. list_events shows what happened.',
        ];
    }

    /** list_events */
    private function toolListEvents(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The events of the site are for administrators.');
        }
        $types = array_values(array_filter(array_map(fn ($t): string => trim((string) $t), is_array($a['types'] ?? null) ? $a['types'] : []), fn (string $t): bool => preg_match('/^[a-z_]+(\.[a-z_]*)?$/', $t) === 1));
        $severity = in_array($a['min_severity'] ?? 'info', Events::SEVERITIES, true) ? (string) ($a['min_severity'] ?? 'info') : 'info';
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $since = (int) ($a['since_id'] ?? 0);
        $db = $this->app->db();
        if ($since <= 0 && ($a['days'] ?? null) !== null) {
            $since = (int) $db->value('SELECT COALESCE(MAX(id), 0) FROM {events} WHERE created_at < NOW() - INTERVAL ? DAY', [max(1, min(180, (int) $a['days']))]);
        }
        $events = Events::since($db, $since, $types, $limit, $severity);

        return ['events' => $events, 'next_since_id' => $events !== [] ? end($events)['id'] : $since, 'more' => count($events) === $limit,
            'types' => array_keys(Events::TYPES)];
    }
}
