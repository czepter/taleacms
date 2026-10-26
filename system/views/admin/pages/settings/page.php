<?php
/**
 * The Page tab of the settings sidebar: title, address, parent page and the menu, then the page's language versions (a link
 * to each translation, or a way to create it). The home page's address is the root, so its field is not sent.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var string $host
 * @var bool $home
 * @var bool $inMenu
 * @var list<array{page_id:int, public_id:string, title:string, slug:string}> $parents
 * @var list<array{code:string, name:string, page:?array{public_id:string, visible:int}}> $translations
 */
$dir = Talea\Core\Language::dirAttribute(Talea\Core\Language::ofContent($app->settings(), (string) $page['language']));
$prefix = $host . '/' . ($page['language'] !== '' ? $page['language'] . '/' : '');
foreach ($parents as $p) {
    if ((int) $p['page_id'] === (int) $page['parent_id']) {
        $prefix .= $p['slug'] . '/';
    }
}
?>
<?= $formStart ?>
<section class="website-settings-section" aria-label="<?= e(t('Page')) ?>">
	<div class="website-field"><label for="ws-title"><?= e(t('Page title')) ?></label><input class="textfield" type="text" id="ws-title" name="title" value="<?= e($page['title']) ?>" maxlength="200" required<?= $dir ?>><span class="help"><?= e(t('Name in the page list, the browser tab and the menu.')) ?></span></div>
	<div class="website-field"><label for="ws-slug"><?= e(t('Address')) ?></label>
		<div class="website-slug"><span><?= e($home ? $host . '/' : $prefix) ?></span><input class="textfield" type="text" id="ws-slug" name="slug" maxlength="110"<?= $home ? ' value="" placeholder="' . e(t('site home page')) . '" disabled' : ' value="' . e(basename((string) $page['slug'])) . '"' ?>></div>
<?php if ($home): ?>
		<span class="help"><?= e(t('This is the home page: its address is the root. Choose another home page in Settings → General.')) ?></span>
<?php endif ?>
	</div>
	<div class="website-field"><label for="ws-parent"><?= e(t('Parent page')) ?></label><select id="ws-parent" name="parent_id">
		<option value="0"><?= e(t('— none (top level) —')) ?></option>
<?php foreach ($parents as $p): ?>
		<option value="<?= e($p['public_id']) ?>"<?= (int) $p['page_id'] === (int) $page['parent_id'] ? ' selected' : '' ?>><?= e(str_repeat('– ', substr_count($p['slug'], '/')) . $p['title']) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('A subpage has an address under its parent (/services/kitchens) and appears in its breadcrumbs.')) ?></span></div>
	<div class="website-pair">
		<div class="website-field"><span class="website-caption"><?= e(t('Menu')) ?></span><input type="hidden" name="in_menu" value="0"><label class="website-option"><input type="checkbox" name="in_menu" value="1"<?= $inMenu ? ' checked' : '' ?>> <?= e(t('Show in the menu')) ?></label></div>
		<div class="website-field"><label for="ws-order"><?= e(t('Order')) ?></label><input class="textfield" type="number" id="ws-order" name="sort_order" value="<?= (int) $page['sort_order'] ?>" min="0" max="65535"></div>
	</div>
</section>
</form>
<?php if ($translations !== []): ?>
<section class="website-settings-section" aria-labelledby="ws-languages">
	<h3 id="ws-languages"><?= e(t('Language versions')) ?></h3>
<?php foreach ($translations as $version): ?>
	<div class="website-language"><b><?= e(strtoupper($version['code'])) ?></b><span><?= e($version['name']) ?></span>
<?php if ($version['page'] !== null): ?>
		<a class="status <?= $version['page']['visible'] ? 'status-live' : 'status-hidden' ?>" href="<?= e($module->url('website', ['id' => $version['page']['public_id'], 'tab' => 'page'])) ?>"><?= e($version['page']['visible'] ? t('Published') : t('hidden')) ?></a>
<?php else: ?>
		<a class="navigation" href="<?= e($module->url('new', ['language' => $version['code'], 'translation_of' => $page['public_id']])) ?>"><?= e(t('Create')) ?></a>
<?php endif ?>
	</div>
<?php endforeach ?>
</section>
<?php endif ?>
