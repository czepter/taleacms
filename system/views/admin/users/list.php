<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var list<array<string, mixed>> $authors
 */
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New user')) ?></a> <a class="navigation" href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Roles')) ?></a></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Last sign-in')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($authors as $a): ?>
<tr<?= $a['blocked'] ? ' class="unpublished"' : '' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => $a['user_id']])) ?>"><?= e($a['username']) ?></a><?= $a['blocked'] ? ' <strong>(' . e(t($a['auto_blocked_at'] !== null ? 'blocked automatically' : 'blocked')) . ')</strong>' : '' ?><?= $a['totp_secret'] !== '' ? ' <span class="badge badge-published" title="' . e(t('two-factor sign-in')) . '">2FA</span>' : '' ?></td>
	<td><?= e($a['name']) ?><br><span class="small-text"><?= e($a['summary']) ?></span></td>
	<td><?= e($a['email']) ?></td>
	<td><?= e($a['role_name'] ?? t(Kaleta\Core\Auth::TYPES[(int) $a['admin']] ?? '?')) ?></td>
	<td class="number"><?= (int) $a['news_count'] ?></td>
	<td class="number"><?= e(format_date($a['last_login_at'], true)) ?: '-' ?></td>
	<td class="actions">
		<a href="<?= e($module->url('edit', ['id' => $a['user_id']])) ?>"><?= e(t('Edit')) ?></a>
<?php if ($a['blocked']): ?>
		/ <form class="inline" method="post" action="<?= e($module->url('reactivate')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>"><input type="hidden" name="username" value="<?= e($a['username']) ?>"><button class="navigation" type="submit"><?= e(t('Reactivate')) ?></button></form>
<?php endif ?>
<?php if ((int) $a['user_id'] !== $app->auth()->id()): ?>
		/ <form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Really delete the user? Their news items will remain, without an author.')) ?>"><?= $csrf ?><input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
