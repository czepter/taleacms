<?php
/**
 * The frame of every page (Kaleta is themeless since 1.6): head, the header and footer site parts from the builder (or the
 * built-in ones), the content. The look comes from the design system and image/template.css. On a phone the navigation
 * opens via the Popover API (no JavaScript).
 *
 * @var Kaleta\Core\Settings $web
 * @var string $title  empty on the home page
 * @var array{main:bool, description:string, keywords:string, image:string, type:string, noindex:bool, build?:bool} $meta  build = a page from the builder (full-width sections)
 * @var string $content  ready-made HTML of the page content (page, news list, news item…)
 * @var string $notice  the notice bar of exceptions to the opening hours (2.10), or empty
 * @var callable(string): string $url
 * @var string $canonical
 * @var string $head  tags for <head> from Settings: SEO, structured data, tracking codes (always output before </head>)
 * @var string $foot   cookie bar and codes before </body> (always output)
 * @var string $language  language code of the displayed version of the site (cs, en…) for <html lang>
 * @var string $languages_html  ready-made language version switcher; empty if the site has a single language
 * @var bool $with_news  the News extension is enabled (links to RSS)
 * @var list<array{titulek:string, seo_link:string, uvod:bool}> $pages  pages "in the menu" (the home page has an empty slug) – only for older templates
 * @var list<array{text:string, url:string, nove_okno:bool, deti:list<array<string, mixed>>, novinky?:bool}> $menu  main menu (Vzhled → Menu), items may have a submenu
 * @var list<array<string, mixed>> $menu_footer  footer menu (empty until the administrator builds it)
 * @var callable(list<array<string, mixed>>, string, string): string $menu_html  menu items as <li> (Core\Menu::html: items, page path, home page url)
 * @var array{header: ?string, footer: ?string} $parts  header and footer from the builder (Appearance → Site parts); null = the layout draws them
 */
$siteName = $web->get('site_name');
$path = (string) parse_url($canonical, PHP_URL_PATH);
$isActive = fn (string $link): bool => $link === '' ? $path === $url('') : ($path === $url($link) || str_starts_with($path, $url($link) . '/'));
$site = array_filter(['LinkedIn' => $web->get('social_linkedin'), 'Facebook' => $web->get('social_facebook'), 'Instagram' => $web->get('social_instagram'), 'YouTube' => $web->get('social_youtube'), 'X' => $web->get('social_x')]);
?>
<!doctype html>
<?php $dark = in_array($web->get('dark_mode'), ['auto', 'dark'], true); ?>
<html lang="<?= e($language ?? 'cs') ?>"<?= $dark ? ' data-dark' : '' ?><?= $web->get('dark_mode') === 'dark' ? ' data-theme="dark"' : '' ?>>
<head>
<meta charset="utf-8">
<?php if ($dark && $web->get('theme_switcher') === '1'): ?>
<script>try{var t=localStorage.getItem('ka-theme'),r=document.documentElement;if(t==='auto')r.removeAttribute('data-theme');else if(t==='light'||t==='dark')r.setAttribute('data-theme',t)}catch(e){}</script>
<?php endif ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title === '' ? $siteName : (str_contains(mb_strtolower($title), mb_strtolower($siteName)) ? $title : $title . ' – ' . $siteName)) ?></title>
<?php if ($meta['description'] !== ''): ?>
<meta name="description" content="<?= e($meta['description']) ?>">
<?php endif ?>
<?php if ($meta['noindex']): ?>
<meta name="robots" content="noindex, follow">
<?php else: ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php endif ?>
<meta property="og:type" content="<?= e($meta['type']) ?>">
<meta property="og:title" content="<?= e($title !== '' ? $title : $siteName) ?>">
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($meta['image'] !== ''): ?>
<meta property="og:image" content="<?= e($meta['image']) ?>">
<?php endif ?>
<?php if ($with_news ?? true): ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($siteName) ?> – <?= e(t('News')) ?>" href="<?= e($url('rss.xml')) ?>">
<?php endif ?>
<link rel="stylesheet" href="<?= e($url('image/template.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<?= $head ?>
</head>
<body>
<a class="skip" href="#main"><?= e(t('Skip to content')) ?></a>
<?= $notice ?? '' ?>
<?php if (($parts['header'] ?? null) !== null): ?>
<?= $parts['header'] ?>
<?php else: ?>
<header class="header">
	<div class="wrap header-wrap">
		<a class="logo" href="<?= e($url('')) ?>"<?= $isActive('') ? ' aria-current="page"' : '' ?>><?php if ($web->get('logo') !== ''): ?><img src="<?= e(preg_match('#^(https?:)?/#', $web->get('logo')) ? $web->get('logo') : $url($web->get('logo'))) ?>" alt="<?= e($siteName) ?>"><?php else: ?><?= e($siteName) ?><?php endif ?></a>
		<button class="menu-btn" type="button" popovertarget="navigation" aria-label="<?= e(t('Menu')) ?>"><span aria-hidden="true"></span></button>
		<nav class="navigation" id="navigation" popover aria-label="<?= e(t('Main navigation')) ?>">
			<ul>
				<?= $menu_html($menu, $path, $url('')) ?>
			</ul>
			<?= $languages_html ?? '' ?>
		</nav>
	</div>
</header>
<?php endif ?>
<main id="main" class="<?= empty($meta['build']) ? 'wrap content' : 'build' ?>">
<?= $content ?>
</main>
<?php if (($parts['footer'] ?? null) !== null): ?>
<?= $parts['footer'] ?>
<?php else: ?>
<footer class="footer">
	<div class="wrap footer-wrap">
		<div>
			<strong><?= e($siteName) ?></strong>
<?php if ($web->get('site_description') !== ''): ?>
			<p><?= e($web->get('site_description')) ?></p>
<?php endif ?>
<?php if ($web->get('footer_text') !== ''): ?>
			<p><?= e($web->get('footer_text')) ?></p>
<?php endif ?>
<?php if ($web->get('company_email') !== ''): ?>
			<p><a href="mailto:<?= e($web->get('company_email')) ?>"><?= e($web->get('company_email')) ?></a></p>
<?php endif ?>
		</div>
		<nav aria-label="<?= e(t('Footer links')) ?>">
			<ul>
<?php $surface = []; foreach ($menu_footer as $p) { $surface[] = ['children' => []] + $p; array_push($surface, ...$p['children']); } // no expanding in the footer ?>
				<?= $menu_html($surface, $path, $url('')) ?>
<?php foreach ($site as $nazevSite => $adresa): ?>
				<li><a href="<?= e($adresa) ?>" rel="me noopener" target="_blank"><?= e($nazevSite) ?></a></li>
<?php endforeach ?>
			</ul>
		</nav>
		<p class="footer-copy">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
	</div>
</footer>
<?php endif ?>
<?= $foot ?>
</body>
</html>
