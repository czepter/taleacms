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
 * @var list<array{ids: int, titulek: string}> $pages
 */
?>
<form class="formular" method="post" action="<?= e($module->url('save_variant', ['type' => $type, 'language' => $language])) ?>">
<?= $csrf ?>
<input type="hidden" name="varianta" value="<?= e($variant) ?>">
<p class="napoveda"><?= e(t('A variant applies only to the selected pages; elsewhere the default stays. For example a landing page with a simpler header. If you leave the variant empty in the builder, the page will have no header (footer).')) ?></p>
<div class="radek"><label for="nazev"><?= e(t('Variant name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($name) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Landing page')) ?>"></div></div>
<div class="radek"><span class="popisek"><?= e(t('Pages')) ?></span><div class="volby">
<?php foreach ($pages as $s): ?>
	<label><input type="checkbox" name="stranky[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $selected, true) ? ' checked' : '' ?>> <?= e($s['titulek']) ?></label><br>
<?php endforeach ?>
</div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($variant === '' ? 'Vytvořit a otevřít v builderu' : 'Uložit')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
