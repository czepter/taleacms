<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The agent notebook over MCP, in the admin and in the export (was: section 87, 2.15). The tests run in order and build on each other. */
#[Group('site')]
final class AgentNotebookTest extends SiteTestCase
{
    use AgentHelpers;

    private static int $before = 0;
    private static int $note = 0;
    private static int $pinned = 0;
    private static int $adminNote = 0;

    public function testClaudeWritesAndChangesNotes(): void
    {
        self::$before = (int) $this->pick($this->mcpData('site_info'), 'notebook_count');

        $written = $this->mcpData('write_notebook', ['topic' => 'style', 'title' => 'Never the word cheap', 'text' => 'We write “affordable” or “good value”, never “cheap” – the client’s decision of 3 Oct 2026.']);
        self::$note = (int) $this->pick($written, 'note', 'id');
        $this->assertSame('id|test|style|', ((self::$note > 0) ? 'id' : '') . '|' . $this->pick($written, 'note', 'author') . '|' . $this->pick($written, 'note', 'topic') . '|' . $this->pick($written, 'note', 'pinned'), 'notebook: Claude writes a note; the author is the name of its connection');

        $second = $this->mcpData('write_notebook', ['topic' => 'credits', 'title' => 'Photos from 2024', 'text' => 'The photos in the References section were taken by the in-house team, without a credit.']);
        self::$pinned = (int) $this->pick($second, 'note', 'id');
        $changed = $this->mcpData('write_notebook', ['id' => self::$pinned, 'pinned' => true]);
        $this->assertSame('Photos from 2024|1|credits', $this->pick($changed, 'note', 'title') . '|' . $this->pick($changed, 'note', 'pinned') . '|' . $this->pick($changed, 'note', 'topic'), 'notebook: a change by id keeps the other fields and pins the note');
    }

    public function testReadingSearchingAndFilteringNotes(): void
    {
        $all = $this->mcpData('read_notebook');
        $this->assertSame(self::$pinned . '|' . self::$note . '|' . (self::$before + 2), $this->pick($all, 'notes', 0, 'id') . '|' . $this->pick($all, 'notes', 1, 'id') . '|' . $this->pick($all, 'count'), 'notebook: pinned first, then the most recently changed');

        $found = $this->mcpData('read_notebook', ['search' => 'cheap']);
        $this->assertSame('1|' . self::$note, $this->pick($found, 'count') . '|' . $this->pick($found, 'notes', 0, 'id'), 'notebook: search in the title and text');

        $topic = $this->mcpData('read_notebook', ['topic' => 'credits']);
        $this->assertSame('1|credits', $this->pick($topic, 'count') . '|' . $this->pick($topic, 'notes', 0, 'topic'), 'notebook: filter by topic');
    }

    public function testInvalidNotesAreRefused(): void
    {
        $this->assertStringContainsString('must be one of', $this->mcpRawText('write_notebook', ['topic' => 'pricing', 'title' => 'x', 'text' => 'y']), 'notebook: an unknown topic is refused');
        $this->assertStringContainsString('needs a text', $this->mcpRawText('write_notebook', ['title' => 'Bez textu']), 'notebook: a note without a text is refused');
    }

    public function testSiteInfoAndServerInstructionsMentionTheNotebook(): void
    {
        $info = $this->mcpData('site_info');
        $this->assertSame((self::$before + 2) . '|Photos from 2024', $this->pick($info, 'notebook_count') . '|' . $this->pick($info, 'notebook_pinned', 0), 'notebook: site_info counts the notes and names the pinned ones');

        $initialize = json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('read_notebook before larger changes', (string) $initialize, 'notebook: the server instructions tell Claude to read the notebook and write decisions down');
    }

    public function testTheAdminListsSearchesAndFiltersNotes(): void
    {
        $list = $this->assertPage('/admin.php?module=notebook', 200, 'Photos from 2024', message: 'notebook: the admin list by topic with the pinned note first');
        preg_match_all('/Photos from 2024|Never the word cheap/', $list->body, $matches);
        $this->assertSame('Photos from 2024|1', $matches[0][0] . '|' . $this->lines('Never the word cheap', $list->body), 'notebook: the pinned note is above the newer one in the admin');

        $search = $this->assertPage('/admin.php?module=notebook&search=cheap', 200, 'Never the word cheap', message: 'notebook: search in the admin');
        $this->assertStringNotContainsString('Photos from 2024', $search->body, 'notebook: the search does not list the other note');

        $this->assertPage('/admin.php?module=notebook&topic=credits', 200, 'Photos from 2024', message: 'notebook: the admin filter by topic');
    }

    public function testNotesFromTheAdminCarryTheUsersNameAndCanBePinnedAndEdited(): void
    {
        $this->adminPost('/admin.php?module=notebook&action=save', ['id' => 0, 'topic' => 'decisions', 'title' => 'Client is sensitive about the About us page', 'text' => 'The About us texts are approved by the director personally.']);
        self::$adminNote = (int) $this->sq("SELECT id FROM ka_notebook WHERE title LIKE 'Client is sensitive%'");
        $this->assertGreaterThan(0, self::$adminNote);
        $this->assertSame('decisions|Tester|0', $this->sq("SELECT CONCAT(topic, '|', author, '|', pinned) FROM ka_notebook WHERE id = ?", [self::$adminNote]), 'notebook: a note from the admin carries the user\'s name as its author');

        $this->adminPost('/admin.php?module=notebook&action=pin', ['id' => self::$adminNote]);
        $this->assertSame('1', $this->sq('SELECT pinned FROM ka_notebook WHERE id = ?', [self::$adminNote]), 'notebook: one click pins the note');

        $this->adminPost('/admin.php?module=notebook&action=save', ['id' => self::$adminNote, 'topic' => 'decisions', 'title' => 'Client is sensitive about the About us page', 'text' => 'The About us texts are approved by the director personally – even small changes.', 'pinned' => 1]);
        $this->assertPage('/admin.php?module=notebook&action=edit&id=' . self::$adminNote, 200, 'even small changes', message: 'notebook: the edit form shows the changed text');
    }

    public function testTheSiteExportCarriesTheNotes(): void
    {
        $this->adminPost('/admin.php?module=transfer&action=export', [], '/admin.php?module=transfer');
        $page = $this->site()->admin()->get('/admin.php?module=transfer');
        $this->assertSame(1, preg_match('/export-[0-9]*-[0-9]*\.[a-z]*/', $page->body, $m), 'the export appears in the list');
        $file = $m[0];
        $download = $this->site()->admin()->get('/admin.php?module=transfer&action=download&file=' . $file);

        $json = $download->body;
        if (str_ends_with($file, '.zip')) {
            $archive = $this->site()->workDir('export') . '/' . $file;
            file_put_contents($archive, $download->body);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($archive), 'the export opens as a zip');
            $json = (string) $zip->getFromName('content.json');
            $zip->close();
        }

        $this->assertStringContainsString('"notebook":[', $json, 'notebook: the site export carries the notes');
        $this->assertStringContainsString('Client is sensitive about the About us page', $json, 'notebook: the exported notes include the admin note');
    }

    public function testNotesAreDeletedInTheAdminAndOverMcp(): void
    {
        $this->adminPost('/admin.php?module=notebook&action=delete', ['id' => self::$adminNote], '/admin.php?module=notebook');
        $deleted = $this->mcpData('delete_notebook_entry', ['id' => self::$note]);

        $this->assertSame(
            '0|' . (self::$before + 1) . '|,test',
            $this->sq('SELECT COUNT(*) FROM ka_notebook WHERE id IN (?, ?)', [self::$note, self::$adminNote]) . '|' . $this->pick($deleted, 'count') . '|' . $this->sq("SELECT GROUP_CONCAT(DISTINCT via ORDER BY via) FROM ka_change_log WHERE module = 'notebook' AND action = 'delete'"),
            'notebook: deleted in the admin and over MCP; the change log names both',
        );
        $this->assertStringContainsString('does not exist', $this->mcpRawText('delete_notebook_entry', ['id' => 999999]), 'notebook: deleting a note that does not exist is an error');
    }
}
