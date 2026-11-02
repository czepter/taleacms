<?php
/**
 * Talea - command line for containers (docker/). Configuration comes from TALEA_* environment variables, see docker/README.md.
 *
 *   php system/docker.php cron      run the background jobs that are due (what the /tasks address does for web cron), for every live site
 *   php system/docker.php provision set up the new sites (migrate, import an archive) now, without waiting for the next cron run
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
            // cron                         the install: owner 1's site 1, then its further sites and the further owners, each in a child process
            // cron <site prefix>           a further site of owner 1
            // cron <owner prefix> <prefix> a site of a further owner (the owner prefix again for its site 1: the owner-wide jobs run there)
            $owner = isset($argv[3]) ? $argv[2] : null;
            $prefix = $argv[3] ?? $argv[2] ?? null;
            $install = Talea\Core\Config::load()['db']['prefix'] ?? 'tl_';
            if ($owner !== null && (!in_array($owner, array_slice(Talea\Core\Owners::readyPrefixes(Talea\Core\Db::fromConfig(Talea\Core\Config::load()['db'])), 1), true) || Talea\Core\Owners::ownerOf($install, $prefix) !== $owner)) {
                throw new RuntimeException("No further owner with the table prefix {$owner} that is set up.");
            }
            $app = Talea\Core\App::boot($prefix, $owner);
            Talea\Core\ErrorReport::tag('area', 'cron');
            if ($prefix !== null && $prefix !== $app->db()->ownerPrefix && Talea\Core\Sites::transferRefusal($app->db(), Talea\Core\Sites::currentId($app->db())) !== null) {
                fwrite(STDOUT, "[{$prefix}] skipped: the site is being moved to another owner\n"); // its jobs would write what the copy then loses
                break;
            }
            $sites = Talea\Core\Sites::all($app->db());
            $ownerRun = $prefix === null || $prefix === $app->db()->ownerPrefix; // the owner's site 1: the owner-wide jobs, new sites, the children
            if ($prefix !== null && !$ownerRun && !in_array($prefix, array_slice(array_column($sites, 'prefix'), 1), true)) {
                throw new RuntimeException("No further site with the table prefix {$prefix}.");
            }
            $app->request->setOrigin($app->settings()->get('site_url'));
            $app->applyTimezone();
            $app->settings()->set('tasks_last_run', (string) time()); // newsletters are sent only while cron runs
            foreach (Talea\Core\Scheduler::run($app, 'cron', 50.0) as $job => $result) {
                fwrite(STDOUT, ($prefix !== null ? "[{$prefix}] " : '') . "{$job}: {$result}\n");
            }
            if ($ownerRun && Talea\Core\Sites::enabled()) {
                // new owners (the install's cron only), then new sites: their tables are made here, never on a page request (Admin\Modules\Sites only inserts the row)
                if ($owner === null && Talea\Core\Owners::enabled()) {
                    foreach (Talea\Core\Owners::provisionPending($app->db(), $app->config['db']) as $line) {
                        fwrite(STDOUT, $line . "\n");
                    }
                }
                foreach (Talea\Core\Sites::provisionPending($app->db(), $app->config['db']) as $line) {
                    fwrite(STDOUT, $line . "\n");
                }
                if ($owner === null && ($added = Talea\Core\Domains::ensurePreviews($app->db())) > 0) {
                    fwrite(STDOUT, "preview hosts added: {$added}\n"); // sites that existed before TALEA_PREVIEW_DOMAIN was set, and the first site of a new owner
                }
                $ready = Talea\Core\Sites::readyPrefixes($app->db());
            }
            if ($ownerRun) {
                // multisite: every further live site in its own process, so no static state crosses from one site to another
                $children = [];
                foreach (array_slice(Talea\Core\Sites::all($app->db()), 1) as $site) {
                    if ($site['status'] === 'live' && in_array($site['prefix'], $ready ?? array_column($sites, 'prefix'), true)) {
                        $children[] = ($owner !== null ? escapeshellarg($owner) . ' ' : '') . escapeshellarg($site['prefix']);
                    }
                }
                // a further owner: its site 1 first (the owner-wide jobs and its new sites), once per owner; a paused or archived owner runs nothing
                if ($owner === null && Talea\Core\Owners::enabled()) {
                    foreach (array_slice(Talea\Core\Owners::all($app->db()), 1) as $o) {
                        if ($o['status'] === 'live' && $o['ready_at'] !== null) {
                            $children[] = escapeshellarg((string) $o['prefix']) . ' ' . escapeshellarg((string) $o['prefix']);
                        }
                    }
                }
                foreach ($children as $args) {
                    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cron ' . $args);
                }
            }
            break;
        case 'provision':
            // started right after an archive is uploaded (Core\Background); cron does the same on its next run if this process could not start
            if (Talea\Core\Config::load() === null || !Talea\Core\Sites::enabled()) {
                break;
            }
            $app = Talea\Core\App::boot();
            Talea\Core\ErrorReport::tag('area', 'cron');
            $end = time() + 4 * 3600; // ponytail: one process for up to four hours; what is left waits for cron
            do {
                $more = false;
                foreach (Talea\Core\Sites::provisionPending($app->db(), $app->config['db']) as $line) {
                    fwrite(STDOUT, $line . "\n");
                    $more = $more || preg_match('/: importing \d+ %$/', $line) === 1; // an import goes on in batches: the next round continues it ("busy" = another process has it)
                }
            } while ($more && time() < $end);
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
