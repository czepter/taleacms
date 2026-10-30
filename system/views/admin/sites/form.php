<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var string $name
 * @var string $from  public id of the site to copy, '' = an empty site
 * @var string $error
 * @var list<array<string, mixed>> $sites  the sites that can be copied
 * @var list<array{owner: string, owner_name: string, site: array<string, mixed>}> $foreign  sites of other owners an operator may copy
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<div><input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required><?= $error !== '' ? '<span class="error-field" role="alert">' . e(t($error)) . '</span>' : '' ?></div>
</div>
<fieldset>
<legend><?= e(t('Start from')) ?></legend>
<div class="cards-options cards-options-text">
	<label class="card-option"><input type="radio" name="from" value=""<?= $from === '' ? ' checked' : '' ?>><strong><?= e(t('An empty site')) ?></strong><span><?= e(t('No pages or news. Mail, AI, time zone and language settings are taken over.')) ?></span></label>
<?php foreach ($sites as $s): ?>
	<label class="card-option"><input type="radio" name="from" value="<?= e($s['public_id']) ?>"<?= $from === $s['public_id'] ? ' checked' : '' ?>><strong><?= e(t('A copy of %s', $s['name'] !== '' ? $s['name'] : $s['slug'])) ?></strong><span><?= e(t('Pages, news, collections, look and settings are copied. Statistics, queues and logs start empty.')) ?></span></label>
<?php endforeach ?>
<?php foreach ($foreign as $f): $s = $f['site']; ?>
	<label class="card-option"><input type="radio" name="from" value="<?= e($s['public_id']) ?>"<?= $from === $s['public_id'] ? ' checked' : '' ?>><strong><?= e(t('A copy of %s', ($s['name'] !== '' ? $s['name'] : $s['slug']) . ' (' . $f['owner_name'] . ')')) ?></strong><span><?= e(t('A site of another owner. Its content, look, media files, components and facts are copied; nothing stays linked to that owner.')) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<p class="small-text"><?= e(t('The new site is paused. Give it a host name, check it, and set it live.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create site')) ?>"></p>
</form>
