<?php
/**
 * The settings sidebar of Website mode: the head with the page's score, the tab strip (links, so it works without a script),
 * the open tab (settings/<tab>.php: its form, then what lives outside the form) and the sticky Save footer, which submits the
 * tab's form. Collapsed (admin.js sets .settings-closed on .website), only the rail with a link per tab is left.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var string $tab the open tab: seo | page | publishing | sharing | advanced
 */
$tabs = [
    'seo' => ['SEO', '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'],
    'page' => ['Page', '<path d="M6 3h9l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 12h8M8 16h5"/>'],
    'publishing' => ['Publishing', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'],
    'sharing' => ['Sharing', '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/>'],
    'advanced' => ['Advanced', '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>'],
];
$icon = fn (string $paths): string => '<svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$link = fn (string $to): string => $module->url('website', ['id' => $page['public_id'], 'tab' => $to]);
$score = $page['seo_score'] === null ? null : (int) $page['seo_score'];
$band = match (true) { $score === null => 'none', $score >= 80 => 'good', $score >= 50 => 'mid', default => 'low' };
// the tab's form starts here; the tab view closes it and adds what must stay outside (forms of other actions)
$formStart = '<form class="website-settings-form" id="website-settings-form" method="post" action="' . e($module->url('settings_save')) . '">' . $csrf
    . '<input type="hidden" name="id" value="' . e($page['public_id']) . '"><input type="hidden" name="tab" value="' . e($tab) . '">';
?>
<aside class="website-settings" id="website-settings" data-settings-panel aria-label="<?= e(t('Page settings')) ?>">
	<div class="website-settings-head">
		<h2><?= e(t('Page settings')) ?></h2>
		<span class="website-score website-score-<?= $band ?>" title="<?= e(t('SEO score')) ?>"><?= $score === null ? '–' : $score . ' / 100' ?></span>
		<button class="menu-tool" type="button" data-settings-toggle aria-expanded="true" aria-controls="website-settings" title="<?= e(t('Hide the sidebar')) ?>" aria-label="<?= e(t('Hide the sidebar')) ?>"><?= $icon('<path d="m9 18 6-6-6-6"/>') ?></button>
	</div>
	<nav class="website-settings-tabs" aria-label="<?= e(t('Settings')) ?>">
<?php foreach ($tabs as $key => [$label, $paths]): ?>
		<a href="<?= e($link($key)) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= $icon($paths) ?><?= e(t($label)) ?></a>
<?php endforeach ?>
	</nav>
	<div class="website-settings-body">
<?= $app->view->render('admin/pages/settings/' . $tab, get_defined_vars()) ?>
	</div>
	<div class="website-settings-foot"><button class="btn" type="submit" form="website-settings-form"><?= e(t('Save')) ?></button></div>
	<nav class="website-settings-rail" aria-label="<?= e(t('Page settings')) ?>">
		<a class="website-score website-score-<?= $band ?>" href="<?= e($link('seo')) ?>" title="<?= e(t('SEO score')) ?>"><?= $score ?? '–' ?></a>
<?php foreach ($tabs as $key => [$label, $paths]): ?>
		<a class="menu-tool" href="<?= e($link($key)) ?>" title="<?= e(t($label)) ?>" aria-label="<?= e(t($label)) ?>"><?= $icon($paths) ?></a>
<?php endforeach ?>
	</nav>
</aside>
