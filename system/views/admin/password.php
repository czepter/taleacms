<?php
/**
 * Reset of a forgotten admin password (Admin\PasswordReset).
 *
 * @var Kaleta\Core\App $app
 * @var string $step request | password | invalid
 * @var bool $sent
 * @var ?string $error
 * @var string $token
 * @var string $account
 */
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/theme.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= e(t('Forgotten password')) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-mark.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-mark-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-mark-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body class="login">
<div class="login-card">
<?= $app->view->render('admin/logo', ['height' => 36]) ?>
<h1><?= e(t($step === 'password' ? 'New password' : 'Forgotten password')) ?></h1>
<?php if ($error !== null): ?>
<p class="notice notice-error" role="alert"><?= e($error) ?></p>
<?php endif ?>
<?php if ($step === 'password'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<input type="hidden" name="token" value="<?= e($token) ?>">
<p><?= e(t('Account: %s', $account)) ?></p>
<div class="login-field"><label for="password"><?= e(t('New password')) ?></label> <input class="textfield" type="password" id="password" name="password" size="20" minlength="10" autocomplete="new-password" required autofocus></div>
<div class="login-field"><label for="password2"><?= e(t('Repeat password')) ?></label> <input class="textfield" type="password" id="password2" name="password2" size="20" minlength="10" autocomplete="new-password" required></div>
<p class="small-text"><?= e(t('At least 10 characters. Two-factor sign-in stays on.')) ?></p>
<p><input class="btn" type="submit" value="<?= e(t('Set password')) ?>"></p>
</form>
<?php elseif ($sent): ?>
<p class="notice notice-ok" role="status"><?= e(t('If such an account exists and has an e-mail address, we have sent it a link for setting a new password. It is valid for one hour.')) ?></p>
<?php elseif ($step === 'request'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<p><?= e(t('Enter the user name or e-mail of your account. We will send you a link for setting a new password.')) ?></p>
<div class="login-field"><label for="kdo"><?= e(t('User name or e-mail')) ?></label> <input class="textfield" type="text" id="kdo" name="user_id" size="20" maxlength="190" autocomplete="username" required autofocus></div>
<p><input class="btn" type="submit" value="<?= e(t('Send link')) ?>"></p>
</form>
<?php endif ?>
<p class="login-link"><a href="<?= e($app->url($step === 'invalid' ? 'admin.php?action=password' : 'admin.php')) ?>"><?= e(t($step === 'invalid' ? 'Request a new link' : 'Back to sign-in')) ?></a></p>
</div>
</body>
</html>
