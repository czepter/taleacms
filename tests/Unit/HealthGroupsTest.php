<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Health;

final class HealthGroupsTest extends TestCase
{
    private function check(string $group, string $name, string $status): array
    {
        return ['group' => $group, 'name' => $name, 'status' => $status, 'info' => ''];
    }

    public function testGroupsWithProblemsComeFirstInTheirOrder(): void
    {
        $groups = Health::grouped([
            $this->check('A', 'a1', 'ok'),
            $this->check('B', 'b1', 'ok'),
            $this->check('C', 'c1', 'ok'),
            $this->check('D', 'd1', 'warning'),
            $this->check('B', 'b2', 'warning'),
            $this->check('A', 'a2', 'ok'),
            $this->check('C', 'c2', 'error'),
            $this->check('C', 'c3', 'ok'),
        ]);
        $this->assertSame(['C', 'B', 'D', 'A'], array_column($groups, 'group'), 'errors, then warnings in the order the groups first appear, then the fine ones');
        $this->assertSame(['error', 'warning', 'warning', 'ok'], array_column($groups, 'status'));
        $this->assertSame(['c1', 'c2', 'c3'], array_column($groups[0]['checks'], 'name'), 'the checks of a group keep their order');
        $this->assertSame(['a1', 'a2'], array_column($groups[3]['checks'], 'name'));
    }

    public function testCleanupState(): void
    {
        $now = 1_800_000_000;
        $this->assertSame('new', Health::cleanupState(0, $now));
        $this->assertSame('ok', Health::cleanupState($now - 86400, $now));
        $this->assertSame('ok', Health::cleanupState($now - 2 * 86400, $now));
        $this->assertSame('stale', Health::cleanupState($now - 2 * 86400 - 1, $now));
    }

    public function testNoChecksNoGroups(): void
    {
        $this->assertSame([], Health::grouped([]));
    }
}
