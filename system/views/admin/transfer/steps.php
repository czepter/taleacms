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
