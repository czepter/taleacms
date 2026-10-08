<?php
/**
 * The draft look (Core\Look) on every admin screen: one line (3.6) – what the draft touches, Review (the whole-site preview)
 * and Publish; the details toggle opens what exactly changes (colours with a swatch) and Discard.
 *
 * @var Kaleta\Core\App $app
 * @var list<string> $summary
 * @var list<string> $areas
 * @var string $csrf
 */
$appearance = fn (string $action): string => $app->url('admin.php?module=appearance&action=' . $action);
?>
<div class="hlaska hlaska-varovani vzhled-koncept" id="vzhled-koncept" role="region" aria-label="<?= e(t('Unpublished look changes')) ?>">
	<details class="vzhled-koncept-detail">
		<summary><strong><?= e(t('Unpublished changes:')) ?></strong> <?= e(implode(', ', $areas)) ?></summary>
		<p><?= e(t('Visitors still see the published look until you publish.')) ?></p>
<?php if ($summary !== []): ?>
		<ul>
<?php foreach ($summary as $line): ?>
			<li><?= preg_replace('/#[0-9a-f]{6}\b/', '<i class="vzhled-vzorek" style="background:$0" aria-hidden="true"></i>$0', e($line)) ?></li>
<?php endforeach ?>
		</ul>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($appearance('discard_look')) ?>" data-potvrdit="<?= e(t('Discard the unpublished look changes?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Discard')) ?></button></form>
	</details>
	<div class="vzhled-koncept-akce">
		<a class="navigace" href="<?= e($appearance('preview_site')) ?>" target="_blank" rel="noopener" title="<?= e(t('Preview the whole site')) ?>"><?= e(t('Review')) ?></a>
		<form class="vradku" method="post" action="<?= e($appearance('publish_look')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Publish')) ?></button></form>
	</div>
</div>
