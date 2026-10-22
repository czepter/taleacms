<?php
/**
 * @var Talea\Admin\Modules\Roles $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $role
 * @var array<string, string> $names  section ident => name
 */
?>
<?= $app->view->render('admin/list/head', ['count' => (string) count($role), 'links' => [[$app->url('admin.php?module=users'), t('Back to users')]], 'primary' => [$module->url('new'), t('New role')]]) ?>
<p class="small-text"><?= e(t('Start from a ready-made role:')) ?>
<?php foreach (Talea\Admin\Modules\Roles::PRESETS as $key => [$presetName, $presetHelp]): ?>
	<a href="<?= e($module->url('new', ['preset' => $key])) ?>" title="<?= e(t($presetHelp)) ?>"><?= e(t($presetName)) ?></a><?= $key !== array_key_last(Talea\Admin\Modules\Roles::PRESETS) ? ' ·' : '' ?>
<?php endforeach ?>
</p>
<p class="small-text"><?= e(t('The built-in roles News author, Editor and Administrator are enough for most websites. A custom role is useful when someone should see only part of the administration – for example a salesperson only Enquiries.')) ?></p>
<?php if ($role === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'users', 'heading' => t('No custom roles yet.'), 'action' => [$module->url('new'), t('New role')]]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Role')) ?></th><th scope="col"><?= e(t('Section')) ?></th><th scope="col"><?= e(t('Users')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($role as $r): $edit = $module->url('edit', ['id' => $r['role_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>"><?= e($r['name']) ?></a><?= $r['description'] !== '' ? '<span class="cell-sub">' . e($r['description']) . '</span>' : '' ?></div></td>
	<td class="cell-weak"><?= e(implode(', ', array_map(fn (string $i): string => t($names[$i] ?? $i), array_filter(explode(',', (string) $r['modules']))))) ?></td>
	<td class="number"><?= (int) $r['member_count'] ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => 'role-' . $r['role_id'], 'name' => $r['name'], 'edit' => $edit, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('delete'), 'fields' => ['role_id' => (string) $r['role_id'], 'name' => $r['name']], 'label' => t('Delete'), 'confirm' => t('Delete the role? Its members will keep their current access.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
