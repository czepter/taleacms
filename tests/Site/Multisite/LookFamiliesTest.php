<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Look families (docs/specs/multisite.md): site 1 ("First", 127.0.0.1) is the parent, the second site (second.test) its child. The lock in
 * the admin and in MCP, a footer shared by both that shows each location's own business fact, and the page cache of the child on a push.
 * The tests run in order and build on each other.
 */
#[Group('site')]
final class LookFamiliesTest extends SiteTestCase
{
    private static string $second = '';
    private static ?Http $child = null;

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1']];
    }

    /** @param array<string, mixed> $arguments @return array{error: bool, text: string} */
    private function call(string $tool, array $arguments = []): array
    {
        $result = $this->site()->mcp($tool, $arguments)['result'] ?? [];

        return ['error' => ($result['isError'] ?? false) === true, 'text' => (string) ($result['content'][0]['text'] ?? '')];
    }

    private function onSecond(): array
    {
        return ['Host: second.test:' . $this->site()->port];
    }

    private function secondGet(string $path): Response
    {
        return (self::$child ?? throw new \LogicException('The second site is not set up.'))->get($path, $this->onSecond());
    }

    /** @param array<string, string> $fields */
    private function secondPost(string $path, array $fields): Response
    {
        return (self::$child ?? throw new \LogicException('The second site is not set up.'))->post($path, ['_csrf' => $this->secondGet('/admin.php')->csrf()] + $fields, $this->onSecond());
    }

    private function footer(string $text): array
    {
        return ['part' => 'footer', 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'text', 'content' => ['html' => '<p>' . $text . '</p>']]]]]]];
    }

    public function testASiteIsMadeIntoAChildOfTheFirstOne(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $first = $site->publicId('sites', 1);
        $site->mcpResult('update_site', ['id' => $first, 'name' => 'First']);

        $created = $site->mcpResult('create_site', ['name' => 'Second', 'parent' => $first]);
        self::$second = $created['site']['id'];
        $this->assertSame($first, $created['site']['look_parent']);
        $this->assertTrue($this->call('create_site', ['name' => 'Nope', 'parent' => self::$second])['error'], 'a site that is not set up cannot be a parent');

        $site->mcpResult('add_site_domain', ['id' => self::$second, 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], ['TALEA_MULTISITE' => '1']));
        $site->mcpResult('update_site', ['id' => self::$second, 'status' => 'live']);
        $this->assertSame(1, (int) $site->value('SELECT look_parent_id FROM tl_sites WHERE site_id = 2'));
        $this->assertSame($first, $site->mcpResult('list_owner_sites')['sites'][1]['look_parent']);
        self::$child = $site->client('second');
        $handoff = $this->adminPost('/admin.php?module=sites&action=handoff', ['id' => self::$second]);
        $this->assertSame(303, $handoff->status);
        self::$child->get(substr($handoff->headers['location'], strpos($handoff->headers['location'], '/admin?')), $this->onSecond());
        $this->assertSame(200, $this->secondGet('/admin.php?module=appearance')->status);
    }

    public function testAFooterOfTheParentShowsEachLocationsOwnFact(): void
    {
        $site = $this->site();
        $site->mcpResult('save_fact', ['key' => 'phone', 'label' => 'Phone', 'value' => '111-first']);
        $site->mcpResult('save_fact', ['key' => 'phone', 'label' => 'Phone', 'value' => '222-second', 'site' => self::$second]);
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_facts WHERE fact_key = 'phone'"));

        @mkdir($site->root . '/storage/cache/pages-tl2_', 0775, true);
        file_put_contents($site->root . '/storage/cache/pages-tl2_/probe.html', 'cached');
        $site->mcpResult('save_build', $this->footer('Call {{fact.phone}}'));

        $this->assertStringContainsString('{{fact.phone}}', (string) $site->value("SELECT build FROM tl2_site_parts WHERE type = 'footer' AND language = ''"), 'the published footer was copied');
        $this->assertFileDoesNotExist($site->root . '/storage/cache/pages-tl2_/probe.html', 'the child\'s page cache was cleared by the push');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl2_look_versions'), 'and its earlier look is kept for a rollback of this site alone');
        $this->assertStringContainsString('Call 111-first', $site->client('visitor')->get('/')->body);
        $this->assertStringContainsString('Call 222-second', $this->secondGet('/')->body);
        $this->assertStringNotContainsString('111-first', $this->secondGet('/')->body);
    }

    public function testTheChildCannotChangeWhatTheParentHandsDownThroughMcp(): void
    {
        $site = $this->site();
        $site->mcpResult('save_classes', ['css' => '.card { color: red; }']); // new on the parent: live at once, and in the child
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl2_classes WHERE name = 'card'"));

        foreach ([
            'update_design_system' => ['design' => ['colors' => ['primary' => '#123456']]],
            'apply_look' => ['look' => (string) $site->mcpResult('list_looks')['looks'][0]['key']],
            'save_classes' => ['css' => '.card { color: blue; }'],
            'save_build' => $this->footer('Mine'),
            'apply_part_template' => ['part' => 'header', 'template' => 'x'],
        ] as $tool => $arguments) {
            $r = $this->call($tool, $arguments + ['site' => self::$second]);
            $this->assertTrue($r['error'], $tool);
            $this->assertStringContainsString('This site takes its look from First. Change it there or leave the family.', $r['text'], $tool);
        }
        $this->assertSame($site->value("SELECT style FROM tl_classes WHERE name = 'card'"), $site->value("SELECT style FROM tl2_classes WHERE name = 'card'"), 'the refused change did not reach the child');
        $this->assertSame('', (string) $site->value("SELECT value FROM tl2_settings WHERE name = 'look_draft'"));

        $own = $this->call('save_classes', ['css' => '.mine { color: green; }', 'site' => self::$second]);
        $this->assertFalse($own['error'], 'the child\'s own classes are its own: ' . $own['text']);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl2_classes WHERE name = 'mine'"));
        $this->assertTrue($this->call('update_settings', ['settings' => ['dark_mode' => 'dark'], 'site' => self::$second])['error']);
    }

    public function testTheChildCannotChangeItInTheAdministration(): void
    {
        $site = $this->site();
        $r = $this->secondPost('/admin.php?module=appearance&action=apply_look', ['look' => 'ember']);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('This site takes its look from First. Change it there or leave the family.', $this->secondGet('/admin.php?module=appearance')->body);
        $this->assertSame('', (string) $site->value("SELECT value FROM tl2_settings WHERE name = 'look_draft'"));

        $this->secondGet('/admin.php?module=parts&action=builder&type=footer');
        $this->assertStringContainsString('This site takes its look from First', $this->secondGet('/admin.php?module=appearance')->body, 'the footer builder is closed to the child');
        $this->assertStringContainsString('Publishing updates 1 sites.', $site->admin()->get('/admin.php?module=appearance')->body);
    }

    public function testLeavingAndJoiningAgainAskForTheDraftToBeDiscarded(): void
    {
        $site = $this->site();
        $first = $site->publicId('sites', 1);
        $this->assertFalse($this->call('set_look_parent', ['site' => self::$second, 'parent' => ''])['error']);
        $this->assertNull($site->value('SELECT look_parent_id FROM tl_sites WHERE site_id = 2'));
        $this->assertFalse($this->call('update_design_system', ['design' => ['colors' => ['primary' => '#123456']], 'site' => self::$second])['error'], 'free again');

        $r = $this->call('set_look_parent', ['site' => self::$second, 'parent' => $first]);
        $this->assertTrue($r['error']);
        $this->assertStringContainsString('discard_draft', $r['text']);
        $this->assertNull($site->value('SELECT look_parent_id FROM tl_sites WHERE site_id = 2'));

        $this->assertFalse($this->call('set_look_parent', ['site' => self::$second, 'parent' => $first, 'discard_draft' => true])['error']);
        $this->assertSame(1, (int) $site->value('SELECT look_parent_id FROM tl_sites WHERE site_id = 2'));
        $this->assertSame('', (string) $site->value("SELECT value FROM tl2_settings WHERE name = 'look_draft'"), 'the draft is gone');
        $this->assertTrue($this->call('set_look_parent', ['site' => $first, 'parent' => self::$second])['error'], 'a parent cannot become a child');
    }
}
