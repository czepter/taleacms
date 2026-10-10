<?php
/**
 * Kaleta - system bootstrap.
 * Common start for index.php (site), admin.php (admin) and install.php.
 */

declare(strict_types=1);

const KALETA_VERSION = '3.3.3';

define('KALETA_ROOT', dirname(__DIR__));
define('KALETA_SYSTEM', __DIR__);

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kaleta requires PHP 8.4 or newer. The server is running PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Composer packages (Phinx, for the database migrations only) – a release package ships vendor/, a git checkout needs "composer install".
if (is_file(KALETA_ROOT . '/vendor/autoload.php')) {
    require KALETA_ROOT . '/vendor/autoload.php';
}

// Custom PSR-4 autoloader: system/src/Core/Db.php = Kaleta\Core\Db. The application code itself needs no Composer.
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
