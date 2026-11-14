<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** Clean-up is the work of the background jobs (Core\Scheduler), never of an admin page view (#337). */
final class NoCleanupOnPageViewsTest extends TestCase
{
    private function source(string $file): string
    {
        return (string) file_get_contents(TALEA_ROOT . '/system/src/Admin/' . $file);
    }

    /** The body of a method: from its signature to the next function declaration. */
    private function body(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($start, $method);
        preg_match('/\n    (?:public|protected|private)[^\n]* function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10);

        return substr($source, $start, isset($m[0]) ? $m[0][1] - $start : null);
    }

    public function testTheKernelRunsNoCleanup(): void
    {
        $kernel = $this->source('Kernel.php');
        $this->assertStringNotContainsString('emptyTrash(', $kernel);
        $this->assertStringNotContainsString('createAutomatic(', $kernel);
    }

    public function testListsDoNotRunRetention(): void
    {
        foreach (['Modules/Enquiries.php' => 'actionList', 'Modules/Bookings.php' => 'actionList'] as $file => $method) {
            $body = $this->body($this->source($file), $method);
            foreach (['deleteExpired(', 'purgeApplications(', 'Booking::purge(', 'emptyTrash('] as $call) {
                $this->assertStringNotContainsString($call, $body, $file . ' ' . $method);
            }
        }
    }
}
