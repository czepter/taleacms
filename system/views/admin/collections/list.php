<?php
/**
 * Collections of the site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var list<array<string, mixed>> $collection
 * @var array<string, array<string, mixed>> $presets
 */
$admin = $app->auth()->isAdmin();
?>
<?php if ($admin): ?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New collection')) ?></a></p>
<details class="advanced presets-collections"<?= $collection === [] ? ' open' : '' ?>>
<summary><?= e(t('Start from a ready-made collection')) ?></summary>
<form method="post" action="<?= e($module->url('preset')) ?>"><?= $csrf ?>
<div class="presets-collections-grid">
<?php foreach ($presets as $key => $p): ?>
	<button type="submit" name="preset" value="<?= e($key) ?>"><strong><?= e(t((string) ($p['button'] ?? $p['name']))) ?></strong><span><?= e(t((string) $p['description'])) ?></span></button>
<?php endforeach ?>
</div>
</form>
</details>
<?php endif ?>
<?php if ($collection === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('No collections yet.'), 'text' => t('A collection is a list of similar things with their own fields – testimonials, team members, products, branches, a price list. Put them on the site with the Collection list element in the builder; each item can also have its own page.'), 'action' => $admin ? [$module->url('new'), t('Create a collection')] : null]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Collections')) ?></th><th scope="col"><?= e(t('Items')) ?></th><th scope="col"><?= e(t('Item pages')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($collection as $k): ?>
<tr>
	<td><a href="<?= e($module->url('items', ['id' => $k['collection_id']])) ?>"><strong><?= e($k['name']) ?></strong></a></td>
	<td><?= (int) $k['count'] ?></td>
	<td><?= $k['detail'] ? '/' . e($k['slug']) . '/…' : e(t('no')) ?></td>
	<td class="actions"><a href="<?= e($module->url('item', ['id' => $k['collection_id']])) ?>"><?= e(t('Add item')) ?></a><?php if ($admin): ?> · <a href="<?= e($module->url('edit', ['id' => $k['collection_id']])) ?>"><?= e(t('Fields and settings')) ?></a><?php if ($k['detail']): ?> · <a href="<?= e($module->url('builder', ['id' => $k['collection_id']])) ?>"><?= e(t('Detail template')) ?></a><?php endif ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
