<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\ErrorReport;

#[CoversClass(ErrorReport::class)]
final class ErrorLogTest extends TestCase
{
    private const string USER = '3f2a9c1e-5b7d-4e8a-9c0b-1d2e3f4a5b6c';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/talea-errlog-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        ErrorReport::reset();
    }

    private function thrown(int $depth = 0, string $message = 'boom'): \Throwable
    {
        $go = function (int $n) use (&$go, $message): never {
            if ($n === 0) {
                throw new \RuntimeException($message);
            }
            $go($n - 1);
        };
        try {
            $go($depth);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    public function testAnEntryNamesTheRequestUserAndVersion(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin.php?module=x&token=abc';
        ErrorReport::user(self::USER);
        $lines = explode("\n", ErrorReport::entry($this->thrown(), 'abc123'));
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT[^\]]+\] RuntimeException: boom in tests\/Unit\/Core\/ErrorLogTest\.php:\d+ \[event abc123\]$/', $lines[0]);
        $this->assertSame('  GET /admin.php · user ' . self::USER . ' · Talea ' . TALEA_VERSION, $lines[1]);
        $this->assertStringNotContainsString('token=abc', implode("\n", $lines));
    }

    public function testACommandLineEntryHasNoRequest(): void
    {
        $lines = explode("\n", ErrorReport::entry($this->thrown()));
        $this->assertSame('  cli · Talea ' . TALEA_VERSION, $lines[1]);
    }

    public function testAnEntryMasksEmailsAndTokens(): void
    {
        $entry = ErrorReport::entry($this->thrown(0, "mail to x@example.com with talea_" . str_repeat('a1', 24) . "\nsecond line"));
        $first = explode("\n", $entry)[0];
        $this->assertStringContainsString('[email]', $first);
        $this->assertStringContainsString('[filtered]', $first);
        $this->assertStringNotContainsString('x@example.com', $entry);
        $this->assertStringContainsString('second line', $first, 'the message stays on one line');
    }

    public function testTheTraceIsShortAndRelative(): void
    {
        $entry = ErrorReport::entry($this->thrown(12));
        $this->assertLessThanOrEqual(8, preg_match_all('/^  #\d+ /m', $entry));
        $this->assertGreaterThan(0, preg_match_all('/^  #\d+ /m', $entry));
        $this->assertStringNotContainsString(TALEA_ROOT, $entry);
    }

    public function testALongMessageIsCapped(): void
    {
        $first = explode("\n", ErrorReport::entry($this->thrown(0, str_repeat('x ', 800))))[0];
        $this->assertLessThan(700, mb_strlen($first));
    }

    public function testTheLogRotatesOnceAtTheCap(): void
    {
        $file = $this->dir . '/errors.log';
        file_put_contents($file, str_repeat('o', 150));
        ErrorReport::append("[2026-01-01T00:00:00+00:00] new\n", $file, 100);
        $this->assertSame(str_repeat('o', 150), file_get_contents($file . '.1'));
        $this->assertSame("[2026-01-01T00:00:00+00:00] new\n", file_get_contents($file));

        ErrorReport::append("[2026-01-01T00:00:01+00:00] more\n", $file, 100);
        $this->assertSame(str_repeat('o', 150), file_get_contents($file . '.1'), 'a log below the cap is not rotated again');
        $this->assertStringContainsString('more', (string) file_get_contents($file));
    }

    public function testReadsReturnWholeEntries(): void
    {
        $file = $this->dir . '/errors.log';
        $old = date('c', time() - 3 * 86400);
        $new = date('c', time() - 60);
        $big = "[$old] RuntimeException: old in a.php:1\n" . str_repeat("  #0 padding.php:1 x()\n", 14000); // about 300 KB: the read starts inside it
        file_put_contents($file, $big . "[$new] RuntimeException: first in b.php:2\n  cli · Talea x\n[$new] LogicException: second in c.php:3\n  cli · Talea x\n");
        $entries = ErrorReport::entries(40, $file);
        $this->assertCount(2, $entries, 'the entry cut by the read is dropped');
        $this->assertStringStartsWith("[$new] LogicException: second", $entries[0], 'newest first');
        $this->assertStringContainsString('cli · Talea x', $entries[1]);
        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^\[\d{4}-/', $entry);
        }
        $this->assertSame(['errors' => 2, 'deprecations' => 0], ErrorReport::counts(time() - 86400, $file));
    }

    public function testAMissingLogReadsAsEmpty(): void
    {
        $this->assertSame([], ErrorReport::entries(40, $this->dir . '/none.log'));
        $this->assertSame(['errors' => 0, 'deprecations' => 0], ErrorReport::counts(0, $this->dir . '/none.log'));
    }

    public function testADeprecationIsLoggedOncePerDayAndPlace(): void
    {
        $file = $this->dir . '/errors.log';
        ErrorReport::deprecation('Function x() is deprecated', TALEA_ROOT . '/system/src/a.php', 10, $file);
        ErrorReport::reset(); // another request: only the memo file remembers
        ErrorReport::deprecation('Function x() is deprecated', TALEA_ROOT . '/system/src/a.php', 10, $file);
        $this->assertCount(1, ErrorReport::entries(40, $file), 'the same message at the same place is written once');
        $this->assertStringContainsString('] Deprecated: Function x() is deprecated in system/src/a.php:10', ErrorReport::entries(1, $file)[0]);

        ErrorReport::deprecation('Function x() is deprecated', TALEA_ROOT . '/system/src/a.php', 11, $file);
        $this->assertCount(2, ErrorReport::entries(40, $file), 'another line is another place');

        ErrorReport::reset();
        $memo = $this->dir . '/deprecations.json';
        $yesterday = array_map(fn ($day): string => date('Y-m-d', time() - 86400), (array) json_decode((string) file_get_contents($memo), true));
        file_put_contents($memo, json_encode($yesterday));
        ErrorReport::deprecation('Function x() is deprecated', TALEA_ROOT . '/system/src/a.php', 10, $file);
        $this->assertCount(3, ErrorReport::entries(40, $file), 'a new day logs it again');
    }

    public function testAFullMemoLogsOneLineInsteadOfEveryNewDeprecation(): void
    {
        $file = $this->dir . '/errors.log';
        $memo = [];
        for ($i = 0; $i < 500; $i++) {
            $memo[sha1('seen' . $i)] = date('Y-m-d');
        }
        file_put_contents($this->dir . '/deprecations.json', json_encode($memo));

        foreach ([1, 2, 3] as $request) {
            ErrorReport::reset(); // every request is a new process
            ErrorReport::deprecation('Function y() is deprecated', TALEA_ROOT . '/system/src/c.php', $request, $file);
            ErrorReport::deprecation('Function z() is deprecated', TALEA_ROOT . '/system/src/c.php', $request, $file);
        }
        $entries = ErrorReport::entries(40, $file);
        $this->assertCount(1, $entries, 'one line, not one per request');
        $this->assertStringContainsString('further ones are not logged', $entries[0]);
        $this->assertStringNotContainsString('Function y()', $entries[0]);

        ErrorReport::reset();
        ErrorReport::deprecation('Function y() is deprecated', TALEA_ROOT . '/system/src/c.php', 9, $file);
        $this->assertCount(1, ErrorReport::entries(40, $file), 'still one line the same day');
    }

    public function testCountsSeparateDeprecations(): void
    {
        $file = $this->dir . '/errors.log';
        ErrorReport::append(ErrorReport::entry($this->thrown()), $file);
        ErrorReport::deprecation('old thing', TALEA_ROOT . '/system/src/b.php', 3, $file);
        $this->assertSame(['errors' => 1, 'deprecations' => 1], ErrorReport::counts(time() - 3600, $file));
        $this->assertSame(['errors' => 0, 'deprecations' => 0], ErrorReport::counts(time() + 3600, $file));
    }
}
