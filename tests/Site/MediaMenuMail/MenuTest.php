<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\MediaMenuMail;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The menu editor, the menu on the site and in MCP (was: section 29 of tools/test.sh). */
#[Group('site')]
final class MenuTest extends SiteTestCase
{
    use Helpers;

    public function testMenuEditorSavesIntoTheDraftLookAndPublishesToTheSite(): void
    {
        $this->assertPage('/admin.php?module=menu', 200, 'data-menu-list', message: 'menu editor');
        $about = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'o-nas'");
        // 2.7: an icon (lide = people) and a description on an item, a group inside the submenu with its own items (a column), an unknown icon drops out
        $menu = [
            ['type' => 'page', 'page_id' => $about, 'text' => 'O firmě', 'icon' => 'people', 'description' => 'Kdo jsme', 'children' => [
                ['type' => 'link', 'text' => 'Kariéra', 'url' => 'https://example.cz/kariera', 'new_window' => true],
                ['type' => 'group', 'text' => 'Tým', 'icon' => 'neexistuje', 'children' => [['type' => 'link', 'text' => 'Vedení', 'url' => '/vedeni']]],
            ]],
            ['type' => 'news'],
            ['type' => 'link', 'text' => 'Zlý', 'url' => 'javascript:alert(1)'],
        ];
        $this->adminPost('/admin.php?module=menu&action=save&location=main', ['items' => json_encode($menu, JSON_UNESCAPED_UNICODE)], '/admin.php?module=menu');
        $this->adminPost('/admin.php?module=menu&action=save&location=footer', ['items' => '[{"type":"link","text":"Zásady ochrany soukromí","url":"/zasady"}]'], '/admin.php?module=menu');

        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM ka_menus WHERE location = 'footer' AND items LIKE '%/zasady%'"), 'the menu waits in the draft look');

        $this->publishLook();
        $page = $this->site()->client()->get('/news');

        $this->assertMatchesRegularExpression('#<li class="submenu"><a href="[^"]*/o-nas"><svg class="menu-icon"#u', $page->body, 'submenu item with the icon before the text');
        $this->assertStringContainsString('</svg>O firmě</a><ul><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>', $page->body, 'submenu with an external link in a new window');
        $this->assertStringContainsString('aria-current="page">Novinky', $page->body, 'the current page is marked');
        $this->assertStringNotContainsString('javascript:', $page->body, 'the unsafe link dropped out');
        $this->assertMatchesRegularExpression('#</li><li class="menu-column"><span class="menu-heading">Tým</span><ul><li><a href="[^"]*/vedeni">Vedení</a></li></ul></li>#u', $page->body, 'a group in the submenu is a column with a heading');
        $this->assertDoesNotMatchRegularExpression('/menu-description|neexistuje/', $page->body, 'the description only in the mega menu, the unknown icon dropped out');
        $this->assertStringContainsString('image/web.js', $page->body, 'a page with a submenu loads web.js (Esc closes the submenu)');
    }

    public function testMenuThroughMcp(): void
    {
        $main = $this->mcpText('get_menu', ['location' => 'main']);
        $this->assertStringContainsString('"icon":"people"', $main, 'MCP: get_menu returns the icon in English');
        $this->assertStringContainsString('"description":"Kdo jsme"', $main, 'MCP: get_menu returns the item description');

        $this->assertStringContainsString('Zásady ochrany soukromí', json_encode(json_decode($this->mcpText('get_menu', ['location' => 'footer']), true), JSON_UNESCAPED_UNICODE), 'the footer menu (MCP)');
    }

    #[Depends('testMenuEditorSavesIntoTheDraftLookAndPublishesToTheSite')]
    public function testACheckedPageIsAppendedToTheBuiltMenuAndTheAutomaticMenuReturns(): void
    {
        $ids = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'kontakt'");
        $this->adminPost('/admin.php?module=pages&action=save', ['page_id' => $ids, 'title' => 'Kontakt', 'slug' => 'kontakty', 'visible' => 1, 'in_menu' => 1, 'text' => '<p>Adresa.</p>'], '/admin.php?module=pages');
        $this->assertSame('1', (string) $this->site()->value("SELECT items LIKE ? FROM ka_menus WHERE location = 'main'", ['%"page_id":' . $ids . '%']), 'a checked page is appended to the built menu');

        $this->adminPost('/admin.php?module=menu&action=automatic&location=main', [], '/admin.php?module=menu');
        $this->publishLook();

        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM ka_menus WHERE location = 'main'"), 'back to the automatic menu');
    }
}
