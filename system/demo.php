<?php
/**
 * Talea – the public demo (2.6). Only from the command line, only on a site with 'demo' in config.php.
 *
 *   php system/demo.php snapshot   save the current database and media as the state to return to
 *   php system/demo.php reset      return to the snapshot (cron, every hour: 0 * * * * php /path/system/demo.php reset)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['snapshot', 'reset'], true)) {
    fwrite(STDERR, "Usage: php system/demo.php snapshot | reset\n");
    exit(1);
}
$app = Talea\Core\App::boot();
if (!Talea\Core\Demo::active()) {
    fwrite(STDERR, "This site is not a demo: add 'demo' => ['user' => …, 'password' => …] to config.php.\n");
    exit(1);
}
try {
    $command === 'snapshot' ? Talea\Core\Demo::snapshot($app->db()) : Talea\Core\Demo::reset($app->db());
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, ($command === 'snapshot' ? 'Snapshot saved' : 'Demo reset') . ' at ' . date('Y-m-d H:i:s') . "\n");
