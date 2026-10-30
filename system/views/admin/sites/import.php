<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var string $name
 * @var string $existing  a file already in storage/import that was chosen
 * @var string $error
 * @var list<array{file: string, size: int, time: int}> $files  Talea exports in storage/import (the first owner only)
 * @var int $uploadLimit
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<p class="small-text"><?= e(t('An export of an older Talea site (Import and export, Export of the whole site) becomes a new site here. Pages, news, collections, look and settings come over, the media files join the shared library, and the other sites keep working while it runs. Accounts are not imported; the new site lists the people the archive names so you can invite them.')) ?></p>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('import')) ?>">
<?= $csrf ?>
<?= $error !== '' ? '<p class="notice notice-error" role="alert">' . e(t($error)) . '</p>' : '' ?>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required>
</div>
<div class="row"><label for="file"><?= e(t('Talea export')) ?></label><div><input type="file" id="file" name="file" accept=".zip,.json,application/zip,application/json">
	<span class="help"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span></div></div>
<?php if ($files !== []): ?>
<div class="row"><label for="existing"><?= e(t('Or a file on the server')) ?></label><select id="existing" name="existing">
	<option value=""></option>
<?php foreach ($files as $f): ?>
	<option value="<?= e($f['file']) ?>"<?= $existing === $f['file'] ? ' selected' : '' ?>><?= bdi($f['file'] . ' (' . Talea\Core\Files::size($f['size']) . ')') ?></option>
<?php endforeach ?>
</select></div>
<?php endif ?>
<p class="small-text"><?= e(t('The new site is paused. When it is ready, give it a host name, check it, and set it live.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Import as a new site')) ?>"></p>
</form>
