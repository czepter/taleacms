<?php
/**
 * The confirmation of a sensitive step: the current password, or "Confirm with your passkey" when the signed-in account has a passkey for
 * this site (image/passkeys.js posts to admin.php?action=proof; the proof then counts for ten minutes, Auth::requireFresh / confirmOwn).
 *
 * @var Talea\Core\App $app
 * @var string $id  prefix of the html ids
 * @var string|null $help  an extra line under the field
 * @var string|null $error  why the last attempt was refused (already translated)
 */
$user = $app->auth()->user();
$keys = $user !== null && $app->auth()->workingKeys((int) $user['user_id']) !== [];
?>
<div class="row"><label for="<?= e($id) ?>-password"><?= e(t('Your current password')) ?></label><div>
	<input class="textfield" type="password" id="<?= e($id) ?>-password" name="current_password" size="30" autocomplete="current-password" data-reauth-input>
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
