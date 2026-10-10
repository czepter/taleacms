<?php

declare(strict_types=1);

namespace Kaleta\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** HF-09: the repository is English; Czech lives only in the Czech dictionaries, the WordPress fixtures and lines marked `check-english: allow`. */
final class EnglishOnlyTest extends TestCase
{
    private function checker(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/check-english.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testTheCheckerFindsCzechInItsOwnFixtures(): void
    {
        [$code, $output] = $this->checker('--self-test');

        $this->assertSame(0, $code, $output);
    }

    public function testNoCzechOutsideTheAllowedPlaces(): void
    {
        [$code, $output] = $this->checker();

        $this->assertSame(0, $code, $output);
    }
}
