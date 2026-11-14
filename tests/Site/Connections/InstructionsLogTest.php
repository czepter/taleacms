<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * #149: the instructions for Claude steer every later connection and a connection may change them itself. Every change, over MCP
 * or in the admin, is a change log row with the whole text before and after; Home tells the administrators until one of them
 * has checked the newest text in Settings → AI.
 */
#[Group('site')]
final class InstructionsLogTest extends SiteTestCase
{
    private const string NOTICE = 'The instructions for the AI were changed by';
    private const string FIRST = 'Always say renovation, never reconstruction.';
    private const string SECOND = 'Prices always with [tax note].';

    /** @return array{via: string, description: string, before: string, after: string, count: int} the newest instructions row */
    private function lastChange(): array
    {
        $rows = $this->site()->rows("SELECT via, description, detail FROM tl_change_log WHERE module = 'claude_settings' AND action = 'instructions' ORDER BY log_id DESC");
        $detail = json_decode((string) ($rows[0]['detail'] ?? ''), true);

        return ['via' => (string) ($rows[0]['via'] ?? ''), 'description' => (string) ($rows[0]['description'] ?? ''),
            'before' => (string) ($detail['before'] ?? ''), 'after' => (string) ($detail['after'] ?? ''), 'count' => count($rows)];
    }

    public function testAChangeOverMcpIsLoggedWithBothTextsAndShownOnHome(): void
    {
        $site = $this->site();
        $this->assertPageLacks('/admin.php', self::NOTICE, message: 'no notice before any change');

        $site->mcp('update_settings', ['settings' => ['claude_instructions' => self::FIRST]]);
        $this->assertSame(['via' => 'test', 'description' => '– → “' . self::FIRST . '”', 'before' => '', 'after' => self::FIRST, 'count' => 1], $this->lastChange(),
            'a change over MCP is a change log row with the connection, a short description and the whole texts');

        $site->mcp('update_settings', ['settings' => ['claude_instructions' => self::FIRST]]);
        $this->assertSame(1, $this->lastChange()['count'], 'the same text again is no change');

        $this->assertPage('/admin.php', 200, [self::NOTICE, 'through the connection test', 'tab=ai#instructions-change', 'Check the instructions'],
            message: 'Home tells the administrator who changed the instructions, through which connection, and links to the change');
        $this->assertPage('/admin.php?module=settings&tab=ai', 200, ['id="instructions-change"', '<ins>' . self::FIRST . '</ins>', 'acknowledge_instructions'],
            message: 'Settings → AI shows the change before → after with the button that acknowledges it');
    }

    #[Depends('testAChangeOverMcpIsLoggedWithBothTextsAndShownOnHome')]
    public function testOnlyAnAdministratorWhoSawTheNewestTextClearsTheNotice(): void
    {
        $site = $this->site();
        $answer = $site->mcpResult('update_settings', ['settings' => ['claude_instructions_seen' => hash('sha256', self::FIRST)]]);
        $this->assertArrayHasKey('claude_instructions_seen', (array) ($answer['errors'] ?? []), 'a connection cannot acknowledge the change itself');
        $this->assertNotSame(hash('sha256', self::FIRST), $site->settingValue('claude_instructions_seen'), 'nothing was acknowledged over MCP');

        $this->adminPost('/admin.php?module=settings&action=acknowledge_instructions', ['instructions_seen' => hash('sha256', 'an older text')]);
        $this->assertPage('/admin.php?module=settings&tab=ai', 200, ['The instructions changed again in the meantime', 'id="instructions-change"'],
            message: 'acknowledging a text that is no longer the current one leaves the notice');

        $this->adminPost('/admin.php?module=settings&action=acknowledge_instructions', ['instructions_seen' => hash('sha256', self::FIRST)]);
        $this->assertPageLacks('/admin.php', self::NOTICE, message: 'once acknowledged, Home no longer shows the notice');
        $this->assertPageLacks('/admin.php?module=settings&tab=ai', 'id="instructions-change"', message: 'nor does Settings → AI');
    }

    #[Depends('testOnlyAnAdministratorWhoSawTheNewestTextClearsTheNotice')]
    public function testAChangeInTheAdminIsLoggedAndALaterMcpChangeShowsAgain(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'ai', 'claude_instructions' => self::SECOND, 'claude_destructive' => '1'], '/admin.php?module=settings&tab=ai');
        $this->assertSame(['via' => '', 'description' => '“' . self::FIRST . '” → “' . self::SECOND . '”', 'before' => self::FIRST, 'after' => self::SECOND, 'count' => 2], $this->lastChange(),
            'a change in the admin is a change log row too, without a connection');
        $this->assertPageLacks('/admin.php', self::NOTICE, message: 'the administrator who typed the text has seen it');

        $site->mcp('update_settings', ['settings' => ['claude_instructions' => self::SECOND . ' ' . self::FIRST]]);
        $this->assertSame(3, $this->lastChange()['count'], 'every change is recorded');
        $this->assertPage('/admin.php', 200, self::NOTICE, message: 'a change after the check shows on Home again');
    }

    #[Depends('testAChangeInTheAdminIsLoggedAndALaterMcpChangeShowsAgain')]
    public function testABlueprintThatBringsInstructionsRaisesTheNotice(): void
    {
        $site = $this->site();
        $own = self::SECOND . ' ' . self::FIRST;
        $this->adminPost('/admin.php?module=settings&action=acknowledge_instructions', ['instructions_seen' => hash('sha256', $own)]);
        $this->assertPageLacks('/admin.php', self::NOTICE, message: 'the earlier change is checked');
        $before = $this->lastChange()['count'];

        $manifest = ['talea_blueprint' => 1, 'key' => 'test_bp', 'name' => 'Test blueprint', 'group' => 'other', 'presets' => [], 'facts' => [], 'questions' => [], 'audit' => [], 'claude' => 'Always mention the free visit.'];
        $site->mcp('apply_blueprint', ['manifest' => $manifest]);
        $change = $this->lastChange();
        $this->assertSame($before + 1, $change['count'], 'applying a blueprint with instructions is a change');
        $this->assertStringStartsWith('Test blueprint: ', $change['description']);
        $this->assertSame(['', 'Always mention the free visit.'], [$change['before'], $change['after']]);

        $this->assertPage('/admin.php', 200, ['The instructions of the blueprint “Test blueprint”', 'through the connection test'], message: 'Home names the blueprint');
        $ai = $this->assertPage('/admin.php?module=settings&tab=ai', 200, '<ins>Always mention the free visit.</ins>', message: 'Settings → AI shows the blueprint\'s text');
        $this->assertSame(1, preg_match('/name="instructions_seen" value="([a-f0-9]{64})"/', $ai->body, $m), 'the button carries the fingerprint of what is watched');
        $this->adminPost('/admin.php?module=settings&action=acknowledge_instructions', ['instructions_seen' => $m[1]]);
        $this->assertPageLacks('/admin.php', self::NOTICE, message: 'one click clears it');

        $site->mcp('apply_blueprint', ['manifest' => $manifest]);
        $this->assertSame($before + 1, $this->lastChange()['count'], 'the same text again is no change');
        $this->assertPageLacks('/admin.php', self::NOTICE);

        $site->mcp('remove_blueprint', ['key' => 'test_bp']);
        $removed = $this->lastChange();
        $this->assertSame([$before + 2, 'Always mention the free visit.', ''], [$removed['count'], $removed['before'], $removed['after']], 'taking it off is a change too');
        $this->assertPage('/admin.php', 200, 'The instructions of the blueprint “Test blueprint”', message: 'and the notice shows again');
    }
}
