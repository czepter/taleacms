<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * The admin menu (#158): collections with "Show in the admin menu" in a group of their own (at most 8, by name, only with the
 * Collections permission), the palette finds every collection, the item list filters by choice fields, and new enquiries and
 * bookings to confirm show as a count next to their menu item.
 */
#[Group('site')]
final class AdminMenuCollectionsTest extends SiteTestCase
{
    /** @return list<string> the names in the menu's Collections group, in their order */
    private function menuCollections(string $body): array
    {
        if (preg_match('#<li class="menu-collections">(.*?)</ul>#s', $body, $group) !== 1) {
            return [];
        }
        preg_match_all('#<bdi>([^<]*)</bdi>#', $group[1], $names);

        return $names[1];
    }

    private function collectionId(string $slug): string
    {
        return (string) $this->site()->value('SELECT public_id FROM tl_collections WHERE slug = ?', [$slug]);
    }

    public function testMcpTakesAndReturnsTheSetting(): void
    {
        $site = $this->site();
        $site->exec('UPDATE tl_collections SET admin_menu = FALSE'); // whatever the starter site made stays out of the counts below
        $created = $site->mcpResult('create_collection', ['name' => 'Menu 01', 'slug' => 'menu-01']);
        $this->assertTrue($created['admin_menu'] ?? null, 'create_collection: on by default');
        $hidden = $site->mcpResult('create_collection', ['name' => 'Menu off', 'slug' => 'menu-off', 'admin_menu' => false]);
        $this->assertFalse($hidden['admin_menu'] ?? null, 'create_collection takes admin_menu');
        $this->assertSame('0', (string) $site->value("SELECT admin_menu FROM tl_collections WHERE slug = 'menu-off'"), 'stored off');

        $updated = $site->mcpResult('update_collection', ['collection' => 'menu-off', 'admin_menu' => true]);
        $this->assertTrue($updated['admin_menu'] ?? null, 'update_collection switches it on');
        $site->mcpResult('update_collection', ['collection' => 'menu-off', 'admin_menu' => false]);
        $listed = array_column((array) $site->mcpResult('list_collections'), 'admin_menu', 'collection');
        $this->assertSame([true, false], [$listed['menu-01'] ?? null, $listed['menu-off'] ?? null], 'list_collections returns admin_menu');
    }

    #[Depends('testMcpTakesAndReturnsTheSetting')]
    public function testTheMenuListsAtMostEightByName(): void
    {
        $site = $this->site();
        foreach (['Menu 10', 'Menu 05', 'Menu 03', 'Menu 08', 'Menu 02', 'Menu 09', 'Menu 07', 'Menu 04', 'Menu 06'] as $name) {
            $site->mcpResult('create_collection', ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
        }
        $home = $this->assertPage('/admin.php', 200, '<span class="menu-heading" id="menu-collections">Collections</span>', message: 'the Collections group in the menu');
        $this->assertSame(['Menu 01', 'Menu 02', 'Menu 03', 'Menu 04', 'Menu 05', 'Menu 06', 'Menu 07', 'Menu 08'], $this->menuCollections($home->body), 'at most 8, by name; one that is off stays out');
        $this->assertStringContainsString('items/' . $this->collectionId('menu-01'), $home->body, 'a menu link opens the items of its collection');
        $this->assertLessThan(strpos($home->body, 'menu-claude'), strpos($home->body, 'menu-collections'), 'the group stands before AI');
        foreach (['Menu 10', 'Menu off'] as $name) {
            $this->assertStringContainsString('"n":"' . $name . '"', $home->body, "the palette finds every collection by name ($name)");
        }
    }

    #[Depends('testTheMenuListsAtMostEightByName')]
    public function testTheOpenCollectionIsCurrentAndOpensNoSection(): void
    {
        $id = $this->collectionId('menu-03');
        $items = $this->assertPage('/admin.php?module=collections&action=items&id=' . $id, 200, message: 'items of a collection in the menu');
        $this->assertMatchesRegularExpression('#<li class="active"><a href="[^"]*' . $id . '" aria-current="page"><bdi>Menu 03</bdi></a></li>#', $items->body, 'the open collection is the current item');
        $this->assertStringNotContainsString('class="menu-section active"', $items->body, 'no section opens');
        $item = $this->assertPage('/admin.php?module=collections&action=item&id=' . $id, 200, message: 'a new item of it');
        $this->assertStringContainsString('aria-current="page"><bdi>Menu 03</bdi>', $item->body, 'current on every screen of the collection');

        $other = $this->assertPage('/admin.php?module=collections&action=items&id=' . $this->collectionId('menu-10'), 200, message: 'a collection beyond the 8');
        $this->assertStringContainsString('class="menu-section active"', $other->body, 'a collection not in the menu opens Content as before');
    }

    #[Depends('testTheMenuListsAtMostEightByName')]
    public function testTheFormSwitchesItOff(): void
    {
        $id = $this->collectionId('menu-02');
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => $id, 'name' => 'Menu 02', 'slug' => 'menu-02', 'hidden_redirect' => ''], '/admin.php?module=collections&action=edit&id=' . $id);
        $this->assertSame('0', (string) $this->site()->value("SELECT admin_menu FROM tl_collections WHERE slug = 'menu-02'"), 'an unticked box takes the collection out of the menu');
        $this->assertNotContains('Menu 02', $this->menuCollections($this->site()->admin()->get('/admin.php')->body), 'and the menu follows');
        $this->assertStringContainsString('name="admin_menu" value="1">', $this->site()->admin()->get('/admin.php?module=collections&action=edit&id=' . $id)->body, 'the form shows it unticked');
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => $id, 'name' => 'Menu 02', 'slug' => 'menu-02', 'hidden_redirect' => '', 'admin_menu' => '1'], '/admin.php?module=collections&action=edit&id=' . $id);
        $this->assertSame('1', (string) $this->site()->value("SELECT admin_menu FROM tl_collections WHERE slug = 'menu-02'"), 'a ticked box puts it back');
        $this->assertStringContainsString('name="admin_menu" value="1" checked>', $this->site()->admin()->get('/admin.php?module=collections&action=new')->body, 'a new collection starts in the menu');
    }

    #[Depends('testTheMenuListsAtMostEightByName')]
    public function testOnlyWhoMayOpenCollectionsSeesThem(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('menu-editor', ?, 'Menu editor', 1, NOW(), NOW())", [password_hash($site->password, PASSWORD_DEFAULT)]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = 'menu-editor'");
        $editor = $site->client('menu-editor');
        $site->signIn($editor, 'menu-editor');
        $this->assertPageLacks('/admin.php', ['menu-collections', '"n":"Menu 01"'], $editor, 'without the Collections permission: no group, no palette entries');

        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'collections' FROM tl_users WHERE username = 'menu-editor'");
        $this->assertContains('Menu 01', $this->menuCollections($editor->get('/admin.php')->body), 'with it the group is there');
    }

    public function testTheItemListFiltersByChoiceFields(): void
    {
        $site = $this->site();
        $site->mcpResult('create_collection', ['name' => 'Shifts', 'slug' => 'shifts', 'fields' => [['label' => 'Kind', 'type' => 'choice', 'options' => ['Full time', 'Part time', 'Freelance']]]]);
        $site->mcpResult('save_collection_item', ['collection' => 'shifts', 'name' => 'Day shift', 'values' => ['kind' => 'Full time']]);
        $site->mcpResult('save_collection_item', ['collection' => 'shifts', 'name' => 'Night shift', 'values' => ['kind' => 'Part time']]);
        $list = '/admin.php?module=collections&action=items&id=' . $this->collectionId('shifts');

        $all = $this->assertPage($list, 200, ['Day shift', 'Night shift', 'name="field_kind"', 'data-submit-on-change'], message: 'every choice field is a filter');
        $this->assertStringNotContainsString('Clear filter', $all->body, 'nothing filtered yet');
        $this->assertPage($list . '&field_kind=' . rawurlencode('Part time'), 200, ['Night shift', '<option value="Part time" selected>', 'Clear filter'], message: 'filtered by an option');
        $this->assertPageLacks($list . '&field_kind=' . rawurlencode('Part time'), 'Day shift', message: 'the other option is left out');
        $this->assertPage($list . '&field_kind=Freelance', 200, 'No item matches the filter.', message: 'an option without items');
        $this->assertPage($list . '&field_kind=Unknown', 200, ['Day shift', 'Night shift'], message: 'an unknown option is no filter');
    }

    public function testCountsNextToTheMenuItems(): void
    {
        $site = $this->site();
        $site->setting('extensions', 'news,enquiries,newsletter_signup,stats,redirects,bookings');
        $this->assertPageLacks('/admin.php', 'menu-count', message: 'nothing waits: no count');

        $site->exec("INSERT INTO tl_enquiries (created_at, form, email, data, status) VALUES (NOW(), 'Contact', 'a@example.com', '[]', 0), (NOW(), 'Contact', 'b@example.com', '[]', 0), (NOW(), 'Contact', 'c@example.com', '[]', 1)");
        $site->exec("INSERT INTO tl_enquiries (created_at, form, email, data, status, category) VALUES (NOW(), 'Contact', 'spam@example.com', '[]', 0, 'spam')");
        $site->exec("INSERT INTO tl_bookings (service_id, staff_id, starts_at, ends_at, status, token_hash, created_at) VALUES (1, 1, NOW() + INTERVAL 2 DAY, NOW() + INTERVAL 2 DAY + INTERVAL 1 HOUR, 'pending', ?, NOW()), (1, 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY + INTERVAL 1 HOUR, 'pending', ?, NOW())",
            [hash('sha256', 'menu-count-1'), hash('sha256', 'menu-count-2')]);

        // Enquiries and Bookings are the only screens of their sections here, so the count stands next to the section's own item
        $home = $this->assertPage('/admin.php', 200, message: 'Home with things waiting');
        $this->assertStringContainsString('>Customers <span class="menu-count" aria-hidden="true">2</span><span class="visually-hidden"> – 2 new</span></a>', $home->body, 'two new enquiries (read and spam left out), screen readers hear "2 new"');
        $this->assertStringContainsString('>Bookings <span class="menu-count" aria-hidden="true">1</span><span class="visually-hidden"> – 1 new</span></a>', $home->body, 'one booking to confirm (a past one left out)');
        $site->setting('extensions', 'news,enquiries,newsletter_signup,stats,redirects,bookings,members');
        $enquiries = $this->assertPage('/admin.php?module=enquiries', 200, message: 'the Enquiries screen, its section open with Members beside it');
        $this->assertMatchesRegularExpression('#aria-current="page">Enquiries <span class="menu-count" aria-hidden="true">2</span>#', $enquiries->body, 'in an open section the count stands next to the module');
        $this->assertStringContainsString('aria-current="true">Customers</a>', $enquiries->body, 'and not twice on the open section');
    }
}
