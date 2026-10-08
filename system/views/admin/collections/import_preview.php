<?php
/**
 * CSV/JSON import of collection items, the preview (3.7): the columns paired with the fields, and what saving would do
 * with every row. Nothing is saved yet.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $state  Builder\ItemImport
 * @var list<string> $header
 * @var list<list<string>> $sample  the first rows
 * @var list<array<string, mixed>> $plan  Builder\ItemBatch::plan
 * @var array<string, int> $counts  added, changed, unchanged, refused
 * @var list<string> $languages  the other languages of the site
 */
use Kaleta\Builder\ItemImport;

$labels = ['added' => t('will be added'), 'changed' => t('will change'), 'unchanged' => t('no change'), 'refused' => t('refused')];
$saving = $counts['added'] + $counts['changed'];
$shown = array_slice($plan, 0, 100);
$refusedLater = array_slice(array_values(array_filter(array_slice($plan, 100), fn (array $p): bool => $p['status'] === 'refused' || $p['invalid'] !== [])), 0, 100);
$sampleOf = function (int $i) use ($sample): string {
    foreach ($sample as $row) {
        if (($row[$i] ?? '') !== '') {
            return mb_strimwidth(strip_tags((string) $row[$i]), 0, 60, '…');
        }
    }

    return '';
};
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('import', ['id' => $k['idk']])) ?>"><?= e(t('Another file')) ?></a> <a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Back to items')) ?></a></p>
<p id="nahled-importu"><?= e(t('%s: %d rows – %d will be added, %d will change, %d stay as they are, %d are refused.', $state['soubor'], count($plan), $counts['added'], $counts['changed'], $counts['unchanged'], $counts['refused'])) ?></p>
<form class="formular" method="post" action="<?= e($module->url('import_map')) ?>" id="import-mapovani">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="import" value="<?= e($state['id']) ?>">
<fieldset>
<legend><?= e(t('Columns')) ?></legend>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Column in the file')) ?></th><th scope="col"><?= e(t('Example')) ?></th><th scope="col"><?= e(t('Goes to')) ?></th></tr></thead>
<tbody>
<?php foreach ($header as $i => $column): ?>
<tr><td><label for="mapovani-<?= $i ?>"><?= e($column) ?></label></td><td class="smltxt"><?= e($sampleOf($i)) ?></td>
	<td><select id="mapovani-<?= $i ?>" name="mapovani[<?= $i ?>]">
		<option value=""><?= e(t('– not imported –')) ?></option>
		<optgroup label="<?= e(t('Item')) ?>">
<?php foreach (ItemImport::TARGETS as $target => $label): ?>
			<option value="<?= e($target) ?>"<?= ($state['mapovani'][$i] ?? '') === $target ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
		</optgroup>
		<optgroup label="<?= e(t('Fields')) ?>">
<?php foreach ($k['pole'] as $field): ?>
			<option value="<?= e($field['klic']) ?>"<?= ($state['mapovani'][$i] ?? '') === $field['klic'] ? ' selected' : '' ?>><?= e($field['popisek']) ?> (<?= e($field['klic']) ?>)</option>
<?php endforeach ?>
		</optgroup>
	</select></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<?php if ($languages !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Language')) ?></label><div><select id="jazyk" name="jazyk">
	<option value=""><?= e(t('Default language')) ?></option>
<?php foreach ($languages as $language): ?>
	<option value="<?= e($language) ?>"<?= $state['jazyk'] === $language ? ' selected' : '' ?>><?= e(strtoupper($language)) ?></option>
<?php endforeach ?>
</select> <span class="napoveda"><?= e(t('For rows without a language column.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Visibility')) ?></span><div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $state['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Show new items on the site at once')) ?></label>
	<span class="napoveda"><?= e(t('Without it new items arrive hidden, so you can check them first. Items that change keep their visibility.')) ?></span></div></div>
<p class="napoveda"><?= e(t('An item is found by its address (slug), or by the address made from its name – importing the same file again updates the items instead of adding them twice. An empty cell leaves the value of an item as it is.')) ?></p>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Update preview')) ?></button>
<?php if ($saving > 0): ?> <button class="tl" type="submit" name="ulozit" value="1"><?= e(t('Save %d items', $saving)) ?></button><?php endif ?></p>
</form>
<h2><?= e(t('Rows')) ?></h2>
<div class="tab-obal">
<table class="vypis" id="import-radky">
<thead><tr><th scope="col"><?= e(t('Row')) ?></th><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Address')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach ([...$shown, ...$refusedLater] as $p): ?>
<tr><td class="cislo"><?= (int) $p['index'] + 2 ?></td><td><?= e((string) $p['name']) ?></td><td><?= e((string) $p['slug']) ?><?= $p['language'] !== '' ? ' <span class="stitek">' . e(strtoupper((string) $p['language'])) . '</span>' : '' ?></td>
	<td><span class="stitek<?= $p['status'] === 'refused' ? ' stitek-chyba' : '' ?>"><?= e($labels[$p['status']] ?? $p['status']) ?></span><?= $p['reason'] !== '' ? ' <span class="smltxt">' . e(t((string) $p['reason'])) . '</span>' : '' ?>
		<?= $p['invalid'] !== [] ? ' <span class="smltxt">' . e(t('Invalid value, left empty: %s', implode(', ', $p['invalid']))) . '</span>' : '' ?>
		<?= $p['note'] !== [] ? ' <span class="smltxt">' . e(t(...$p['note'])) . '</span>' : '' ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if (count($plan) > 100): ?><p class="smltxt"><?= e(t('The first 100 rows are shown, then the refused ones and those with an invalid value.')) ?></p><?php endif ?>
