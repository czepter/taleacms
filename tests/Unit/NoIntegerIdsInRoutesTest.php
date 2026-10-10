<?php

declare(strict_types=1);

namespace Kaleta\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HF-16: a public route never names a row by its integer key. A route pattern of the front or the core that captures only digits
 * (`#^/_popup/(\d+)$#`) would take the number of a row of a public-id table; the capture must be a public id (or a slug, or a token).
 */
final class NoIntegerIdsInRoutesTest extends TestCase
{
    /** Digits-only captures inside a path pattern: (\d+), (\d{1,9}), ([0-9]+), ([1-9]\d*). */
    private const string DIGITS_CAPTURE = '~\((?:\\\\d|\[0-9\]|\[1-9\])[^)]*\)~';

    /** The start of a path pattern in a preg_match call: the quote, the delimiter, an optional ^ and the first slash, up to the closing quote. */
    private const string PATH_PATTERN = '%preg_match\w*\(\s*[\'"][#~/]\^?/[^\'"]*%';

    public function testNoRoutePatternCapturesAnIntegerKey(): void
    {
        $found = [];
        foreach (['Front', 'Core'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/system/src/' . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with((string) $file, '.php')) {
                    continue;
                }
                foreach (file((string) $file) ?: [] as $number => $line) {
                    // a path pattern: preg_match('#^/…(\d+)…#') or ('~^/…~'); the capture group is what a visitor controls
                    if (preg_match(self::PATH_PATTERN, $line, $m) === 1 && preg_match(self::DIGITS_CAPTURE, $m[0]) === 1) {
                        $found[] = substr((string) $file, strlen(dirname(__DIR__, 2)) + 1) . ':' . ($number + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $found, "A route captures an integer key – use the public id (Db::internalId):\n" . implode("\n", $found));
    }

    public function testTheCheckSeesAnIntegerRoute(): void
    {
        $line = "if (preg_match('#^/_popup/(\\d+)\$#', \$path, \$m)) {";
        $this->assertSame(1, preg_match(self::PATH_PATTERN, $line, $m));
        $this->assertSame(1, preg_match(self::DIGITS_CAPTURE, $m[0]), 'the pattern the old route had is caught');
        $this->assertSame(0, preg_match(self::DIGITS_CAPTURE, "preg_match('#^/_popup/([a-f0-9-]{36})\$#'"), 'a public-id capture is not');
    }
}
