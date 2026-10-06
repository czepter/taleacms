<?php
/**
 * Kaleta - system bootstrap.
 * Common start for index.php (site), admin.php (admin) and install.php.
 */

declare(strict_types=1);

const KALETA_VERSION = '3.3.2';

/** Number of the last migration in system/sql/migrace - the site uses it to tell that it must update the database after an update (checked by tools/test.sh). */
const KALETA_DB_VERSION = 73;

define('KALETA_ROOT', dirname(__DIR__));
define('KALETA_SYSTEM', __DIR__);

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kaleta vyžaduje PHP 8.4 nebo novější. Na serveru běží PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Custom PSR-4 autoloader: system/src/Core/Db.php = Kaleta\Core\Db.
// Composer is not needed at runtime - the site can be uploaded over FTP as it is.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Kaleta\\')) {
        return;
    }
    $file = KALETA_SYSTEM . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require KALETA_SYSTEM . '/src/helpers.php';
