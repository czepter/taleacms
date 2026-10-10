<?php
/**
 * Components of the site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Components $module
 * @var string $csrf
 * @var list<array<string, mixed>> $components  including the number of uses (usage) and their places
 */
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New component')) ?></a></p>
<?php if ($components === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'component', 'heading' => t('No components yet.'), 'text' => t('A component is a block you use in several places – a service card, a contact strip, a call to action. In the builder, select an element and choose “Save as component”; when you edit it later, it changes everywhere at once.'), 'action' => [$module->url('new'), t('Create a component')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Component')) ?></th><th scope="col"><?= e(t('Properties')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($components as $k): ?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $k['public_id']])) ?>"><strong><?= e($k['name']) ?></strong></a><?= $k['build_draft'] !== null && $k['build'] !== null ? ' <span class="badge badge-draft">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td><?= $k['properties'] === [] ? '—' : implode(' ', array_map(fn (array $v): string => '<code>{{' . e($v['key']) . '}}</code>', $k['properties'])) ?></td>
	<td<?= $k['places'] !== [] ? ' title="' . e(implode(', ', $k['places'])) . '"' : '' ?>><?= e(t('%s×', (string) $k['usage'])) ?></td>
	<td class="actions"><a href="<?= e($module->url('builder', ['id' => $k['public_id']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $k['public_id']])) ?>"><?= e(t('Name and properties')) ?></a> ·
		<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e($k['usage'] > 0 ? t('The component “%s” is used on: %s. Deleting it leaves an empty space there. Delete it anyway?', $k['name'], implode(', ', array_slice($k['places'], 0, 8)) . (count($k['places']) > 8 ? ' ' . t('and %d more', count($k['places']) - 8) : '')) : t('Really delete this component?')) ?>"><?= $csrf ?><input type="hidden" name="component_id" value="<?= e($k['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
