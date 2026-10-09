<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Categories $module
 * @var string $csrf
 * @var list<array<string, mixed>> $category
 * @var list<string> $siteLanguages
 * @var string $language
 */
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New category')) ?></a> <a class="navigation" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Back to news')) ?></a></p>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text"><input type="hidden" name="module" value="categories"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($category === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'categories', 'heading' => t('No category has been created yet.'), 'text' => t('Every news item belongs to one category – without it, the news item cannot be saved.'), 'action' => [$module->url('new'), t('Create the first category')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('URL')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Order')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($category as $k): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $k['category_id']])) ?>"><?= e($k['name']) ?></a><?= ($k['language'] ?? '') !== '' ? ' <span class="badge">' . e(strtoupper($k['language'])) . '</span>' : '' ?></td>
	<td><?= e('/' . (($k['language'] ?? '') !== '' ? $k['language'] . '/' : '') . ltrim(substr($app->url('news/category/' . $k['slug']), strlen($app->request->basePath())), '/')) ?></td>
	<td class="number"><?= (int) $k['news_count'] ?></td>
	<td class="number"><?= (int) $k['weight'] ?></td>
	<td class="actions">
		<a href="<?= e($module->url('edit', ['id' => $k['category_id']])) ?>"><?= e(t('Edit')) ?></a> ·
		<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Really delete the category?')) ?>"><?= $csrf ?><input type="hidden" name="category_id" value="<?= (int) $k['category_id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
