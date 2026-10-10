<?php
/**
 * Talea - configuration. The installer (install.php) creates config.php;
 * by hand, just copy this sample and fill in the database details.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'talea',
        'username' => 'talea',
        'password' => '',
        'prefix' => 'tl_',
    ],
    // true = errors are printed into the page; always false on a live site
    'debug' => false,
];
