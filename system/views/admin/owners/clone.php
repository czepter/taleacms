<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var string $csrf
 * @var string $name
 * @var string $from  public id of the site to copy
 * @var string $to  public id of the owner that gets the copy
 * @var string $error
 * @var list<array{owner: string, owner_name: string, owner_public_id: string, site: array<string, mixed>}> $sources  every owner's sites that are set up
 * @var list<array<string, mixed>> $owners  the owners that can get a copy
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to owners')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('clone')) ?>">
<?= $csrf ?>
<?= $error !== '' ? '<p class="notice notice-error" role="alert">' . e(t($error)) . '</p>' : '' ?>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required>
</div>
<div class="row">
	<label for="from"><?= e(t('Site to copy')) ?></label>
	<select id="from" name="from" required>
		<option value=""></option>
<?php foreach ($sources as $f): $s = $f['site']; ?>
		<option value="<?= e($s['public_id']) ?>"<?= $from === $s['public_id'] ? ' selected' : '' ?>><?= bdi(($s['name'] !== '' ? $s['name'] : $s['slug']) . ' (' . $f['owner_name'] . ')') ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="row">
	<label for="to"><?= e(t('Owner that gets the copy')) ?></label>
	<select id="to" name="to" required>
		<option value=""></option>
<?php foreach ($owners as $o): ?>
		<option value="<?= e($o['public_id']) ?>"<?= $to === $o['public_id'] ? ' selected' : '' ?>><?= bdi($o['name'] !== '' ? $o['name'] : $o['prefix']) ?></option>
<?php endforeach ?>
	</select>
</div>
<p class="small-text"><?= e(t('Across owners the content, look, media files, components and facts are copied and nothing stays linked. The mail, AI and other account settings of the source are never copied. The new site is paused; give it a host name and set it live.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create site')) ?>"></p>
</form>
