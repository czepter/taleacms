<?php

/**
 * Phinx configuration for the command line: `php vendor/bin/phinx status|migrate|rollback|create Name`.
 * The connection is the site's own (KALETA_DB_* environment variables or config.php); Core\Migrator builds the rest.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

use Kaleta\Core\Config;
use Kaleta\Core\Migrator;

$config = Config::fromEnv() ? Config::fromEnvironment() : (is_file(KALETA_ROOT . '/config.php') ? require KALETA_ROOT . '/config.php' : null);
if (!is_array($config) || !isset($config['db'])) {
    fwrite(STDERR, "No database configuration: set KALETA_DB_NAME (and the other KALETA_DB_*) or create config.php.\n");
    exit(1);
}

return Migrator::phinxConfig($config['db']);
