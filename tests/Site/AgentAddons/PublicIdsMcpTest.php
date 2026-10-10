<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** HF-16: MCP names every row by its public id (UUID v4) – arguments refuse numbers, results carry no numbers, the contract declares strings. */
#[Group('site')]
final class PublicIdsMcpTest extends SiteTestCase
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** Integer ids that name no row of a public table (journal sessions, versions, notes, drafts, runs, exceptions) – they stay numbers. */
    private const array NOT_ROWS = ['resolve_draft_comment.id', 'update_social_draft.id', 'undo_agent_session.id', 'report_agent_run.id', 'save_hours_exception.id',
        'delete_hours_exception.id', 'restore_look_version.id', 'write_notebook.id', 'delete_notebook_entry.id', 'restore_build_version.version_id', 'list_events.since_id', 'list_events.next_since_id'];

    /** @return array<string, mixed> */
    private function answer(string $tool, array $arguments = []): array
    {
        $data = $this->site()->mcpResult($tool, $arguments);

        return is_array($data) ? $data : ['text' => $data];
    }

    /** Every `id` and `*_id` in a result is a UUID (or not a number) – no database numbers leave the server. */
    private function assertNoNumbers(mixed $node, string $where): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || $key === 'parent')) {
                $this->assertFalse(is_int($value), "$where: $key is a number ($value)");
            }
            $this->assertNoNumbers($value, $where);
        }
    }

    public function testANumberIsNotAnId(): void
    {
        $site = $this->site();
        $page = (int) $site->value('SELECT page_id FROM tl_pages ORDER BY page_id LIMIT 1');
        $this->assertGreaterThan(0, $page);

        foreach ([$page, (string) $page, '3f1c2b4a-0000-4000-8000-000000000000'] as $id) {
            $text = $site->mcpText('get_page', ['id' => $id]);
            $this->assertStringContainsString('is not valid', $text, 'get_page with ' . json_encode($id));
            $this->assertStringNotContainsString('"title"', $text, 'nothing is read with a number');
        }
        $this->assertStringContainsString('is not valid', $site->mcpText('update_page', ['id' => $page, 'title' => 'Changed by number']), 'update_page');
        $this->assertStringContainsString('is not valid', $site->mcpText('get_build', ['id' => $page]), 'get_build');
        $this->assertStringContainsString('is not valid', $site->mcpText('create_page', ['title' => 'Child by number', 'parent' => $page]), 'a parent given as a number is not silently dropped');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_pages WHERE title IN ('Changed by number', 'Child by number')"), 'nothing was written');
        $this->assertStringContainsString('is not valid', $site->mcpText('get_news', ['id' => 1]), 'get_news');
        $this->assertStringContainsString('is not valid', $site->mcpText('save_collection_item', ['collection' => 'team', 'id' => 1, 'name' => 'x']), 'save_collection_item');

        // the public id of the same page works, and so does an empty parent
        $uuid = $site->publicId('pages', $page);
        $this->assertStringContainsString('"title"', $site->mcpText('get_page', ['id' => $uuid]), 'the public id reads the page');
    }

    public function testResultsCarryPublicIds(): void
    {
        $site = $this->site();

        $pages = $this->answer('list_pages');
        $this->assertNotEmpty($pages);
        foreach ($pages as $row) {
            $this->assertMatchesRegularExpression(self::UUID, $row['id'], 'list_pages: id');
        }
        $created = $this->answer('create_page', ['title' => 'Public ids', 'slug' => 'public-ids']);
        $this->assertMatchesRegularExpression(self::UUID, $created['id'], 'create_page: id');
        $this->assertStringContainsString('id=' . $created['id'], $created['admin_url'], 'the admin link carries the public id');
        $child = $this->answer('create_page', ['title' => 'Public child', 'slug' => 'public-child', 'parent' => $created['id']]);
        $page = $this->answer('get_page', ['id' => $child['id']]);
        $this->assertMatchesRegularExpression(self::UUID, $page['id'], 'get_page: id');
        $this->assertSame($created['id'], $page['parent'], 'get_page: parent is the public id');
        $this->assertNoNumbers($page, 'get_page');
        $this->assertSame((int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'public-ids'"), $site->internalId('pages', $created['id']));

        $category = $this->answer('create_category', ['name' => 'Ids']);
        $this->assertMatchesRegularExpression(self::UUID, $category['id'], 'create_category: id');
        $news = $this->answer('create_news', ['title' => 'Public news', 'category' => 'Ids', 'content' => '<p>x</p>']);
        $this->assertMatchesRegularExpression(self::UUID, $news['id'], 'create_news: id');
        $list = $this->answer('list_news');
        $this->assertNotEmpty($list);
        foreach ($list as $row) {
            $this->assertMatchesRegularExpression(self::UUID, $row['id'], 'list_news: id');
        }
        $this->assertSame('Public news', $this->answer('get_news', ['id' => $news['id']])['title']);
        $this->assertNoNumbers($list, 'list_news');

        $this->answer('create_collection', ['name' => 'Ids team', 'slug' => 'ids-team', 'fields' => [['label' => 'Role', 'type' => 'text']]]);
        $item = $this->answer('save_collection_item', ['collection' => 'ids-team', 'name' => 'Jane', 'values' => ['role' => 'Boss']]);
        $this->assertMatchesRegularExpression(self::UUID, $item['id'], 'save_collection_item: id');
        $items = $this->answer('list_collection_items', ['collection' => 'ids-team']);
        $this->assertCount(1, $items['items']);
        $this->assertSame($item['id'], $items['items'][0]['id'], 'list_collection_items: id');
        $this->assertNoNumbers($items, 'list_collection_items');

        // a row a tool deletes is still named by its public id in the answer
        $deleted = $this->answer('delete_category', ['id' => $this->answer('create_category', ['name' => 'Gone soon'])['id']]);
        $this->assertMatchesRegularExpression(self::UUID, $deleted['deleted'], 'delete_category: deleted');
    }

    public function testPopupsComponentsSectionsMenusSettingsAndTheTrashUsePublicIds(): void
    {
        $site = $this->site();
        $page = $this->answer('create_page', ['title' => 'Ids everywhere', 'slug' => 'ids-everywhere', 'visible' => true]);

        $popup = $this->answer('save_popup', ['template' => 'blank', 'name' => 'Ids pop-up', 'rules' => ['where' => 'selected', 'pages' => [$page['id']]]]);
        $this->assertMatchesRegularExpression(self::UUID, $popup['id'], 'save_popup: id');
        $this->assertSame([$page['id']], $popup['rules']['pages'], 'save_popup: the pages of the rules are public ids');
        $this->assertStringContainsString('/_popup/' . $popup['id'], $popup['preview'], 'the preview address names the pop-up by its public id');
        $this->assertStringContainsString('id=' . $popup['id'], $popup['builder_url'], 'the builder link names the pop-up by its public id');
        $this->assertSame($popup['id'], $this->answer('list_popups')[0]['id'], 'list_popups: id');
        $this->assertNoNumbers($this->answer('list_popups'), 'list_popups');

        $component = $this->answer('save_component', ['name' => 'Ids card', 'properties' => [['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Hello']]]);
        $this->assertMatchesRegularExpression(self::UUID, $component['id'], 'save_component: id');
        $this->assertStringContainsString('"component":"' . $component['id'] . '"', $component['use'], 'the hint shows the public id');
        $this->answer('save_build', ['component' => $component['id'], 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'heading', 'content' => ['text' => '{{title}}']]]]]], 'publish' => true]);
        $this->answer('save_build', ['id' => $page['id'], 'build' => ['v' => 1, 'children' => [['type' => 'component', 'content' => ['component' => $component['id']]]]]]);
        $this->assertSame((string) $site->internalId('components', $component['id']), (string) $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(build_draft, '$.children[0].content.component')) FROM tl_pages WHERE slug = 'ids-everywhere'"), 'the page build stores the number the renderer needs');
        $build = $this->answer('get_build', ['id' => $page['id']]);
        $this->assertSame($component['id'], $build['build']['children'][0]['content']['component'], 'get_build: the component of an element is its public id');
        $this->assertSame($page['id'], $build['id'], 'get_build: the target is named by its public id');
        $this->assertStringContainsString('is not valid', $site->mcpText('save_build', ['id' => $page['id'], 'build' => ['v' => 1, 'children' => [['type' => 'component', 'content' => ['component' => '5']]]]]), 'a component given as a number is refused');
        $this->assertNoNumbers($this->answer('list_components'), 'list_components');

        $section = $this->answer('save_section', ['id' => $page['id'], 'element' => $build['build']['children'][0]['id'], 'name' => 'Ids section']);
        $this->assertMatchesRegularExpression(self::UUID, $section['id'], 'save_section: id');
        $this->assertStringContainsString($section['id'], $section['insert'], 'save_section: the hint');
        $this->assertSame($section['id'], $this->answer('builder_schema')['saved_sections'][0]['id'], 'builder_schema: saved sections');
        $this->assertSame($section['id'], $this->answer('delete_section', ['id' => $section['id']])['deleted'], 'delete_section names the section it deleted');

        $menu = $this->answer('save_menu', ['location' => 'main', 'items' => [['type' => 'page', 'page_id' => $page['id'], 'text' => 'Ids']]]);
        $this->assertSame($page['id'], $menu['items'][0]['page_id'], 'save_menu: the page of an item');
        $this->assertStringContainsString('is not valid', $site->mcpText('save_menu', ['location' => 'main', 'items' => [['type' => 'page', 'page_id' => 1]]]), 'a menu item names its page by public id');
        $this->answer('save_menu', ['location' => 'main', 'items' => null]);

        $settings = $this->answer('update_settings', ['settings' => ['home_page' => $page['id']]]);
        $this->assertSame($page['id'], $settings['settings']['home_page'], 'update_settings: home_page is a public id');
        $this->assertSame($page['id'], $this->answer('site_info')['home_page'], 'site_info: home_page');
        $this->assertStringContainsString('is not valid', $site->mcpText('update_settings', ['settings' => ['home_page' => 1]]), 'a home page given as a number is refused');
        $this->answer('update_settings', ['settings' => ['home_page' => $this->answer('list_pages')[0]['id']]]);

        $other = $this->answer('create_page', ['title' => 'Ids trash', 'slug' => 'ids-trash']);
        $this->answer('trash_page', ['id' => $other['id']]);
        $this->assertSame($other['id'], $this->answer('list_trash')['pages'][0]['id'], 'list_trash: id');
        $this->assertSame($other['id'], $this->answer('restore_from_trash', ['type' => 'page', 'id' => $other['id']])['id'], 'restore_from_trash: id');
        $this->assertStringContainsString('is not valid', $site->mcpText('restore_from_trash', ['type' => 'news', 'id' => $other['id']]), 'a page id does not restore news');

        $this->assertSame($popup['id'], $this->answer('delete_popup', ['id' => $popup['id']])['deleted'], 'delete_popup names the pop-up it deleted');
    }

    /** The read tools of every area answer without the integer key of a row of a public-id table: no `id`, `*_id`, `site`, `booking` or `item` number anywhere in the result. */
    public function testReadToolsLeakNoRowNumbers(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_events (created_at, type, severity, message, data) VALUES (NOW(), 'booking.confirmed', 'info', 'Scan', '{\"booking\":1,\"page\":1,\"site\":1}')");
        $tools = ['list_pages', 'list_news', 'list_categories', 'list_media', 'list_popups', 'list_components', 'list_collections', 'list_enquiries', 'list_newsletters',
            'list_redirects', 'list_bookings', 'list_requests', 'list_trash', 'list_site_parts', 'list_classes', 'list_changes', 'list_events', 'list_agent_sessions',
            'list_pending_review', 'list_broken_links', 'list_facts', 'list_connectors', 'get_site', 'get_health', 'get_menu'];
        foreach ($tools as $tool) {
            $this->assertNoRowNumbers($this->site()->mcpResult($tool), $tool);
        }
        $events = $this->site()->mcpResult('list_events');
        $this->assertStringNotContainsString('"booking":1', json_encode($events), 'event data name rows by their public id');
    }

    /** Like assertNoNumbers() for the keys a result uses for other rows (`page_id`, `site`, `booking`). */
    private function assertNoRowNumbers(mixed $node, string $where): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && is_int($value) && !in_array($where . '.' . $key, self::NOT_ROWS, true)
                && ($key === 'parent' || str_ends_with($key, '_id') || in_array($key, ['site', 'booking'], true))) {
                $this->fail("$where: $key is a number ($value)");
            }
            $this->assertNoRowNumbers($value, $where);
        }
    }

    public function testTheContractDeclaresStringIds(): void
    {
        $contract = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tools/contracts/mcp-tools.json'), true);
        $this->assertNotEmpty($contract);
        $integers = [];
        foreach ($contract as $tool => $definition) {
            foreach ($definition['parameters'] as $name => $type) {
                $names = $name === 'id' || str_ends_with($name, '_id') || in_array($name, ['parent', 'translation_of', 'popup', 'component', 'saved_section', 'staff', 'service'], true);
                if ($names && $type === 'integer' && !in_array($tool . '.' . $name, self::NOT_ROWS, true)) {
                    $integers[] = $tool . '.' . $name;
                }
            }
        }
        $this->assertSame([], $integers, 'parameters that name a row are declared as strings (public ids)');

        // what the server translates is what the definitions declare: every tool of the translation tables exists, its top-level id parameters are strings
        $reflection = new \ReflectionClass(\Talea\Mcp\PublicIds::class);
        foreach (['IN', 'OUT'] as $table) {
            foreach ((array) $reflection->getConstant($table) as $tool => $paths) {
                $this->assertArrayHasKey($tool, $contract, "$table names the tool $tool");
                if ($table === 'IN') {
                    foreach (array_keys($paths) as $path) {
                        if (!str_contains($path, '.') && !str_contains($path, '*')) {
                            $this->assertSame('string', $contract[$tool]['parameters'][$path] ?? null, "$tool.$path is declared as a string");
                        }
                    }
                }
            }
        }

        // lists of ids: the items are strings too
        foreach (\Talea\Mcp\Tools::definitions() as $tool) {
            foreach ((array) $tool['inputSchema']['properties'] as $name => $property) {
                if (($property['type'] ?? '') === 'array' && preg_match('/(^|_)ids$|^(pages|staff|services)$/', (string) $name)) {
                    $this->assertSame('string', $property['items']['type'] ?? null, $tool['name'] . '.' . $name . ' lists public ids');
                }
            }
        }
    }
}
