<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Backup;
use Talea\Core\Migrator;
use Talea\Core\OwnerFiles;
use Talea\Core\Owners;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** A client's own backups: only its tables, in a folder of its own, restored without touching anybody else. */
#[CoversClass(OwnerFiles::class)]
#[CoversClass(Backup::class)]
final class OwnerBackupsTest extends DatabaseTestCase
{
    private static bool $made = false;

    /** @var list<string> */
    private static array $files = [];

    protected function setUp(): void
    {
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                $db = $this->db();
                Owners::add($db, 'Beta', 'organisation', 'b@beta.example');
                Owners::add($db, 'Gamma', 'organisation', 'g@gamma.example');
                Owners::provisionPending($db, $config);
                Sites::add($db->forOwner('tlb_'), 'Beta two', 'two');
                Migrator::migrate($config);
                $db->forOwner('tlb_')->run('UPDATE {sites} SET ready_at = ? WHERE ready_at IS NULL', [date('Y-m-d H:i:s')]);
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
                self::$made = true;
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
        Sites::forgetCurrent();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $file) {
            is_file($file) && @unlink($file);
        }
        self::$files = [];
        self::$made = false;
        Sites::forgetCurrent();
        parent::tearDownAfterClass();
    }

    private function setting(\Talea\Core\Db $db, string $value): void
    {
        $db->upsert('settings', ['name' => 'owner_note', 'value' => $value], ['name']);
    }

    private function read(string $path): string
    {
        return (string) (str_ends_with($path, '.gz') ? gzdecode((string) file_get_contents($path)) : file_get_contents($path));
    }

    public function testABackupHoldsOnlyTheOwnersTablesInItsOwnFolder(): void
    {
        $db = $this->db();
        $beta = $db->forOwner('tlb_');
        $file = Backup::createForOwner($beta);
        $path = Backup::pathForOwner($beta, $file) ?? '';
        self::$files[] = $path;
        $this->assertSame(OwnerFiles::folder($beta) . '/' . $file, $path);
        $text = $this->read($path);
        $this->assertStringContainsString('-- prefixes: tlb_,tlb2_', $text);
        $this->assertMatchesRegularExpression('/tlb_pages/', $text);
        $this->assertMatchesRegularExpression('/tlb2_pages/', $text);
        preg_match_all('/`(tl_[a-z_]+)`/', $text, $found);
        $this->assertSame([], array_values(array_unique($found[1])), 'no table of the install or owner 1');
        $this->assertStringNotContainsString('tlc_', $text);
        $this->assertSame([$file], array_column(Backup::listForOwner($beta), 'file'));
        $this->assertNotContains($file, array_column(Backup::listAll(), 'file'), 'the install\'s list does not show it');
    }

    public function testAClientCannotReachAnotherOwnersFiles(): void
    {
        $db = $this->db();
        $beta = $db->forOwner('tlb_');
        $gamma = $db->forOwner('tlc_');
        $file = Backup::createForOwner($beta);
        self::$files[] = OwnerFiles::folder($beta) . '/' . $file;
        $this->assertNull(Backup::pathForOwner($gamma, $file));
        $this->assertSame([], Backup::listForOwner($gamma));
        $this->assertNull(Backup::pathForOwner($beta, '../' . $file));
        $this->assertNull(Backup::pathForOwner($beta, '../../talea-x.sql'));
        $this->assertNull(Backup::path($file, $beta), 'the install\'s lookup is refused to a client');
        $this->assertNull(Backup::pathForOwner($db, $file), 'owner 1 has no own folder');
        $this->expectException(\RuntimeException::class);
        Backup::restoreForOwner($gamma, $file);
    }

    public function testARestoreBringsBackOnlyThatOwnersTables(): void
    {
        $db = $this->db();
        $beta = $db->forOwner('tlb_');
        $gamma = $db->forOwner('tlc_');
        $db->pdo()->rollBack(); // a restore runs its own statements, outside the test's transaction
        $this->setting($beta, 'one');
        $this->setting($beta->forSite('tlb2_'), 'one-too');
        $file = Backup::createForOwner($beta);
        self::$files[] = OwnerFiles::folder($beta) . '/' . $file;
        $this->setting($beta, 'two');
        $this->setting($beta->forSite('tlb2_'), 'two-too');
        $this->setting($gamma, 'gamma-changed');
        $this->setting($db, 'owner-one-changed');

        try {
            Backup::restoreForOwner($beta, $file);
        } finally {
            set_time_limit(0);
        }

        $this->assertSame('one', $beta->value("SELECT value FROM {settings} WHERE name = 'owner_note'"));
        $this->assertSame('one-too', $beta->forSite('tlb2_')->value("SELECT value FROM {settings} WHERE name = 'owner_note'"));
        $this->assertSame('gamma-changed', $gamma->value("SELECT value FROM {settings} WHERE name = 'owner_note'"), 'another owner is not touched');
        $this->assertSame('owner-one-changed', $db->value("SELECT value FROM {settings} WHERE name = 'owner_note'"));
        $db->run("DELETE FROM {settings} WHERE name = 'owner_note'");
        $gamma->run("DELETE FROM {settings} WHERE name = 'owner_note'");
    }

    public function testAFileNamingAnotherOwnersPrefixesIsRefused(): void
    {
        $db = $this->db();
        $beta = $db->forOwner('tlb_');
        $folder = OwnerFiles::folder($beta);
        @mkdir($folder, 0775, true);
        $file = 'talea-20261108-000000-forged.sql';
        file_put_contents($folder . '/' . $file, "-- Talea test\n-- engine: " . $db->dialect()->name() . "\n-- prefixes: tlb_,tlc_\nDELETE FROM \"tlc_pages\";\n");
        self::$files[] = $folder . '/' . $file;
        try {
            Backup::restoreForOwner($beta, $file);
            $this->fail('A file with another owner\'s prefix must stop the restore.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('other table prefixes', $e->getMessage());
        }
    }

    public function testAnExportTakesTheMediaOfTheOwnersFolderOnlyAndStaysInItsFolder(): void
    {
        $db = $this->db();
        $beta = $db->forOwner('tlb_');
        $gamma = $db->forOwner('tlc_');
        $id = Owners::publicIdOf($db, 'tlb_');
        $mine = 'media/' . $id . '/2026/10/export-mine-' . bin2hex(random_bytes(3)) . '.jpg';
        $first = 'media/2026/10/export-first-' . bin2hex(random_bytes(3)) . '.jpg';
        foreach ([$mine, $first] as $path) {
            @mkdir(dirname(TALEA_ROOT . '/' . $path), 0775, true);
            imagejpeg(imagecreatetruecolor(4, 4), TALEA_ROOT . '/' . $path);
            self::$files[] = TALEA_ROOT . '/' . $path;
        }
        $result = \Talea\Core\SiteExport::create($beta, new \Talea\Core\Settings($beta));
        self::$files[] = OwnerFiles::folder($beta) . '/' . $result['file'];
        $this->assertMatchesRegularExpression('/^export-\d{8}-\d{6}-s1\.zip$/', $result['file']);
        $zip = new \ZipArchive();
        $zip->open(OwnerFiles::folder($beta) . '/' . $result['file']);
        $this->assertNotFalse($zip->locateName($mine));
        $this->assertFalse($zip->locateName($first), 'owner 1\'s files are not in a client\'s export');
        $zip->close();
        $this->assertSame([$result['file']], array_column(\Talea\Core\SiteExport::listAll($beta), 'file'));
        $this->assertSame([], \Talea\Core\SiteExport::listAll($gamma));
        $this->assertNull(\Talea\Core\SiteExport::path($result['file'], $gamma));
        $this->assertNull(\Talea\Core\SiteExport::path($result['file']), 'not in the install\'s folder');
        @rmdir(TALEA_ROOT . '/media/' . $id . '/2026/10');
        @rmdir(TALEA_ROOT . '/media/' . $id . '/2026');
        @rmdir(TALEA_ROOT . '/media/' . $id);
    }
}
