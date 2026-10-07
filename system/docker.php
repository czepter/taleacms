<?php
/**
 * Kaleta - command line for containers (docker/). Configuration comes from KALETA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php install   create the tables and the first administrator unless they exist (runs at every container start)
 *   php system/docker.php cron      run the background jobs that are due (what the /ulohy address does for web cron)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    switch ($argv[1] ?? '') {
        case 'install':
            fwrite(STDOUT, (new Kaleta\Install\Installer())->installFromEnv() . "\n");
            break;
        case 'cron':
            $app = Kaleta\Core\App::boot();
            $app->request->setOrigin($app->settings()->get('site_url'));
            $app->applyTimezone();
            $app->settings()->set('tasks_last_run', (string) time()); // newsletters are sent only while cron runs
            foreach (Kaleta\Core\Scheduler::run($app, 'cron', 50.0) as $job => $result) {
                fwrite(STDOUT, "{$job}: {$result}\n");
            }
            break;
        default:
            fwrite(STDERR, "Usage: php system/docker.php install | cron\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
