<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var string $csrf
 * @var string $name
 * @var string $kind
 * @var string $email  the first administrator
 * @var string $error
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to owners')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<?= $error !== '' ? '<p class="notice notice-error" role="alert">' . e(t($error)) . '</p>' : '' ?>
<div class="row">
	<label for="name"><?= e(t('Name of the owner')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required>
</div>
<div class="row">
	<label for="kind"><?= e(t('Kind')) ?></label>
	<select id="kind" name="kind">
		<option value="organisation"<?= $kind !== 'person' ? ' selected' : '' ?>><?= e(t('Organisation')) ?></option>
		<option value="person"<?= $kind === 'person' ? ' selected' : '' ?>><?= e(t('Person')) ?></option>
	</select>
</div>
<div class="row">
	<label for="admin_email"><?= e(t('E-mail of the first administrator')) ?></label>
	<div><input class="textfield" type="email" id="admin_email" name="admin_email" value="<?= e($email) ?>" maxlength="190" required><span class="help"><?= e(t('This person gets an invitation to set a password once the owner has a host name.')) ?></span></div>
</div>
<p class="small-text"><?= e(t('The owner is set up in the background by the scheduled tasks. It starts empty: no pages, no media, no users but the one you invite.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create owner')) ?>"></p>
</form>
