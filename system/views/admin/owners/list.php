<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $owners
 */
$state = function (array $o): string {
    if (!$o['ready']) {
        return '<span class="badge badge-pending">' . e(t('Setting up…')) . '</span>';
    }

    return '<span class="badge' . ($o['status'] === 'live' ? ' badge-published' : ($o['status'] === 'paused' ? ' badge-draft' : '')) . '">' . e(t(['live' => 'Live', 'paused' => 'Paused', 'archived' => 'Archived'][$o['status']] ?? $o['status'])) . '</span>';
};
?>
<?= $app->view->render('admin/list/head', ['count' => (string) count($owners), 'primary' => [$module->url('new'), t('New owner')]]) ?>
<p class="small-text"><?= e(t('Every owner has its own users, media, sites and settings. Nothing is shared between owners. You, the administrators of the first owner, can open the sites of the others.')) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Owner')) ?></th><th scope="col"><?= e(t('Kind')) ?></th><th scope="col"><?= e(t('State')) ?></th><th scope="col"><?= e(t('Sites')) ?></th><th scope="col"><?= e(t('Storage')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($owners as $o): $name = (string) ($o['name'] !== '' ? $o['name'] : ($o['first'] ? t('This owner (the operators)') : $o['prefix'])); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><?php if ($o['first']): ?><span class="cell-title"><?= bdi($name) ?></span><?php else: ?><a class="cell-title" href="<?= e($module->url('edit', ['id' => $o['public_id']])) ?>"><?= bdi($name) ?></a><?php endif ?></div></td>
	<td><?= e(t(['person' => 'Person', 'organisation' => 'Organisation'][$o['kind']] ?? $o['kind'])) ?></td>
	<td><?= $o['first'] ? '<span class="badge badge-published">' . e(t('Live')) . '</span>' : $state($o) ?></td>
	<td class="number"><?= $o['ready'] ? (int) $o['sites'] : '' ?></td>
	<td class="number"><?= $o['ready'] ? e(Talea\Core\Files::size((int) $o['bytes'])) : '' ?></td>
	<td class="cell-actions"><?php if (!$o['first']): ?><a class="navigation" href="<?= e($module->url('edit', ['id' => $o['public_id']])) ?>"><?= e(t('Open')) ?></a><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
