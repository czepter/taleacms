<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use Talea\Admin\Modules\Pages;
use PHPUnit\Framework\TestCase;

/** The whistleblowing channel was removed (issue #21): nothing in the code, views, dictionaries or scripts may bring it back. */
final class NoWhistleblowingTest extends TestCase
{
    public function testTheFeatureLeavesNoTraceInTheSource(): void
    {
        $found = [];
        foreach ([TALEA_SYSTEM, TALEA_ROOT . '/image'] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (preg_match('/\.(php|js|css|sql)$/', (string) $file) && stripos((string) file_get_contents((string) $file), 'whistleblowing') !== false) {
                    $found[] = (string) $file;
                }
            }
        }

        $this->assertSame([], $found, 'files that still mention the removed whistleblowing channel');
    }

    public function testItsAddressIsNoLongerReserved(): void
    {
        $this->assertNotContains('_report', Pages::RESERVED_SLUGS);
    }
}
