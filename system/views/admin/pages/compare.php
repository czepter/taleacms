<?php
/**
 * Comparison of a saved version of a page's text with its current wording.
 *
 * @var Talea\Admin\Modules\Pages $module
 * @var array<string, mixed> $page
 * @var array<string, mixed> $version
 * @var array{html:string, added:int, deleted:int} $title
 * @var array{html:string, added:int, deleted:int} $text
 * @var string $csrf
 */
$added = $title['added'] + $text['added'];
$deleted = $title['deleted'] + $text['deleted'];
?>
<div class="navigation-row">
	<a class="navigation" href="<?= e($module->url('edit', ['id' => $page['public_id']])) ?>"><?= e(t('Back to the page')) ?></a>
	<form class="inline" method="post" action="<?= e($module->url('restore_version')) ?>" data-confirm="<?= e(t('Restore this version of the text? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="revision_id" value="<?= (int) $version['revision_id'] ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form>
</div>
<p><?= e(t('Version from %s', format_date($version['created_at'], true))) ?><?= ($version['user_name'] ?? '') !== '' ? ' · ' . e($version['user_name']) : '' ?> → <?= e(t('current text')) ?>.
	<ins><?= e(t('added')) ?>: <?= $added ?></ins> · <del><?= e(t('deleted')) ?>: <?= $deleted ?></del></p>
<?php if ($added + $deleted === 0): ?>
<p class="notice"><?= e(t('The text has not changed since this version (formatting and image changes are not compared).')) ?></p>
<?php endif ?>
<div class="compare">
	<h2><?= e(t('Title')) ?></h2>
	<div class="compare-title"><?= $title['html'] ?></div>
	<h2><?= e(t('Text')) ?></h2>
	<?= $text['html'] ?>
</div>
