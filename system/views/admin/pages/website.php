<?php
/**
 * Website mode: the chosen page as the visitor sees it (the draft where there is one), with a bar that leads into the
 * builder. The device switch narrows the preview without a script (:has on the radio buttons).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var array<string, mixed>|null $page
 * @var string $url the page's address on the site
 * @var string $preview what the frame shows (the draft where the page has one)
 */
?>
<?php if ($page === null): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No pages yet.'), 'text' => t('Create the first page of your site.'), 'action' => [$module->url('new'), t('New page')]]) ?>
<?php else: ?>
<div class="website-frame">
	<div class="website-bar">
		<div class="website-bar-start">
			<a class="btn" href="<?= e($module->url('builder', ['id' => $page['public_id']])) ?>"><svg class="icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg><?= e(t('Edit')) ?></a>
		</div>
		<div class="website-bar-title">
			<strong><?= e($page['title']) ?></strong>
			<span><?= e(t('Page')) ?> · <?= e($page['visible'] ? t('Published') : t('hidden')) ?><?= $page['has_draft'] ? ' · ' . e(t('unpublished changes')) : '' ?></span>
		</div>
		<div class="website-bar-end">
			<fieldset class="website-devices">
				<legend class="guide-hidden"><?= e(t('Preview size')) ?></legend>
				<label title="<?= e(t('Desktop')) ?>"><input type="radio" name="device" value="desktop" checked><span class="guide-hidden"><?= e(t('Desktop')) ?></span><svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg></label>
				<label title="<?= e(t('Phone')) ?>"><input type="radio" name="device" value="phone" id="website-phone"><span class="guide-hidden"><?= e(t('Phone')) ?></span><svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/></svg></label>
			</fieldset>
			<a class="menu-tool" href="<?= e($module->url('edit', ['id' => $page['public_id']])) ?>" title="<?= e(t('Page settings')) ?>" aria-label="<?= e(t('Page settings')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg></a>
			<a class="menu-tool" href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e(t('Open the live page')) ?>" aria-label="<?= e(t('Open the live page')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg></a>
		</div>
	</div>
	<iframe class="website-preview" src="<?= e($preview) ?>" title="<?= e(t('Preview of %s', $page['title'])) ?>"></iframe>
</div>
<?php endif ?>
