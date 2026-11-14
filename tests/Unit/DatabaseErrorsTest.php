<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Db;

/** A database error is an error, not an empty list (#337). */
final class DatabaseErrorsTest extends TestCase
{
    private function error(string $state, int $code, string $message = 'x'): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$state, $code, $message];

        return $e;
    }

    public function testAMissingTableOrColumnOnEveryEngine(): void
    {
        foreach ([['42S02', 1146], ['42S22', 1054], ['42P01', 7], ['42703', 7]] as [$state, $code]) {
            $this->assertTrue(Db::isMissing($this->error($state, $code)), $state);
        }
        $this->assertTrue(Db::isMissing($this->error('HY000', 1, 'SQLSTATE[HY000]: General error: 1 no such table: tl_x')));
        $this->assertTrue(Db::isMissing($this->error('HY000', 1, 'no such column: nope')));
        foreach ([['HY000', 2006, 'MySQL server has gone away'], ['08006', 7], ['23000', 1062], ['42000', 1064]] as $row) {
            $this->assertFalse(Db::isMissing($this->error(...$row)), $row[0]);
        }
    }

    public function testARefusedRow(): void
    {
        foreach ([['23000', 1062], ['23505', 7], ['22001', 1406]] as [$state, $code]) {
            $this->assertTrue(Db::isRefusedRow($this->error($state, $code)), $state);
        }
        foreach ([['HY000', 1364], ['HY000', 1366], ['HY000', 20]] as [$state, $code]) {
            $this->assertTrue(Db::isRefusedRow($this->error($state, $code)), $state . ' ' . $code . ' (an unfit value on MySQL or SQLite)');
        }
        $this->assertFalse(Db::isRefusedRow($this->error('HY000', 1)), 'a general SQLite error is not a refused row');
        $this->assertFalse(Db::isRefusedRow($this->error('42S02', 1146)));
        $this->assertFalse(Db::isRefusedRow($this->error('HY000', 2006)));
    }

    public function testNoCatchSwallowsEveryDatabaseError(): void
    {
        $allowed = [
            'system/src/Install/Installer.php' => 'the connection test answers with the reason',
            'system/src/Core/Config.php' => 'no database reachable means not installed',
            'system/src/Admin/Modules/Addons.php' => 'the administrator is shown the error text of the failed add-on step',
        ];
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/system', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr(str_replace('\\', '/', (string) $file), strlen(TALEA_ROOT) + 1);
            if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/') || isset($allowed[$path])) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            if (!str_contains($source, 'PDOException')) {
                continue;
            }
            $tokens = token_get_all($source);
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                    continue;
                }
                $type = '';
                for ($j = $i + 1; $j < $n && $tokens[$j] !== '{'; $j++) {
                    $type .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                if (!str_contains($type, 'PDOException')) {
                    continue;
                }
                $depth = 0;
                $body = '';
                for (; $j < $n; $j++) {
                    $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    $depth += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
                    $body .= $text;
                    if ($depth === 0) {
                        break;
                    }
                }
                if (!str_contains($body, 'isMissing(') && !str_contains($body, 'isRefusedRow(') && !str_contains($body, 'throw ')) {
                    $offenders[] = $path . ':' . $tokens[$i][2];
                }
            }
        }
        $this->assertSame([], $offenders, 'a catch of PDOException must classify the error (Db::isMissing / isRefusedRow) or rethrow it');
    }
}
