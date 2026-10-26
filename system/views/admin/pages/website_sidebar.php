<?php
/**
 * The sidebar of Website mode: back to Home, the tabs Pages · Style · SEO, the pages grouped by the menu and the other
 * screens of the website. Rendered by Pages::actionWebsite into Kernel::$sidebar.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var array{menu: list<array<string, mixed>>, other: list<array<string, mixed>>} $groups pages with level, home and has_draft
 * @var int $current the page shown in the preview
 * @var array<string, class-string<Talea\Admin\Module>> $modules what the person may open
 * @var int $inTrash pages in the trash
 * @var bool $seo the SEO tab is open
 * @var string $tab the settings tab asked for, '' = none (the page links keep it)
 * @var string $currentId the public id of the page shown
 * @var bool $scored the score dots are shown
 */
$tabs = array_filter([
    ['Pages', $module->url('website', array_filter(['id' => $currentId])), !$seo],
    isset($modules['appearance']) ? ['Style', $app->url('admin.php?module=appearance'), false] : null,
    ['SEO', $module->url('website', array_filter(['id' => $currentId, 'tab' => 'seo'])), $seo],
]);
$more = $seo ? array_filter(['audit' => 'Site audit', 'redirects' => 'Redirects'], fn (string $ident): bool => isset($modules[$ident]), ARRAY_FILTER_USE_KEY) : null;
$more ??= array_filter([
    'parts' => 'Header, footer and system pages', 'menu' => 'Menu', 'components' => 'Components', 'popups' => 'Pop-ups', 'redirects' => 'Redirects',
], fn (string $ident): bool => isset($modules[$ident]), ARRAY_FILTER_USE_KEY);
?>
	<a class="menu-back" href="<?= e($app->url('admin.php')) ?>"><svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg><?= e(t('Home')) ?></a>
	<p class="menu-title"><?= e(t('Website')) ?></p>
<?php if (count($tabs) > 1): ?>
	<nav class="tabs website-tabs" aria-label="<?= e(t('Website')) ?>">
<?php foreach ($tabs as [$label, $url, $active]): ?>
		<a href="<?= e($url) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= e(t($label)) ?></a>
<?php endforeach ?>
	</nav>
<?php endif ?>
	<ul class="menu website-pages" id="menu">
<?php foreach (['menu' => 'Main navigation', 'other' => 'Not in the menu'] as $key => $heading): if ($groups[$key] === [] && $key === 'other') { continue; } ?>
		<li><span class="website-group"><span class="menu-heading"><?= e(t($heading)) ?></span><a class="website-add" href="<?= e($module->url('new')) ?>" title="<?= e(t('New page')) ?>" aria-label="<?= e(t('New page')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></a></span>
			<ul class="menu-sub">
<?php foreach ($groups[$key] as $page): $isCurrent = (int) $page['page_id'] === $current; ?>
				<li class="website-page<?= $isCurrent ? ' active' : '' ?><?= $page['visible'] ? '' : ' website-hidden' ?>" style="--level: <?= (int) $page['level'] ?>"><a href="<?= e($module->url('website', array_filter(['id' => $page['public_id'], 'tab' => $tab]))) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><?= $page['home'] ? '<path d="M3 11 12 4l9 7v9H3z"/>' : '<path d="M6 3h9l4 4v14H6z"/>' ?></svg><span><?= e($page['title']) ?></span><?php if ($scored): $score = $page['seo_score'] === null ? null : (int) $page['seo_score']; $dot = $score !== null ? t('SEO score') : ($page['has_keyword'] ? t('Not checked yet') : t('No focus keyword')); ?><span class="website-dot website-dot-<?= Talea\Core\ContentCheck::band($score) ?>" title="<?= e($dot) ?>"><span class="guide-hidden"><?= e($dot) ?> </span><?= $score ?></span><?php endif ?><?php if ($page['has_draft']): ?><span class="website-draft" title="<?= e(t('unpublished changes')) ?>"><span class="guide-hidden"><?= e(t('unpublished changes')) ?></span></span><?php endif ?><?php if (!$page['visible']): ?> <span class="badge badge-draft"><?= e(t('hidden')) ?></span><?php endif ?></a></li>
<?php endforeach ?>
			</ul>
		</li>
<?php endforeach ?>
	</ul>
	<ul class="website-more">
<?php foreach ($more as $ident => $label): ?>
		<li><a href="<?= e($app->url('admin.php?module=' . $ident)) ?>"><?= e(t($label)) ?></a></li>
<?php endforeach ?>
<?php if ($seo && $app->auth()->isAdmin()): ?>
		<li><a href="<?= e($app->url('admin.php?module=settings&tab=seo')) ?>"><?= e(t('Site-wide SEO and GEO')) ?></a></li>
<?php else: ?>
		<li><a href="<?= e($module->url()) ?>"><?= e(t('All pages')) ?><?= $inTrash > 0 ? ' · ' . e(t('Trash (%d)', $inTrash)) : '' ?></a></li>
<?php endif ?>
	</ul>
