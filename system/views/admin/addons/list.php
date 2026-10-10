<?php
/**
 * Add-ons (3.0): what is in extensions/, what is on, and the pages add-ons added.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Addons $module
 * @var string $csrf
 * @var array<string, array<string, mixed>> $addons Extension\Registry::discover()
 * @var list<string> $enabled
 * @var array<string, int> $pending slug => migrations of an enabled add-on that did not run yet
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
	<?php if ($a['problem'] !== ''): ?><br><span class="error-field"><?= e(t($a['problem'])) ?></span><?php endif ?>
	<?php if ($a['bundled']): ?><br><span class="small-text"><?= e(t('Official add-on, shipped with Talea and updated with it.')) ?></span><?php endif ?>
	<?php if ($a['requires_api'] >= 2): ?><br><span class="small-text"><strong><?= e(t('It declares that it:')) ?></strong>
		<?= $a['capabilities'] === [] ? e(t('needs nothing beyond the extension API.')) : e(implode('; ', array_map(fn (string $c): string => lcfirst(t(Talea\Extension\Api::CAPABILITIES[$c])), $a['capabilities']))) . '.' ?>
		<?= e(t('This is what it says about itself – Talea cannot check it, an add-on runs with the same rights as Talea.')) ?></span><?php endif ?>
	<?php if (isset($pending[$slug])): ?><br><span class="small-text"><?= e(t('%d database migration(s) of a newer version are waiting – switch the add-on off and on again to run them.', $pending[$slug])) ?></span><?php endif ?></td>
	<td><?= e((string) $a['version']) ?></td>
	<td><?= e($on ? t('On') : t('Off')) ?></td>
	<td class="actions">
<?php if ($on): ?>
		<form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="on" value="0"><button class="navigation" type="submit"><?= e(t('Switch off')) ?></button></form>
<?php elseif ($a['problem'] === '' && !$demo): ?>
		<form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="on" value="1">
		<label class="small-text"><input type="checkbox" name="trust" value="1" required> <?= e(t('I trust this code')) ?></label> <button class="btn" type="submit"><?= e(t('Switch on')) ?></button></form>
<?php if ($a['has_tables'] || $a['requires_api'] >= 2): ?>
			<form class="inline" method="post" action="<?= e($module->url('uninstall')) ?>"><?= $csrf ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
			<label class="small-text"><input type="radio" name="data" value="keep" checked> <?= e(t('Keep its data')) ?></label> <label class="small-text"><input type="radio" name="data" value="delete"> <?= e(t('Delete its data')) ?></label>
			<button class="navigation danger" type="submit" data-confirm="<?= e(t('Uninstall this add-on? With “Delete its data” its tables and settings are removed for good.')) ?>"><?= e(t('Uninstall')) ?></button></form>
<?php endif ?>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
<?php if ($pages !== []): ?>
<h2><?= e(t('Pages of add-ons')) ?></h2>
<ul><?php foreach ($pages as $key => $p): ?><li><a href="<?= e($module->url('page', ['p' => $key])) ?>"><?= e(t($p['title'])) ?></a> <span class="small-text">(<?= e($p['slug']) ?>)</span></li><?php endforeach ?></ul>
<?php endif ?>
