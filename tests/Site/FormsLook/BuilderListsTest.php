<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Collections, components and pop-ups in the shared list design (docs/specs/admin-lists.md). */
#[Group('site')]
final class BuilderListsTest extends SiteTestCase
{
    public function testComponentsListShowsUnusedTabAndPlaces(): void
    {
        $this->adminPost('/admin.php?module=components&action=save', ['component_id' => 0, 'name' => 'Lonely card', 'properties' => [['label' => 'Heading', 'type' => 'text', 'default' => 'x']]]);
        $id = (string) $this->site()->value("SELECT public_id FROM tl_components WHERE name = 'Lonely card'");
        $this->assertPage('/admin.php?module=components', 200, [
            'Unused <span class="list-tab-count">', 'nowhere – can be deleted', 'popovertarget="row-menu-' . $id . '"', '<code>{{heading}}</code>',
        ], message: 'components list');
        $this->assertPage('/admin.php?module=components&status=unused', 200, 'Lonely card', message: 'the unused tab lists the component');
    }

    public function testPopupsListHasASwitchOnceAPopupIsPublished(): void
    {
        $site = $this->site();
        $popup = (string) $site->mcpResult('save_popup', ['template' => 'blank', 'name' => 'Spring offer'])['id'];
        $this->assertPage('/admin.php?module=popups', 200, ['class="switch-placeholder"', 'popovertarget="row-menu-' . $popup . '"', 'no views yet'], message: 'an unpublished pop-up has no switch yet');
        $site->mcpResult('save_build', ['popup' => $popup, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Spring']]]]]);
        $this->assertPage('/admin.php?module=popups', 200, ['role="switch" aria-checked="false" aria-label="Spring offer on"', 'name="popup_id" value="' . $popup . '"'], message: 'a published pop-up gets the switch');

        $this->adminPost('/admin.php?module=popups&action=toggle', ['popup_id' => $popup], '/admin.php?module=popups');
        $this->assertSame('1', (string) $site->value('SELECT active FROM tl_popups WHERE public_id = ?', [$popup]), 'the switch turns the pop-up on');
    }

    public function testCollectionItemsHaveRowMenusAndASelectionBar(): void
    {
        $site = $this->site();
        $site->mcpText('create_collection', ['name' => 'Team', 'slug' => 'team-list', 'fields' => [['label' => 'Role', 'type' => 'text']]]);
        $site->mcpText('save_collection_item', ['collection' => 'team-list', 'name' => 'Anna', 'visible' => true, 'data' => ['role' => 'Photographer']]);
        $collection = (string) $site->value("SELECT public_id FROM tl_collections WHERE slug = 'team-list'");
        $item = (string) $site->value("SELECT public_id FROM tl_collection_items WHERE name = 'Anna'");
        $this->assertPage('/admin.php?module=collections', 200, 'popovertarget="row-menu-' . $collection . '"', message: 'collections list');
        $this->assertPage('/admin.php?module=collections&action=items&id=' . $collection, 200, [
            'popovertarget="row-menu-' . $item . '"', 'class="selection-bar"', 'name="collection_id" value="' . $collection . '"', 'class="cell-thumb"', 'class="status status-live"',
        ], message: 'collection items list');
    }
}
