<?php
/**
 * Talea - public part of the site.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Talea\Core\App::boot();
(new Talea\Front\Kernel($app))->handle()->send();

// after the page is sent: notifications about just-published (including scheduled) articles and a check for security updates (at most once per 12 hours)
Talea\Core\Webhook::afterResponse($app); // a new enquiry goes to the webhook only now – the visitor does not wait
Talea\Core\Notifications::runInBackground($app);
Talea\Core\Updater::runInBackground($app);
