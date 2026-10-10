<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Categories $module
 * @var string $csrf
 * @var array<string, mixed> $category
 * @var array<string, string> $errors
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="category_id" value="<?= e($category['public_id']) ?>">
<div class="row">
	<label for="name"><?= e(t('Category name')) ?></label>
	<div><input class="textfield wide" type="text" id="name" name="name" value="<?= e($category['name']) ?>" maxlength="100" required><?= $error('name') ?></div>
</div>
<div class="row">
	<label for="slug"><?= e(t('URL')) ?></label>
	<div><input class="textfield wide" type="text" id="slug" name="slug" value="<?= e($category['slug']) ?>" maxlength="110" placeholder="<?= e(t('generated from the name')) ?>">
	<span class="help"><?= e(t('The part of the address after %s.', substr($app->url('news/category/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="row">
	<label for="description"><?= e(t('Description')) ?></label>
	<div><textarea class="textbox" id="description" name="description" rows="4"><?= e($category['description']) ?></textarea>
	<span class="help"><?= e(t('Shown above the category\'s news list and used as the description for search engines.')) ?></span></div>
</div>
<div class="row">
	<label for="weight"><?= e(t('Order')) ?></label>
	<div><input class="textfield" type="number" id="weight" name="weight" value="<?= (int) $category['weight'] ?>" min="0" max="65535">
	<span class="help"><?= e(t('Higher number = higher in the list.')) ?></span></div>
</div>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($category['language'] ?? ''), 'translationOf' => $app->db()->publicId('categories', (int) ($category['translation_of'] ?? 0)), 'originals' => $app->db()->pairs("SELECT public_id, name FROM {categories} WHERE language = '' ORDER BY name"), 'hint' => t('News in this category belongs to this language version of the site.')]) ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t($category['category_id'] ? 'Save' : 'Add')) ?>"></p>
</form>
