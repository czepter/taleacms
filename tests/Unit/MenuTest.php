<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    public function testSanitizeAndHtml(): void
    {
        $this->assertSame([['type' => 'group', 'text' => 'Služby', 'children' => [['type' => 'page', 'text' => '', 'page_id' => 3], ['type' => 'link', 'text' => 'Ceník', 'url' => '/cenik', 'new_window' => true]]]], \Talea\Core\Menu::sanitize([ // check-english: allow
            ['type' => 'skript'], ['type' => 'link', 'text' => 'X', 'url' => 'javascript:alert(1)'],
            ['type' => 'group', 'text' => 'Služby', 'children' => [['type' => 'page', 'page_id' => 3, 'children' => [['type' => 'news_list']]], ['type' => 'link', 'text' => 'Ceník', 'url' => '/cenik', 'new_window' => 1]]], // check-english: allow
        ]), 'Menu::sanitize: an unknown type, a dangerous address and the third level drop out'); // check-english: allow
        $this->assertSame('<li><a href="/">Úvod</a></li><li class="submenu active"><button type="button" class="menu-group">Služby</button><ul><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>', \Talea\Core\Menu::html([ // check-english: allow
            ['text' => 'Úvod', 'url' => '/', 'new_window' => false, 'children' => []], // check-english: allow
            ['text' => 'Služby', 'url' => '', 'new_window' => false, 'children' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'new_window' => false, 'children' => []]]], // check-english: allow
        ], '/kuchyne/detail', '/'), 'Menu::html: submenu, active item and branch, home only by exact match'); // check-english: allow
        // a link with a fragment is never the current page: on the home page "/#products" matched the path "/" (every anchor underlined)
        $anchors = [
            ['text' => 'Home', 'url' => '/', 'new_window' => false, 'children' => []], ['text' => 'Products', 'url' => '/#products', 'new_window' => false, 'children' => []],
            ['text' => 'Prices', 'url' => '/services#prices', 'new_window' => false, 'children' => []], ['text' => 'Contact', 'url' => '#contact', 'new_window' => false, 'children' => []],
        ];
        $this->assertSame([1, 0], array_map(fn (string $path): int => substr_count(\Talea\Core\Menu::html($anchors, $path, '/'), 'aria-current'), ['/', '/services']), 'Menu::html: a link with a fragment is never aria-current, the page itself still is');
        // 2.7: icons and descriptions of menu items, a group inside a submenu with its own items (a column of the mega menu)
        $this->assertSame([
            ['type' => 'link', 'text' => 'Kontakt', 'icon' => 'phone', 'description' => 'Zavolejte nám ' . str_repeat('x', 106), 'url' => '/kontakt', 'new_window' => false], // check-english: allow
            ['type' => 'news', 'text' => ''],
            ['type' => 'group', 'text' => 'Služby', 'children' => [ // check-english: allow
                ['type' => 'group', 'text' => 'Kuchyně', 'icon' => 'home', 'children' => [['type' => 'page', 'text' => '', 'page_id' => 3]]], // check-english: allow
                ['type' => 'page', 'text' => '', 'page_id' => 4],
            ]],
        ], \Talea\Core\Menu::sanitize([
            ['type' => 'link', 'text' => 'Kontakt', 'url' => '/kontakt', 'icon' => 'phone', 'description' => ' <b>Zavolejte</b> nám ' . str_repeat('x', 130)], // check-english: allow
            ['type' => 'news', 'icon' => 'neexistuje', 'description' => ['pole']],
            ['type' => 'group', 'text' => 'Služby', 'children' => [ // check-english: allow
                ['type' => 'group', 'text' => 'Kuchyně', 'icon' => 'home', 'children' => [['type' => 'page', 'page_id' => 3, 'children' => [['type' => 'news_list']]]]], // check-english: allow
                ['type' => 'page', 'page_id' => 4, 'children' => [['type' => 'news_list']]],
            ]],
        ]), 'Menu::sanitize: icon only from the set, description without tags up to 120 characters, a group in a submenu may have items, a page in a submenu may not');
        $menuWithColumns = [
            ['text' => 'Kontakt', 'url' => '/kontakt', 'new_window' => false, 'children' => [], 'icon' => 'phone', 'description' => 'Nahoře se popis neukáže'], // check-english: allow
            ['text' => 'Služby', 'url' => '', 'new_window' => false, 'children' => [ // check-english: allow
                ['text' => 'Kuchyně', 'url' => '', 'new_window' => false, 'children' => [['text' => 'Na míru', 'url' => '/na-miru', 'new_window' => false, 'children' => [], 'description' => 'Podle vašich <rozměrů>']], 'icon' => 'home'], // check-english: allow
                ['text' => 'Ceník', 'url' => '/cenik', 'new_window' => false, 'children' => [], 'description' => 'Orientační ceny'], // check-english: allow
            ]],
        ];
        $menuSvg = fn (string $key): string => \Talea\Builder\Icons::svg($key, 'menu-icon');
        $this->assertSame('<li><a href="/kontakt">' . $menuSvg('phone') . 'Kontakt</a></li><li class="submenu active"><button type="button" class="menu-group">Služby</button><ul>' // check-english: allow
            . '<li class="menu-column"><span class="menu-heading">' . $menuSvg('home') . 'Kuchyně</span><ul><li><a href="/na-miru" aria-current="page">Na míru<small class="menu-description">Podle vašich &lt;rozměrů&gt;</small></a></li></ul></li>' // check-english: allow
            . '<li><a href="/cenik">Ceník<small class="menu-description">Orientační ceny</small></a></li></ul></li>', \Talea\Core\Menu::html($menuWithColumns, '/na-miru', '/', true), 'Menu::html: icon before the text, a group in a submenu as a column with a heading, description only in the mega menu under submenu items'); // check-english: allow
        $this->assertSame([false, true], [str_contains(\Talea\Core\Menu::html($menuWithColumns, '/', '/'), 'menu-description'), str_contains(\Talea\Core\Menu::html($menuWithColumns, '/', '/'), '<li class="menu-column"><span class="menu-heading">')], 'Menu::html: without a mega menu the column stays, descriptions are not printed');
        $this->assertSame(['Kontakt', 'Služby', 'Kuchyně', 'Na míru', 'Ceník'], array_column(\Talea\Core\Menu::flatten($menuWithColumns), 'text'), 'Menu::flatten: all levels in order'); // check-english: allow
        $navMegaCss = \Talea\Builder\Elements\Navigation::baseCss();
        $this->assertSame([true, true, true, true], [str_contains($navMegaCss, '.tl-nav .menu-icon {'), str_contains($navMegaCss, '.tl-nav .menu-column > ul {'), str_contains($navMegaCss, '.tl-nav .menu-heading {'),
            (bool) preg_match('/@media \(max-width: 767px\).*?\.tl-nav-menu\[popover\] \.menu-description \{ display: none; \}/s', $navMegaCss)], 'Navigation: icon, column and description style of the menu; the description hides on a phone');
    }
}
