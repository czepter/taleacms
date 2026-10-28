<?php
/**
 * Talea - command line for containers (docker/). Configuration comes from TALEA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php cron      run the background jobs that are due (what the /tasks address does for web cron)
 *   php system/docker.php site-url  store TALEA_SITE_URL as the site address when it differs (the entrypoint, on every start)
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
        case 'site-url':
            // TALEA_SITE_URL wins over the stored address (Core\SiteUrl); not set or not installed yet = nothing to do
            $url = Talea\Core\SiteUrl::fromEnvironment();
            if ($url === '' || Talea\Core\Config::load() === null) {
                break;
            }
            if (Talea\Admin\Modules\Settings::verifyValue('site_url', $url) === null) {
                fwrite(STDERR, "TALEA_SITE_URL must be an address like https://example.com (scheme and host, no path); the site address stays as it is.\n");
                exit(1);
            }
            $app = Talea\Core\App::boot();
            fwrite(STDOUT, Talea\Core\SiteUrl::change($app, $url, 'TALEA_SITE_URL') ? "Site address changed to {$url}.\n" : "Site address is {$url}.\n");
            break;
        default:
            fwrite(STDERR, "Usage: php system/docker.php cron|site-url\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
