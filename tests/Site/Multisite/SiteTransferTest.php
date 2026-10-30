<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Moving a site to another owner (docs/specs/multisite.md, "Transfer between owners"): the operator's report and confirmation in the Owners
 * area, the MCP dry run, the old site refusing work while the move is queued (host, MCP `site`, handoff, cron) and the cron step that finishes it.
 * The tests run in order and build on each other.
 */
#[Group('site')]
final class SiteTransferTest extends SiteTestCase
{
    private static string $second = '';
    private static string $beta = '';
    private static string $hash = '';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1']];
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1'];
    }

    public function testAnOperatorSeesTheReportAndQueuesTheMove(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 TALEA_OWNERS=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());
        $new = $site->admin()->get('/admin.php?module=owners&action=new');
        $site->admin()->post('/admin.php?module=owners&action=create', ['_csrf' => $new->csrf(), 'name' => 'Beta GmbH', 'kind' => 'organisation', 'admin_email' => 'boss@beta.example']);
        self::$beta = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tlb_'");
        $this->assertStringContainsString('owner tlb_: ready', $site->cli(['system/docker.php', 'cron'], $this->env()));

        self::$second = $site->mcpResult('create_site', ['name' => 'Second'])['site']['id'];
        $site->mcpResult('add_site_domain', ['id' => self::$second, 'host' => 'second.test']);
        $this->assertStringContainsString('site tl2_: ready', $site->cli(['system/docker.php', 'cron'], $this->env()));

        $dry = $site->mcpResult('transfer_site', ['id' => self::$second, 'owner' => self::$beta]);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame([], $dry['report']['blockers']);
        $this->assertSame([['host' => 'second.test', 'verified' => true]], $dry['report']['domains']);
        self::$hash = $dry['report']['hash'];
        $this->assertNull($site->value('SELECT transfer_to FROM tl_sites WHERE site_id = 2'), 'a dry run writes nothing');

        $page = $site->admin()->get('/admin.php?module=owners&action=transfer&site=' . self::$second . '&to=' . self::$beta);
        $this->assertStringContainsString('second.test', $page->body);
        $this->assertStringContainsString('name="hash" value="' . self::$hash . '"', $page->body);
        $this->assertStringContainsString('data-confirm', $page->body);

        $stale = $site->admin()->post('/admin.php?module=owners&action=transfer', ['_csrf' => $page->csrf(), 'site' => self::$second, 'to' => self::$beta, 'hash' => str_repeat('0', 64)]);
        $this->assertSame(302, $stale->status);
        $this->assertNull($site->value('SELECT transfer_to FROM tl_sites WHERE site_id = 2'), 'a wrong hash queues nothing');

        $this->assertSame(302, $site->admin()->post('/admin.php?module=owners&action=transfer', ['_csrf' => $page->csrf(), 'site' => self::$second, 'to' => self::$beta, 'hash' => self::$hash])->status);
        $this->assertSame('tlb2_', $site->value('SELECT transfer_to FROM tl_sites WHERE site_id = 2'));
    }

    public function testTheOldSiteRefusesWorkWhileTheMoveIsQueued(): void
    {
        $site = $this->site();
        $r = $site->mcp('create_page', ['title' => 'Too late', 'visible' => false, 'site' => self::$second])['result'] ?? [];
        $this->assertTrue($r['isError'] ?? false);
        $this->assertStringContainsString('This site is being moved to another owner.', (string) ($r['content'][0]['text'] ?? ''));
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl2_pages WHERE title = 'Too late'"));

        $host = $site->client('second')->get('/', ['Host: second.test:' . $site->port]);
        $this->assertSame(503, $host->status);
    }

    public function testCronFinishesTheMove(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('site tlb2_: ready', $site->cli(['system/docker.php', 'cron'], $this->env()));
        $this->assertSame('trashed', $site->value('SELECT status FROM tl_sites WHERE site_id = 2'));
        $this->assertNull($site->value('SELECT transfer_to FROM tl_sites WHERE site_id = 2'));
        $this->assertSame(self::$second, $site->value("SELECT public_id FROM tlb_sites WHERE prefix = 'tlb2_'"));
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb_site_domains WHERE host = 'second.test'"));
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_site_domains WHERE host = 'second.test'"));
    }
}
