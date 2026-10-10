<?php
/**
 * Add-ons (3.0): what is in extensions/, what is on, and the pages add-ons added.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Addons $module
 * @var string $csrf
 * @var array<string, array<string, mixed>> $addons Extension\Registry::discover()
 * @var list<string> $enabled
 * @var array<string, string> $errors slug => the error that switched it off
 * @var array<string, array{slug: string, name: string, title: string}> $pages
 * @var bool $safeMode
 * @var bool $demo
 */
?>
<p><?= e(t('Add-ons are code from other developers. Copy an add-on into the folder extensions/<name>/ on your hosting and switch it on here. An add-on runs with the same rights as Talea – switch on only code you trust, from a source you know. Talea never uploads or downloads add-ons by itself.')) ?></p>
<p class="help"><?= e(t('A developer’s guide is in docs/EXTENSIONS.md. This Talea offers extension API %d.', Talea\Extension\Api::VERSION)) ?></p>
<?php if ($safeMode || $demo): ?><p class="notice error"><?= e($demo ? t('Add-ons do not run in the public demo.') : t('Add-ons are switched off in config.php (safe mode) – none is loaded.')) ?></p><?php endif ?>
<?php if ($addons === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'extensions', 'heading' => t('No add-on in extensions/ yet.'), 'text' => t('Most sites need none – the features of a business site are built in. A developer can write one with the extension API.'), 'action' => null]) ?>
<?php else: ?>
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Add-on')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($addons as $slug => $a): $on = in_array($slug, $enabled, true); ?>
<tr>
	<td><strong><?= e((string) $a['name']) ?></strong> <code class="small-text"><?= e($slug) ?></code><br><span class="small-text"><?= e((string) $a['description']) ?><?= $a['author'] !== '' ? ' – ' . e((string) $a['author']) : '' ?></span>
	<?php if (($errors[$slug] ?? '') !== ''): ?><br><span class="error-field"><?= e(t('Switched off after an error: %s', $errors[$slug])) ?></span><?php endif ?>
	<?php if ($a['problem'] !== ''): ?><br><span class="error-field"><?= e(t($a['problem'])) ?></span><?php endif ?></td>
	<td><?= e((string) $a['version']) ?></td>
	<td><?= e($on ? t('On') : t('Off')) ?></td>
	<td class="actions">
<?php if ($on): ?>
		<form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="on" value="0"><button class="navigation" type="submit"><?= e(t('Switch off')) ?></button></form>
<?php elseif ($a['problem'] === '' && !$demo): ?>
		<form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="on" value="1">
		<label class="small-text"><input type="checkbox" name="trust" value="1" required> <?= e(t('I trust this code')) ?></label> <button class="btn" type="submit"><?= e(t('Switch on')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
<?php if ($pages !== []): ?>
<h2><?= e(t('Pages of add-ons')) ?></h2>
<ul><?php foreach ($pages as $key => $p): ?><li><a href="<?= e($module->url('page', ['p' => $key])) ?>"><?= e($p['title']) ?></a> <span class="small-text">(<?= e($p['slug']) ?>)</span></li><?php endforeach ?></ul>
<?php endif ?>
