<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Mcp\Catalog;
use Talea\Mcp\Discovery;
use Talea\Mcp\Server;
use Talea\Mcp\Tools;

/** #52: the Essentials tool set – every tool belongs to exactly one runner, the runners' annotations hold, tools/list is much shorter. */
#[CoversClass(Discovery::class)]
final class DiscoveryTest extends TestCase
{
    /** @return list<array<string, mixed>> what tools/list shows an "all" connection with full access */
    private function everyTool(): array
    {
        return array_map(Server::listed(...), Tools::definitions());
    }

    public function testEveryToolIsClassifiedAndLandsInExactlyOneRunner(): void
    {
        foreach (array_keys(Catalog::TOOLS) as $name) {
            $this->assertContains(Catalog::access($name), ['read', 'draft', 'write', 'destructive'], "$name has no access level");
            $this->assertArrayHasKey(Discovery::runner($name), Discovery::RUNNERS, "$name has no runner");
        }
        foreach (Tools::definitions() as $definition) {
            $this->assertArrayHasKey($definition['name'], Catalog::TOOLS, $definition['name'] . ' is defined but not classified in Mcp\Catalog');
        }
    }

    public function testRunnerFollowsTheAccessLevelHonestly(): void
    {
        foreach (array_keys(Catalog::TOOLS) as $name) {
            $annotations = Tools::annotations($name);
            switch (Discovery::runner($name)) {
                case 'run_read_tool':
                    $this->assertTrue($annotations['readOnlyHint'], "$name is a read");
                    $this->assertFalse($annotations['openWorldHint'], "$name reaches outside – not a read runner tool");
                    $this->assertNotContains($name, Catalog::SENDS, "$name sends");
                    break;
                case 'run_tool':
                    $this->assertContains(Catalog::access($name), ['draft', 'write'], $name);
                    $this->assertFalse($annotations['destructiveHint'], "$name is destructive");
                    $this->assertFalse($annotations['openWorldHint'], $name);
                    $this->assertNotContains($name, Catalog::SENDS, $name);
                    break;
                default:
                    $this->assertTrue(Catalog::access($name) === 'destructive' || Catalog::isOpenWorld($name) || in_array($name, Catalog::SENDS, true), $name);
            }
        }
    }

    public function testToolsThatSendOrReachOutsideNeedTheDestructiveRunner(): void
    {
        foreach ([...Catalog::SENDS, ...Catalog::OPEN_WORLD, 'trash_page', 'send_newsletter', 'delete_media', 'publish_look'] as $name) {
            $this->assertArrayHasKey($name, Catalog::TOOLS, $name . ' in a list of Catalog is not a tool');
            $this->assertSame('run_destructive_tool', Discovery::runner($name), $name);
        }
        $this->assertSame('run_read_tool', Discovery::runner('list_news'));
        $this->assertSame('run_tool', Discovery::runner('create_news'));
        $this->assertSame('run_tool', Discovery::runner('update_settings'));
    }

    public function testTheRunnersAreAnnotatedForWhatTheyRun(): void
    {
        $this->assertSame(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false], Tools::annotations('run_read_tool'));
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false], Tools::annotations('run_tool'));
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true], Tools::annotations('run_destructive_tool'));
        $this->assertSame(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false], Tools::annotations('find_tools'));
        $this->assertStringContainsString('Confirm the exact action with the user', (string) array_column(Discovery::definitions(), 'description', 'name')['run_destructive_tool']);
    }

    public function testEssentialsAreRealToolsAndFarShorterThanAll(): void
    {
        foreach (Catalog::ESSENTIALS as $name) {
            $this->assertArrayHasKey($name, Catalog::TOOLS, $name);
        }
        $all = $this->everyTool();
        $essentials = Discovery::essentials($all, 'full');
        $names = array_column($essentials, 'name');
        $this->assertSame(['find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool'], array_slice($names, -4));
        $this->assertCount(count(Catalog::ESSENTIALS) + 4, $essentials);
        $allSize = strlen((string) json_encode($all));
        $essentialsSize = strlen((string) json_encode($essentials));
        $this->assertLessThan(60 * 1024, $essentialsSize, 'the essentials list must stay small');
        $this->assertLessThan($allSize / 2, $essentialsSize, 'the essentials list is less than half of all tools');
    }

    public function testConnectionAccessDecidesWhichRunnersAreListed(): void
    {
        $forAccess = fn (string $access): array => array_values(array_filter($this->everyTool(), fn (array $t): bool => Catalog::allows($access, $t['name'])));
        $names = fn (string $access): array => array_column(Discovery::essentials($forAccess($access), $access), 'name');

        $this->assertContains('run_destructive_tool', $names('full'));
        $this->assertSame(['find_tools', 'run_read_tool'], array_values(array_intersect($names('read'), ['find_tools', ...array_keys(Discovery::RUNNERS)])), 'a read-only connection lists the read runner only');
        $this->assertNotContains('run_tool', $names('read'));
        $this->assertContains('run_tool', $names('drafts'));
        $this->assertNotContains('save_collection_item', $names('read'));
    }

    public function testFindToolsListsWhatTheEssentialsDoNot(): void
    {
        $all = $this->everyTool();
        $found = Discovery::find($all, Catalog::ESSENTIALS, []);
        $listed = array_column($found['tools'], null, 'name');
        $this->assertArrayNotHasKey('get_page', $listed);
        $this->assertSame('run_read_tool', $listed['list_news']['runner']);
        $this->assertSame('run_tool', $listed['create_news']['runner']);
        $this->assertSame('run_destructive_tool', $listed['trash_news']['runner']);

        $byQuery = Discovery::find($all, Catalog::ESSENTIALS, ['query' => 'redirect']);
        $this->assertContains('save_redirect', array_column($byQuery['tools'], 'name'));
        $this->assertLessThanOrEqual(Discovery::MAX_DEFINITIONS, count($byQuery['tools']));

        $byName = Discovery::find($all, Catalog::ESSENTIALS, ['names' => ['list_news', 'nonsense']]);
        $this->assertSame(['list_news'], array_column($byName['tools'], 'name'));
        $this->assertSame(['nonsense'], $byName['not_available']);

        $this->expectException(\InvalidArgumentException::class);
        Discovery::find($all, Catalog::ESSENTIALS, ['query' => str_repeat('a', Discovery::MAX_QUERY + 1)]);
    }

    public function testARunnerRefusesAToolOfAnotherClassAndNamesTheRightOne(): void
    {
        $this->assertStringContainsString('Use run_destructive_tool for it, after the user confirmed this exact action.', Discovery::refusal('run_tool', 'trash_page'));
        $this->assertStringContainsString('Use run_tool for it.', Discovery::refusal('run_read_tool', 'create_news'));
        $this->assertStringContainsString('sends something', Discovery::refusal('run_tool', 'send_test_newsletter'));
    }

    public function testToolSetsKnowOnlyAllAndEssentials(): void
    {
        $this->assertSame('essentials', Catalog::toolSet('essentials'));
        $this->assertSame('all', Catalog::toolSet('all'));
        $this->assertSame('all', Catalog::toolSet(''), 'an unknown value (an old row, an old form) is all tools');
        $this->assertSame('all', Catalog::toolSet('everything'));
    }
}
