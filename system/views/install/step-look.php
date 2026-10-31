<?php if ($data['blueprint'] === 'export'): ?>
<p class="help"><?= e(t('An empty site takes its look from the import.')) ?></p>
<?php else: ?>
<div class="look-layout">
<fieldset>
<legend class="caption"><?= e(t('Choose a look')) ?></legend>
<div class="choices compact">
<?php foreach ($looks as $key => $look): $colors = $look['design_system']['colors']; ?>
	<button class="choice" type="submit" name="look" value="<?= e($key) ?>" formnovalidate aria-pressed="<?= $key === $chosenLook ? 'true' : 'false' ?>">
		<span class="swatches"><i style="background: <?= e($colors['primary']) ?>"></i><i style="background: <?= e($colors['secondary']) ?>"></i><i style="background: <?= e($colors['surface']) ?>"></i></span>
		<strong><?= e($look['name']) ?></strong>
<?php if (array_intersect($topics, $look['topics']) !== [] && $data['blueprint'] !== ''): ?>
		<span class="fit"><?= e(t('Suits your business')) ?></span>
<?php endif ?>
		<span class="choice-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>
	</button>
<?php endforeach ?>
</div>
<span class="help"><?= e(t('A look can be changed or previewed later in Site appearance → Looks.')) ?></span>
</fieldset>
<?php if ($preview !== ''): ?>
<div class="pv-wrap">
	<span class="caption"><?= e(t('Preview')) ?></span>
	<div class="pv"><iframe src="<?= e($preview) ?>" title="<?= e(t('Preview of your home page')) ?>" loading="lazy"></iframe></div>
</div>
<?php endif ?>
</div>
<?php endif ?>
