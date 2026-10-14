<?php
/**
 * One look tried on the real site (#46): the home page in a frame (desktop or phone), the look's palettes and font pairs to choose
 * from (Front\Kernel renders ?look=<key>&palette=<n>&fonts=<n> for administrators, nothing is stored), then "Use this look".
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var string $csrf
 * @var array{topic: string, features: list<string>} $answers
 * @var array<string, mixed> $look
 * @var int $palette
 * @var int $fonts
 * @var array<string, array{name: string}> $library
 * @var string $preview address of the tried-on home page
 */
use Talea\Core\Onboarding;

$fontName = fn (string $value): string => $library[substr($value, 4)]['name'] ?? $value;
?>
<p class="help"><?= e(t($look['description'])) ?> <a href="<?= e($module->url('looks', $answers)) ?>"><?= e(t('All looks')) ?></a></p>
<div class="onboarding-look">
<div class="onboarding-preview">
	<p class="onboarding-devices" role="radiogroup" aria-label="<?= e(t('Preview size')) ?>">
		<label><input type="radio" name="device" value="desktop" checked> <?= e(t('Desktop')) ?></label>
		<label><input type="radio" name="device" value="phone"> <?= e(t('Phone')) ?></label>
	</p>
	<iframe src="<?= e($preview) ?>" title="<?= e(t('Preview of %s on your site', $look['name'])) ?>"></iframe>
</div>
<div class="onboarding-options">
<form method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="wizard"><input type="hidden" name="action" value="look"><input type="hidden" name="key" value="<?= e($look['key']) ?>"><?= $module->carry($answers) ?>
	<fieldset><legend><?= e(t('Colours')) ?></legend>
<?php foreach ($look['palettes'] as $i => $p): ?>
		<label class="onboarding-option"><input type="radio" name="palette" value="<?= $i ?>"<?= $i === $palette ? ' checked' : '' ?> data-submit-on-change>
			<span class="appearance-swatches" aria-label="<?= e(t('Palette %d', $i + 1)) ?>"><?php foreach (['primary', 'secondary'] as $b): ?><i style="background:<?= e($p['colors'][$b]) ?>"></i><?php endforeach ?><?php foreach (['primary', 'secondary'] as $b): ?><i style="background:<?= e($p['colors_dark'][$b]) ?>" title="<?= e(t('Dark mode')) ?>"></i><?php endforeach ?></span></label>
<?php endforeach ?>
	</fieldset>
	<fieldset><legend><?= e(t('Fonts')) ?></legend>
<?php foreach ($look['fonts'] as $i => $f): ?>
		<label class="onboarding-option"><input type="radio" name="fonts" value="<?= $i ?>"<?= $i === $fonts ? ' checked' : '' ?> data-submit-on-change> <?= e($fontName($f['font_heading']) . ' + ' . $fontName($f['font_body'])) ?></label>
<?php endforeach ?>
	</fieldset>
	<p><button class="button" type="submit"><?= e(t('Show')) ?></button></p>
</form>
<form method="post" action="<?= e($module->url('use')) ?>"><?= $csrf ?>
	<input type="hidden" name="key" value="<?= e($look['key']) ?>"><input type="hidden" name="palette" value="<?= $palette ?>"><input type="hidden" name="fonts" value="<?= $fonts ?>"><?= $module->carry($answers) ?>
	<p class="help"><?= e(t('The look goes to the draft look; nothing is published until you do it.')) ?>
<?php if ($answers['features'] !== []): ?>
		<?= e(t('Also switches on and prepares: %s.', implode(', ', array_map(fn (string $f): string => t(Onboarding::FEATURES[$f][0]), $answers['features'])))) ?>
<?php endif ?>
	</p>
	<p class="navigation-row"><button class="btn" type="submit"><?= e(t('Use this look')) ?></button> <a class="navigation" href="<?= e($preview) ?>" target="_blank"><?= e(t('View as site')) ?></a></p>
</form>
</div>
</div>
