<?php
/**
 * My account.
 *
 * @var Talea\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var list<array<string, mixed>> $keys the account's sign-in keys (passkeys)
 * @var list<string> $backupCodes  backup codes just created (shown only once)
 * @var string $newSecret      two-factor sign-in being turned on (in progress)
 * @var string $uri
 * @var int $codesLeft
 * @var bool $claude  the Claude connection extension is enabled
 * @var list<array<string, mixed>> $tokens  personal tokens
 * @var list<array<string, mixed>> $apps  apps connected via OAuth (Claude connector)
 * @var string $newToken  token just created (shown only once)
 * @var string $mcpUrl
 */
$action = e($app->url('admin.php?action=account'));
?>
<?php if ($backupCodes !== []): ?>
<div class="notice notice-ok">
	<p><strong><?= e(t('Two-factor sign-in is on.')) ?></strong> <?= e(t('Save your backup codes – each works once when you do not have your phone. They will not be shown again.')) ?></p>
	<p class="backup-codes"><?= implode(' &nbsp; ', array_map(e(...), $backupCodes)) ?></p>
</div>
<?php endif ?>

<form class="form" method="post" action="<?= $action ?>">
<?= $csrf ?><input type="hidden" name="op" value="profil">
<fieldset><legend><?= e(t('My details')) ?></legend>
<div class="row"><span class="caption"><?= e(t('User name')) ?></span><div><?= e($username['username']) ?> <span class="help"><?= e(t('Changed by an administrator in Users.')) ?></span></div></div>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" type="text" id="name" name="name" value="<?= e($username['name']) ?>" maxlength="100"><span class="help"><?= e(t('Shown with news items on the site.')) ?></span></div></div>
<div class="row"><label for="email"><?= e(t('Email')) ?></label><input class="textfield wide" type="email" id="email" name="email" value="<?= e($username['email']) ?>" maxlength="190"></div>
<div class="row"><label for="email-password"><?= e(t('Current password')) ?></label><div><input class="textfield" type="password" id="email-password" name="current_password" size="30" autocomplete="current-password"><span class="help"><?= e(t('Needed only when you change the e-mail – a password reset goes there. The old address gets a notice.')) ?></span></div></div>
<div class="row"><label for="url"><?= e(t('My website')) ?></label><input class="textfield wide" type="url" id="url" name="url" value="<?= e($username['url']) ?>" maxlength="255" placeholder="https://"></div>
<div class="row"><label for="position"><?= e(t('Position in the company')) ?></label><input class="textfield wide" type="text" id="position" name="position" value="<?= e($username['position']) ?>" maxlength="100" placeholder="<?= e(t('e.g. head of sales')) ?>"></div>
<div class="row"><label for="photo"><?= e(t('My photo')) ?></label><div><input class="textfield wide" type="text" id="photo" name="photo" value="<?= e($username['photo']) ?>" maxlength="255" data-image><span class="help"><?= e(t('A square photo, 300 × 300 px is enough.')) ?></span></div></div>
<div class="row"><label for="bio"><?= e(t('A few sentences about me')) ?></label><div><textarea class="textbox low" id="bio" name="bio" rows="4" maxlength="1200"><?= e((string) $username['bio']) ?></textarea><span class="help"><?= e(t('Shown as a short profile under your news items. What you do in the company and your background.')) ?></span></div></div>
<div class="row"><label for="language"><?= e(t('Administration language')) ?></label><div><select id="language" name="language">
<?php
// the selected language is the one the admin actually runs in (without an own choice, the site language, if the admin supports it)
$adminLanguage = $username['language'] ?: Talea\Core\Language::defaults($app->settings());
$adminLanguage = isset(Talea\Core\Language::ADMIN_LANGUAGES[$adminLanguage]) ? $adminLanguage : 'en';
foreach (Talea\Core\Language::ADMIN_LANGUAGES as $languageCode => $languageName): ?>
	<option value="<?= e($languageCode) ?>"<?= $adminLanguage === $languageCode ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
</select><span class="help">Language · Jazyk</span></div></div>
<?php if ($adminLanguage === 'de'): ?>
<div class="row"><label for="register"><?= e(t('Form of address in German')) ?></label><div><select id="register" name="register">
	<option value="formal"<?= ($username['register'] ?? '') !== 'informal' ? ' selected' : '' ?>><?= e(t('Formal (Sie)')) ?></option>
	<option value="informal"<?= ($username['register'] ?? '') === 'informal' ? ' selected' : '' ?>><?= e(t('Informal (du)')) ?></option>
</select><span class="help"><?= e(t('How the German administration addresses you. The texts for visitors have their own setting in Settings.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save details')) ?>"></p>
</form>

<form class="form" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="op" value="heslo">
<fieldset><legend><?= e(t('Change password')) ?></legend>
<div class="row"><label for="current-password"><?= e(t('Current password')) ?></label><div><input class="textfield" type="password" id="current-password" name="current_password" size="30" autocomplete="current-password" required></div></div>
<div class="row"><label for="new_password"><?= e(t('New password')) ?></label><div><input class="textfield" type="password" id="new_password" name="new_password" size="30" minlength="10" autocomplete="new-password" required><span class="help"><?= e(t('At least 10 characters.')) ?></span></div></div>
<div class="row"><label for="new_password_again"><?= e(t('New password again')) ?></label><div><input class="textfield" type="password" id="new_password_again" name="new_password_again" size="30" autocomplete="new-password" required></div></div>
<?php if ($tokens !== []): ?>
<div class="row"><span class="caption"><?= e(t('Connections')) ?></span><div class="options"><label><input type="checkbox" name="revoke_tokens" value="1" checked> <?= e(t('also revoke connection tokens (Claude, API)')) ?></label>
	<span class="help"><?= e(t('A token works without the password and without two-factor sign-in. If you are changing the password because you suspect misuse, leave this ticked and create the connection again afterwards.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Change password')) ?>"></p>
</form>

<form class="form" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?>
<fieldset><legend><?= e(t('Two-factor sign-in')) ?></legend>
<?php if ($username['totp_secret'] !== ''): ?>
<p><span class="badge badge-published"><?= e(t('on')) ?></span> <?= e(t('When signing in you enter a code from the app in addition to your password. Backup codes left: %d.', $codesLeft)) ?></p>
<input type="hidden" name="op" value="totp_vypni">
<div class="row"><label for="off-password"><?= e(t('Password to confirm')) ?></label><div><input class="textfield" type="password" id="off-password" name="current_password" size="30" autocomplete="current-password" required></div></div>
<p class="buttons"><button class="navigation" type="submit"><?= e(t('Turn off two-factor sign-in')) ?></button></p>
<?php elseif ($newSecret !== ''): ?>
<input type="hidden" name="op" value="totp_potvrd">
<ol>
	<li><?= e(t('In your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…) add a new account by scanning the QR code:')) ?><br>
		<span class="totp-qr"><?= Talea\Core\Qr::svg($uri, t('QR code for the authenticator app')) ?></span><br>
		<?= e(t('Cannot scan it? Add the account by typing the key:')) ?><br><code class="totp-key"><?= e(trim(chunk_split($newSecret, 4, ' '))) ?></code><br><small><a href="<?= e($uri) ?>"><?= e(t('On a phone you can tap here – the link opens your authenticator app.')) ?></a></small></li>
	<li><?= e(t('Enter the six-digit code shown by the app:')) ?></li>
</ol>
<div class="row"><label for="code"><?= e(t('Code from the app')) ?></label><div><input class="textfield" type="text" id="code" name="code" size="12" maxlength="7" inputmode="numeric" autocomplete="one-time-code" required autofocus></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Confirm and turn on')) ?>"></p>
<?php else: ?>
<p><?= e(t('Your account is protected by a password only. With two-factor sign-in, nobody can sign in without your phone – even if they guess or steal the password.')) ?></p>
<input type="hidden" name="op" value="totp_start">
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Turn on two-factor sign-in')) ?>"></p>
<?php endif ?>
</fieldset>
</form>

<?php if ($username['totp_secret'] !== ''): ?>
<form class="form" method="post" action="<?= $action ?>" data-passkey="<?= $action ?>">
<?= $csrf ?>
<fieldset><legend><?= e(t('Passkeys')) ?></legend>
<p><?= e(t('Fingerprint, Face ID, Windows Hello or a security key instead of typing the code from the app. The code and the backup codes keep working – in case you do not have the device with you.')) ?></p>
<?php if ($keys !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Devices')) ?></th><th scope="col"><?= e(t('Added')) ?></th><th scope="col"><?= e(t('Last used')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($keys as $k): ?>
<tr>
	<td><?= e($k['name']) ?></td>
	<td class="number"><?= e(format_date((string) $k['created_at'])) ?></td>
	<td class="number"><?= $k['used_at'] !== null ? e(format_date((string) $k['used_at'])) : '–' ?></td>
	<td class="actions"><button class="navigation danger" type="submit" name="passkey_id" value="<?= e($k['public_id']) ?>" data-confirm="<?= e(t('Remove this passkey? You can still sign in with the code from the app.')) ?>"><?= e(t('Delete')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<input type="hidden" name="op" value="passkey_delete">
<?php endif ?>
<div class="row"><label for="passkey-name"><?= e(t('Device name')) ?></label><div><input class="textfield" type="text" id="passkey-name" name="name" size="30" maxlength="80" placeholder="<?= e(t('e.g. MacBook, phone')) ?>"></div></div>
<div class="row"><label for="passkey-password"><?= e(t('Current password')) ?></label><div><input class="textfield" type="password" id="passkey-password" name="current_password" size="30" autocomplete="current-password" data-passkey-password><span class="help"><?= e(t('Needed to add a passkey.')) ?></span></div></div>
<p class="buttons"><button class="navigation" type="button" data-passkey-add><?= e(t('Add a passkey from this device')) ?></button></p>
<p class="notice notice-error" data-passkey-error hidden role="alert"></p>
<p class="help" data-passkey-unsupported hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</fieldset>
</form>
<script src="<?= e($app->url('image/passkeys.js')) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
<?php endif ?>

<?php if ($claude): ?>
<form class="form" method="post" action="<?= $action ?>">
<?= $csrf ?>
<fieldset id="claude"><legend><?= e(t('Claude connection')) ?></legend>
<?php if ($newToken !== ''): ?>
<div class="notice notice-ok">
	<p><strong><?= e(t('The token has been created.')) ?></strong> <?= e(t('Copy it now – it will not be shown again.')) ?></p>
	<p><code class="totp-key"><?= e($newToken) ?></code></p>
	<p><?= e(t('In Claude Code, run:')) ?></p>
	<p><code class="totp-key" style="font-size:12px">claude mcp add --transport http talea <?= e($mcpUrl) ?> --header "Authorization: Bearer <?= e($newToken) ?>"</code></p>
	<p class="help"><?= e(t('In the Claude app you do not need a token: add a custom connector with the address %s and confirm access by signing in.', $mcpUrl)) ?></p>
</div>
<?php endif ?>
<p><?= e(t('The easiest way is to add a custom connector in the Claude app with the address %s – Claude sends you here to sign in and confirm access, no token to copy. The token below is for Claude Code and other tools without sign-in.', $mcpUrl)) ?></p>
<?php $accessLabel = ['full' => t('full access'), 'drafts' => t('drafts only'), 'read' => t('read only')]; ?>
<?php if ($apps !== []): ?>
<h2><?= e(t('Connected applications')) ?></h2>
<?php foreach ($apps as $a): ?>
<p><span class="badge"><?= e($a['name']) ?></span> <span class="badge"><?= e($accessLabel[$a['access']] ?? $accessLabel['read']) ?></span> <?= e(t('connected %s', format_date($a['created_at']))) ?>, <?= e($a['used_at'] ? t('last used %s', format_date($a['used_at'], true)) : t('not used yet')) ?>
	<button class="navigation danger" type="submit" name="disconnect_client" value="<?= e($a['client_id']) ?>" data-confirm="<?= e(t('Disconnect the application? It will not get into the website until you allow it again.')) ?>"><?= e(t('Disconnect')) ?></button></p>
<?php endforeach ?>
<?php endif ?>
<p><?= e(t('Claude will work with the site')) ?> <strong><?= e(t('in your name and with your permissions')) ?></strong>: <?= e(t((int) $username['admin'] === 2 ? 'write and edit pages and news, and manage categories, collections and the look of the site.' : 'write and edit news.')) ?> <?= e(t('It creates new news items as drafts and new pages as hidden. All its changes are in the Change log. Protect the token like a password.')) ?></p>
<?php foreach ($tokens as $t): ?>
<p><span class="badge"><?= e($t['name']) ?></span> <span class="badge"><?= e($accessLabel[$t['access']] ?? $accessLabel['read']) ?></span> <?= e(t('created %s', format_date($t['created_at']))) ?>, <?= e($t['used_at'] ? t('last used %s', format_date($t['used_at'], true)) : t('not used yet')) ?>,
	<?= $t['expires_at'] === null ? e(t('no expiry')) : ($t['expires_at'] < date('Y-m-d H:i:s') ? '<strong>' . e(t('expired %s', format_date($t['expires_at']))) . '</strong>' : e(t('valid until %s', format_date($t['expires_at'])))) ?>
	<button class="navigation danger" type="submit" name="delete_token" value="<?= e($t['public_id']) ?>" data-confirm="<?= e(t('Revoke the token? Claude will no longer be able to sign in with it.')) ?>"><?= e(t('Revoke token')) ?></button></p>
<?php endforeach ?>
<div class="row"><label for="token-name"><?= e(t('Name of the new token')) ?></label><div><input class="textfield" type="text" id="token-name" name="name" maxlength="100" size="30" placeholder="<?= e(t('e.g. Claude on my laptop')) ?>"></div></div>
<div class="row"><label for="token-lifetime"><?= e(t('Valid for')) ?></label><div><select id="token-lifetime" name="lifetime">
<?php foreach (Talea\Admin\Account::TOKEN_LIFETIMES as $days): ?>
	<option value="<?= $days ?>"<?= $days === 365 ? ' selected' : '' ?>><?= e($days === 0 ? t('no expiry') : ($days === 365 ? t('1 year') : t('%d days', $days))) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('An expired token stops working on its own; you then create a new one. A token nobody uses for %d days is reported in System status.', Talea\Core\SecurityHygiene::CONNECTION_DAYS)) ?></span></div></div>
<?= $app->view->render('admin/connection-access', ['role' => t(Talea\Core\Auth::TYPES[(int) $username['admin']] ?? ''), 'selected' => 'full']) ?>
<p class="buttons"><button class="btn" type="submit" name="op" value="token_novy"><?= e(t('Create token')) ?></button></p>
</fieldset>
</form>
<?php endif ?>
