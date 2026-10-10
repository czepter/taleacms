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
}
