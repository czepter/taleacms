<?php

declare(strict_types=1);

// Composer's autoloader first (PHPUnit, Phinx, the Kaleta\Tests namespace), then the application's own bootstrap (constants, its PSR-4 loader, helpers).
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/system/bootstrap.php';
