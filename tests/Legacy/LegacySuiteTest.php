<?php

declare(strict_types=1);

namespace Kaleta\Tests\Legacy;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * tools/unit-tests.php (about 930 checks with its own check() harness) as one PHPUnit test, so `vendor/bin/phpunit` covers everything.
 * Move checks into tests/Unit or tests/Integration as the code they cover is touched; this file goes when the script is empty.
 */
#[Group('legacy')]
final class LegacySuiteTest extends TestCase
{
    public function testTheLegacyUnitTestScriptPasses(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/unit-tests.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, "tools/unit-tests.php failed:\n" . mb_substr($output, -3000));
        $this->assertStringContainsString('jednotkové testy', $output);
    }
}
