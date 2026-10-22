<?php
/**
 * Components of the site (docs/specs/admin-lists.md).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Components $module
 * @var string $csrf
 * @var list<array<string, mixed>> $components  including the number of uses (usage) and their places
 * @var array{total: int, changes: int, unused: int} $counts
 * @var string $status  '' | changes | unused
 */
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) $counts['total'], 'primary' => [$module->url('new'), t('New component')]]);
$tab = fn (string $value, string $label, int $count): array => ['label' => $label, 'count' => $count, 'url' => $module->url('', array_filter(['status' => $value])), 'active' => $status === $value];
?>
<?php if ($counts['total'] === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'component', 'heading' => t('No components yet.'), 'text' => t('A component is a block you use in several places – a service card, a contact strip, a call to action. In the builder, select an element and choose “Save as component”; when you edit it later, it changes everywhere at once.'), 'action' => [$module->url('new'), t('Create a component')]]) ?>
<?php else: ?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Components'), 'tabs' => [$tab('', t('All'), $counts['total']), $tab('changes', t('Unpublished changes'), $counts['changes']), $tab('unused', t('Unused'), $counts['unused'])]]) ?>
<?php if ($components === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'component', 'heading' => t('No component matches the filter.'), 'text' => '', 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Component')) ?></th><th scope="col"><?= e(t('Used on')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($components as $k):
    $builder = $module->url('builder', ['id' => $k['public_id']]);
    $changed = $k['build_draft'] !== null && $k['build'] !== null;
?>
<tr>
	<td class="cell-main"><div class="cell-row"><span class="cell-icon"><?= $icon('layers') ?></span><div class="cell-text"><a class="cell-title" href="<?= e($builder) ?>"><?= e($k['name']) ?></a><span class="cell-sub"><?= $k['properties'] === [] ? e(t('no properties')) : implode(' ', array_map(fn (array $v): string => '<code>{{' . e($v['key']) . '}}</code>', $k['properties'])) ?></span></div></div></td>
	<td><?php if ($k['usage'] === 0): ?><span class="status-note-warning"><?= e(t('nowhere – can be deleted')) ?></span><?php else: ?>
		<button class="places" type="button" popovertarget="places-<?= e($k['public_id']) ?>" aria-label="<?= e(t('Where %s is used', $k['name'])) ?>"><span><?= e(t('%s×', (string) $k['usage'])) ?></span> <span class="cell-sub"><?= e(implode(', ', array_slice($k['places'], 0, 2))) ?></span></button>
		<div class="row-menu" id="places-<?= e($k['public_id']) ?>" popover><p class="row-menu-note"><?= e(t('Used on')) ?></p><ul class="places-list"><?php foreach ($k['places'] as $place): ?><li><?= e($place) ?></li><?php endforeach ?></ul></div>
	<?php endif ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $k['build'] !== null ? 'live' : 'draft', 'label' => $k['build'] !== null ? t('Published') : t('Draft'),
        'notes' => $changed ? [['text' => t('Changes not published'), 'url' => $builder]] : []]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $k['public_id'], 'name' => $k['name'], 'edit' => $builder, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('edit', ['id' => $k['public_id']]), 'label' => t('Name and properties')], '-',
        ['post' => $module->url('delete'), 'fields' => ['component_id' => $k['public_id']], 'label' => t('Delete'), 'danger' => true,
            'confirm' => $k['usage'] > 0 ? t('The component “%s” is used on: %s. Deleting it leaves an empty space there. Delete it anyway?', $k['name'], implode(', ', array_slice($k['places'], 0, 8)) . (count($k['places']) > 8 ? ' ' . t('and %d more', count($k['places']) - 8) : '')) : t('Really delete this component?')],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php endif ?>
