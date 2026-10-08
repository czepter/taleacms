<?php
/**
 * The draft look (Core\Look) on every admin screen: what it changes (colours with a swatch), the whole-site preview, publish and discard.
 *
 * @var Kaleta\Core\App $app
 * @var list<string> $summary
 * @var string $csrf
 */
$appearance = fn (string $action): string => $app->url('admin.php?module=appearance&action=' . $action);
?>
<div class="hlaska hlaska-varovani vzhled-koncept" id="vzhled-koncept">
	<p><strong><?= e(t('Unpublished look changes')) ?></strong> – <?= e(t('visitors still see the published look.')) ?></p>
<?php if ($summary !== []): ?>
	<ul>
<?php foreach ($summary as $line): ?>
		<li><?= preg_replace('/#[0-9a-f]{6}\b/', '<i class="vzhled-vzorek" style="background:$0" aria-hidden="true"></i>$0', e($line)) ?></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<div class="navigace-radek">
		<a class="navigace" href="<?= e($appearance('preview_site')) ?>" target="_blank" rel="noopener"><?= e(t('Preview the whole site')) ?></a>
		<form class="vradku" method="post" action="<?= e($appearance('publish_look')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Publish the look')) ?></button></form>
		<form class="vradku" method="post" action="<?= e($appearance('discard_look')) ?>" data-potvrdit="<?= e(t('Discard the unpublished look changes?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Discard')) ?></button></form>
	</div>
</div>
