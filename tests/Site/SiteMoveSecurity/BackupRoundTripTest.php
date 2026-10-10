<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * HF-14: the backup is a plain SQL file the application writes and reads itself, on both engines (MySQL: structure and rows; PostgreSQL: rows, the
 * structure comes from the migrations). A value that looks like SQL, line breaks, quotes, backslashes, booleans and the numbers of new rows after
 * the restore must all survive the round trip.
 */
#[Group('site')]
final class BackupRoundTripTest extends SiteTestCase
{
    use SiteFixtures;

    private const string NASTY = "First line;\nSecond 'quote' and \"double\" and back\\slash\nTRUE;\nEND";

    public function testTheContentSurvivesABackupAndARestore(): void
    {
        $site = $this->site();
        $site->setting('site_name', self::NASTY);
        $page = $site->mcpResult('create_page', ['title' => 'Backup page', 'content' => '<p>Visible after the restore</p>', 'visible' => true]);
        $id = (int) $site->rowId((string) ($page['id'] ?? ''));
        $this->assertGreaterThan(0, $id, 'the page was created');
        $this->assertSame('1', (string) $site->value('SELECT visible FROM tl_pages WHERE page_id = ?', [$id]), 'it is visible');

        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
        $backup = $this->newestBackup($site);
        $this->assertNotSame('', $backup, 'the backup was created');
        $file = $site->path('storage/backups/' . $backup);
        $sql = str_ends_with($backup, '.gz') ? (string) gzdecode((string) file_get_contents($file)) : (string) file_get_contents($file);
        $this->assertStringContainsString('-- engine: ' . \Talea\Tests\Support\TestDatabase::driver(), $sql, 'the file says which engine wrote it');

        $site->setting('site_name', 'Changed after the backup');
        $site->exec('UPDATE tl_pages SET visible = ? WHERE page_id = ?', [0, $id]);
        $site->exec('DELETE FROM tl_pages WHERE title = ?', ['Backup page']);

        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => $backup], '/admin.php?module=settings&tab=backups');
        $this->assertSame(self::NASTY, $site->settingValue('site_name'), 'a value with line breaks, quotes, a backslash and a semicolon at the end of a line is the same after the restore');
        $this->assertSame('1', (string) $site->value('SELECT visible FROM tl_pages WHERE page_id = ?', [$id]), 'the page is back and visible');

        // the numbers of new rows continue after the restored ones (PostgreSQL: the sequences were moved)
        $second = $site->mcpResult('create_page', ['title' => 'After the restore', 'content' => '<p>x</p>']);
        $this->assertNotSame('', (string) ($second['id'] ?? ''), 'a new page can be created after the restore');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_pages WHERE title = ?', ['After the restore']), 'and it is there once');
    }

    public function testABackupOfAnotherEngineIsRefused(): void
    {
        $site = $this->site();
        $other = \Talea\Tests\Support\TestDatabase::isPostgres() ? 'mysql' : 'pgsql';
        $folder = $site->path('storage/backups');
        @mkdir($folder, 0775, true);
        file_put_contents($folder . '/talea-foreign-engine.sql', "-- Talea 9.9 - database backup\n-- engine: $other\nSELECT 1;\n");
        $site->setting('site_name', 'Unchanged');
        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => 'talea-foreign-engine.sql'], '/admin.php?module=settings&tab=backups');
        $this->assertSame('Unchanged', $site->settingValue('site_name'), 'a backup of another engine changes nothing');
    }
}
