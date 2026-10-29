<?php
/**
 * Talea - command line for containers (docker/). Configuration comes from TALEA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php cron      run the background jobs that are due (what the /tasks address does for web cron)
 *   php system/docker.php site-url  store TALEA_SITE_URL as the site address when it differs (the entrypoint, on every start)
 *   php system/docker.php error-test send a test event to the error tracking (TALEA_ERROR_DSN, Core\ErrorReport)
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
            Talea\Core\ErrorReport::tag('area', 'cron');
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
        case 'error-test':
            // the operator's first step after setting the DSN: one event, sent now, with the answer of the tracker
            Talea\Core\ErrorReport::configure(Talea\Core\Config::load() ?? []);
            if (!Talea\Core\ErrorReport::enabled()) {
                fwrite(STDERR, "Error reporting is off: set TALEA_ERROR_DSN to the DSN of a Sentry or GlitchTip project.\n");
                exit(1);
            }
            Talea\Core\ErrorReport::tag('area', 'cli');
            Talea\Core\ErrorReport::message('Talea test event: error reporting works.', 'info', ['force' => true, 'tags' => ['test' => 'yes']]);
            $result = Talea\Core\ErrorReport::flush()[0] ?? ['id' => '', 'ok' => false, 'error' => 'nothing was sent'];
            if (!$result['ok']) {
                fwrite(STDERR, 'The test event did not arrive at ' . Talea\Core\ErrorReport::host() . ': ' . $result['error'] . "\n");
                exit(1);
            }
            fwrite(STDOUT, 'Test event ' . $result['id'] . ' sent to ' . Talea\Core\ErrorReport::host() . ".\n");
            break;
        default:
            fwrite(STDERR, "Usage: php system/docker.php cron|site-url|error-test\n");
            exit(1);
    }
} catch (Throwable $e) {
    Talea\Core\ErrorReport::log($e, Talea\Core\ErrorReport::capture($e, 'error', ['handled' => false])); // sent by the shutdown function
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
