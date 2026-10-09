<?php
/**
 * Consent to connecting an app via OAuth (the Claude connector and other MCP clients).
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{nazev:string, redirect_uri:string} $pending
 * @var array<string, mixed> $user
 * @var string $url  the server the app returns to after consent
 */
$role = t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? '');
?>
<div class="oauth-souhlas">
	<p class="oauth-kdo"><strong><?= e($pending['nazev']) ?></strong> <?= e(t('wants to work with the website %s.', $app->settings()->get('site_name'))) ?></p>
	<p><?= e(t('It will act with your account %s (%s) – never with more than your role allows.', (string) $user['username'], $role)) ?></p>
	<form method="post" action="<?= e($app->url('admin.php?action=oauth')) ?>">
		<?= $csrf ?>
		<?= $app->view->render('admin/connection-access', ['role' => $role, 'selected' => 'full']) ?>
		<p class="napoveda"><?= e(t('After allowing it you return to the application at %s. You can revoke the connection at any time in My account → Connected applications.', $url)) ?></p>
		<div class="tlacitka">
		<button class="tl" type="submit" name="povolit" value="1"><?= e(t('Allow access')) ?></button>
		<button class="navigace" type="submit" name="povolit" value="0"><?= e(t('Deny')) ?></button>
		</div>
	</form>
</div>
