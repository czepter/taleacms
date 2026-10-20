<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    public function testIsDue(): void
    {
        // 2.8: when a job is due, and when an update counts as broken (only with a clear sign – never just because the site cannot reach itself)
        $this->assertSame([true, true, false, true, false], [\Talea\Core\Scheduler::isDue(null, 300, 1000), \Talea\Core\Scheduler::isDue(900, 0, 1000), \Talea\Core\Scheduler::isDue(800, 300, 1000),
            \Talea\Core\Scheduler::isDue(700, 300, 1000), \Talea\Core\Scheduler::isDue(1000 - 86400 + 60, 86400, 1000)], '2.8: Scheduler::isDue');
    }
}
