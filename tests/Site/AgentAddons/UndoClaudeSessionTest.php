<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Undoing a whole Claude session (was: section 91, 2.17). The tests run in order. */
#[Group('site')]
final class UndoClaudeSessionTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $old = 0;
    private static int $new = 0;
    private static int $conflict = 0;
    private static int $session = 0;

    private function newSession(): void
    {
        $this->site()->exec("UPDATE ka_agent_sessions SET last_at = '2000-01-01 00:00:00'");
    }

    public function testASessionListsItsChangesAndToolsAndUndoNeedsConfirmation(): void
    {
        $this->newSession();
        self::$old = $this->firstId($this->mcpText('vytvor_stranku', ['title' => 'Undo original', 'visible' => false]));
        $this->newSession();
        $this->site()->mcp('update_page', ['id' => self::$old, 'title' => 'Changed by Claude']);
        $this->site()->mcp('save_build', ['id' => self::$old, 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'content' => ['text' => 'Draft by Claude']]]]]);
        self::$new = $this->firstId($this->mcpText('vytvor_stranku', ['title' => 'Undo new page', 'visible' => false]));
        self::$conflict = $this->firstId($this->mcpText('vytvor_stranku', ['title' => 'Undo conflict', 'visible' => false]));
        $this->site()->exec("UPDATE ka_pages SET title = 'Edited by a person' WHERE page_id = ?", [self::$conflict]);
        self::$session = (int) $this->sq('SELECT MAX(id) FROM ka_agent_sessions');

        $sessions = $this->mcpText('list_agent_sessions', ['limit' => 3]);
        $this->assertStringContainsString('"id":' . self::$session . ',', $sessions, 'undo: the session is listed');
        $this->assertStringContainsString('save_build', $sessions, 'undo: the session lists its changes and tools');

        $this->assertStringContainsString('confirm=true', $this->mcpRawText('undo_agent_session', ['id' => self::$session]), 'undo: needs an explicit confirmation');
    }

    public function testUndoRestoresWhatClaudeChangedAndKeepsWhatAPersonEdited(): void
    {
        $text = $this->mcpText('undo_agent_session', ['id' => self::$session, 'confirm' => true]);

        $this->assertSame(
            'Undo original|1|0|Edited by a person|1',
            $this->sq("SELECT CONCAT(title, '|', build_draft IS NULL) FROM ka_pages WHERE page_id = ?", [self::$old]) . '|' . $this->sq('SELECT COUNT(*) FROM ka_pages WHERE page_id = ?', [self::$new]) . '|' . $this->sq('SELECT title FROM ka_pages WHERE page_id = ?', [self::$conflict]) . '|' . $this->lines('"conflicts":[{"table":"stranky"', $text),
            'undo: the original page has its title and no build again, the new page is gone, the page a person edited since stays and is reported',
        );
    }

    public function testASessionCannotBeUndoneTwiceAndTheChangeLogHasTheButton(): void
    {
        $this->assertSame('1|1', $this->sq('SELECT undone_at IS NOT NULL FROM ka_agent_sessions WHERE id = ?', [self::$session]) . '|' . $this->lines('already undone', $this->mcpRawText('undo_agent_session', ['id' => self::$session, 'confirm' => true])), 'undo: the session is marked undone and cannot be undone twice');
        $this->assertPage('/admin.php?module=changelog&action=sessions', 200, 'action=undo', message: 'undo: the change log lists Claude sessions with the undo button');
    }
}
