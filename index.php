<?php
/**
 * Talea - public part of the site.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

// /admin/<module>/<action>/<id> is the administration
if (Talea\Admin\Kernel::route()) {
    require __DIR__ . '/admin.php';

    return;
}

$app = Talea\Core\App::boot();
(new Talea\Front\Kernel($app))->handle()->send();

// after the page is sent: notifications about just-published (including scheduled) articles
Talea\Core\Mail::afterResponse($app); // sign-in links of the member login are queued and go out now (Core\Members)
Talea\Core\Webhook::afterResponse($app); // a new enquiry goes to the webhook only now – the visitor does not wait
Talea\Core\Notifications::runInBackground($app);
