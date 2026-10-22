<?php
/**
 * News categories (docs/specs/admin-lists.md).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Categories $module
 * @var string $csrf
 * @var list<array<string, mixed>> $category
 * @var list<string> $siteLanguages
 * @var string $language
 */
echo $app->view->render('admin/list/head', [
    'count' => (string) count($category),
    'links' => [[$app->url('admin.php?module=news'), t('Back to news')]],
    'primary' => [$module->url('new'), t('New category')],
]);
?>
<?php if ($siteLanguages !== []): ?>
<?= $app->view->render('admin/list/toolbar', ['tabs' => [], 'tabsLabel' => '', 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'categories'],
    'filters' => $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true])]) ?>
<?php endif ?>
<?php if ($category === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'categories', 'heading' => t('No category has been created yet.'), 'text' => t('Every news item belongs to one category – without it, the news item cannot be saved.'), 'action' => [$module->url('new'), t('Create the first category')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Category')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Order')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($category as $k):
    $edit = $module->url('edit', ['id' => $k['public_id']]);
    $address = '/' . (($k['language'] ?? '') !== '' ? $k['language'] . '/' : '') . ltrim(substr($app->url('news/category/' . $k['slug']), strlen($app->request->basePath())), '/');
?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-row"><a class="cell-title" href="<?= e($edit) ?>"><?= e($k['name']) ?></a><?= ($k['language'] ?? '') !== '' ? ' <span class="tag">' . e(strtoupper($k['language'])) . '</span>' : '' ?></span><a class="cell-sub" href="<?= e($app->url(ltrim($address, '/'))) ?>" target="_blank" rel="noopener"><?= e($address) ?></a></div></td>
	<td class="number"><a href="<?= e($app->url('admin.php?module=news&category=' . $k['public_id'])) ?>"><?= (int) $k['news_count'] ?></a></td>
	<td class="number"><?= (int) $k['weight'] ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $k['public_id'], 'name' => $k['name'], 'edit' => $edit, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('delete'), 'fields' => ['category_id' => $k['public_id']], 'label' => t('Delete'), 'confirm' => t('Really delete the category?'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
