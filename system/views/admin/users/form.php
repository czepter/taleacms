<?php
/**
 * User: name, sign-in and role. Permissions follow from the role; manual settings are hidden in "Podrobné nastavení" (Advanced settings).
 *
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var array<string, mixed> $author
 * @var array<string, string> $errors
 * @var bool $isSelf  the admin is editing their own account
 * @var array<string, string> $modules  ident => name (sections to which access is set)
 * @var list<string> $hasModules
 * @var bool $manual  access to sections is set manually (differs from the default for the role)
 * @var list<array<string, mixed>> $customRoles  roles from Uživatelé → Role (Users → Roles)
 * @var list<array<string, mixed>> $connections  the user's Claude connections (Core\SecurityHygiene::connections)
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e(t($errors[$field])) . '</span>' : '';
$role = [
    0 => ['News author', 'Writes and edits their own news. An editor publishes them.'],
    1 => ['Editor', 'Manages all site content – pages, news, media – and publishes.'],
    2 => ['Administrator', 'Everything, including users, appearance and site settings.'],
];
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<?php if (($summary ?? '') !== ''): ?>
<p class="notice"><strong><?= e(t('What this user may do now:')) ?></strong> <?= e($summary) ?></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="user_id" value="<?= (int) $author['user_id'] ?>">
<div class="row">
	<label for="jmeno"><?= e(t('First and last name')) ?></label>
	<div><input class="textfield wide" type="text" id="jmeno" name="name" value="<?= e($author['name']) ?>" maxlength="100"><span class="help"><?= e(t('Shown with news items.')) ?></span></div>
</div>
<div class="row">
	<label for="user"><?= e(t('User name')) ?></label>
	<div><input class="textfield" type="text" id="user" name="username" value="<?= e($author['username']) ?>" maxlength="40" size="30" required><?= $error('username') ?></div>
</div>
<div class="row">
	<label for="email"><?= e(t('Email')) ?></label>
	<div><input class="textfield wide" type="email" id="email" name="email" value="<?= e($author['email']) ?>" maxlength="190"><?= $error('email') ?></div>
</div>
<div class="row">
	<label for="password"><?= e(t($author['user_id'] ? 'New password' : 'Password')) ?></label>
	<div><input class="textfield" type="password" id="password" name="password" size="30" minlength="10" autocomplete="new-password">
	<label style="font-weight:normal"><input type="checkbox" data-show-password="password"> <?= e(t('visible')) ?></label><?= $error('password') ?>
	<span class="help"><?= e(t('At least 10 characters.')) ?> <?= e(t($author['user_id'] ? 'Leave empty if you are not changing the password.' : 'The user can change it later under My account.')) ?></span>
<?php if (!$author['user_id']): ?>
	<label style="font-weight:normal"><input type="checkbox" name="invite" value="1"> <?= e(t('Instead of a password, send an e-mail invitation – the user sets their own password')) ?></label>
<?php endif ?>
	</div>
</div>

<fieldset>
<legend><?= e(t('Roles')) ?></legend>
<div class="cards-options cards-options-text">
<?php foreach ($role as $value => [$name, $description]): ?>
	<label class="card-option">
		<input type="radio" name="admin" value="<?= $value ?>"<?= (int) $author['admin'] === $value && empty($author['role']) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
<?php foreach ($customRoles as $customRole): ?>
	<label class="card-option">
		<input type="radio" name="admin" value="r<?= (int) $customRole['role_id'] ?>"<?= (int) ($author['role'] ?? 0) === (int) $customRole['role_id'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e($customRole['name']) ?></strong>
		<span><?= e($customRole['description'] !== '' ? $customRole['description'] : t('Custom role')) ?></span>
	</label>
<?php endforeach ?>
</div>
<p class="help"><a href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Custom role')) ?></a> – <?= e(t('a named set of sections, for example only Enquiries for a salesperson.')) ?></p>
<?php if ($isSelf): ?>
<p class="help"><?= e(t('You cannot remove administrator rights from your own account.')) ?></p>
<?php endif ?>
</fieldset>

<details class="advanced"<?= $manual || $author['blocked'] || !empty($author['url']) ? ' open' : '' ?>>
<summary><?= e(t('Detailed settings')) ?></summary>
<div class="row">
	<label for="url"><?= e(t('User\'s website')) ?></label>
	<input class="textfield wide" type="url" id="url" name="url" value="<?= e($author['url']) ?>" maxlength="255" placeholder="https://">
</div>
<div class="row">
	<span class="caption"><?= e(t('Access to areas')) ?></span>
	<div class="options">
		<label><input type="checkbox" name="manual" value="1"<?= $manual ? ' checked' : '' ?>> <?= e(t('set manually (otherwise by role)')) ?></label><br>
<?php foreach ($modules as $ident => $name): ?>
		<label style="margin-left:22px"><input type="checkbox" name="modules[]" value="<?= e($ident) ?>"<?= in_array($ident, $hasModules, true) ? ' checked' : '' ?>> <?= e(t($name)) ?></label><br>
<?php endforeach ?>
		<span class="help"><?= e(t('An author usually has only News, an editor all content sections, an administrator everything. With a custom role, the role\'s sections apply.')) ?></span>
	</div>
</div>
<?php if (!empty($author['totp_secret'])): ?>
<div class="row">
	<span class="caption"><?= e(t('Two-factor sign-in')) ?></span>
	<div class="options"><span class="badge badge-published"><?= e(t('on')) ?></span> <label><input type="checkbox" name="totp_reset" value="1"> <?= e(t('turn off (the user lost both their phone and backup codes)')) ?></label></div>
</div>
<?php endif ?>
<?php if (!$isSelf): ?>
<div class="row">
	<span class="caption"><?= e(t('Block account')) ?></span>
	<div class="options"><label><input type="checkbox" name="blocked" value="1"<?= $author['blocked'] ? ' checked' : '' ?>> <?= e(t('user cannot sign in')) ?></label>
<?php if ($author['blocked'] && !empty($author['auto_blocked_at'])): ?>
	<span class="help"><?= e(t('Blocked automatically on %s – nobody had used the account for %d days. Untick the box and save to reactivate the account.', format_date($author['auto_blocked_at']), Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS)) ?></span>
<?php endif ?>
	</div>
</div>
<?php endif ?>
</details>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t($author['user_id'] ? 'Save' : 'Add user')) ?>"></p>
</form>
<?php if ($author['user_id'] && ($connections ?? []) !== []): ?>
<fieldset id="napojeni">
<legend><?= e(t('Claude connections')) ?></legend>
<p class="help"><?= e(t('Personal tokens and connected applications of this account. A connection nobody has used for %d days is reported in System status; revoke what is not needed any more.', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></p>
<?php $accessLabel = ['full' => t('full access'), 'drafts' => t('drafts only'), 'read' => t('read only')]; ?>
<?php foreach ($connections as $c): ?>
<form class="inline" method="post" action="<?= e($module->url('revoke_connection')) ?>" data-confirm="<?= e(t('Revoke the connection? Claude will no longer be able to sign in with it.')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $author['user_id'] ?>"><input type="hidden" name="username" value="<?= e($author['username']) ?>">
<p><span class="badge"><?= e($c['name']) ?></span> <span class="badge"><?= e(t($c['kind'] === 'token' ? 'personal token' : 'connected application')) ?></span> <span class="badge"><?= e($accessLabel[$c['access']] ?? $accessLabel['read']) ?></span>
	<?= e(t('created %s', format_date($c['created']))) ?>, <?= e($c['used'] ? t('last used %s', format_date($c['last'], true)) : t('never used')) ?>, <?= e($c['expiry'] !== null ? t('valid until %s', format_date($c['expiry'])) : t('no expiry')) ?>
	<?php if ($c['kind'] === 'token'): ?><input type="hidden" name="token_id" value="<?= (int) $c['id'] ?>"><?php else: ?><input type="hidden" name="client_id" value="<?= e($c['id']) ?>"><?php endif ?>
	<button class="navigation danger" type="submit"><?= e(t('Revoke')) ?></button></p>
</form>
<?php endforeach ?>
</fieldset>
<?php endif ?>
<?php if ($author['user_id'] && $author['email'] !== '' && !$author['blocked']): ?>
<div class="navigation-row actions-bottom"><form class="inline" method="post" action="<?= e($module->url('password_link')) ?>" data-confirm="<?= e(t('Send the user an e-mail link to set a new password?')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $author['user_id'] ?>"><input type="hidden" name="username" value="<?= e($author['username']) ?>"><button class="navigation" type="submit"><?= e(t('Send a new password link')) ?></button></form></div>
<?php endif ?>
