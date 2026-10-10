<?php
/**
 * Bulk actions under a list (2.14): the checkboxes in the table carry form="hromadne", so the rows keep their own small
 * forms. With site languages the action "language version" appears with a choice; $categories adds "category" (news).
 *
 * @var string $csrf
 * @var string $action  URL of the module's bulk action
 * @var array<string, string> $actions  value => label (show, hide, trash…)
 * @var list<string> $siteLanguages  [] = a single-language site
 * @var array<int, string>|null $categories  idt => name (news)
 * @var array<string, string>|null $hidden  extra hidden fields (collection_id of a collection)
 */
use Kaleta\Core\Language;
?>
<form id="hromadne" class="bulk" method="post" action="<?= e($action) ?>" data-confirm="<?= e(t('Apply the action to the selected items?')) ?>">
	<?= $csrf ?>
<?php foreach ($hidden ?? [] as $name => $value): ?>
	<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
	<label><?= e(t('With selected:')) ?>
	<select name="bulk">
<?php foreach ($actions as $value => $label): ?>
		<option value="<?= e($value) ?>"><?= e($label) ?></option>
<?php endforeach ?>
<?php if (($categories ?? []) !== []): ?>
		<option value="kategorie"><?= e(t('Category…')) ?></option>
<?php endif ?>
<?php if ($siteLanguages !== []): ?>
		<option value="jazyk"><?= e(t('Language version…')) ?></option>
<?php endif ?>
	</select></label>
<?php if (($categories ?? []) !== []): ?>
	<select name="category" aria-label="<?= e(t('Category')) ?>">
<?php foreach ($categories as $idt => $name): ?>
		<option value="<?= (int) $idt ?>"><?= e($name) ?></option>
<?php endforeach ?>
	</select>
<?php endif ?>
<?php if ($siteLanguages !== []): ?>
	<select name="language" aria-label="<?= e(t('Language version')) ?>">
<?php foreach ($siteLanguages as $i => $code): ?>
		<option value="<?= $i === 0 ? '' : e($code) ?>"><?= e(Language::AVAILABLE[$code][0]) ?><?= $i === 0 ? ' (' . e(t('default')) . ')' : '' ?></option>
<?php endforeach ?>
	</select>
<?php endif ?>
	<button class="navigation" type="submit"><?= e(t('Apply')) ?></button>
</form>
