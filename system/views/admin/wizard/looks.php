<?php
/**
 * The onboarding's looks gallery (#46): every look, the best fits of the topic first. A card opens the look on the site.
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var array{topic: string, features: list<string>} $answers
 * @var array<string, array<string, mixed>> $looks in the order of the topic
 * @var array<string, array{name: string}> $library
 */
use Talea\Builder\Looks;

$fontName = fn (string $value): string => $library[substr($value, 4)]['name'] ?? $value;
?>
<p class="help"><?= e(isset(Looks::TOPICS[$answers['topic']]) && $answers['topic'] !== 'other' ? t('All looks – the ones that fit %s best come first. Open one to try its colours and fonts on your site.', t(Looks::TOPICS[$answers['topic']]))
	: t('Open a look to try its colours and fonts on your site.')) ?>
	<a href="<?= e($module->url('start', $answers)) ?>"><?= e(t('Back')) ?></a></p>
<div class="looks-gallery">
<?php foreach ($looks as $key => $look): $ds = $look['design_system']; ?>
	<a class="look-card" href="<?= e($module->url('look', ['key' => $key] + $answers)) ?>">
		<span class="appearance-swatches"><?php foreach (['primary', 'secondary', 'text', 'surface'] as $b): ?><i style="background:<?= e($ds['colors'][$b]) ?>"></i><?php endforeach ?></span>
		<strong><?= e($look['name']) ?></strong>
		<small><?= e(t($look['description'])) ?></small>
		<small><?= e($fontName($ds['font_heading']) . ' + ' . $fontName($ds['font_body'])) ?></small>
	</a>
<?php endforeach ?>
</div>
