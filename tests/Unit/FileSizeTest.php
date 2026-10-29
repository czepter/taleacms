<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Files people and agents can read in one go (docs/specs/split-large-files.md): PHP under system/src and scripts under image stay
 * under LIMIT lines. The dictionaries (system/languages, image/languages) are data and third-party code (image/vendor) is not ours to
 * split, so neither is checked.
 */
final class FileSizeTest extends TestCase
{
    private const int LIMIT = 900;

    /**
     * The files above the limit today, with their line count as their ceiling: they may shrink, never grow. A split lowers or removes
     * the entry; do not raise a number, move code out instead.
     */
    private const array CEILINGS = [
        'image/web.js' => 1045,
        'system/src/Admin/Modules/Pages.php' => 1087,
        'system/src/Admin/Modules/Settings.php' => 921,
        'system/src/Core/Booking.php' => 1400,
        'system/src/Core/SiteImport.php' => 1172,
        'system/src/Core/WpImport.php' => 1392,
        'system/src/Mcp/Tools.php' => 1240,
    ];

    public function testFilesStayUnderTheLimitOrTheirCeiling(): void
    {
        $tooLong = [];
        foreach ($this->files() as $path => $lines) {
            $ceiling = self::CEILINGS[$path] ?? self::LIMIT - 1;
            if ($lines > $ceiling) {
                $tooLong[] = isset(self::CEILINGS[$path]) ? "$path: $lines lines, its ceiling is $ceiling" : "$path: $lines lines, the limit is under " . self::LIMIT;
            }
        }
        $this->assertSame([], $tooLong, 'Split the file into parts with one job each instead of raising its ceiling (docs/specs/split-large-files.md).');
    }

    public function testTheCeilingsListOnlyFilesStillAboveTheLimit(): void
    {
        $files = $this->files();
        $stale = [];
        foreach (array_keys(self::CEILINGS) as $path) {
            if (!isset($files[$path])) {
                $stale[] = "$path: no longer exists";
            } elseif ($files[$path] < self::LIMIT) {
                $stale[] = "$path: {$files[$path]} lines, under the limit now";
            }
        }
        $this->assertSame([], $stale, 'FileSizeTest::CEILINGS: remove the entry.');
    }

    /** @return array<string, int> path relative to the root => lines, counted as wc -l does (newline characters) */
    private function files(): array
    {
        $files = [];
        foreach (['system/src' => '.php', 'image' => '.js'] as $folder => $extension) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = substr($file->getPathname(), strlen(TALEA_ROOT) + 1);
                if (str_ends_with($path, $extension) && !str_starts_with($path, 'image/languages/') && !str_starts_with($path, 'image/vendor/')) {
                    $files[$path] = substr_count((string) file_get_contents($file->getPathname()), "\n");
                }
            }
        }

        return $files;
    }
}
