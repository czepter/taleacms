<?php
/**
 * Sign-in to the admin.
 *
 * @var Talea\Core\App $app
 * @var string|null $error
 * @var string $login
 * @var bool $code  second step: the password is already correct, waiting for the code from the authenticator app
 */
?>
<!doctype html>
<html lang="<?= e(Talea\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/theme.js')) ?>?v=<?= e(TALEA_VERSION) ?>"></script>
<title><?= e(t('Sign in')) ?> – Talea</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/talea-mark.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/talea-mark-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/talea-mark-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(TALEA_VERSION) ?>">
</head>
<body class="login">
<div class="login-card">
<?= $app->view->render('admin/logo', ['height' => 36]) ?>
<h1><?= e(t('Sign in to the administration')) ?></h1>
<?php if ($error !== null): ?>
<p class="notice notice-error" role="alert"><?= e($error) ?></p>
<?php elseif ($app->request->get('password') === 'changed'): ?>
<p class="notice notice-ok" role="status"><?= e(t('The password has been changed. Sign in with the new password.')) ?></p>
<?php endif ?>
<?php $demo = Talea\Core\Demo::account(); ?>
<?php if ($demo !== null && !$code): // the public demo (2.6): the shared account is filled in ?>
<p class="notice notice-ok"><?= e(t('This is the public demo of Talea. Sign in as %s with the password %s. Everything you change disappears at the next hourly reset.', $demo['username'], $demo['password'])) ?></p>
<?php endif ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<?php if ($code): ?>
<input type="hidden" name="step" value="code">
<p><?= e(t('Enter the six-digit code from your authenticator app. No phone? Use one of your backup codes.')) ?></p>
<div class="login-field"><label for="code"><?= e(t('Verification code:')) ?></label> <input class="textfield" type="text" id="code" name="code" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
<?php else: ?>
<div class="login-field"><label for="user"><?= e(t('User name')) ?></label> <input class="textfield" type="text" id="user" name="username" value="<?= e($demo !== null && $login === '' ? $demo['username'] : $login) ?>" size="20" maxlength="40" autocomplete="username" required autofocus></div>
<div class="login-field"><label for="password"><?= e(t('Password')) ?></label> <input class="textfield" type="password" id="password" name="password" size="20" autocomplete="current-password" required<?= $demo !== null ? ' value="' . e($demo['password']) . '"' : '' ?>></div>
<?php endif ?>
<p><input class="btn" type="submit" value="<?= e(t($code ? 'Verify code' : 'Sign in')) ?>"></p>
</form>
<?php if ($code && !empty($keys)): ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>" data-passkey="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<p class="login-or"><?= e(t('or')) ?></p>
<p><button class="btn" type="button" data-passkey-signin><?= e(t('Sign in with fingerprint or passkey')) ?></button></p>
<p class="notice notice-error" data-passkey-error hidden role="alert"></p>
<p class="small-text" data-passkey-unsupported hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</form>
<script src="<?= e($app->url('image/passkeys.js')) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
<?php endif ?>
<?php if (!$code): ?>
<p class="login-link"><a href="<?= e($app->url('admin.php?action=password')) ?>"><?= e(t('Forgotten your password?')) ?></a></p>
<?php endif ?>
<?= $app->view->render('admin/agency', ['app' => $app, 'withLogo' => true]) ?>
</div>
</body>
</html>
