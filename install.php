<?php
/**
 * Kaleta - installation. Open this file in the browser; after a successful installation it deletes itself
 * (in a development copy delete it yourself).
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

(new Kaleta\Install\Installer())->handle()->send();
