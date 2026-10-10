<?php
/**
 * @var Talea\Admin\Modules\Roles $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $role
 * @var array<string, string> $names  section ident => name
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('admin.php?module=users')) ?>"><?= e(t('Back to users')) ?></a> <a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New role')) ?></a></p>
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
<thead><tr><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('Section')) ?></th><th scope="col"><?= e(t('Users')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($role as $r): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $r['role_id']])) ?>"><?= e($r['name']) ?></a><?= $r['description'] !== '' ? '<br><span class="small-text">' . e($r['description']) . '</span>' : '' ?></td>
	<td><?= e(implode(', ', array_map(fn (string $i): string => t($names[$i] ?? $i), array_filter(explode(',', (string) $r['modules']))))) ?></td>
	<td class="number"><?= (int) $r['member_count'] ?></td>
	<td class="actions"><a href="<?= e($module->url('edit', ['id' => $r['role_id']])) ?>"><?= e(t('Edit')) ?></a>
		/ <form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the role? Its members will keep their current access.')) ?>"><?= $csrf ?><input type="hidden" name="role_id" value="<?= (int) $r['role_id'] ?>"><input type="hidden" name="name" value="<?= e($r['name']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
