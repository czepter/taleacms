<?php
/**
 * Talea - command line for containers (docker/). Configuration comes from TALEA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php cron      run the background jobs that are due (what the /tasks address does for web cron), for every live site
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    switch ($argv[1] ?? '') {
        case 'cron':
            if (Talea\Core\Config::load() === null) {
                fwrite(STDOUT, "Not installed yet (open the site and run the installer).\n");
                break;
            }
            $prefix = $argv[2] ?? null;
            $app = Talea\Core\App::boot($prefix);
            $sites = Talea\Core\Sites::all($app->db());
            if ($prefix !== null && !in_array($prefix, array_slice(array_column($sites, 'prefix'), 1), true)) {
                throw new RuntimeException("No further site with the table prefix {$prefix}.");
            }
            $app->request->setOrigin($app->settings()->get('site_url'));
            $app->applyTimezone();
            $app->settings()->set('tasks_last_run', (string) time()); // newsletters are sent only while cron runs
            foreach (Talea\Core\Scheduler::run($app, 'cron', 50.0) as $job => $result) {
                fwrite(STDOUT, ($prefix !== null ? "[{$prefix}] " : '') . "{$job}: {$result}\n");
            }
            if ($prefix === null) {
                // multisite: every further live site in its own process, so no static state crosses from one site to another
                foreach (array_slice($sites, 1) as $site) {
                    if ($site['status'] === 'live') {
                        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cron ' . escapeshellarg($site['prefix']));
                    }
                }
            }
            break;
        default:
            fwrite(STDERR, "Usage: php system/docker.php cron\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
