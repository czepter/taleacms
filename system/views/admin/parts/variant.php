<?php
/**
 * A header or footer variant: name and the pages on which it applies instead of the default version.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var string $type
 * @var string $language
 * @var string $variant
 * @var string $name
 * @var list<int> $selected
 * @var list<array{page_id: int, title: string}> $pages
 */
?>
<form class="form" method="post" action="<?= e($module->url('save_variant', ['type' => $type, 'language' => $language])) ?>">
<?= $csrf ?>
<input type="hidden" name="variant" value="<?= e($variant) ?>">
<p class="help"><?= e(t('A variant applies only to the selected pages; elsewhere the default stays. For example a landing page with a simpler header. If you leave the variant empty in the builder, the page will have no header (footer).')) ?></p>
<div class="row"><label for="name"><?= e(t('Variant name')) ?></label><div><input class="textfield wide" id="name" name="name" value="<?= e($name) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Landing page')) ?>"></div></div>
<div class="row"><span class="caption"><?= e(t('Pages')) ?></span><div class="options">
<?php foreach ($pages as $s): ?>
	<label><input type="checkbox" name="pages[]" value="<?= (int) $s['page_id'] ?>"<?= in_array((int) $s['page_id'], $selected, true) ? ' checked' : '' ?>> <?= e($s['title']) ?></label><br>
<?php endforeach ?>
</div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t($variant === '' ? 'Create and open in the builder' : 'Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
