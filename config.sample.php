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
    // errors to a Sentry or GlitchTip project (docs/DEPLOYMENT.md, "Error tracking"); the TALEA_ERROR_* variables win
    // 'error_dsn' => 'https://<key>@glitchtip.example.com/<project>',
    // 'error_environment' => 'production',
    // 'error_sample_rate' => 1,
    // 'error_browser' => false, // true = JavaScript errors of the public site too
];
