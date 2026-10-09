<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The reason of a change and the guardrails for Claude (was: section 86, 2.15). The tests run in order and build on each other. */
#[Group('site')]
final class ReasonAndGuardrailsTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $guardPage = 0;
    private static int $freePage = 0;

    public function testAReasonOfAWriteToolIsInTheChangeLog(): void
    {
        self::$guardPage = $this->firstId($this->mcpText('vytvor_stranku', ['title' => 'Guarded page', 'visible' => false]));
        self::$freePage = $this->firstId($this->mcpText('vytvor_stranku', ['title' => 'Free page', 'visible' => false]));
        $this->assertGreaterThan(0, self::$guardPage);
        $this->assertGreaterThan(0, self::$freePage);

        $this->site()->mcp('update_page', ['id' => self::$freePage, 'description' => 'New description', 'reason' => 'Request 7: the client asked for a shorter description']);

        $this->assertSame('Request 7: the client asked for a shorter description', $this->sq("SELECT reason FROM ka_change_log WHERE module = 'claude' ORDER BY log_id DESC LIMIT 1"), 'reason: a write tool\'s reason is in the change log');
        $this->assertStringContainsString('"reason":"Request 7', $this->mcpText('list_changes', ['by' => 'claude', 'limit' => 5]), 'reason: list_changes returns it');
    }

    public function testAProtectedPageRefusesChanges(): void
    {
        $this->site()->setting('claude_protected_pages', (string) self::$guardPage);

        $raw = $this->mcpRawText('update_page', ['id' => self::$guardPage, 'description' => 'x']);
        $this->assertStringContainsString('isError', $raw, 'guardrails: a protected page refuses update_page (error)');
        $this->assertStringContainsString('protected from changes', $raw, 'guardrails: a protected page refuses update_page (reason)');

        $this->assertStringContainsString('protected from changes', $this->mcpRawText('save_build', ['id' => self::$guardPage, 'build' => ['v' => 1, 'children' => []]]), 'guardrails: and its build');

        // 3.3.2 (N31): an empty other target does not take the protection off
        $popup = $this->mcpRawText('save_build', ['id' => self::$guardPage, 'popup' => 0, 'build' => ['v' => 1, 'children' => []]]);
        $part = $this->mcpRawText('save_build', ['id' => self::$guardPage, 'part' => '', 'build' => ['v' => 1, 'children' => []]]);
        $this->assertStringContainsString('protected from changes', $popup, 'guardrails: an empty pop-up does not unprotect the page');
        $this->assertStringContainsString('protected from changes', $part, 'guardrails: an empty part does not unprotect the page');

        $this->assertStringNotContainsString('isError', $this->mcpRawText('update_page', ['id' => self::$freePage, 'description' => 'Still free']), 'guardrails: other pages stay free');
        $this->site()->setting('claude_protected_pages', '');
    }

    public function testSwitchingOffDeletingRefusesDestructiveTools(): void
    {
        $this->site()->setting('claude_destructive', '0');

        $raw = $this->mcpRawText('trash_page', ['id' => self::$freePage]);
        $this->assertStringContainsString('switched off deleting', $raw, 'guardrails: deleting switched off - trash_page refused');
        $this->assertSame('1', $this->sq('SELECT deleted_at IS NULL FROM ka_pages WHERE page_id = ?', [self::$freePage]), 'guardrails: the page stays');

        // 3.3.2 (N32): deleting, overwriting and sending through write tools count as destructive too
        $added = $this->mcpRawText('save_redirect', ['from' => '/n32-old', 'to' => '/n32-new']);
        $deleted = $this->mcpRawText('save_redirect', ['from' => '/n32-old', 'delete' => true]);
        $restored = $this->mcpRawText('restore_item_version', ['collection' => 'x', 'id' => 1, 'version' => 1]);
        $this->assertStringNotContainsString('isError', $added, 'guardrails: adding a redirect is not refused');
        $this->assertStringContainsString('switched off deleting', $deleted, 'guardrails: deleting a redirect is refused');
        $this->assertStringContainsString('switched off deleting', $restored, 'guardrails: restoring an item version is refused');
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM ka_redirects WHERE from_path = 'n32-old'"), 'guardrails: the redirect is still there');

        $this->site()->setting('claude_destructive', '1');
        $this->site()->mcp('save_redirect', ['from' => '/n32-old', 'delete' => true]);
    }

    public function testTheHourlyLimitStopsAConnectionButNeverReading(): void
    {
        $this->site()->setting('claude_change_limit', '1');

        $this->assertStringContainsString('reached the limit of 1 changes an hour', $this->mcpRawText('update_page', ['id' => self::$freePage, 'description' => 'Over the limit']), 'guardrails: the hourly limit stops a connection');
        $this->assertStringNotContainsString('isError', $this->mcpRawText('get_page', ['id' => self::$freePage]), 'guardrails: reading is never limited');

        $this->site()->setting('claude_change_limit', '0');
        $this->assertPage('/admin.php?module=claude_settings', 200, 'claude_protected_pages', message: 'guardrails: the settings are in Claude settings');
    }
}
