<?php
/**
 * Consent to connecting an app via OAuth (the Claude connector and other MCP clients).
 *
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var string|null $error  why the last attempt was refused
 * @var string $nonce  ties the form to the pending request it was rendered for
 * @var array{nazev:string, redirect_uri:string} $pending
 * @var array<string, mixed> $user
 * @var string $url  the server the app returns to after consent
 * @var bool $connected whether this site has a connection with the app already; without one its name is not verified
 */
$role = t(Talea\Core\Auth::TYPES[(int) $username['admin']] ?? '');
?>
<div class="oauth-consent">
	<p class="oauth-who"><strong><?= e($pending['name']) ?></strong> <?= e(t('wants to work with the website %s.', $app->settings()->get('site_name'))) ?><?php if (!$connected): ?> <span class="badge badge-draft"><?= e(t('new, not verified')) ?></span><?php endif ?></p>
<?php if (!$connected): ?>
	<p class="help"><?= e(t('This website has no connection with this application yet. An application chooses its name itself, so the name is not verified – allow access only if you started this connection yourself.')) ?></p>
<?php endif ?>
	<p><?= e(t('It will act with your account %s (%s) – never with more than your role allows.', (string) $username['username'], $role)) ?></p>
<?php if ($error !== null): ?>
	<p class="notice notice-error" role="alert"><?= e(t($error)) ?></p>
<?php endif ?>
	<form method="post" action="<?= e($app->url('admin.php?action=oauth')) ?>">
		<?= $csrf ?>
		<input type="hidden" name="request" value="<?= e($nonce) ?>">
		<?= $app->view->render('admin/connection-access', ['role' => $role, 'selected' => 'drafts', 'recommended' => 'drafts', 'toolSet' => 'essentials']) ?>
		<div class="row"><label for="consent-password"><?= e(t('Current password')) ?></label><div><input class="textfield" type="password" id="consent-password" name="current_password" size="30" autocomplete="current-password"></div></div>
		<p class="help"><?= e(t('After allowing it you return to the application at %s. You can revoke the connection at any time in My account → Connected applications.', $url)) ?></p>
		<div class="buttons">
		<button class="btn" type="submit" name="allow" value="1"><?= e(t('Allow access')) ?></button>
		<button class="navigation" type="submit" name="allow" value="0"><?= e(t('Deny')) ?></button>
		</div>
	</form>
</div>
