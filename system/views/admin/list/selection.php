<?php
/**
 * The bar for the selected rows (docs/specs/admin-lists.md). Rows join it with form="<id>" on their checkbox
 * (name="selected[]"); CSS shows it and counts the selection, the reset button clears every associated checkbox.
 *
 * @var string $form       the form id, normally "bulk"
 * @var string $action
 * @var string $csrf
 * @var array<string, string> $hidden
 * @var list<array{value?: string, label: string, kind?: string, confirm?: string, formaction?: string}> $actions  kind: primary | danger | ''; value → name="bulk"
 * @var string $extra      raw HTML placed before the buttons (e.g. a category select)
 */
$icon = require __DIR__ . '/../icons.php';
$hidden ??= [];
$extra ??= '';
?>
<form id="<?= e($form) ?>" class="selection-bar" method="post" action="<?= e($action) ?>"><?= $csrf ?>
<?php foreach ($hidden as $name => $value): ?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
<span class="selection-count"><span class="selection-number"></span> <?= e(t('selected')) ?></span>
<?= $extra ?>
<?php foreach ($actions as $a): $kind = $a['kind'] ?? ''; ?>
<button class="<?= $kind === 'primary' ? 'btn' : 'navigation' . ($kind === 'danger' ? ' danger' : '') ?>" type="submit"<?= isset($a['value']) ? ' name="bulk" value="' . e($a['value']) . '"' : '' ?><?= isset($a['formaction']) ? ' formaction="' . e($a['formaction']) . '"' : '' ?><?= isset($a['confirm']) ? ' data-confirm="' . e($a['confirm']) . '"' : '' ?>><?= e($a['label']) ?></button>
<?php endforeach ?>
<button class="icon-btn" type="reset" aria-label="<?= e(t('Clear selection')) ?>" title="<?= e(t('Clear selection')) ?>"><?= $icon('close') ?></button>
</form>
