<?php
/**
 * The confirmation of a sensitive step: the current password, or "Confirm with your passkey" when the signed-in account has a passkey for
 * this site (image/passkeys.js posts to admin.php?action=proof; the proof then counts for ten minutes, Auth::requireFresh / confirmOwn).
 *
 * @var Talea\Core\App $app
 * @var string $id  prefix of the html ids
 * @var string|null $help  an extra line under the field
 * @var string|null $error  why the last attempt was refused (already translated)
 * @var bool|null $passkeyAdd  the fields belong to the add-a-passkey form: image/passkeys.js reads them (data-passkey-password, data-passkey-backup)
 *
 * A passkey-only account (Auth::passkeyOnly) is never asked for the password: a backup code instead.
 */
$user = $app->auth()->user();
$keys = $user !== null && $app->auth()->workingKeys((int) $user['user_id']) !== [];
$only = $user !== null && $app->auth()->passkeyOnly($user);
?>
<?php if ($only): ?>
<div class="row"><label for="<?= e($id) ?>-backup"><?= e(t('Backup code')) ?></label><div>
	<input class="textfield" type="text" id="<?= e($id) ?>-backup" name="backup_code" size="30" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" data-reauth-input<?= !empty($passkeyAdd) ? ' data-passkey-backup' : '' ?>>
	<span class="help"><?= e(t('This account confirms changes with its passkey or a backup code.')) ?></span>
<?php else: ?>
<div class="row"><label for="<?= e($id) ?>-password"><?= e(t('Your current password')) ?></label><div>
	<input class="textfield" type="password" id="<?= e($id) ?>-password" name="current_password" size="30" autocomplete="current-password" data-reauth-input<?= !empty($passkeyAdd) ? ' data-passkey-password' : '' ?>>
<?php endif ?>
<?php if ($keys): ?>
	<button class="navigation" type="button" data-passkey-confirm="<?= e($app->url('admin.php?action=proof')) ?>"><?= e(t('Confirm with your passkey')) ?></button>
	<span class="help" data-passkey-confirmed hidden><?= e(t('Confirmed with your passkey.')) ?></span>
	<p class="notice notice-error" data-passkey-confirm-error hidden role="alert"></p>
	<script src="<?= e($app->url('image/passkeys.js')) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
<?php endif ?>
<?php if (!empty($error)): ?>
	<span class="error-field" role="alert"><?= e($error) ?></span>
<?php endif ?>
<?php if (!empty($help)): ?>
	<span class="help"><?= e($help) ?></span>
<?php endif ?>
</div></div>
