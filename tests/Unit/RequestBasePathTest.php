<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Request;

/** Links in e-mails that cron sends: the command line has no base path, never "https://example.orgsystem/admin/…". */
final class RequestBasePathTest extends TestCase
{
    public function testWebRequestsKeepTheirInstallationPath(): void
    {
        $base = static fn (string $script): string => (new Request([], [], ['SCRIPT_NAME' => $script, 'REQUEST_URI' => '/']))->basePath();

        $this->assertSame('', $base('/index.php'));
        $this->assertSame('/magazin', $base('/magazin/index.php'));
        $this->assertSame('/magazin', $base('/magazin/admin.php'));
    }

    public function testTheCommandLineHasNoBasePath(): void
    {
        $this->assertSame('', (new Request([], [], ['SCRIPT_NAME' => 'system/docker.php']))->basePath());
        $this->assertSame('', (new Request([], [], ['SCRIPT_NAME' => '/var/www/html/system/docker.php']))->basePath());
        $this->assertSame('', (new Request([], [], []))->basePath());
    }
}
