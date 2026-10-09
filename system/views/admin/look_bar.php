<?php
/**
 * The draft look (Core\Look) on every admin screen: what it changes, the whole-site preview, publish and discard.
 *
 * @var Kaleta\Core\App $app
 * @var list<string> $summary
 * @var string $csrf
 */
$appearance = fn (string $action): string => $app->url('admin.php?module=appearance&action=' . $action);
?>
<div class="notice notice-warning appearance-draft" id="appearance-draft">
	<p><strong><?= e(t('Unpublished look changes')) ?></strong> – <?= e(t('visitors still see the published look.')) ?></p>
<?php if ($summary !== []): ?>
	<ul>
<?php foreach ($summary as $line): ?>
		<li><?= e($line) ?></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<div class="navigation-row">
		<a class="navigation" href="<?= e($appearance('preview_site')) ?>" target="_blank" rel="noopener"><?= e(t('Preview the whole site')) ?></a>
		<form class="inline" method="post" action="<?= e($appearance('publish_look')) ?>"><?= $csrf ?><button class="btn" type="submit"><?= e(t('Publish the look')) ?></button></form>
		<form class="inline" method="post" action="<?= e($appearance('discard_look')) ?>" data-confirm="<?= e(t('Discard the unpublished look changes?')) ?>"><?= $csrf ?><button class="navigation danger" type="submit"><?= e(t('Discard')) ?></button></form>
	</div>
</div>
