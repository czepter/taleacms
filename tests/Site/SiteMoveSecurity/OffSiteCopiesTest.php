<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: section 40 of tools/test.sh – off-site copies of the database and the media, incrementally, against a fake S3 (a PHP router
 * that logs the PUTs). Needs from earlier sections: media files (28) and a recent backup (39) – otherwise the first page view makes an
 * automatic backup that is uploaded as well.
 */
#[Group('site')]
final class OffSiteCopiesTest extends SiteTestCase
{
    use SiteFixtures;

    private static string $log = '';

    private function backupNow(): void
    {
        $this->adminPost('/admin.php?module=settings&action=backup', [], '/admin.php?module=settings&tab=backups');
    }

    private function puts(): string
    {
        return is_file(self::$log) ? (string) file_get_contents(self::$log) : '';
    }

    private function clearPuts(): void
    {
        file_put_contents(self::$log, '');
    }

    private function putCount(string $pattern): int
    {
        return preg_match_all($pattern, $this->puts());
    }

    private function autoBackups(): int
    {
        return count(array_filter(glob($this->site()->path('storage/backups/*')) ?: [], static fn (string $f): bool => str_contains($f, '-auto-')));
    }

    public function testFakeS3IsConfigured(): void
    {
        $site = $this->site();
        $this->uploadPhoto($site);
        $this->backupNow(); // the recent backup of the earlier sections, still without a remote target

        $dir = $site->workDir('s3');
        self::$log = $dir . '/puts.log';
        file_put_contents($dir . '/router.php', <<<'PHP'
            <?php
            $h = array_change_key_case(getallheaders());
            file_put_contents(__DIR__ . '/puts.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . strlen(file_get_contents('php://input')) . ' ' . (str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 ') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
            http_response_code(200); return true;
            PHP);
        $port = $site->startPhp($dir, 'router.php');
        $this->clearPuts();
        foreach (['remote_backup' => 's3', 'backup_host' => 's3.example.com', 'backup_user' => 'AKIDTEST', 'backup_password' => 'tajne-s3', 'backup_folder' => 'talea-backups',
            'backup_region' => 'eu-central-1', 'backup_test_url' => 'http://127.0.0.1:' . $port, 'backup_media' => '1', 'remote_media_status' => ''] as $key => $value) {
            $site->setting($key, $value);
        }
        @unlink($site->path('storage/backups/media-copy.json'));

        $this->assertSame('s3', $site->settingValue('remote_backup'), 'the fake S3 is configured');
    }

    #[Depends('testFakeS3IsConfigured')]
    public function testTheBackupAndEveryMediaFileAreUploadedSigned(): void
    {
        $site = $this->site();
        $mediaFiles = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site->path('media'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = $file->getFilename();
            $mediaFiles += $file->isFile() && $name[0] !== '.' && !str_ends_with($name, '.php') ? 1 : 0;
        }
        $this->assertGreaterThan(0, $mediaFiles, 'the site has media files');
        $this->backupNow();

        $this->assertSame(1, $this->putCount('#^PUT /talea-backups/talea-.*\.sql#m'), 'the backup is uploaded once');
        $this->assertSame($mediaFiles, $this->putCount('#^PUT /talea-backups/media/#m'), 'every media file is uploaded');
        $this->assertSame(0, $this->putCount('/unsigned/'), 'every request is signed');
        $this->assertSame('ok|0', implode('|', array_slice(explode('|', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'remote_media_status'")), -2)), 'media status: complete');
        $this->assertPage('/admin.php?module=settings&tab=backups', 200, 'Media: the copy is complete', message: 'Backups show the media copy');
    }

    #[Depends('testTheBackupAndEveryMediaFileAreUploadedSigned')]
    public function testOnlyNewMediaIsCopiedAgain(): void
    {
        $site = $this->site();
        $this->clearPuts();
        $this->backupNow();
        $this->assertSame(0, $this->putCount('#^PUT /talea-backups/media/#m'), 'the next backup uploads no unchanged media');

        mkdir($site->path('media/2026/09'), 0775, true);
        file_put_contents($site->path('media/2026/09/novy-soubor.txt'), "novy\n");
        $this->clearPuts();
        $site->setting('media_sync_check', '0');
        $site->runTasks();

        $this->assertSame('PUT /talea-backups/media/2026/09/novy-soubor.txt 5 signed', trim($this->puts()), 'cron copies only the new file');
    }

    #[Depends('testOnlyNewMediaIsCopiedAgain')]
    public function testAutomaticBackupIsDailyWhenSomethingChangedOtherwiseWeekly(): void
    {
        $site = $this->site();
        $site->setting('auto_backups', '1');
        $site->setting('remote_backup', 'off');
        $site->exec("INSERT INTO tl_change_log (created_at, module, action) VALUES (NOW(), 'test', 'change')");
        $age = static function () use ($site): void {
            foreach (glob($site->path('storage/backups/talea-*')) ?: [] as $file) {
                touch($file, time() - 2 * 86400);
            }
        };
        $age();
        $before = $this->autoBackups();
        $site->admin()->get('/admin.php');
        $this->assertSame($before + 1, $this->autoBackups(), 'a change since the last backup (older than a day) makes a new automatic one');

        $age();
        $site->exec('UPDATE tl_change_log SET created_at = NOW() - INTERVAL 3 DAY WHERE created_at > NOW() - INTERVAL 3 DAY');
        $site->exec('UPDATE tl_enquiries SET created_at = NOW() - INTERVAL 3 DAY WHERE created_at > NOW() - INTERVAL 3 DAY');
        $site->admin()->get('/admin.php');
        $this->assertSame($before + 1, $this->autoBackups(), 'without a change no new backup before the week is over');
    }
}
