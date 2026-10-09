<?php
/**
 * CSV/JSON import of collection items, the saving (3.7): rows in batches, then the images, then the result. While it runs,
 * the form submits itself (data-auto-odeslat in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $state  Builder\ItemImport
 */
$c = $state['pocty'];
$images = $state['obrazky'];
$running = in_array($state['faze'], ['ulozeni', 'obrazky'], true);
?>
<p><?= e(t('File: %s', $state['soubor'])) ?></p>
<?php if ($running): ?>
<p class="hlaska" role="status"><?= e($state['faze'] === 'ulozeni' ? t('Saving: %s of %s rows. Keep this page open, it continues by itself.', (int) $state['pozice'], (int) $state['radku'])
    : t('Downloading images: %s of %s. Keep this page open, it continues by itself.', (int) $images['pozice'], count($images['fronta']))) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, $state['faze'] === 'ulozeni' ? (int) $state['radku'] : count($images['fronta'])) ?>" value="<?= $state['faze'] === 'ulozeni' ? (int) $state['pozice'] : (int) $images['pozice'] ?>"></progress>
<form method="post" action="<?= e($module->url('import_progress', ['id' => $k['idk']])) ?>" data-auto-odeslat="400"><?= $csrf ?><input type="hidden" name="import" value="<?= e($state['id']) ?>">
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php else: ?>
<p class="hlaska hlaska-ok" id="import-hotovo"><?= e($state['zobrazit'] ? t('The import is finished.') : t('The import is finished. New items are hidden until you check and show them.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $c['added'] ?></strong><span><?= e(t('Added')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $c['changed'] ?></strong><span><?= e(t('Changed')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $c['unchanged'] ?></strong><span><?= e(t('No change')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $c['refused'] ?></strong><span><?= e(t('Refused')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $images['stazeno'] ?></strong><span><?= e(t('Images in Media')) ?></span></div>
</div>
<?php if ((int) $state['neplatna'] > 0): ?><p class="hlaska hlaska-chyba"><?= e(t('%d items had a field with an invalid value – it was left empty. Check them in the list.', (int) $state['neplatna'])) ?></p><?php endif ?>
<?php if ($state['odmitnute'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('Refused rows: %d', (int) $c['refused'])) ?></summary><ul>
<?php foreach ($state['odmitnute'] as [$row, $name, $reason]): ?><li><?= e(t('Row %d', (int) $row)) ?><?= $name !== '' ? ' (' . e($name) . ')' : '' ?> – <?= e(t($reason)) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php if ($images['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('%d images could not be downloaded', (int) $images['chyb'])) ?></summary><ul>
<?php foreach ($images['chyby'] as [$name, $url, $error]): ?><li><?= e($name) ?> – <?= e($url) ?> – <?= e(t($error)) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Show items')) ?></a> <a class="navigace" href="<?= e($module->url('import', ['id' => $k['idk']])) ?>"><?= e(t('Import another file')) ?></a></p>
<form method="post" action="<?= e($module->url('import_delete')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="import" value="<?= e($state['id']) ?>">
	<p class="smltxt"><?= e(t('The result of an import stays here until you remove it, at most %s days; the items stay either way. The uploaded rows are deleted as soon as they are saved.', Kaleta\Core\WpFile::KEEP_DAYS)) ?> <button class="navigace" type="submit"><?= e(t('Remove this import')) ?></button></p></form>
<?php endif ?>
