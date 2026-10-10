<?php
/**
 * The looks gallery: finished looks (Builder\Looks) – a click puts one into the draft look, the whole site is previewed, then published or discarded.
 *
 * @var Talea\Admin\Modules\Appearance $module
 * @var string $csrf
 * @var array<string, array<string, mixed>> $looks
 * @var string $current key of the look whose design system is the current one
 * @var array<string, array{name: string}> $library
 * @var array<string, string> $sections ready-section key => name
 */
$fontName = fn (string $value): string => $library[substr($value, 4)]['name'] ?? $value;
?>
<p class="help"><?= e(t('A look is a ready set of colours (with a dark mode), fonts, scale and shapes plus a header and a footer. Applying it goes to the draft look: preview the whole site, then publish or discard. Pages, news, menus and settings stay as they are.')) ?>
	<a href="<?= e($module->url()) ?>"><?= e(t('Back to the appearance')) ?></a></p>
<div class="looks-gallery">
<?php foreach ($looks as $key => $look): $ds = $look['design_system']; ?>
	<form class="look-card" method="post" action="<?= e($module->url('apply_look')) ?>"><?= $csrf ?>
		<input type="hidden" name="look" value="<?= e($key) ?>">
		<span class="appearance-swatches"><?php foreach (['primary', 'secondary', 'text', 'surface'] as $b): ?><i style="background:<?= e($ds['colors'][$b]) ?>"></i><?php endforeach ?><?php foreach (['background', 'surface'] as $b): ?><i style="background:<?= e($ds['colors_dark'][$b]) ?>" title="<?= e(t('Dark mode')) ?>"></i><?php endforeach ?></span>
		<strong><?= e($look['name']) ?></strong>
<?php if ($key === $current): ?>		<span class="badge badge-published"><?= e(t('current')) ?></span>
<?php endif ?>
		<small><?= e(t($look['description'])) ?></small>
		<small><?= e($fontName($ds['font_heading']) . ' + ' . $fontName($ds['font_body'])) ?> · <?= e(t(Talea\Builder\PartTemplates::LIST['header'][$look['header']][0])) ?> · <?= e(t(Talea\Builder\PartTemplates::LIST['footer'][$look['footer']][0])) ?></small>
		<small><?= e(t('Ready sections')) ?>: <?= e(implode(', ', array_map(fn (string $k): string => $sections[$k] ?? $k, $look['sections']))) ?></small>
		<button type="submit" class="button"><?= e(t('Apply as a draft')) ?></button>
	</form>
<?php endforeach ?>
</div>
