<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testCatalogDefinitionsAndMethodsAgree(): void
    {
        // 2.1: every MCP tool once in Mcp\Catalog – its definition, its parameter types and its method agree with it
        $catalogTools = array_keys(\Talea\Mcp\Catalog::TOOLS);
        $definedTools = array_column(\Talea\Mcp\Tools::definitions(), 'name');
        $toolMethods = array_values(array_filter(array_map(fn (\ReflectionMethod $m): string => $m->name, (new \ReflectionClass(\Talea\Mcp\Tools::class))->getMethods()), fn (string $m): bool => preg_match('/^tool[A-Z]/', $m) === 1));
        $this->assertSame([[], [], [], [], count($catalogTools), ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], false, 'news'], [
            array_values(array_diff($catalogTools, $definedTools)), array_values(array_diff($definedTools, $catalogTools)),
            array_values(array_diff(array_map([\Talea\Mcp\Catalog::class, 'method'], $catalogTools), $toolMethods)), array_values(array_diff($toolMethods, array_map([\Talea\Mcp\Catalog::class, 'method'], $catalogTools))),
            count(\Talea\Mcp\Tools::definitions()), \Talea\Mcp\Tools::annotations('trash_page'), \Talea\Mcp\Tools::isWriteTool('site_audit'), \Talea\Mcp\Catalog::extension('list_news'),
        ], '2.1: MCP catalog, definitions, parameter types and methods agree');
    }

    public function testConnectionAccess(): void
    {
        // 2.2: what a connection may do – full everything, drafts only reads and drafts, read only reads; never an unknown level
        $this->assertSame([true, true, true, false, false, false, true, false, false, false, 'drafts', 'read', ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false]], [\Talea\Mcp\Catalog::allows('full', 'publish_look'), \Talea\Mcp\Catalog::allows('drafts', 'save_build'), \Talea\Mcp\Catalog::allows('drafts', 'create_page'),
            \Talea\Mcp\Catalog::allows('drafts', 'publish_build'), \Talea\Mcp\Catalog::allows('drafts', 'update_settings'), \Talea\Mcp\Catalog::allows('drafts', 'trash_page'),
            \Talea\Mcp\Catalog::allows('read', 'get_build'), \Talea\Mcp\Catalog::allows('read', 'save_build'), \Talea\Mcp\Catalog::allows('read', 'update_page'), \Talea\Mcp\Catalog::allows('whatever', 'save_build'),
            \Talea\Front\OAuth::access('drafts'), \Talea\Front\OAuth::access('admin'), \Talea\Mcp\Tools::annotations('save_build')], '2.2: connection access');
        $this->assertSame([], array_values(array_filter(array_keys(\Talea\Mcp\Catalog::TOOLS),
            fn (string $t): bool => \Talea\Mcp\Catalog::access($t) === 'draft' && (\Talea\Mcp\Tools::annotations($t)['readOnlyHint'] || \Talea\Mcp\Tools::annotations($t)['destructiveHint']))), '2.2: every drafts-only tool is a write that does not remove anything');
    }
}
