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

/**
 * What a site tells its console (2.9): version, health, background jobs, backups, enquiries waiting for an answer,
 * visits, the site audit and errors, and for the console's inventory the database engine, the web server family, the features
 * switched on and the languages. Counts, states and system names only – never names, e-mail addresses or the content of enquiries.
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
            'enquiries_unanswered' => Extensions::isEnabled($s, 'enquiries') ? (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE status = 0 AND deleted_at IS NULL') : null,
            'enquiries_7_days' => Extensions::isEnabled($s, 'enquiries') ? (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE created_at > NOW() - INTERVAL 7 DAY AND deleted_at IS NULL') : null,
            'visits_7_days' => \Talea\Front\Stats::enabled($s) ? (int) $db->value('SELECT COALESCE(SUM(visits), 0) FROM {stats_days} WHERE day > CURRENT_DATE - INTERVAL 7 DAY') : null,
            'audit' => $audit,
            'problems_7_days' => Events::problems($db, 168),
            'claude' => Extensions::isEnabled($s, 'claude'),
            'kit_version' => $s->int('fleet_kit_version') ?: null, // the shared design kit this site applied (2.16, Fleet\Kit)
            'error_reporting' => \Talea\Core\ErrorReport::enabled(), // errors go to Sentry or GlitchTip (TALEA_ERROR_DSN); the host is not told
            // the inventory (#341): an older console drops these keys, an older site never sends them (the console reads them as null)
            'database' => self::database((string) $db->value('SELECT VERSION()')),
            'web_server' => self::webServer($s),
            'extensions' => Extensions::enabled($s),
            'languages' => array_values(array_unique([Language::defaults($s), ...Language::additional($s)])),
        ];
    }

    /** The engine and its major.minor version ("MariaDB 11.4"); '' when the server's version text holds no number. Pure. */
    public static function database(string $version): string
    {
        if (preg_match('/(\d+)\.(\d+)/', $version, $m) !== 1) {
            return '';
        }
        $engine = match (true) {
            str_starts_with($version, 'SQLite') => 'SQLite',
            stripos($version, 'mariadb') !== false => 'MariaDB',
            stripos($version, 'postgres') !== false => 'PostgreSQL',
            default => 'MySQL',
        };

        return $engine . ' ' . $m[1] . '.' . $m[2];
    }

    /** The web server family from SERVER_SOFTWARE, else from the SAPI; '' when neither says. Never a version. Pure. */
    public static function serverFamily(string $software, string $sapi): string
    {
        $software = strtolower($software);
        foreach (['frankenphp' => 'FrankenPHP', 'caddy' => 'Caddy', 'litespeed' => 'LiteSpeed', 'nginx' => 'nginx', 'apache' => 'Apache', 'lighttpd' => 'lighttpd',
            'microsoft-iis' => 'IIS', 'development server' => 'PHP built-in server'] as $needle => $family) {
            if (str_contains($software, $needle)) {
                return $family;
            }
        }

        return ['frankenphp' => 'FrankenPHP', 'apache2handler' => 'Apache', 'litespeed' => 'LiteSpeed', 'cli-server' => 'PHP built-in server'][$sapi] ?? '';
    }

    /**
     * The web server family. A heartbeat sent by the command-line cron (the container) cannot see the web server, so it reports the
     * family last seen on a web request: pairing, "Send now" and the heartbeats that visits trigger are web requests.
     */
    private static function webServer(\Talea\Core\Settings $s): ?string
    {
        $seen = self::serverFamily((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), PHP_SAPI);
        if ($seen !== '' && $seen !== $s->get('fleet_web_server')) {
            $s->set('fleet_web_server', $seen);
        }

        return $seen !== '' ? $seen : ($s->get('fleet_web_server') ?: null);
    }
}
