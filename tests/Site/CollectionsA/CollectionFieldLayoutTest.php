<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The item form's layout set on the collection: width, description, placeholder, required, order and removal of fields. */
#[Group('site')]
final class CollectionFieldLayoutTest extends SiteTestCase
{
    public function testLayoutOrderRequiredAndRemoval(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Stories', 'detail' => 1, 'fields' => [
            ['label' => 'Place', 'type' => 'text', 'width' => 'third', 'help' => 'Town or venue', 'placeholder' => 'Hof', 'required' => '1', 'position' => '1'],
            ['label' => 'Lead', 'type' => 'lines', 'width' => 'half', 'position' => '0'],
            ['label' => 'Gone', 'type' => 'text', 'position' => '2', 'remove' => '1'],
        ]], '/admin.php?module=collections');
        $fields = json_decode((string) $site->value("SELECT fields FROM tl_collections WHERE name = 'Stories'"), true);
        $this->assertSame(['lead', 'place'], array_column($fields, 'key'), 'the position orders the fields, a ticked one is removed');
        $this->assertSame(['key' => 'place', 'label' => 'Place', 'type' => 'text', 'width' => 'third', 'help' => 'Town or venue', 'placeholder' => 'Hof', 'required' => true], $fields[1]);

        $collection = $site->publicId('collections', (int) $site->value("SELECT collection_id FROM tl_collections WHERE name = 'Stories'"));
        $this->assertPage("/admin.php?module=collections&action=edit&id=$collection", 200, ['class="field-tile field-tile-half"', 'data-field-tiles', 'name="fields[1][width]" value="third" checked'], message: 'the collection form shows the tiles with their widths');
        $this->assertPage("/admin.php?module=collections&action=item&id=$collection", 200, ['class="item-field item-field-third"', 'placeholder="Hof" required aria-describedby="field-place-help"', '<p class="help" id="field-place-help">Town or venue</p>'], message: 'the item form lays the fields out');

        // a required field left empty is refused, filled it saves
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => $collection, 'item_id' => '', 'name' => 'Spring', 'data' => ['lead' => 'Text', 'place' => ''], 'visible' => '1'], "/admin.php?module=collections&action=item&id=$collection");
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_collection_items WHERE name = 'Spring'"), 'an empty required field stops the save');
        $this->adminPost('/admin.php?module=collections&action=save_item', ['collection_id' => $collection, 'item_id' => '', 'name' => 'Spring', 'data' => ['lead' => 'Text', 'place' => 'Hof'], 'visible' => '1'], "/admin.php?module=collections&action=item&id=$collection");
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_collection_items WHERE name = 'Spring'"), 'with the field filled the item is saved');

        // Earlier / Later without a script: the save swaps the field with its neighbour
        $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => $collection, 'name' => 'Stories', 'slug' => 'stories', 'detail' => 1, 'move' => '1:-1', 'fields' => [
            ['key' => 'lead', 'label' => 'Lead', 'type' => 'lines', 'width' => 'half', 'position' => '0'],
            ['key' => 'place', 'label' => 'Place', 'type' => 'text', 'width' => 'third', 'required' => '1', 'position' => '1'],
        ]], "/admin.php?module=collections&action=edit&id=$collection");
        $this->assertSame(['place', 'lead'], array_column(json_decode((string) $site->value("SELECT fields FROM tl_collections WHERE name = 'Stories'"), true), 'key'), 'Earlier moves the field up');

        // a connection gets the same refusal
        $this->assertStringContainsString('required fields are empty: Place', $site->mcpText('save_collection_item', ['collection' => 'stories', 'name' => 'Autumn', 'values' => ['lead' => 'Text']]));
    }
}
