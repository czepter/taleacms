<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Issue #30: the notice board is a feature that is off by default; the municipality blueprint turns it on; switching it off deletes nothing. */
#[Group('site')]
final class NoticeBoardFeatureTest extends SiteTestCase
{
    use Helpers;

    private function toolNames(): array
    {
        $answer = $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}');

        return array_column($answer['result']['tools'] ?? [], 'name');
    }

    private function boardLink(string $slug): string
    {
        return 'action=items&amp;id=' . $this->site()->publicId('collections', (int) $this->site()->value('SELECT collection_id FROM tl_collections WHERE slug = ?', [$slug]));
    }

    private function setFeature(bool $on): void
    {
        $site = $this->site();
        $keys = array_values(array_diff(explode(',', $site->settingValue('extensions')), ['notice_board']));
        $site->mcp('update_settings', ['settings' => ['extensions' => $on ? [...$keys, 'notice_board'] : $keys]]);
    }

    public function testOffByDefault(): void
    {
        $site = $this->site();
        $this->assertNotContains('notice_board', explode(',', $site->settingValue('extensions')), 'a new site has the feature off');
        $this->assertNotContains('list_notice_log', $this->toolNames(), 'MCP: no list_notice_log');
        $this->assertStringNotContainsString('"notices"', $this->mcpText('list_collection_presets'), 'MCP: the preset is not offered');
        $this->assertStringContainsString('Unknown preset', $this->mcpText('create_collection', ['name' => 'Board', 'preset' => 'notices']), 'MCP: the preset cannot be created');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_collections WHERE preset = 'notices'"));
        $this->assertPageLacks('/admin.php?module=collections', 'value="notices"');
        $this->assertPage('/admin.php?module=extensions', 200, 'Official notice board');
        $this->assertStringContainsString('switched off', $this->mcpText('list_notice_log', ['collection' => 'x']), 'MCP: calling the tool is refused');
    }

    #[Depends('testOffByDefault')]
    public function testTheMunicipalityBlueprintTurnsItOn(): void
    {
        $site = $this->site();
        $this->mcpText('apply_blueprint', ['key' => 'municipality']);
        $this->assertContains('notice_board', explode(',', $site->settingValue('extensions')), 'applying the blueprint enables the feature');
        $this->assertContains('list_notice_log', $this->toolNames());
        $slug = (string) $site->value("SELECT slug FROM tl_collections WHERE preset = 'notices'");
        $this->assertNotSame('', $slug, 'the notice board collection exists');
        $this->assertPage('/admin.php?module=collections', 200, $this->boardLink($slug));
    }

    #[Depends('testTheMunicipalityBlueprintTurnsItOn')]
    public function testNoticesAndTheGuards(): void
    {
        $site = $this->site();
        $slug = (string) $site->value("SELECT slug FROM tl_collections WHERE preset = 'notices'");
        $day = fn (string $m): string => date('Y-m-d', strtotime($m));
        $posted = $site->rowId($site->mcpResult('save_collection_item', ['collection' => $slug, 'name' => 'Posted', 'values' => ['posted' => $day('-1 day'), 'taken_down' => $day('+5 day')], 'visible' => true])['id']);
        $site->mcp('save_collection_item', ['collection' => $slug, 'name' => 'Scheduled', 'values' => ['posted' => $day('+3 day')]]);
        $archived = $site->rowId($site->mcpResult('save_collection_item', ['collection' => $slug, 'name' => 'Archived', 'values' => ['posted' => $day('-9 day'), 'taken_down' => $day('-2 day')], 'visible' => true])['id']);
        $this->assertSame('3', (string) $site->value('SELECT COUNT(*) FROM tl_collection_items WHERE deleted_at IS NULL'));
        $this->assertStringContainsString('stay in the archive', $this->mcpText('delete_collection_item', ['collection' => $slug, 'id' => $site->publicId('collection_items', $posted)]));
        $this->assertSame('3', (string) $site->value('SELECT COUNT(*) FROM tl_collection_items WHERE deleted_at IS NULL'), 'a posted notice is not deleted');
        $before = (int) $site->value('SELECT COUNT(*) FROM tl_notice_log');
        $site->mcp('save_collection_item', ['collection' => $slug, 'id' => $site->publicId('collection_items', $archived), 'values' => ['summary' => 'Note']]);
        $this->assertSame($before + 1, (int) $site->value('SELECT COUNT(*) FROM tl_notice_log'), 'the audit trail appends');
    }

    #[Depends('testNoticesAndTheGuards')]
    public function testSwitchingOffKeepsEverything(): void
    {
        $site = $this->site();
        $slug = (string) $site->value("SELECT slug FROM tl_collections WHERE preset = 'notices'");
        $items = (string) $site->value('SELECT COUNT(*) FROM tl_collection_items');
        $log = (string) $site->value('SELECT COUNT(*) FROM tl_notice_log');
        $this->setFeature(false);
        $this->assertNotContains('list_notice_log', $this->toolNames());
        $this->assertPageLacks('/admin.php?module=collections', $this->boardLink($slug));
        $this->assertSame([$items, $log, '1'], [(string) $site->value('SELECT COUNT(*) FROM tl_collection_items'), (string) $site->value('SELECT COUNT(*) FROM tl_notice_log'),
            (string) $site->value("SELECT COUNT(*) FROM tl_collections WHERE preset = 'notices'")], 'nothing is deleted');
        // the guards stay on for the posted notices
        $posted = (int) $site->value("SELECT item_id FROM tl_collection_items WHERE name = 'Posted'");
        $this->assertStringContainsString('stay in the archive', $this->mcpText('delete_collection_item', ['collection' => $slug, 'id' => $site->publicId('collection_items', $posted)]));

        $this->setFeature(true);
        $this->assertContains('list_notice_log', $this->toolNames());
        $this->assertPage('/admin.php?module=collections', 200, $this->boardLink($slug));
    }
}
