<?php
/**
 * CSV/JSON import of collection items (3.7, Builder\ItemImport): the upload, and the imports that are not finished.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $unfinished
 * @var list<array<string, mixed>> $finished
 */
use Kaleta\Builder\ItemImport;

$keys = implode(', ', array_merge(['name', 'slug'], array_column($k['pole'], 'klic')));
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Back to items')) ?></a></p>
<?php if ($unfinished !== []): ?>
<h2><?= e(t('Imports not finished')) ?></h2>
<ul>
<?php foreach ($unfinished as $u): ?>
	<li><a href="<?= e($module->url($u['faze'] === 'nahled' ? 'import_preview' : 'import_progress', ['id' => $k['idk'], 'import' => $u['id']])) ?>"><?= e($u['soubor']) ?></a>
		<span class="smltxt"><?= e(format_date((string) $u['zalozeno'], true)) ?> · <?= e(t('%s rows', (int) $u['radku'])) ?></span>
		<form class="vradku" method="post" action="<?= e($module->url('import_delete')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="import" value="<?= e($u['id']) ?>"><button class="navigace" type="submit"><?= e(t('Remove')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if ($finished !== []): ?>
<h2><?= e(t('Finished imports')) ?></h2>
<p class="napoveda"><?= e(t('The result of an import stays here until you remove it, at most %s days; the items stay either way. The uploaded rows are deleted as soon as they are saved.', Kaleta\Core\WpFile::KEEP_DAYS)) ?></p>
<ul>
<?php foreach ($finished as $u): ?>
	<li><a href="<?= e($module->url('import_progress', ['id' => $k['idk'], 'import' => $u['id']])) ?>"><?= e($u['soubor']) ?></a>
		<span class="smltxt"><?= e(format_date((string) $u['zalozeno'], true)) ?> · <?= e(t('%s rows', (int) $u['radku'])) ?></span>
		<form class="vradku" method="post" action="<?= e($module->url('import_delete')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="import" value="<?= e($u['id']) ?>"><button class="navigace" type="submit"><?= e(t('Remove')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('import_upload')) ?>" enctype="multipart/form-data" id="import-polozek">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<p class="napoveda"><?= e(t('A CSV file (from Excel or another system – comma, semicolon or tab) or a JSON list, up to %s rows. The first row names the columns; one row is one item. You pair the columns with the fields and see what will be added, changed or refused before anything is saved.', number_format(ItemImport::MAX_ROWS, 0, '', ' '))) ?></p>
<p class="napoveda"><?= e(t('Columns named like the fields are paired by themselves: %s.', $keys)) ?> <?= e(t('An image or file column with web addresses (https://…) is downloaded into Media after the items are saved.')) ?></p>
<div class="radek"><label for="soubor"><?= e(t('CSV or JSON file')) ?></label><div><input type="file" id="soubor" name="soubor" accept=".csv,.json,text/csv,application/json,text/plain"></div></div>
<div class="radek"><label for="text"><?= e(t('Or paste the rows')) ?></label><div><textarea class="textpole siroke" id="text" name="text" rows="5" placeholder="name;slug;<?= e((string) ($k['pole'][0]['klic'] ?? 'field')) ?>"></textarea></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Show what will change')) ?>"></p>
</form>
