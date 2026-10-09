<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var list<array<string, mixed>> $authors
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New user')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Roles')) ?></a></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Jméno')) ?></th><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Last sign-in')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($authors as $a): ?>
<tr<?= $a['blocked'] ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => $a['user_id']])) ?>"><?= e($a['username']) ?></a><?= $a['blocked'] ? ' <strong>(' . e(t($a['auto_blocked_at'] !== null ? 'blocked automatically' : 'blocked')) . ')</strong>' : '' ?><?= $a['totp_secret'] !== '' ? ' <span class="stitek stitek-vydano" title="' . e(t('two-factor sign-in')) . '">2FA</span>' : '' ?></td>
	<td><?= e($a['jmeno']) ?><br><span class="smltxt"><?= e($a['shrnuti']) ?></span></td>
	<td><?= e($a['email']) ?></td>
	<td><?= e($a['nazev_role'] ?? t(Kaleta\Core\Auth::TYPES[(int) $a['admin']] ?? '?')) ?></td>
	<td class="cislo"><?= (int) $a['pocet_clanku'] ?></td>
	<td class="cislo"><?= e(format_date($a['last_login_at'], true)) ?: '-' ?></td>
	<td class="akce">
		<a href="<?= e($module->url('edit', ['id' => $a['user_id']])) ?>"><?= e(t('Edit')) ?></a>
<?php if ($a['blocked']): ?>
		/ <form class="vradku" method="post" action="<?= e($module->url('reactivate')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>"><input type="hidden" name="username" value="<?= e($a['username']) ?>"><button class="navigace" type="submit"><?= e(t('Reactivate')) ?></button></form>
<?php endif ?>
<?php if ((int) $a['user_id'] !== $app->auth()->id()): ?>
		/ <form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Really delete the user? Their news items will remain, without an author.')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
