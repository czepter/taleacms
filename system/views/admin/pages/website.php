<?php
/**
 * Website mode: the chosen page in a frame as the visitor sees it (the draft where there is one), the bar of tools above
 * it, a status line below and the settings sidebar beside it (settings.php). The device switch narrows the frame without
 * a script (:has on the radio buttons); admin.js only remembers a collapsed sidebar.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var array<string, mixed>|null $page the full page row
 * @var string $url the page's address on the site
 * @var string $preview what the frame shows
 * @var string $host the site's host, for the live address
 * @var bool $home it is the home page
 * @var bool $hasBuild the page is built in the builder (has a build or a draft)
 * @var int $comments unresolved comments on the draft
 * @var array<string, string>|null $marks what "SEO on the page" marks on the preview; null for a text page
 * @var bool $marksOn the preview shows the marks
 */
if ($page === null) {
    echo $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No pages yet.'), 'text' => t('Create the first page of your site.'), 'action' => [$module->url('new'), t('New page')]]);

    return;
}
$builder = $module->url('builder', ['id' => $page['public_id']]);
// Edit opens what holds the content: the builder for a build page, the text form for a text page
$edit = $hasBuild ? $builder : $module->url('edit', ['id' => $page['public_id']]);
$devices = [
    'desktop' => ['Desktop', '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'],
    'tablet' => ['Tablet', '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>'],
    'phone' => ['Phone', '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>'],
];
$path = (string) preg_replace('#^' . preg_quote($app->request->basePath(), '#') . '#', '', $url);
?>
<div class="website">
	<div class="website-stage">
		<div class="website-bar">
			<div class="website-bar-title">
				<strong><?= e($page['title']) ?></strong>
				<span><span class="status <?= $page['visible'] ? 'status-live' : 'status-hidden' ?>"><?= e($page['visible'] ? t('Published') : t('hidden')) ?></span><span><?= e(implode(' · ', array_filter([$home ? t('home page') : '', $page['updated_at'] !== null ? t('edited %s', format_ago((string) $page['updated_at'])) : '', $page['build_draft'] !== null ? t('unpublished changes') : '']))) ?></span><a class="website-address" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($host . $path) ?><svg class="icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a></span>
			</div>
			<div class="website-tools">
				<fieldset class="website-devices">
					<legend class="guide-hidden"><?= e(t('Preview size')) ?></legend>
<?php foreach ($devices as $value => [$label, $icon]): ?>
					<label title="<?= e(t($label)) ?>"><input type="radio" name="device" value="<?= $value ?>"<?= $value === 'desktop' ? ' checked' : '' ?>><span class="guide-hidden"><?= e(t($label)) ?></span><svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><?= $icon ?></svg></label>
<?php endforeach ?>
				</fieldset>
<?php if ($marks !== null): ?>
				<a class="website-tool" href="<?= e($module->url('website', array_filter(['id' => $page['public_id'], 'tab' => $tab, 'marks' => $marksOn ? '' : '1']))) ?>"<?= $marksOn ? ' aria-current="true"' : '' ?> title="<?= e(t('Mark what the SEO check finds on the page')) ?>"><svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v3.5M11 14h.01"/></svg><?= e(t('SEO on the page')) ?><?php if ($marks !== []): ?><span class="website-tool-badge"><span class="guide-hidden">: </span><?= count($marks) ?></span><?php endif ?></a>
<?php endif ?>
				<a class="menu-tool" href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e(t('Open the live page')) ?>" aria-label="<?= e(t('Open the live page')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg></a>
				<button class="menu-tool" type="button" data-settings-toggle aria-expanded="true" aria-controls="website-settings" title="<?= e(t('Page settings')) ?>" aria-label="<?= e(t('Page settings')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg></button>
				<a class="btn" href="<?= e($edit) ?>"><svg class="icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg><?= e(t('Edit')) ?></a>
			</div>
		</div>
		<div class="website-ground">
			<iframe class="website-preview" src="<?= e($preview) ?>" title="<?= e(t('Preview of %s', $page['title'])) ?>"></iframe>
		</div>
		<p class="website-status"><span><?= e(t('All changes saved')) ?></span><span><a href="<?= e($hasBuild ? $builder : $module->url('website', ['id' => $page['public_id'], 'tab' => 'publishing'])) ?>"><?= e(t('Versions')) ?></a><?php if ($comments > 0): ?> · <a href="<?= e($builder) ?>"><?= e(t('Comments: %d', $comments)) ?></a><?php endif ?></span></p>
	</div>
<?= $app->view->render('admin/pages/settings', get_defined_vars()) ?>
</div>
