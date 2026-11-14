<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Backup;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * A MySQL session with ANSI_QUOTES / NO_BACKSLASH_ESCAPES (a host's default, a connection that was set by hand): the dump is not
 * empty and reads back with its backslashes, and an empty file is not a backup.
 */
#[CoversClass(Backup::class)]
final class BackupSqlModeTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->db()->dialect()->name() !== 'mysql') {
            $this->markTestSkipped('sql_mode is MySQL / MariaDB only.');
        }
        $this->db()->pdo()->commit(); // the restore drops and creates tables
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            @unlink(Backup::FOLDER . '/' . $file);
        }
        parent::tearDown();
    }

    public function testADumpMadeInAnAnsiQuotesSessionIsNotEmptyAndRestoresWithItsBackslashes(): void
    {
        $this->db()->run("INSERT INTO {settings} (name, value) VALUES ('backup_probe', 'C:\\\\temp\\\\new ''quoted''')");
        $this->db()->pdo()->exec("SET SESSION sql_mode = 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES'"); // the session is hostile after it connected

        $file = Backup::create($this->db(), 'sqlmode');
        $this->made[] = $file;
        $text = (string) gzdecode((string) file_get_contents(Backup::FOLDER . '/' . $file));
        $this->assertStringContainsString('INSERT INTO `tl_settings`', $text, 'the dump holds the data');
        $this->assertMatchesRegularExpression('/CREATE TABLE [`"]tl_settings[`"]/', $text);

        $this->db()->run("UPDATE {settings} SET value = 'changed' WHERE name = 'backup_probe'");
        $this->db()->pdo()->exec("SET SESSION sql_mode = 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES'");
        Backup::restore($this->db(), $file);
        $this->assertSame('C:\\temp\\new \'quoted\'', (string) $this->db()->value("SELECT value FROM {settings} WHERE name = 'backup_probe'"));
    }

    public function testAFileWithoutTablesIsNotARestorableBackup(): void
    {
        $file = 'talea-20260101-000000-empty-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents(Backup::FOLDER . '/' . $file, "-- Talea " . TALEA_VERSION . " - database backup\n-- engine: mysql\n-- prefixes: tl_\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\nSET FOREIGN_KEY_CHECKS = 1;\n");
        $this->made[] = $file;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The backup holds no tables');
        Backup::restore($this->db(), $file);
    }

    public function testAnInterruptedRunLeavesATraceAndItsHalfFileIsRemovedByTheNextBackup(): void
    {
        $part = Backup::FOLDER . '/.talea-20260101-000000-interrupted-' . bin2hex(random_bytes(4)) . '.sql.gz.part';
        file_put_contents($part, 'half');
        touch($part, time() - 7200);

        $this->made[] = Backup::create($this->db(), 'sweep');

        $this->assertFileDoesNotExist($part);
        $this->assertSame(1, (int) $this->db()->value("SELECT COUNT(*) FROM {events} WHERE type = 'backup.failed' AND message LIKE '%interrupted%'"));
    }
}
