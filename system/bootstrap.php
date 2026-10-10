<?php
/**
 * Talea - system bootstrap.
 * Common start for index.php (site), admin.php (admin) and install.php.
 */

declare(strict_types=1);

const TALEA_VERSION = '3.3.3';

define('TALEA_ROOT', dirname(__DIR__));
define('TALEA_SYSTEM', __DIR__);

if (PHP_VERSION_ID < 80500) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Talea requires PHP 8.5 or newer. The server is running PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Composer packages (Phinx, for the database migrations only) – a release package ships vendor/, a git checkout needs "composer install".
if (is_file(TALEA_ROOT . '/vendor/autoload.php')) {
    require TALEA_ROOT . '/vendor/autoload.php';
}

// Custom PSR-4 autoloader: system/src/Core/Db.php = Talea\Core\Db. The application code itself needs no Composer.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Talea\\')) {
        return;
    }
    $file = TALEA_SYSTEM . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require TALEA_SYSTEM . '/src/helpers.php';
