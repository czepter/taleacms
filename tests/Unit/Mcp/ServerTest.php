<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    public function testProtocolPromptsAndResources(): void
    {
        $this->assertSame(['2025-03-26', '2025-06-18', '2025-06-18'], [\Talea\Mcp\Server::protocol('2025-03-26'), \Talea\Mcp\Server::protocol('2099-01-01'), \Talea\Mcp\Server::protocol(null)], '2.2: MCP protocol version negotiated');
        $promptText = \Talea\Mcp\Prompts::get('build_page', ['topic' => 'kitchens', 'audience' => 'families'])['messages'][0]['content']['text'];
        $promptError = '';
        try {
            \Talea\Mcp\Prompts::get('translate_page', ['page_id' => '3']);
        } catch (\InvalidArgumentException $e) {
            $promptError = $e->getMessage();
        }
        $this->assertSame([true, true, 'The prompt translate_page needs the argument language.', ['build_page', 'audit_and_fix', 'translate_page', 'write_news', 'migrate_site', 'weekly_review', 'work_requests', 'scheduled_run', 'review_pending', 'draft_blueprint'], ['talea://instructions', 'talea://overview']], [str_starts_with($promptText, 'Build a new page about kitchens for families.'), str_contains(\Talea\Mcp\Prompts::get('build_page', ['topic' => 'x'])['messages'][0]['content']['text'], 'about x. First'),
            $promptError, array_column(\Talea\Mcp\Prompts::listAll(), 'name'), array_column(\Talea\Mcp\Prompts::resources(), 'uri')], '2.2: MCP prompts and resources');
    }

    public function testExtractJson(): void
    {
        $mcpList = [['name' => 'save_collection_item', 'inputSchema' => ['properties' => ['data' => ['type' => 'object'], 'name' => ['type' => 'string'], 'fields' => ['type' => 'array']]]]];
        $this->assertSame(['data' => ['a' => 'b'], 'name' => '{"x":1}', 'fields' => [1, 2]], \Talea\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{"a":"b"}', 'name' => '{"x":1}', 'fields' => '[1,2]']), 'MCP: an object and an array sent as JSON text are unpacked by the schema, text stays text');
        $this->assertSame(['data' => '{nic', 'fields' => '{"a":1}'], \Talea\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{nic', 'fields' => '{"a":1}']), 'MCP: invalid JSON or an array instead of an object is not unpacked');
        $this->assertSame(['visible' => false, 'title' => 'false'], \Talea\Mcp\Server::extractJson([['name' => 'create_page', 'inputSchema' => ['properties' => ['visible' => ['type' => 'boolean'], 'title' => ['type' => 'string']]]]], 'create_page', ['visible' => 'false', 'title' => 'false']), 'MCP: a boolean sent as text ("false" does not publish a hidden page)');
        $this->assertSame([true, true, false, 'ano'], array_values(array_map(fn (string $h): mixed => \Talea\Mcp\Server::extractJson([['name' => 't', 'inputSchema' => ['properties' => ['v' => ['type' => 'boolean']]]]], 't', ['v' => $h])['v'], ['true', '1', '0', 'ano'])), 'MCP: boolean "true" and "1" as text');
        $this->assertSame(['items' => [['type' => 'page']]], \Talea\Mcp\Server::extractJson([['name' => 'save_menu', 'inputSchema' => ['properties' => ['items' => ['type' => ['array', 'null']]]]]], 'save_menu', ['items' => '[{"type":"page"}]']), 'MCP: JSON text for a parameter of type [array, null] is unpacked');
        $this->assertSame(['classes'], \Talea\Mcp\Server::unknownParams($mcpList, 'save_collection_item', ['data' => '{}', 'classes' => [], 'name' => 'x']), 'MCP: unknown parameters are listed');
    }
}
