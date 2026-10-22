<?php
/**
 * Collections of the site.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var list<array<string, mixed>> $collection
 * @var array<string, array<string, mixed>> $presets
 */
$icon = require __DIR__ . '/../icons.php';
$admin = $app->auth()->isAdmin();
echo $app->view->render('admin/list/head', ['count' => (string) count($collection), 'primary' => $admin ? [$module->url('new'), t('New collection')] : null]);
?>
<?php if ($admin): ?>
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
<thead><tr><th scope="col"><?= e(t('Collection')) ?></th><th scope="col"><?= e(t('Items')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($collection as $k): $items = $module->url('items', ['id' => $k['public_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-row"><span class="cell-icon"><?= $icon('collections') ?></span><div class="cell-text"><a class="cell-title" href="<?= e($items) ?>"><?= e($k['name']) ?></a><span class="cell-sub"><?= $k['detail'] ? e(t('Item pages at %s', '/' . $k['slug'] . '/…')) : e(t('No item pages')) ?></span></div></div></td>
	<td class="number"><?= (int) $k['count'] ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $k['public_id'], 'name' => $k['name'], 'edit' => $items, 'csrf' => $csrf, 'menu' => array_merge(
        [['url' => $module->url('item', ['id' => $k['public_id']]), 'label' => t('Add item'), 'icon' => 'plus']],
        $admin ? [['url' => $module->url('edit', ['id' => $k['public_id']]), 'label' => t('Fields and settings')]] : [],
        $admin && $k['detail'] ? [['url' => $module->url('builder', ['id' => $k['public_id']]), 'label' => t('Detail template'), 'icon' => 'layers']] : [],
    )]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
