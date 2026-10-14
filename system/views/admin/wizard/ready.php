<?php
/**
 * What using a look made (#46): the draft look, the features switched on, the starter pages as hidden drafts.
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var array{look: string, extensions: list<string>, pages: list<array{title: string, url: string}>} $result
 * @var string $preview signed link to the whole-site preview
 * @var array<string, array{0: string, 1: string, 2: bool}> $extensions the catalogue of features
 */
?>
<p class="notice"><?= e(t('Done. Everything is a draft: nothing on your public site has changed yet.')) ?></p>
<ul>
	<li><?= e(t('Look in the draft: %s. Preview the whole site, then publish it.', $result['look'])) ?> <a href="<?= e($preview) ?>" target="_blank"><?= e(t('Preview the whole site')) ?></a></li>
<?php if ($result['extensions'] !== []): ?>
	<li><?= e(t('Switched on: %s.', implode(', ', array_map(fn (string $k): string => t($extensions[$k][0]), $result['extensions'])))) ?> <a href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></li>
<?php endif ?>
</ul>
<?php if ($result['pages'] !== []): ?>
<h2><?= e(t('Draft pages')) ?></h2>
<ul>
<?php foreach ($result['pages'] as $page): ?>
	<li><a href="<?= e($page['url']) ?>"><?= e($page['title']) ?></a></li>
<?php endforeach ?>
</ul>
<p class="help"><?= e(t('The pages are hidden. Replace the texts in square brackets, then publish each page in the builder.')) ?></p>
<?php endif ?>
<p class="navigation-row"><a class="btn" href="<?= e($app->url('admin.php?module=appearance')) ?>"><?= e(t('Site appearance')) ?></a> <a class="navigation" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Pages')) ?></a></p>
