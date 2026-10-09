<?php
/**
 * Ready-made templates of a site part (Builder\PartTemplates): structure only, the look comes from the design system.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var string $type
 * @var string $language
 * @var string $variant
 * @var list<array{key: string, name: string, description: string}> $templates
 */
$params = ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : []);
?>
<p class="help"><?= e(t('A template is a clean skeleton – colours, fonts and spacing come from your design system. It goes into the draft of the part: adjust it in the builder and publish it; until then visitors see the published version.')) ?></p>
<div class="cards-options cards-options-text">
<?php foreach ($templates as $template): ?>
	<form class="card-option" method="post" action="<?= e($module->url('apply_template', $params)) ?>">
		<?= $csrf ?><input type="hidden" name="template" value="<?= e($template['key']) ?>">
		<strong><?= e(t($template['name'])) ?></strong>
		<span><?= e(t($template['description'])) ?></span>
		<button class="navigation" type="submit"><?= e(t('Use this template')) ?></button>
	</form>
<?php endforeach ?>
</div>
<p class="navigation-row actions-bottom"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('All site parts')) ?></a></p>
