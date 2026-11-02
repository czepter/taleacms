<?php
/**
 * Indicator of the three import steps (file → preview → import).
 *
 * @var int $step  the step in progress, 1–3
 */
$steps = [1 => 'File', 2 => 'Preview', 3 => 'Import'];
?>
<ol class="transfer-steps">
<?php foreach ($steps as $number => $name): ?>
	<li<?= $number === $step ? ' class="active" aria-current="step"' : ($number < $step ? ' class="finished"' : '') ?>><span><?= $number ?></span> <?= e(t($name)) ?></li>
<?php endforeach ?>
</ol>
<?php if (isset($kind) && $step === 2): ?>
<p class="small-text"><?= e(t('Detected: %s', $kind === 'talea' ? t('Talea export') : t('WordPress export'))) ?><?= !empty($size) ? ', ' . e(Talea\Core\Files::size((int) $size)) : '' ?> – <a href="<?= e($module->url()) ?>"><?= e(t('Choose another file')) ?></a></p>
<?php endif ?>
