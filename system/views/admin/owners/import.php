<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var string $csrf
 * @var string $name
 * @var string $to  public id of the owner that gets the site
 * @var string $error
 * @var list<array<string, mixed>> $owners  the owners that can get a site
 * @var list<array{file: string, size: int, time: int}> $files  Talea exports in storage/import
 * @var int $uploadLimit
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to owners')) ?></a></p>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('import')) ?>">
<?= $csrf ?>
<?= $error !== '' ? '<p class="notice notice-error" role="alert">' . e(t($error)) . '</p>' : '' ?>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required>
</div>
<div class="row">
	<label for="to"><?= e(t('Owner that gets the site')) ?></label>
	<select id="to" name="to" required>
		<option value=""></option>
<?php foreach ($owners as $o): ?>
		<option value="<?= e($o['public_id']) ?>"<?= $to === $o['public_id'] ? ' selected' : '' ?>><?= bdi($o['name'] !== '' ? $o['name'] : $o['prefix']) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="row"><label for="file"><?= e(t('Talea export')) ?></label><div><input type="file" id="file" name="file" accept=".zip,.json,application/zip,application/json">
	<span class="help"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span></div></div>
<?php if ($files !== []): ?>
<div class="row"><label for="existing"><?= e(t('Or a file on the server')) ?></label><select id="existing" name="existing">
	<option value=""></option>
<?php foreach ($files as $f): ?>
	<option value="<?= e($f['file']) ?>"><?= bdi($f['file'] . ' (' . Talea\Core\Files::size($f['size']) . ')') ?></option>
<?php endforeach ?>
</select></div>
<?php endif ?>
<p class="small-text"><?= e(t('The archive becomes a new site of that owner, set up by the owner’s scheduled tasks. Accounts are not imported and the news get no author; the site lists the people the archive names.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Import as a new site')) ?>"></p>
</form>
