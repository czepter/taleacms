<?php

declare(strict_types=1);

namespace Talea\Fleet;

use Talea\Core\App;
use Talea\Core\Audit;
use Talea\Core\Backup;
use Talea\Core\Events;
use Talea\Core\Extensions;
use Talea\Core\Health;
use Talea\Core\Language;
use Talea\Core\Scheduler;
use Talea\Core\Updater;

/**
 * What a site tells its console (2.9): version, health, background jobs, backups, updates, enquiries waiting for an answer,
 * visits, the site audit and errors. Counts and states only – never names, e-mail addresses or the content of enquiries.
 * Texts are English (the console translates its own screens, not the site's sentences).
 */
final class Heartbeat
{
    /** @return array<string, mixed> */
    public static function build(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $checks = Language::runWith('en', fn (): array => Health::checks($app));
        $problems = array_values(array_map(fn (array $c): array => ['group' => $c['group'], 'check' => $c['name'], 'status' => $c['status'] === 'error' ? 'error' : 'warning',
            'detail' => mb_substr(trim(strip_tags((string) $c['info'])), 0, 200)], array_filter($checks, fn (array $c): bool => $c['status'] !== 'ok')));
        $backup = Backup::listAll()[0] ?? null;
        $update = (new Updater($s))->state();
        $rolledBack = $db->one("SELECT created_at, message FROM {events} WHERE type IN ('update.rolled_back', 'update.failed') ORDER BY id DESC LIMIT 1");
        $audit = [];
        try {
            foreach (Language::runWith('en', fn (): array => (new Audit($app))->run()) as $finding) {
                $audit[$finding['kind']] = ($audit[$finding['kind']] ?? 0) + 1;
            }
        } catch (\Throwable) {
            $audit = null; // the audit must never stop the heartbeat
        }

        return [
            'name' => $s->get('site_name'),
            'url' => rtrim($s->get('site_url'), '/'),
            'version' => TALEA_VERSION,
            'php' => PHP_VERSION,
            'schema_version' => (string) $db->value('SELECT MAX(version) FROM {migrations}'), // the newest applied migration (Phinx)
            'status' => ['ok' => 'ok', 'warning' => 'warning', 'error' => 'error'][Health::summary($checks)],
            'problems' => array_slice($problems, 0, 20),
            'jobs_failing' => array_values(array_map(fn (array $j): string => $j['name'], array_filter(Scheduler::overview($db, $s), fn (array $j): bool => $j['failures'] > 0))),
            'cron_last_run' => $s->int('tasks_last_run') ?: null,
            'last_backup' => $backup !== null ? (int) $backup['time'] : null,
            'offsite_backup' => $s->get('remote_backup') !== 'off',
            'update_available' => $update['available']['version'] ?? null,
            'update_problem' => $rolledBack !== null && strtotime((string) $rolledBack['created_at']) > time() - 14 * 86400 ? mb_substr((string) $rolledBack['message'], 0, 200) : null,
            'auto_updates' => $s->bool('auto_updates'),
            'enquiries_unanswered' => Extensions::isEnabled($s, 'enquiries') ? (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE status = 0') : null,
            'enquiries_7_days' => Extensions::isEnabled($s, 'enquiries') ? (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE created_at > NOW() - INTERVAL 7 DAY') : null,
            'visits_7_days' => \Talea\Front\Stats::enabled($s) ? (int) $db->value('SELECT COALESCE(SUM(visits), 0) FROM {stats_days} WHERE day > CURRENT_DATE - INTERVAL 7 DAY') : null,
            'audit' => $audit,
            'problems_7_days' => Events::problems($db, 168),
            'claude' => Extensions::isEnabled($s, 'claude'),
            'kit_version' => $s->int('fleet_kit_version') ?: null, // the shared design kit this site applied (2.16, Fleet\Kit)
        ];
    }
}
