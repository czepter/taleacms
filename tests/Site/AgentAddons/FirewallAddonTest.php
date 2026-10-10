<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * The firewall as a bundled add-on (issue #29; was Core\Firewall with calls in Front\Kernel, Settings, Scheduler and Events). Off by default:
 * nothing of it exists and the basic limits of core still work. Switched on: blocked addresses, probing, the request limit, unblocking, the
 * tool for Claude, fail-open. The test server runs with TALEA_FIREWALL_LOCAL=1, so 127.0.0.1 counts as a visitor's address.
 * The tests of the class run in order.
 */
#[Group('site')]
final class FirewallAddonTest extends SiteTestCase
{
    use AgentHelpers;

    private const string PAGE = '/admin.php?module=addons&action=page&p=firewall.settings';

    private function toggle(int $on): void
    {
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'firewall', 'on' => $on] + ($on === 1 ? ['trust' => 1] : []), '/admin.php?module=addons');
    }

    private function tableExists(string $table): bool
    {
        return $this->sq('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?', [$table]) === '1';
    }

    public function testNothingOfItExistsWhileItIsOffAndTheCoreLimitsStay(): void
    {
        $this->assertPage('/admin.php?module=addons', 200, ['Firewall', 'Official add-on, shipped with Talea', 'may refuse it'], message: 'the bundled add-on is listed with what it declares');
        $this->assertFalse($this->tableExists('tl_ext_firewall_blocks') || $this->tableExists('tl_ext_firewall_log'), 'no tables');
        $this->assertSame(404, $this->site()->admin()->get(self::PAGE)->status, 'no settings page');
        $this->assertStringNotContainsString('name="firewall_ips"', $this->site()->admin()->get('/admin.php?module=settings&tab=firewall')->body, 'the Firewall tab is gone from Settings');
        $this->assertStringNotContainsString('ext_firewall_blocks', $this->mcpRawText('get_health') . json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')), 'no tool for Claude');

        $visitor = $this->site()->client('probe');
        for ($i = 0; $i < 7; $i++) {
            $visitor->get('/wp-login.php');
        }
        $this->assertSame(200, $visitor->get('/')->status, 'probing blocks nobody while the add-on is off');

        $this->assertPage('/admin.php?module=settings&tab=general', 200, ['name="trusted_proxy"', 'The site runs behind'], message: 'the proxy choice stays in core');
    }

    public function testBasicLoginLimitStaysWithTheAddonOff(): void
    {
        $guest = $this->site()->client('guesser');
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $answer = $guest->post('/admin.php', ['_csrf' => $guest->get('/admin.php')->csrf(), 'username' => 'nobody', 'password' => 'wrong-' . $i]);
            $codes[] = $answer->contains('Too many sign-in attempts') ? 1 : 0;
        }
        $this->assertContains(1, $codes, 'a login brute force is still limited with every add-on off');
    }

    public function testSwitchedOnItCreatesItsTablesAndBlocksAListedAddress(): void
    {
        $site = $this->site();
        $this->toggle(1);
        $this->assertTrue($this->tableExists('tl_ext_firewall_blocks') && $this->tableExists('tl_ext_firewall_log'), 'the migration created the tables');

        $this->assertPage(self::PAGE, 200, ['Blocked addresses and networks', 'Blocked countries', 'Requests per minute from one address', 'No address is blocked right now.', 'Your address as the site sees it'], message: 'the settings page');
        $this->assertPage('/admin.php?module=status', 200, ['Firewall', 'blocked now'], message: 'a row in System status');

        $visitor = $site->client();
        $this->adminPost(self::PAGE, ['_ext_settings' => 1, 'probes' => 1, 'rate' => 0, 'ips' => "127.0.0.1 # test\nnonsense", 'countries' => ''], self::PAGE);
        $this->assertSame(403, $visitor->get('/')->status, 'a listed address is refused on the public site, before routing');
        $this->assertSame(403, $visitor->get('/no-such-page-at-all')->status, 'also where there is no page');
        $this->assertPage(self::PAGE, 200, ['nonsense', 'blocked address', '/no-such-page-at-all'], message: 'the administration stays open; the page lists the refused request and the ignored line');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_ext_firewall_log WHERE reason = 'list' AND path = '/'"), 'the refusal is logged');

        $this->adminPost(self::PAGE, ['_ext_settings' => 1, 'probes' => 1, 'rate' => 0, 'ips' => '', 'countries' => ''], self::PAGE);
        $this->assertSame(200, $visitor->get('/')->status, 'off the list, the address is let through again');
        $this->assertArrayHasKey('blocked', $this->mcpData('ext_firewall_blocks'), 'the tool for Claude is there');
    }

    public function testProbingBlocksForADayAndUnblockWorks(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        for ($i = 0; $i < 4; $i++) {
            $visitor->get('/wp-login.php');
        }
        $this->assertSame(403, $visitor->get('/wp-login.php')->status, 'probing for other systems is blocked at the fifth try');
        $this->assertSame(403, $visitor->get('/')->status, 'the blocked address is refused everywhere for a while');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_events WHERE type = 'firewall.blocked'"), 'the block is recorded as an event');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_ext_firewall_blocks WHERE reason = 'probe'"), 'in the table of blocks');

        $this->assertPage(self::PAGE, 200, ['probing for other systems', 'Unblock'], message: 'the page shows the block with a button');
        $this->adminPost(self::PAGE, ['unblock' => '127.0.0.1'], self::PAGE);
        $this->assertSame(200, $visitor->get('/')->status, 'an address can be unblocked');

        $tool = $this->mcpData('ext_firewall_blocks');
        $this->assertContains('probe', array_column($tool['refused'] ?? [], 'reason'), 'the tool for Claude lists the refused requests');
        $this->assertSame([], $tool['blocked'] ?? null, 'and no block is left');
    }

    public function testTheRequestLimitAnswers429AndTheCleanUpJobRuns(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $this->adminPost(self::PAGE, ['_ext_settings' => 1, 'probes' => 1, 'rate' => 3, 'ips' => '', 'countries' => ''], self::PAGE);
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $visitor->get('/')->status;
        }
        $this->assertContains(429, $codes, 'too many requests a minute get 429');
        $this->assertSame(200, $this->site()->admin()->get('/admin.php')->status, 'the administration is never limited');

        $site->exec("UPDATE tl_ext_firewall_log SET created_at = '2020-01-01 00:00:00'");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'ext_firewall_cleanup'");
        $this->adminPost(self::PAGE, ['_ext_settings' => 1, 'probes' => 1, 'rate' => 0, 'ips' => '', 'countries' => ''], self::PAGE);
        $site->runTasks();
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_jobs WHERE name = 'ext_firewall_cleanup' AND last_ok IS NOT NULL AND last_error = ''"), 'the clean-up job ran');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_ext_firewall_log WHERE created_at < '2021-01-01'"), 'old log rows are gone');
    }

    public function testAFailingHookSwitchesTheAddonOffAndRequestsContinue(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $site->exec('DROP TABLE tl_ext_firewall_blocks');
        $this->assertSame(200, $visitor->get('/')->status, 'the hook fails, the request goes on');
        $this->assertSame('', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_enabled'"), 'the add-on is switched off');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_events WHERE type = 'addon.failed'"), 'with an event');
        $this->assertSame(200, $visitor->get('/')->status, 'and the next request is served');
    }

    public function testUninstallDeletesTheDataOnRequest(): void
    {
        $this->toggle(1);
        $this->assertTrue($this->tableExists('tl_ext_firewall_log'), 'switched on again, the log table is there');
        $this->toggle(0);
        $this->adminPost('/admin.php?module=addons&action=uninstall', ['slug' => 'firewall', 'data' => 'delete'], '/admin.php?module=addons');
        $this->assertFalse($this->tableExists('tl_ext_firewall_log') || $this->tableExists('tl_ext_firewall_blocks'), 'delete: the tables are dropped');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name LIKE 'ext.firewall.%'"), 'and the settings');
    }
}
