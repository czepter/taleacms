<?php
/**
 * Components of the site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Components $module
 * @var string $csrf
 * @var list<array<string, mixed>> $components  including the number of uses (pouziti) and their places (mista)
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New component')) ?></a></p>
<?php if ($components === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'component', 'heading' => t('No components yet.'), 'text' => t('A component is a block you use in several places – a service card, a contact strip, a call to action. In the builder, select an element and choose “Save as component”; when you edit it later, it changes everywhere at once.'), 'action' => [$module->url('new'), t('Create a component')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Component')) ?></th><th scope="col"><?= e(t('Properties')) ?></th><th scope="col"><?= e(t('Použitá')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($components as $k): ?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $k['component_id']])) ?>"><strong><?= e($k['name']) ?></strong></a><?= $k['build_draft'] !== null && $k['build'] !== null ? ' <span class="stitek stitek-koncept">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td><?= $k['properties'] === [] ? '—' : implode(' ', array_map(fn (array $v): string => '<code>{{' . e($v['key']) . '}}</code>', $k['properties'])) ?></td>
	<td<?= $k['mista'] !== [] ? ' title="' . e(implode(', ', $k['mista'])) . '"' : '' ?>><?= e(t('%s×', (string) $k['pouziti'])) ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', ['id' => $k['component_id']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $k['component_id']])) ?>"><?= e(t('Name and properties')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e($k['pouziti'] > 0 ? t('The component “%s” is used on: %s. Deleting it leaves an empty space there. Delete it anyway?', $k['name'], implode(', ', array_slice($k['mista'], 0, 8)) . (count($k['mista']) > 8 ? ' ' . t('and %d more', count($k['mista']) - 8) : '')) : t('Really delete this component?')) ?>"><?= $csrf ?><input type="hidden" name="component_id" value="<?= (int) $k['component_id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
