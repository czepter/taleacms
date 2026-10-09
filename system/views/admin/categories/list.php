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
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New category')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Back to news')) ?></a></p>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="categories"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($category === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rubriky', 'heading' => t('No category has been created yet.'), 'text' => t('Every news item belongs to one category – without it, the news item cannot be saved.'), 'action' => [$module->url('new'), t('Create the first category')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($category as $k): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $k['idt']])) ?>"><?= e($k['nazev']) ?></a><?= ($k['language'] ?? '') !== '' ? ' <span class="stitek">' . e(strtoupper($k['language'])) . '</span>' : '' ?></td>
	<td><?= e('/' . (($k['language'] ?? '') !== '' ? $k['language'] . '/' : '') . ltrim(substr($app->url('novinky/kategorie/' . $k['slug']), strlen($app->request->basePath())), '/')) ?></td>
	<td class="cislo"><?= (int) $k['pocet_clanku'] ?></td>
	<td class="cislo"><?= (int) $k['weight'] ?></td>
	<td class="akce">
		<a href="<?= e($module->url('edit', ['id' => $k['idt']])) ?>"><?= e(t('Edit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Really delete the category?')) ?>"><?= $csrf ?><input type="hidden" name="idt" value="<?= (int) $k['idt'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
