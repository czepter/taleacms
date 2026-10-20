<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HtaccessTest extends TestCase
{
    /** .htaccess: rewrite targets are URLs, not relative paths */
    public function testRewriteTargetsAreUrls(): void
    {
        // A relative target (RewriteRule ^ index.php) ends in a loop and error 500 on hosts that map subdomains into a folder outside the web root.
        $htaccess = (string) file_get_contents(TALEA_ROOT . '/.htaccess');
        preg_match_all('/^\s*RewriteRule\s+\S+\s+(\S+)/m', $htaccess, $targets);
        $this->assertSame([], array_values(array_filter($targets[1], static fn (string $c): bool => $c !== '-' && !str_starts_with($c, '%{ENV:BASE}/'))), '.htaccess: no rewrite has a relative target');
        $this->assertTrue(str_contains($htaccess, 'E=BASE:%1'), '.htaccess: the site folder is computed from the request address');
    }
}
