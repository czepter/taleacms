<?php
/**
 * The admin frame: the sidebar (sections, the screens of the open one, account and tools), section heading, messages, content.
 *
 * @var Talea\Core\App $app
 * @var string $heading
 * @var string $content  ready-made HTML of the module
 * @var array<string, class-string<Talea\Admin\Module>> $modules
 * @var string $active
 * @var array<string, mixed>|null $user
 * @var list<array{typ:string, text:string}> $flashes
 * @var string $sidebar HTML a screen shows in the sidebar instead of the menu (Website mode), '' = the menu
 */
$icon = require __DIR__ . '/icons.php';
$onDashboard = $active === '' && (string) $app->request->get('action') === ''; // My account (akce=ucet) is not the Dashboard

// command palette (Ctrl/⌘+K): only where the signed-in user may go – the module list already follows permissions
$statements = [];
$collections = []; // every collection by name: the palette finds each one, the sidebar lists those with "Show in the admin menu"
if ($username !== null) {
    $collections = isset($modules['collections']) ? Talea\Admin\Modules\Collections::navigation($app) : [];
    $adminUrl = fn (string $query = ''): string => $app->url('admin.php' . ($query !== '' ? '?' . $query : ''));
    $statements[] = ['n' => t('Home'), 'u' => $adminUrl(), 's' => ''];
    foreach ($modules as $ident => $class) {
        $statements[] = ['n' => t($class::NAME), 'u' => $adminUrl('module=' . $ident), 's' => t($class::GROUP)];
    }
    $quick = [
        'pages' => [['New page', 'module=pages&action=new']],
        'news' => [['New news item', 'module=news&action=new'], ['Broken links', 'module=news&action=links']],
        'categories' => [['New category', 'module=categories&action=new']],
        'users' => [['New user', 'module=users&action=new']],
        'transfer' => [['Import from WordPress', 'module=transfer']],
    ];
    foreach ($quick as $ident => $items) {
        foreach (isset($modules[$ident]) ? $items : [] as [$name, $query]) {
            $statements[] = ['n' => t($name), 'u' => $adminUrl($query), 's' => t($modules[$ident]::NAME)];
        }
    }
    foreach (isset($modules['settings']) ? Talea\Admin\Modules\Settings::TABS : [] as $key => $name) {
        if (!Talea\Admin\Modules\Settings::tabAvailable($key, $app->settings())) {
            continue;
        }
        $statements[] = ['n' => t('Settings') . ' → ' . t($name), 'u' => $adminUrl('module=settings&tab=' . $key), 's' => t('Settings')];
    }
    // site pages can be found in the palette by name (news is searched on the server, there are more of them)
    foreach (isset($modules['pages']) ? $app->db()->all('SELECT public_id, title FROM {pages} WHERE deleted_at IS NULL ORDER BY sort_order, title LIMIT 300') : [] as $pageRow) {
        $statements[] = ['n' => $pageRow['title'], 'u' => $adminUrl('module=pages&action=edit&id=' . $pageRow['public_id']), 's' => t('Page')];
    }
    foreach ($collections as $collection) {
        $statements[] = ['n' => $collection['name'], 'u' => $adminUrl('module=collections&action=items&id=' . $collection['public_id']), 's' => t('Collection')];
    }
    $statements[] = ['n' => t('My account'), 'u' => $adminUrl('action=account'), 's' => ''];
    $statements[] = ['n' => t('View site'), 'u' => $app->url(''), 's' => ''];
}
?>
<!doctype html>
<html lang="<?= e(Talea\Core\Language::code()) ?>" data-timezone="<?= e(date_default_timezone_get()) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/theme.js')) ?>?v=<?= e(TALEA_VERSION) ?>"></script>
<title><?= $heading !== '' ? e($heading) . ' – ' : '' ?>Talea</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/talea-mark.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/talea-mark-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/talea-mark-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(TALEA_VERSION) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(TALEA_VERSION) ?>">
</head>
<body>
<?php if ($username !== null): ?>
<a class="skip" href="#main"><?= e(t('Skip to content')) ?></a>
<?php
$sections = Talea\Admin\Kernel::sections($modules);
$inMenu = isset($modules[$active]) && $modules[$active]::PARENT !== '' ? $modules[$active]::PARENT : $active;
$current = $onDashboard ? 'Home' : (isset($modules[$active]) ? $modules[$active]::GROUP : '');
$listed = array_values(array_filter(Talea\Admin\Kernel::SECTIONS, fn (string $section): bool => isset($sections[$section])));
// the collections in the menu: a group of their own after Analytics and before AI, whichever section is open; the open one opens no section
$menuCollections = array_slice(array_values(array_filter($collections, fn (array $c): bool => (bool) $c['admin_menu'])), 0, Talea\Admin\Modules\Collections::MENU_LIMIT);
$openCollection = $active === 'collections' ? (string) $app->request->get('id') : '';
if ($menuCollections !== []) {
    array_splice($listed, ($at = array_search('AI', $listed, true)) === false ? count($listed) : $at, 0, ['Collections']);
    if (in_array($openCollection, array_column($menuCollections, 'public_id'), true)) {
        $current = '';
    }
}
// how many things wait (new enquiries, bookings to confirm): next to the module, or next to its section while that is closed
$waiting = Talea\Admin\Kernel::waiting($app, $modules);
$waitingCount = fn (int $n): string => $n > 0 ? ' <span class="menu-count" aria-hidden="true">' . ($n > 99 ? '99+' : $n) . '</span><span class="visually-hidden"> – ' . e(t('%s new', (string) $n)) . '</span>' : '';
// the settings are the gear at the bottom; inside them the sidebar lists their screens instead of the sections
$settingsMenu = [];
if ($current === 'Settings') {
    $tab = (string) $app->request->get('tab');
    foreach (Talea\Admin\Kernel::SETTINGS_MENU as $menuGroup => $entries) {
        foreach ($entries as [$ident, $entryTab, $label]) {
            if (isset($modules[$ident]) && ($ident !== 'settings' || Talea\Admin\Modules\Settings::tabAvailable($entryTab, $app->settings()))) {
                $here = $ident === $inMenu && ($ident !== 'settings' || $entryTab === ($tab !== '' ? $tab : 'general'));
                // Analytics lists a page per service while it is open (Admin\AnalyticsServices); the overview is the entry itself
                $servicePage = $here && $entryTab === 'analytics' ? Talea\Admin\AnalyticsServices::page((string) $app->request->get('service')) : '';
                $settingsMenu[$menuGroup][] = ['url' => $app->url('admin.php?module=' . $ident . ($entryTab !== '' ? '&tab=' . $entryTab : '')), 'label' => $label,
                    'active' => $here && $servicePage === '',
                    'children' => $here && $entryTab === 'analytics' ? array_map(fn (string $page, string $name): array => ['url' => $app->url('admin.php?module=settings&tab=analytics&service=' . $page),
                        'label' => $name, 'active' => $page === $servicePage], array_keys(Talea\Admin\AnalyticsServices::PAGES), Talea\Admin\AnalyticsServices::PAGES) : []];
            }
        }
    }
}
$userName = $username['name'] ?: $username['username'];
?>
<header class="header">
	<div class="header-top">
		<a class="brand" href="<?= e($app->url('admin.php')) ?>" aria-label="Talea – <?= e(t('Home')) ?>"><?= $app->view->render('admin/logo', ['height' => 26]) ?></a>
		<?= $app->view->render('admin/claude_chip', ['app' => $app, 'modules' => $modules]) ?>
		<button class="palette-start" type="button" data-palette title="<?= e(t('Quick search and commands')) ?> (Ctrl K)" aria-label="<?= e(t('Quick search and commands')) ?>"><?= $icon('b-search') ?></button>
		<button class="menu-switch" type="button" aria-expanded="false" aria-controls="menu"><?= e(t('Menu')) ?></button>
	</div>
	<nav class="menu-wrap" aria-label="<?= e(t('Main menu')) ?>">
<?php if (($sidebar ?? '') !== ''): ?>
	<?= $sidebar ?>
<?php elseif ($settingsMenu !== []): ?>
	<a class="menu-back" href="<?= e($app->url('admin.php')) ?>"><svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg><?= e(t('Home')) ?></a>
	<p class="menu-title"><?= e(t('Settings')) ?></p>
	<ul class="menu menu-settings" id="menu">
<?php foreach ($settingsMenu as $menuGroup => $entries): ?>
		<li><span class="menu-heading"><?= e(t($menuGroup)) ?></span>
			<ul class="menu-sub">
<?php foreach ($entries as $entry): ?>
				<li<?= $entry['active'] ? ' class="active"' : '' ?>><a href="<?= e($entry['url']) ?>"<?= $entry['active'] ? ' aria-current="page"' : '' ?>><?= e(t($entry['label'])) ?></a>
<?php if ($entry['children'] !== []): ?>
					<ul class="menu-sub">
<?php foreach ($entry['children'] as $child): ?>
						<li<?= $child['active'] ? ' class="active"' : '' ?>><a href="<?= e($child['url']) ?>"<?= $child['active'] ? ' aria-current="page"' : '' ?>><?= e(t($child['label'])) ?></a></li>
<?php endforeach ?>
					</ul>
<?php endif ?>
				</li>
<?php endforeach ?>
			</ul>
		</li>
<?php endforeach ?>
	</ul>
<?php else: ?>
	<ul class="menu" id="menu">
		<li class="menu-section<?= $current === 'Home' ? ' active' : '' ?>"><a href="<?= e($app->url('admin.php')) ?>"<?= $current === 'Home' ? ' aria-current="page"' : '' ?>><?= e(t('Home')) ?></a></li>
<?php foreach ($listed as $section): ?>
<?php if ($section === 'Collections'): ?>
		<li class="menu-collections"><span class="menu-heading" id="menu-collections"><?= e(t('Collections')) ?></span>
			<ul class="menu-sub" aria-labelledby="menu-collections">
<?php foreach ($menuCollections as $collection): $here = $collection['public_id'] === $openCollection; ?>
				<li<?= $here ? ' class="active"' : '' ?>><a href="<?= e($app->url('admin.php?module=collections&action=items&id=' . $collection['public_id'])) ?>"<?= $here ? ' aria-current="page"' : '' ?>><?= bdi($collection['name']) ?></a></li>
<?php endforeach ?>
			</ul>
		</li>
<?php continue; endif; $open = $section === $current; $entries = $sections[$section]; $listsEntries = $open && count($entries) > 1; ?>
		<li class="menu-section<?= $open ? ' active' : '' ?><?= $section === 'AI' ? ' menu-claude' : '' ?>"><a href="<?= e($app->url('admin.php?module=' . ($section === 'Website' && isset($entries['pages']) ? 'pages&action=website' : array_key_first($entries)))) ?>"<?= $open ? ' aria-current="true"' : '' ?>><?= e(t($section)) ?><?= $listsEntries ? '' : $waitingCount(array_sum(array_intersect_key($waiting, $entries))) ?></a>
<?php if ($listsEntries): ?>
			<ul class="menu-sub">
<?php foreach ($entries as $ident => $class): ?>
				<li<?= $ident === $inMenu ? ' class="active"' : '' ?>><a href="<?= e($app->url('admin.php?module=' . $ident)) ?>"<?= $ident === $inMenu ? ' aria-current="page"' : '' ?>><?= e(t($class::MENU_NAME ?: $class::NAME)) ?><?= $waitingCount($waiting[$ident] ?? 0) ?></a></li>
<?php endforeach ?>
			</ul>
<?php endif ?>
		</li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<div class="menu-foot">
		<a class="signed-in" href="<?= e($app->url('admin.php?action=account')) ?>" title="<?= e(t('My account')) ?>" aria-label="<?= e(t('My account') . ' – ' . $userName) ?>"><span class="avatar" title="<?= e($userName . ' – ' . t(Talea\Core\Auth::TYPES[(int) $username['admin']] ?? '')) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($userName, 0, 1))) ?></span></a>
		<span class="menu-tools">
			<button class="menu-tool theme-switch" type="button" data-theme-switch title="<?= e(t('Light / dark mode')) ?>" aria-label="<?= e(t('Toggle light and dark mode')) ?>"><?= $icon('theme') ?></button>
			<a class="menu-tool" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener" title="<?= e(t('View site')) ?>" aria-label="<?= e(t('View site')) ?>"><?= $icon('site') ?></a>
<?php if (isset($sections['Settings'])): ?>
			<a class="menu-tool<?= $current === 'Settings' ? ' active' : '' ?>" href="<?= e($app->url('admin.php?module=' . array_key_first($sections['Settings']))) ?>" title="<?= e(t('Settings')) ?>" aria-label="<?= e(t('Settings')) ?>"<?= $current === 'Settings' ? ' aria-current="true"' : '' ?>><?= $icon('settings') ?></a>
<?php endif ?>
			<form method="post" action="<?= e($app->url('admin.php?action=logout')) ?>"><?= $app->session->csrfField() ?><button class="menu-tool" type="submit" title="<?= e(t('Sign out')) ?>" aria-label="<?= e(t('Sign out')) ?>"><?= $icon('logout') ?></button></form>
		</span>
	</div>
	</nav>
</header>
<?php endif ?>
<?php if ($statements !== []): ?>
<dialog class="palette" id="palette" aria-label="<?= e(t('Quick search and commands')) ?>"<?= isset($modules['news']) ? ' data-articles="' . e($app->url('admin.php?module=news&action=search_json&edit=1')) . '"' : '' ?>>
	<input class="palette-field" type="search" autocomplete="off" spellcheck="false" placeholder="<?= e(t('Where do you want to go? Type the name of a section, action, page or news item…')) ?>" aria-label="<?= e(t('Quick search and commands')) ?>" aria-controls="palette-list">
	<ul class="palette-list" id="palette-list" role="listbox"></ul>
	<p class="palette-help"><kbd>↑</kbd> <kbd>↓</kbd> <?= e(t('select')) ?> · <kbd>Enter</kbd> <?= e(t('open')) ?> · <kbd>Esc</kbd> <?= e(t('close')) ?></p>
	<script type="application/json" id="palette-data"><?= json_encode($statements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
<?php endif ?>
<main class="content" id="main" tabindex="-1">
<?php if (Talea\Core\Demo::active()): ?>
<p class="notice notice-warning" role="status"><?= e(t('Public demo: everything you change here is reset in %s minutes. E-mail, imports, updates, users and the Claude connection are switched off.', (string) max(1, (int) ceil(Talea\Core\Demo::secondsToReset() / 60)))) ?></p>
<?php endif ?>
<?php if ($heading !== ''): $guide = $username !== null ? Talea\Admin\Guide::forScreen($active === '' && $app->request->get('action') === 'account' ? 'account' : $active, '', (string) $app->request->get('tab'), Talea\Core\Language::code()) : null; ?>
<div class="header-pages">
<div class="page-title"><h1><?= e($heading) ?></h1><?php if (($count = $app->view->slot('count')) !== ''): ?> <span class="page-count"><?= $count ?></span><?php endif ?></div>
<?php $actions = $app->view->slot('actions'); if ($guide !== null || $actions !== ''): ?>
<div class="page-actions">
<?= $guide !== null ? $app->view->render('admin/guide_link', ['url' => $guide]) : '' ?>
<?= $actions ?>
</div>
<?php endif ?>
</div>
<?php endif ?>
<?php foreach ($flashes as $message): ?>
<p class="notice notice-<?= e($message['type']) ?>" role="status"><?= Talea\Admin\MenuPaths::links($app->url('admin.php'), t($message['text']), array_keys($modules)) ?></p>
<?php endforeach ?>
<?php if ($app->auth()->isAdmin() && Talea\Core\Look::hasDraft($app->settings())): // a draft look waits on every screen until it is published or discarded ?>
<?= $app->view->render('admin/look_bar', ['app' => $app, 'summary' => Talea\Core\Look::summary($app->db(), $app->settings()), 'csrf' => $app->session->csrfField()]) ?>
<?php endif ?>
<?= $content ?>
<?= $app->view->render('admin/agency', ['app' => $app, 'withLogo' => false]) ?>
<footer class="version">Talea <?= e(TALEA_VERSION) ?> · <?= e(t('Talea is free and has no ads.')) ?></footer>
</main>
<?php foreach (Talea\Core\Language::scriptDictionaries() as $dictionary): ?>
<script src="<?= e($app->url($dictionary)) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
<?php endforeach ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(TALEA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" data-max-file="<?= Talea\Core\Files::limit() ?>" data-max-file-text="<?= e(Talea\Core\Files::limitText()) ?>" data-max-page="<?= Talea\Core\Images::MAX_SIDE ?>" defer></script>
</body>
</html>
