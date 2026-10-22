<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Bulk actions on collection items address the items by their public ids (docs/specs/admin-lists.md). */
#[Group('site')]
final class CollectionItemsBulkTest extends SiteTestCase
{
    public function testBulkHideUsesPublicIds(): void
    {
        $site = $this->site();
        $site->mcpText('create_collection', ['name' => 'Bulk test', 'slug' => 'bulk-test', 'fields' => [['label' => 'Note', 'type' => 'text']]]);
        $site->mcpText('save_collection_item', ['collection' => 'bulk-test', 'name' => 'First', 'visible' => true]);
        $site->mcpText('save_collection_item', ['collection' => 'bulk-test', 'name' => 'Second', 'visible' => true]);
        $first = (int) $site->value("SELECT item_id FROM tl_collection_items WHERE name = 'First'");
        $second = (int) $site->value("SELECT item_id FROM tl_collection_items WHERE name = 'Second'");
        // a public id whose digits read like the other item's integer id: the bulk action must not take it for that id
        $tricky = sprintf('%08d-0000-4000-8000-000000000000', $second);
        $site->exec('UPDATE tl_collection_items SET public_id = ? WHERE item_id = ?', [$tricky, $first]);
        $collection = (string) $site->value("SELECT public_id FROM tl_collections WHERE slug = 'bulk-test'");

        $this->adminPost('/admin.php?module=collections&action=bulk_items', ['collection_id' => $collection, 'selected' => [$tricky], 'bulk' => 'hide'], '/admin.php?module=collections&action=items&id=' . $collection);
        $this->assertSame('0|1', $site->value('SELECT visible FROM tl_collection_items WHERE item_id = ?', [$first]) . '|' . $site->value('SELECT visible FROM tl_collection_items WHERE item_id = ?', [$second]), 'only the selected item is hidden');
    }
}
