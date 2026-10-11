<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

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
            'menu' => ['type' => 'choice', 'label' => 'Which menu', 'default' => 'main', 'options' => \Talea\Core\Menu::LOCATIONS],
            'news_link' => ['type' => 'boolean', 'label' => 'Link to news (in the automatic menu)', 'default' => true],
            'phone_menu' => ['type' => 'boolean', 'label' => 'Hide behind a button on phones', 'default' => true],
            'mega_menu' => ['type' => 'boolean', 'label' => 'Submenu as a wide panel (mega menu)', 'default' => false],
            'highlight' => ['type' => 'choice', 'label' => 'Current item highlight', 'default' => 'background', 'options' => ['background' => 'background', 'underline' => 'underline in the secondary colour']],
            'language_switcher' => ['type' => 'boolean', 'label' => 'Language switcher (turn it off when it is elsewhere, for example in the footer)', 'default' => true],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-nav { display: flex; align-items: center; }
.tl-nav-menu { display: flex; align-items: center; gap: var(--tl-space-xs); }
.tl-nav ul { display: flex; flex-wrap: wrap; gap: var(--tl-nav-space, var(--tl-space-2xs)); margin: 0; padding: 0; list-style: none; }
.tl-nav a { display: block; padding: var(--tl-nav-padding, 0.5em 0.8em); border-radius: var(--tl-radius-full); color: inherit; font-weight: var(--tl-nav-weight, 600); text-decoration: none; }
.tl-nav a:focus-visible { outline: 3px solid var(--tl-color-secondary); outline-offset: 2px; }
.tl-nav a:hover { background: var(--tl-color-surface); }
.tl-nav a[aria-current] { background: var(--tl-color-primary-soft); color: var(--tl-color-primary); }
.tl-nav--underline a:hover, .tl-nav--underline a[aria-current] { background: none; }
.tl-nav--underline a:hover { color: var(--tl-nav-hover, var(--tl-color-secondary)); }
.tl-nav--underline a[aria-current] { color: inherit; text-decoration: underline; text-decoration-color: var(--tl-color-secondary); text-decoration-thickness: 2px; text-underline-offset: 6px; }
.tl-nav li { position: relative; }
.tl-nav li > .menu-group { display: block; border: 0; background: none; color: inherit; font: inherit; text-align: start; padding: var(--tl-nav-padding, 0.5em 0.8em); font-weight: var(--tl-nav-weight, 600); cursor: default; }
.tl-nav .submenu > a::after, .tl-nav .submenu > .menu-group::after { content: ""; display: inline-block; width: 0.4em; height: 0.4em; margin-inline-start: 0.45em; border: solid currentColor; border-width: 0 2px 2px 0; transform: translateY(-0.2em) rotate(45deg); }
.tl-nav .submenu.active > a, .tl-nav .submenu.active > .menu-group { color: var(--tl-color-primary); }
.tl-nav .submenu > ul { display: none; position: absolute; top: 100%; left: 0; z-index: 60; flex-direction: column; flex-wrap: nowrap; min-width: 14rem; padding: var(--tl-space-2xs); border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: var(--tl-color-text); box-shadow: var(--tl-shadow-m); gap: 2px; }
/* a submenu has its own spacing and padding – --tl-nav-space and --tl-nav-padding belong to the items of the main bar */
.tl-nav .submenu > ul a { padding: 0.55em 0.8em; border-radius: calc(var(--tl-radius) / 1.5); font-weight: 500; }
.tl-nav .submenu:hover > ul, .tl-nav .submenu:focus-within > ul { display: flex; }
.tl-nav li.submenu.closed > ul { display: none; } /* Esc closed a submenu opened by focus or by the mouse (web.js) */
/* icon before the label (Core\Menu), a group inside a submenu = a heading with its items (a column of the mega menu), a description under a mega menu item */
.tl-nav .menu-icon { display: inline-block; width: 1.1em; height: 1.1em; margin-inline-end: 0.45em; vertical-align: -0.2em; }
.tl-nav .menu-column > ul { flex-direction: column; flex-wrap: nowrap; gap: 2px; }
.tl-nav .menu-heading { display: block; padding: 0.55em 0.8em 0.25em; color: var(--tl-color-muted); font-size: 0.8em; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.tl-nav .menu-description { display: block; margin-block-start: 0.15em; color: var(--tl-color-muted); font-size: 0.85em; font-weight: 400; }
/* language switcher in the navigation (image/web.css): the menu rules (.tl-nav a, .tl-nav ul) do not apply to it */
.tl-nav .tl-languages a { padding: 0.45em 0.6em; font-weight: 600; }
.tl-nav .tl-languages a[aria-current] { background: var(--tl-color-primary); color: var(--tl-color-on-primary); }
.tl-nav .tl-languages-select [popover]:popover-open { display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; }
.tl-nav .tl-languages-select [popover] a { display: flex; padding: 0.55em 0.75em; border-radius: calc(var(--tl-radius) / 1.5); font-weight: 500; }
.tl-nav .tl-languages-select [popover] a[aria-current] { background: var(--tl-color-primary-soft); color: var(--tl-color-primary); font-weight: 600; }
.tl-nav-btn { display: none; }
.tl-nav-menu[popover] { position: static; inset: auto; width: auto; margin: 0; padding: 0; border: 0; background: none; color: inherit; overflow: visible; }
@media (min-width: 768px) {
	/* the panel is centred under the whole navigation – aligning it to the edge of the menu pushed it off the page for a menu on the left or right */
	.tl-nav--mega { position: relative; }
	.tl-nav--mega .tl-nav-menu, .tl-nav--mega li.submenu { position: static; }
	.tl-nav--mega .submenu > ul { left: 50%; right: auto; translate: -50% 0; width: min(56rem, 100vw - 2rem); padding: var(--tl-space-s); }
	.tl-nav--mega .submenu:hover > ul, .tl-nav--mega .submenu:focus-within > ul { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: var(--tl-space-2xs); }
	.tl-nav--mega .submenu > ul a { padding: 0.8em 1em; }
	.tl-nav--mega .submenu > ul > li:not(.menu-column) { align-self: start; }
	.tl-nav--mega .menu-column > ul a { padding: 0.55em 1em; }
}
@media (max-width: 767px) {
	.tl-nav-btn { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: var(--tl-nav-button-border, 1px solid var(--tl-color-line)); border-radius: 50%; background: var(--tl-color-background); color: var(--tl-color-text); cursor: pointer; }
	.tl-nav-btn span, .tl-nav-btn span::before, .tl-nav-btn span::after { display: block; width: 1.1rem; height: 2px; background: currentColor; }
	.tl-nav-btn span { position: relative; }
	.tl-nav-btn span::before, .tl-nav-btn span::after { content: ""; position: absolute; left: 0; }
	.tl-nav-btn span::before { top: -6px; }
	.tl-nav-btn span::after { top: 6px; }
	.tl-nav-menu[popover] { position: fixed; inset: 4.5rem var(--tl-space-m) auto; flex-direction: column; align-items: stretch; padding: var(--tl-space-s); border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: var(--tl-color-text); box-shadow: var(--tl-shadow-l); max-height: calc(100dvh - 5.5rem); overflow-y: auto; overscroll-behavior: contain; }
	.tl-nav-menu[popover]:not(:popover-open) { display: none; }
	/* keyboard: Tab past the last menu item – the open menu hides so that it does not cover the element focus moved to (WCAG 2.4.11),
	   and shows again when focus returns to the navigation; Esc or a tap outside closes it completely (Popover API, no JavaScript) */
	:root:has(:focus-visible) .tl-nav:not(:has(:focus-visible)) > .tl-nav-menu[popover]:popover-open { display: none; }
	/* the rows of the phone sheet sit close together: --tl-nav-space is the gap of the bar on a wide screen */
	.tl-nav-menu[popover] ul { flex-direction: column; gap: 2px; }
	.tl-nav-menu[popover] .submenu > ul { display: flex; position: static; min-width: 0; padding: 0 0 0 1rem; border: 0; box-shadow: none; }
	.tl-nav-menu[popover] .submenu > a::after, .tl-nav-menu[popover] .submenu > .menu-group::after { display: none; }
	.tl-nav-menu[popover] .menu-column > ul { padding-inline-start: 1rem; }
	.tl-nav-menu[popover] .menu-description { display: none; } /* the descriptions belong to the wide panel; on a phone the list stays short */
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $menu = $k->menu[$p['content']['menu'] ?? 'main'] ?? [];
        if (!$p['content']['news_link']) {
            $menu = array_values(array_filter($menu, fn (array $x): bool => empty($x['auto'])));
        }
        $items = \Talea\Core\Menu::html($menu, $k->path, $k->url(''), !empty($p['content']['mega_menu']));
        if ($items === '' && $k->editor) {
            $items = '<li><span>' . e(t('Build the menu in Appearance → Menu')) . '</span></li>';
        }
        $menu = '<ul>' . $items . '</ul>' . (($p['content']['language_switcher'] ?? true) ? $k->languages : '') . $k->colorScheme;
        $classes = 'tl-nav' . (!empty($p['content']['mega_menu']) ? ' tl-nav--mega' : '') . (($p['content']['highlight'] ?? '') === 'underline' ? ' tl-nav--underline' : '');
        if (!$p['content']['phone_menu']) {
            return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '"><div class="tl-nav-menu">' . $menu . '</div></nav>';
        }
        $id = 'tl-nav-' . $p['id'];

        return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '">'
            . '<button class="tl-nav-btn" type="button" popovertarget="' . e($id) . '" aria-label="' . e(t('Menu')) . '"><span aria-hidden="true"></span></button>'
            . '<div class="tl-nav-menu" id="' . e($id) . '" popover>' . $menu . '</div></nav>';
    }
}
