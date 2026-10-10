<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Menu $module
 * @var string $csrf
 * @var string $location  hlavni | paticka
 * @var string $language     the language column ('' = default)
 * @var bool $automatic the main menu is still built automatically
 * @var bool $inDraft the items come from the draft look (Core\Look)
 * @var list<array<string, mixed>> $items
 * @var list<array{ids:int, titulek:string, skryta:bool}> $pages
 * @var array<string, string> $languages
 */
$choice = ['location' => $location, 'language' => $language];
?>
<nav class="tabs" aria-label="<?= e(t('Menu')) ?>">
<?php foreach (Kaleta\Core\Menu::LOCATIONS as $key => $name): ?>
	<a href="<?= e($module->url('', ['location' => $key, 'language' => $language])) ?>"<?= $key === $location ? ' class="active" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php if (count($languages) > 1): ?>
<p class="small-text"><?= e(t('Language version:')) ?>
<?php foreach ($languages as $code => $name): ?>
	<a class="navigation<?= $code === $language ? ' active' : '' ?>" href="<?= e($module->url('', ['location' => $location, 'language' => $code])) ?>"<?= $code === $language ? ' aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?></p>
<?php endif ?>
<p class="small-text"><?= e(t($location === 'main'
    ? ($automatic ? 'The menu is currently built automatically from pages ticked “in navigation”. Once you edit and save it here, this version applies.' : 'Reorder by dragging or with the arrows. The right arrow moves an item into the submenu of the one above.')
    : 'Links in the site footer (privacy policy, contact, careers…). Used by the default footer and by a Navigation element set to the footer menu.')) ?> <?= e(t('An icon shows before the text. The description and group columns (a group inside a submenu with its own items) appear in a mega menu – the Navigation element with “Submenu as a wide panel”.')) ?></p>

<form method="post" action="<?= e($module->url('save', $choice)) ?>" class="menu-form" data-menu>
<?= $csrf ?>
<input type="hidden" name="items" value="">
<script type="application/json" data-menu-data><?= json_encode(['items' => $items, 'pages' => $pages, 'icons' => ['' => t('no icon')] + array_map(fn (string $n): string => t($n), Kaleta\Builder\Icons::options())], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<ol class="menu-editor" data-menu-list></ol>
<p class="help" data-menu-empty hidden><?= e(t('The menu is empty – add the first item.')) ?></p>
<fieldset class="menu-add">
	<legend><?= e(t('Add item')) ?></legend>
	<label><?= e(t('Page')) ?>
		<select data-menu-page>
<?php foreach ($pages as $s): ?>
			<option value="<?= e($s['page_id']) ?>"><?= e($s['title']) ?><?= $s['hidden'] ? ' (' . e(t('hidden')) . ')' : '' ?></option>
<?php endforeach ?>
		</select>
	</label>
	<button class="navigation" type="button" data-menu-add="page"><?= e(t('Add page')) ?></button>
	<button class="navigation" type="button" data-menu-add="link"><?= e(t('Custom link')) ?></button>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'news')): ?>
	<button class="navigation" type="button" data-menu-add="news"><?= e(t('News')) ?></button>
<?php endif ?>
	<button class="navigation" type="button" data-menu-add="group" title="<?= e(t('An item without a link that only opens a submenu')) ?>"><?= e(t('Group')) ?></button>
</fieldset>
<p class="buttons"><button class="btn" type="submit"><?= e(t('Save menu')) ?></button></p>
</form>
<?php if (!$automatic || $location !== 'main'): ?>
<div class="navigation-row actions-bottom"><form class="inline" method="post" action="<?= e($module->url('automatic', $choice)) ?>" data-confirm="<?= e(t($location === 'main' ? 'Return the menu to being built automatically from pages? Your changes will be discarded.' : 'Empty the footer menu?')) ?>"><?= $csrf ?><button class="navigation danger" type="submit"><?= e(t($location === 'main' ? 'Back to automatic menu' : 'Empty the menu')) ?></button></form></div>
<?php endif ?>
<script src="<?= e($app->url('image/menu.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
