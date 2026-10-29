<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Multisite stage 4 with the flag off: no site argument, no site tools, and a call that brings one acts on the one site as before. */
#[Group('site')]
final class McpSitesOffTest extends SiteTestCase
{
    public function testNothingOfMultisiteShowsInTheToolList(): void
    {
        $tools = (array) $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')['result']['tools'];
        $names = array_column($tools, 'name');
        foreach (['list_owner_sites', 'create_site', 'update_site', 'add_site_domain', 'update_site_domain', 'remove_site_domain'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
        $this->assertStringNotContainsString('"site":{"type":"string"', (string) json_encode(array_column($tools, 'inputSchema')), 'no tool takes a site argument');
        $this->assertStringNotContainsString('SEVERAL SITES', (string) ($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')['result']['instructions'] ?? ''));
    }

    public function testASiteArgumentIsJustAnUnknownParameter(): void
    {
        $r = $this->site()->mcpResult('list_pages', ['site' => 'whatever']);
        $this->assertIsArray($r);
        $text = $this->site()->mcpText('site_info', ['site' => 'whatever']);
        $this->assertStringContainsString('"unknown_parameters":["site"]', $text);
        $this->assertStringContainsString('not switched on', $this->site()->mcpText('create_site', ['name' => 'x']));
    }
}
