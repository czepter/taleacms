<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HF-14: the same SQL runs on MySQL and PostgreSQL. `tools/sql-dialect-scan.php` finds the constructs only one of them understands
 * (ON DUPLICATE KEY, INSERT IGNORE, JSON_EXTRACT, IF(), GROUP_CONCAT, `col = 1` on a boolean column, SHOW …); they belong in
 * `Core\Dialect\MySql` / `Postgres` or behind the helpers of `Db` (upsert, insertIgnore, lock, columns …). The count only stays at zero.
 */
final class NoMysqlOnlySqlTest extends TestCase
{
    public function testNoMysqlOnlySqlOutsideTheDialectLayer(): void
    {
        $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/tools/sql-dialect-scan.php') . ' --json');
        $scan = json_decode($json, true);
        $this->assertIsArray($scan, 'the scan prints JSON');
        $found = [];
        foreach ($scan['categories'] as $key => $category) {
            if (!$category['portable'] && $category['lines'] > 0) {
                $found[] = $key . ': ' . implode(', ', array_map(fn (string $file, int $n): string => "$file ($n)", array_keys($category['by_file']), $category['by_file']));
            }
        }
        $this->assertSame([], $found, "MySQL-only SQL outside Core\\Dialect (see docs/specs/postgres.md for the portable form):\n" . implode("\n", $found));
    }
}
