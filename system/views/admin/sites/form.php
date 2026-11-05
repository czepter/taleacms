<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var string $name
 * @var string $from  public id of the site to copy, '' = an empty site
 * @var string $error
 * @var list<array<string, mixed>> $sites  the sites that can be copied
 * @var string $parent  public id of the site whose look the new site takes, '' = none
 * @var list<array<string, mixed>> $parents  the sites that can be a look parent
 * @var list<array{file: string, size: int, time: int}> $files  Talea exports in storage/import (the first owner only)
 * @var string $existing  a file already in storage/import that was chosen
 * @var int $uploadLimit
 * @var list<array<string, mixed>> $templates  site templates an empty site may start from (Builder\SiteTemplates::all)
 * @var string $template  the template chosen
 * @var bool $previews  the templates' pictures can be rendered here
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('create')) ?>" data-dropzone-form>
<?= $csrf ?>
<fieldset class="panel-card">
<legend><?= e(t('Start from')) ?></legend>
<div class="cards-options cards-options-text">
	<label class="card-option"><input type="radio" name="from" value=""<?= $from === '' ? ' checked' : '' ?>><strong><?= e(t('An empty site')) ?></strong><span><?= e(t('No pages or news. Mail, AI, time zone and language settings are taken over.')) ?></span></label>
<?php if ($sites !== []): $copying = $from !== '' && $from !== 'file'; // one card for copying, the site chosen in its list ?>
	<label class="card-option"><input type="radio" name="from" value="copy"<?= $copying ? ' checked' : '' ?>><strong><?= e(t('A copy of a site')) ?></strong><span><?= e(t('Pages, news, collections, look and settings are copied. Statistics, queues and logs start empty.')) ?></span>
		<select name="copy_of" aria-label="<?= e(t('Site to copy')) ?>"><?php foreach ($sites as $s): ?><option value="<?= e($s['public_id']) ?>"<?= $from === $s['public_id'] ? ' selected' : '' ?>><?= bdi($s['name'] !== '' ? $s['name'] : $s['slug']) ?></option><?php endforeach ?></select></label>
<?php endif ?>
	<label class="card-option"><input type="radio" name="from" value="file"<?= $from === 'file' ? ' checked' : '' ?>><strong><?= e(t('Import from a file')) ?></strong><span><?= e(t('A Talea export (.zip) of another site becomes this new site.')) ?></span></label>
</div>
</fieldset>
<?php if ($templates !== []): // an empty site may start from a finished design: applied as drafts when an administrator first opens it ?>
<fieldset class="panel-card site-design">
<legend><?= e(t('Design of the empty site')) ?></legend>
<div class="site-design-grid">
	<label class="card-option"><input type="radio" name="template" value=""<?= $template === '' ? ' checked' : '' ?>><strong><?= e(t('No template')) ?></strong><span><?= e(t('Start without pages and choose a look later.')) ?></span></label>
<?php foreach ($templates as $tpl): ?>
	<label class="card-option site-design-option"><input type="radio" name="template" value="<?= e($tpl['key']) ?>"<?= $template === $tpl['key'] ? ' checked' : '' ?>>
<?php if ($previews): ?>
		<span class="template-preview"><iframe src="<?= e($app->url('admin.php?module=appearance&action=template_preview&key=' . rawurlencode($tpl['key']))) ?>" title="<?= e(t('Home page of the template %s', $tpl['name'])) ?>" loading="lazy" tabindex="-1" inert></iframe></span>
<?php endif ?>
		<strong><?= e($tpl['name']) ?></strong><span><?= e(implode(' · ', $tpl['pages'])) ?></span></label>
<?php endforeach ?>
</div>
<p class="small-text"><?= e(t('The template waits as drafts until you first open the new site; nothing is public before you publish it.')) ?></p>
</fieldset>
<?php endif ?>
<section class="panel-card start-file">
<h2><?= e(t('The file')) ?></h2>
<label class="dropzone" data-dropzone data-dropzone-manual for="site-file">
	<strong><?= e(t('Drop a file here or choose one')) ?></strong>
	<span class="chips"><span class="chip"><?= e(t('Talea export (.zip)')) ?></span></span>
	<input type="file" id="site-file" name="file" accept=".zip,.json,application/zip,application/json">
	<span class="help" data-dropzone-name><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span>
</label>
<p class="small-text"><?= e(t('Talea reads the file to see what it is (the same detection as on Import and export); a WordPress export is not accepted here.')) ?></p>
<?php if ($files !== []): ?>
<div class="row"><label for="existing"><?= e(t('Or a file on the server')) ?></label><select id="existing" name="existing">
	<option value=""></option>
<?php foreach ($files as $f): ?>
	<option value="<?= e($f['file']) ?>"<?= $existing === $f['file'] ? ' selected' : '' ?>><?= bdi($f['file'] . ' (' . Talea\Core\Files::size($f['size']) . ')') ?></option>
<?php endforeach ?>
</select></div>
<?php endif ?>
</section>
<section class="panel-card">
<h2><?= e(t('Details')) ?></h2>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<div><input class="textfield" type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="120" required><?= $error !== '' ? '<span class="error-field" role="alert">' . e(t($error)) . '</span>' : '' ?></div>
</div>

<?php if ($parents !== []): ?>
<div class="row">
	<label for="look_parent"><?= e(t('Join the look family of')) ?></label>
	<div><select id="look_parent" name="look_parent">
		<option value=""><?= e(t('None – the site has its own look')) ?></option>
<?php foreach ($parents as $p): ?>
		<option value="<?= e($p['public_id']) ?>"<?= $parent === $p['public_id'] ? ' selected' : '' ?>><?= bdi($p['name'] !== '' ? $p['name'] : $p['slug']) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('The new site gets the design system, classes, header and footer of that site, and again whenever it publishes them.')) ?></span></div>
</div>
<?php endif ?>
</section>
<p class="small-text"><?= e(t('The new site is paused. Give it a host name, check it, and set it live.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create site')) ?>"></p>
</form>
