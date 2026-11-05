<?php
/**
 * Site templates (Builder\SiteTemplates): finished designs – a look plus pages of their own. Each card shows the template's real home page
 * (Install\Preview::siteTemplate, rendered from a scratch database, scaled down in a frame); using one puts everything in as drafts.
 *
 * @var Talea\Admin\Modules\Appearance $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array{key: string, name: string, description: string, look: string, look_name: string, topics: list<string>, pages: list<string>}> $templates
 * @var bool $previews the pictures can be rendered here (SQLite and Phinx)
 */
?>
<p class="help"><?= e(t('A site template is a finished design: a look with its header and footer plus pages composed for it. Using one puts its look into the draft look and its pages into drafts – on your own pages where they match (the home page, a page of the same name), otherwise as new hidden pages. Nothing on the site changes until you publish. Photos are shown as tinted frames until you choose your own.')) ?>
	<a href="<?= e($module->url('looks')) ?>"><?= e(t('Only colours and fonts: Looks')) ?></a></p>
<div class="templates-gallery">
<?php foreach ($templates as $template): ?>
	<article class="template-card">
<?php if ($previews): ?>
		<div class="template-preview"><iframe src="<?= e($module->url('template_preview', ['key' => $template['key']])) ?>" title="<?= e(t('Home page of the template %s', $template['name'])) ?>" loading="lazy" tabindex="-1" inert></iframe></div>
<?php endif ?>
		<div class="template-body">
			<h2><?= e($template['name']) ?></h2>
			<p><?= e($template['description']) ?></p>
			<p class="small-text"><?= e(t('Pages')) ?>: <?= e(implode(' · ', $template['pages'])) ?> · <?= e(t('Look')) ?>: <?= e($template['look_name']) ?></p>
			<form method="post" action="<?= e($module->url('apply_template')) ?>"><?= $csrf ?><input type="hidden" name="template" value="<?= e($template['key']) ?>">
				<button class="btn" type="submit" data-confirm="<?= e(t('Put the look and the pages of %s into drafts? Pages that already have unpublished changes are left alone. Nothing on the site changes until you publish.', $template['name'])) ?>"><?= e(t('Use this template')) ?></button>
<?php if ($previews): ?>
				<a class="navigation" href="<?= e($module->url('template_preview', ['key' => $template['key']])) ?>" target="_blank" rel="noopener"><?= e(t('Open the preview')) ?></a>
<?php endif ?>
			</form>
		</div>
	</article>
<?php endforeach ?>
</div>
