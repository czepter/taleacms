<?php

/**
 * Phinx configuration for the command line: `php vendor/bin/phinx status|migrate|rollback|create Name`.
 * The connection is the site's own (TALEA_DB_* environment variables or config.php); Core\Migrator builds the rest.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

use Talea\Core\Config;
use Talea\Core\Migrator;

$config = Config::fromEnv() ? Config::fromEnvironment() : (is_file(TALEA_ROOT . '/config.php') ? require TALEA_ROOT . '/config.php' : null);
if (!is_array($config) || !isset($config['db'])) {
    fwrite(STDERR, "No database configuration: set TALEA_DB_NAME (and the other TALEA_DB_*) or create config.php.\n");
    exit(1);
}

return Migrator::phinxConfig($config['db']);
