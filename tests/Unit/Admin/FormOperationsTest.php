<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class FormOperationsTest extends TestCase
{
    public function testEveryPostedOperationHasAHandler(): void
    {
        // 4.0.5: My account still posted four Czech operation names after the controller had been renamed to English, so saving the
        // details, changing the password and switching two-factor sign-in on or off did nothing
        $handlers = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_SYSTEM . '/src/Admin')) as $file) {
            $handlers .= $file->isFile() ? (string) file_get_contents($file->getPathname()) : '';
        }
        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_SYSTEM . '/views/admin')) as $view) {
            preg_match_all('/name="op" value="([a-z_]+)"/', $view->isFile() ? (string) file_get_contents($view->getPathname()) : '', $ops);
            foreach ($ops[1] as $op) {
                if (!str_contains($handlers, "case '$op'")) {
                    $missing[] = basename($view->getPathname()) . ': ' . $op;
                }
            }
        }
        $this->assertSame([], $missing, 'every operation an admin form posts is handled');
    }
}
