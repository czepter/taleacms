<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Navigation: the menu from „Vzhled → Menu“ (Appearance → Menu; main or in the footer, with submenus too) and the language switcher.
 * On a phone it hides behind a button and opens as a popover (Popover API – without JavaScript, Esc and a click outside close it).
 */
final class Navigation extends Element
{
    public const string TYPE = 'navigation';
    public const string NAME = 'Navigation';
    public const string DESCRIPTION = 'Site menu (Appearance → Menu) with submenus and the language switcher; collapsible on phones.';
    public const string ICON = 'menu';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['nav'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return [
            'menu' => ['type' => 'choice', 'label' => 'Which menu', 'default' => 'main', 'options' => \Kaleta\Core\Menu::LOCATIONS],
            'news_link' => ['type' => 'boolean', 'label' => 'Link to news (in the automatic menu)', 'default' => true],
            'phone_menu' => ['type' => 'boolean', 'label' => 'Hide behind a button on phones', 'default' => true],
            'mega_menu' => ['type' => 'boolean', 'label' => 'Submenu as a wide panel (mega menu)', 'default' => false],
            'highlight' => ['type' => 'choice', 'label' => 'Current item highlight', 'default' => 'background', 'options' => ['background' => 'background', 'underline' => 'underline in the secondary colour']],
            'language_switcher' => ['type' => 'boolean', 'label' => 'Language switcher (turn it off when it is elsewhere, for example in the footer)', 'default' => true],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-nav { display: flex; align-items: center; }
.ka-nav-menu { display: flex; align-items: center; gap: var(--ka-space-xs); }
.ka-nav ul { display: flex; flex-wrap: wrap; gap: var(--ka-nav-space, var(--ka-space-2xs)); margin: 0; padding: 0; list-style: none; }
.ka-nav a { display: block; padding: var(--ka-nav-padding, 0.5em 0.8em); border-radius: var(--ka-radius-full); color: inherit; font-weight: var(--ka-nav-weight, 600); text-decoration: none; }
.ka-nav a:focus-visible { outline: 3px solid var(--ka-color-secondary); outline-offset: 2px; }
.ka-nav a:hover { background: var(--ka-color-surface); }
.ka-nav a[aria-current] { background: var(--ka-color-primary-soft); color: var(--ka-color-primary); }
.ka-nav--underline a:hover, .ka-nav--underline a[aria-current] { background: none; }
.ka-nav--underline a:hover { color: var(--ka-nav-hover, var(--ka-color-secondary)); }
.ka-nav--underline a[aria-current] { color: inherit; text-decoration: underline; text-decoration-color: var(--ka-color-secondary); text-decoration-thickness: 2px; text-underline-offset: 6px; }
.ka-nav li { position: relative; }
.ka-nav li > .menu-group { display: block; border: 0; background: none; color: inherit; font: inherit; text-align: start; padding: var(--ka-nav-padding, 0.5em 0.8em); font-weight: var(--ka-nav-weight, 600); cursor: default; }
.ka-nav .submenu > a::after, .ka-nav .submenu > .menu-group::after { content: ""; display: inline-block; width: 0.4em; height: 0.4em; margin-inline-start: 0.45em; border: solid currentColor; border-width: 0 2px 2px 0; transform: translateY(-0.2em) rotate(45deg); }
.ka-nav .submenu.aktivni > a, .ka-nav .submenu.aktivni > .menu-group { color: var(--ka-color-primary); }
.ka-nav .submenu > ul { display: none; position: absolute; top: 100%; left: 0; z-index: 60; flex-direction: column; flex-wrap: nowrap; min-width: 14rem; padding: var(--ka-space-2xs); border: 1px solid var(--ka-color-line); border-radius: var(--ka-radius); background: var(--ka-color-background); color: var(--ka-color-text); box-shadow: var(--ka-shadow-m); gap: 2px; }
/* submenu má vlastní mezery a odsazení – --ka-nav-space a --ka-nav-padding patří položkám hlavní lišty */
.ka-nav .submenu > ul a { padding: 0.55em 0.8em; border-radius: calc(var(--ka-radius) / 1.5); font-weight: 500; }
.ka-nav .submenu:hover > ul, .ka-nav .submenu:focus-within > ul { display: flex; }
.ka-nav li.submenu.closed > ul { display: none; } /* Esc zavřel submenu otevřené fokusem nebo myší (web.js) */
/* icon before the label (Core\Menu), a group inside a submenu = a heading with its items (a column of the mega menu), a description under a mega menu item */
.ka-nav .menu-icon { display: inline-block; width: 1.1em; height: 1.1em; margin-inline-end: 0.45em; vertical-align: -0.2em; }
.ka-nav .menu-column > ul { flex-direction: column; flex-wrap: nowrap; gap: 2px; }
.ka-nav .menu-heading { display: block; padding: 0.55em 0.8em 0.25em; color: var(--ka-color-muted); font-size: 0.8em; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.ka-nav .menu-description { display: block; margin-block-start: 0.15em; color: var(--ka-color-muted); font-size: 0.85em; font-weight: 400; }
/* přepínač jazyků v navigaci (image/web.css): pravidla menu (.ka-nav a, .ka-nav ul) se na něj nevztahují */
.ka-nav .ka-languages a { padding: 0.45em 0.6em; font-weight: 600; }
.ka-nav .ka-languages a[aria-current] { background: var(--ka-color-primary); color: var(--ka-color-on-primary); }
.ka-nav .ka-languages-select [popover]:popover-open { display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; }
.ka-nav .ka-languages-select [popover] a { display: flex; padding: 0.55em 0.75em; border-radius: calc(var(--ka-radius) / 1.5); font-weight: 500; }
.ka-nav .ka-languages-select [popover] a[aria-current] { background: var(--ka-color-primary-soft); color: var(--ka-color-primary); font-weight: 600; }
.ka-nav-btn { display: none; }
.ka-nav-menu[popover] { position: static; inset: auto; width: auto; margin: 0; padding: 0; border: 0; background: none; color: inherit; overflow: visible; }
@media (min-width: 768px) {
	/* panel se vystředí pod celou navigací – zarovnání k okraji menu ho u menu vlevo nebo vpravo vysunulo mimo stránku */
	.ka-nav--mega { position: relative; }
	.ka-nav--mega .ka-nav-menu, .ka-nav--mega li.submenu { position: static; }
	.ka-nav--mega .submenu > ul { left: 50%; right: auto; translate: -50% 0; width: min(56rem, 100vw - 2rem); padding: var(--ka-space-s); }
	.ka-nav--mega .submenu:hover > ul, .ka-nav--mega .submenu:focus-within > ul { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: var(--ka-space-2xs); }
	.ka-nav--mega .submenu > ul a { padding: 0.8em 1em; }
	.ka-nav--mega .submenu > ul > li:not(.menu-column) { align-self: start; }
	.ka-nav--mega .menu-column > ul a { padding: 0.55em 1em; }
}
@media (max-width: 767px) {
	.ka-nav-btn { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: var(--ka-nav-button-border, 1px solid var(--ka-color-line)); border-radius: 50%; background: var(--ka-color-background); color: var(--ka-color-text); cursor: pointer; }
	.ka-nav-btn span, .ka-nav-btn span::before, .ka-nav-btn span::after { display: block; width: 1.1rem; height: 2px; background: currentColor; }
	.ka-nav-btn span { position: relative; }
	.ka-nav-btn span::before, .ka-nav-btn span::after { content: ""; position: absolute; left: 0; }
	.ka-nav-btn span::before { top: -6px; }
	.ka-nav-btn span::after { top: 6px; }
	.ka-nav-menu[popover] { position: fixed; inset: 4.5rem var(--ka-space-m) auto; flex-direction: column; align-items: stretch; padding: var(--ka-space-s); border: 1px solid var(--ka-color-line); border-radius: var(--ka-radius); background: var(--ka-color-background); color: var(--ka-color-text); box-shadow: var(--ka-shadow-l); max-height: calc(100dvh - 5.5rem); overflow-y: auto; overscroll-behavior: contain; }
	.ka-nav-menu[popover]:not(:popover-open) { display: none; }
	/* klávesnice: Tab za poslední položku menu – otevřené menu se schová, aby nezakrylo prvek, na který fokus přešel (WCAG 2.4.11),
	   a ukáže se zase, když se fokus do navigace vrátí; Esc nebo klepnutí mimo ho zavře úplně (Popover API, bez JavaScriptu) */
	:root:has(:focus-visible) .ka-nav:not(:has(:focus-visible)) > .ka-nav-menu[popover]:popover-open { display: none; }
	.ka-nav-menu[popover] ul { flex-direction: column; }
	.ka-nav-menu[popover] .submenu > ul { display: flex; position: static; min-width: 0; padding: 0 0 0 1rem; border: 0; box-shadow: none; }
	.ka-nav-menu[popover] .submenu > a::after, .ka-nav-menu[popover] .submenu > .menu-group::after { display: none; }
	.ka-nav-menu[popover] .menu-column > ul { padding-inline-start: 1rem; }
	.ka-nav-menu[popover] .menu-description { display: none; } /* the descriptions belong to the wide panel; on a phone the list stays short */
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $menu = $k->menu[$p['content']['menu'] ?? 'main'] ?? [];
        if (!$p['content']['news_link']) {
            $menu = array_values(array_filter($menu, fn (array $x): bool => empty($x['auto'])));
        }
        $items = \Kaleta\Core\Menu::html($menu, $k->path, $k->url(''), !empty($p['content']['mega_menu']));
        if ($items === '' && $k->editor) {
            $items = '<li><span>' . e(t('Build the menu in Appearance → Menu')) . '</span></li>';
        }
        $menu = '<ul>' . $items . '</ul>' . (($p['content']['language_switcher'] ?? true) ? $k->languages : '') . $k->colorScheme;
        $classes = 'ka-nav' . (!empty($p['content']['mega_menu']) ? ' ka-nav--mega' : '') . (($p['content']['highlight'] ?? '') === 'underline' ? ' ka-nav--underline' : '');
        if (!$p['content']['phone_menu']) {
            return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '"><div class="ka-nav-menu">' . $menu . '</div></nav>';
        }
        $id = 'ka-nav-' . $p['id'];

        return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '">'
            . '<button class="ka-nav-btn" type="button" popovertarget="' . e($id) . '" aria-label="' . e(t('Menu')) . '"><span aria-hidden="true"></span></button>'
            . '<div class="ka-nav-menu" id="' . e($id) . '" popover>' . $menu . '</div></nav>';
    }
}
