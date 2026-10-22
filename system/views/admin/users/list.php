<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Users $module
 * @var string $csrf
 * @var list<array<string, mixed>> $authors
 */
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) count($authors), 'links' => [[$app->url('admin.php?module=roles'), t('Roles')]], 'primary' => [$module->url('new'), t('New user')]]);
?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('Sign-in')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Last sign-in')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($authors as $a):
    $edit = $module->url('edit', ['id' => $a['public_id']]);
    $name = (string) ($a['name'] !== '' ? $a['name'] : $a['username']);
    $me = (int) $a['user_id'] === $app->auth()->id();
    $menu = [];
    if ($a['blocked']) {
        $menu[] = ['post' => $module->url('reactivate'), 'fields' => ['user_id' => $a['public_id'], 'username' => $a['username']], 'label' => t('Reactivate'), 'icon' => 'restore'];
    }
    if (!$me) {
        $menu[] = '-';
        $menu[] = ['post' => $module->url('delete'), 'fields' => ['user_id' => $a['public_id']], 'label' => t('Delete'), 'confirm' => t('Really delete the user? Their news items will remain, without an author.'), 'danger' => true];
    }
?>
<tr<?= $a['blocked'] ? ' class="inactive"' : '' ?>>
	<td class="cell-main"><div class="cell-row"><span class="cell-icon avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($name, 0, 2))) ?></span><div class="cell-text">
		<span class="cell-row"><a class="cell-title" href="<?= e($edit) ?>"><?= bdi($name) ?></a><?= $me ? ' <span class="cell-sub">(' . e(t('you')) . ')</span>' : '' ?><?= $a['blocked'] ? ' <span class="tag tag-warning">' . e(t($a['auto_blocked_at'] !== null ? 'blocked automatically' : 'blocked')) . '</span>' : '' ?></span>
		<span class="cell-sub"><?= bdi($a['username']) ?><?= $a['email'] !== '' ? ' · ' . bdi($a['email']) : '' ?></span>
	</div></div></td>
	<td><div class="cell-text"><span><span class="tag"><?= e($a['role_name'] ?? t(Talea\Core\Auth::TYPES[(int) $a['admin']] ?? '?')) ?></span></span><?= (string) $a['summary'] !== '' ? '<span class="cell-sub" title="' . e($a['summary']) . '">' . e($a['summary']) . '</span>' : '' ?></div></td>
	<td><?= $a['totp_secret'] !== '' ? '<span class="cell-row nowrap" title="' . e(t('two-factor sign-in')) . '">' . $icon('shield') . e(t('2FA')) . '</span>' : '<span class="cell-row nowrap status-note-warning">' . $icon('shield') . e(t('password only')) . '</span>' ?></td>
	<td class="number"><?= (int) $a['news_count'] ?></td>
	<td class="cell-weak"><?= e(format_ago($a['last_login_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $a['public_id'], 'name' => $name, 'edit' => $edit, 'menu' => $menu, 'csrf' => $csrf]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
