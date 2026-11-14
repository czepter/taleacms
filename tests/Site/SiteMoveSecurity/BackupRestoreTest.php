<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 39 of tools/test.sh – backup and restore of the database. */
#[Group('site')]
final class BackupRestoreTest extends SiteTestCase
{
    use SiteFixtures;

    public function testABackupRestoresAndABrokenOneChangesNothing(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
        $backup = $this->newestBackup($site);
        $this->assertNotSame('', $backup, 'the backup was created');

        $site->setting('site_name', 'Po zaloze');
        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => $backup], '/admin.php?module=settings&tab=backups');
        $this->assertNotSame('Po zaloze', $site->settingValue('site_name'), 'the restore brings back the state from the backup');

        $gz = str_ends_with($backup, '.gz');
        $broken = $site->path('storage/backups/' . ($gz ? 'talea-poskozena.sql.gz' : 'talea-poskozena.sql'));
        $sql = $gz ? (string) gzdecode((string) file_get_contents($site->path('storage/backups/' . $backup))) : (string) file_get_contents($site->path('storage/backups/' . $backup));
        file_put_contents($broken, $gz ? gzencode(substr($sql, 0, 4000)) : substr($sql, 0, 4000));
        $site->setting('site_name', 'Pred poskozenou');
        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => basename($broken)], '/admin.php?module=settings&tab=backups');
        $this->assertSame('Pred poskozenou', $site->settingValue('site_name'), 'a damaged backup does not change the database');
    }

    public function testRestoringAndDeletingABackupAreLogged(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
        $backup = $this->newestBackup($site);
        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => $backup], '/admin.php?module=settings&tab=backups');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_change_log WHERE action = 'backup_restore' AND description = ?", [$backup]), 'the restore is in the change log (written after it, so the restored log has it)');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_events WHERE type = 'backup.restored' AND message LIKE ?", ['%' . $backup . '%']), 'and in the events');

        $this->adminPost('/admin.php?module=settings&action=delete_backup', ['file' => $backup], '/admin.php?module=settings&tab=backups');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_change_log WHERE action = 'backup_delete' AND description = ?", [$backup]));
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_events WHERE type = 'backup.deleted' AND message LIKE ?", ['%' . $backup . '%']));
    }

    public function testRestoringTheOldestOfTenBackupsKeepsItsFile(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
        $backup = $this->newestBackup($site);
        $this->assertNotSame('', $backup);
        $folder = $site->path('storage/backups');
        $ext = str_ends_with($backup, '.gz') ? '.sql.gz' : '.sql';
        // the file to restore is the oldest of everything; nine copies are newer
        foreach (glob($folder . '/talea-*') ?: [] as $other) {
            if (basename($other) !== $backup) {
                touch($other, time() - 3600);
            }
        }
        touch($folder . '/' . $backup, time() - 20 * 3600);
        for ($n = 1; $n <= 9; $n++) {
            $copy = $folder . sprintf('/talea-20260101-0000%02d-copy-%08x', $n, $n) . $ext;
            copy($folder . '/' . $backup, $copy);
            touch($copy, time() - (19 - $n) * 3600);
        }
        $site->setting('site_name', 'Marker after the backup');
        $this->adminPost('/admin.php?module=settings&action=restore_backup', ['file' => $backup], '/admin.php?module=settings&tab=backups');

        $this->assertNotSame('Marker after the backup', $site->settingValue('site_name'), 'the restore worked: its file was not rotated away');
        $this->assertFileExists($folder . '/' . $backup);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_change_log WHERE action = 'backup_restore' AND description = ?", [$backup]));
    }
}
