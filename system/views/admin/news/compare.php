<?php
/**
 * Comparison of a saved version of a news item with its current wording.
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var array<string, mixed> $newsItem
 * @var array<string, mixed> $versions
 * @var array{html:string, added:int, deleted:int} $title
 * @var array{html:string, added:int, deleted:int} $home
 * @var array{html:string, added:int, deleted:int} $text
 */
$added = $title['added'] + $home['added'] + $text['added'];
$deleted = $title['deleted_at'] + $home['deleted_at'] + $text['deleted_at'];
?>
<p class="navigation-row">
	<a class="navigation" href="<?= e($module->url('edit', ['id' => (int) $newsItem['news_id']])) ?>"><?= e(t('Back to the news item')) ?></a>
	<a class="navigation" href="<?= e($module->url('versions', ['id' => (int) $newsItem['news_id'], 'revision' => (int) $versions['revision_id']])) ?>"><?= e(t('Load this version into the editor')) ?></a>
</p>
<p><?= e(t('Version from %s', format_date($versions['created_at'], true))) ?><?= ($versions['user_name'] ?? '') !== '' ? ' · ' . e($versions['user_name']) : '' ?> → <?= e(t('current text')) ?>.
	<ins><?= e(t('added')) ?>: <?= $added ?></ins> · <del><?= e(t('deleted')) ?>: <?= $deleted ?></del></p>
<?php if ($added + $deleted === 0): ?>
<p class="notice"><?= e(t('The text has not changed since this version (formatting and image changes are not compared).')) ?></p>
<?php endif ?>
<div class="compare">
	<h2><?= e(t('Title')) ?></h2>
	<div class="compare-title"><?= $title['html'] ?></div>
	<h2><?= e(t('Lead paragraph')) ?></h2>
	<?= $home['html'] ?>
	<h2><?= e(t('Text')) ?></h2>
	<?= $text['html'] ?>
</div>
