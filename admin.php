<?php
/**
 * Talea - admin.
 * URLs look like admin.php?module=news&action=edit&id=5.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Talea\Core\App::boot();
$response = (new Talea\Admin\Kernel($app))->handle();
// forms may submit only to the site itself; the exception is consent to connecting an application (OAuth): after submitting,
// the browser goes to the application's return URL (claude.ai, localhost for Claude Code) and CSP form-action guards this redirect too
$headers = $response->headers;
$formTargets = "'self'" . (preg_match('#^https?://[a-z0-9.\[\]:-]+$#i', $headers['X-Talea-Form-Action'] ?? '') ? ' ' . $headers['X-Talea-Form-Action'] : '');
unset($headers['X-Talea-Form-Action']);
// admin: nothing from it belongs in the browser or proxy cache, and it may run only its own scripts (no inline, no third-party)
(new Talea\Core\Response($response->body, $response->status, $headers + [
    'Cache-Control' => 'no-store, private',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https:; "
        . "frame-src 'self' https:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action {$formTargets}; frame-ancestors 'self'",
] + ($app->request->isHttps() ? ['Strict-Transport-Security' => 'max-age=15552000'] : [])))->send();
Talea\Core\Webhook::afterResponse($app); // e.g. a just-published news item
Talea\Core\Mail::afterResponse($app); // e.g. the password reset link – queued, so the answer's timing reveals no account (3.3.3)
