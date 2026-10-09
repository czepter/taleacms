<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Every Czech text in image/*.js has an entry in image/languages/admin-en.js (no site needed). */
#[Group('site')]
final class AdminScriptsTest extends TestCase
{
    public function testEveryCzechTextOfTheAdminScriptsHasAnEnglishEntry(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/find-czech.php', '--js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), "texts in image/*.js without an entry in image/languages/admin-en.js\n$output");
    }
}
