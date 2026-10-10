<?php
/**
 * Talea - command line for containers (docker/). Configuration comes from TALEA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php cron      run the background jobs that are due (what the /tasks address does for web cron)
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
            $app = Talea\Core\App::boot();
            $app->request->setOrigin($app->settings()->get('site_url'));
            $app->applyTimezone();
            $app->settings()->set('tasks_last_run', (string) time()); // newsletters are sent only while cron runs
            foreach (Talea\Core\Scheduler::run($app, 'cron', 50.0) as $job => $result) {
                fwrite(STDOUT, "{$job}: {$result}\n");
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
