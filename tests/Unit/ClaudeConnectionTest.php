<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\ClaudeConnection;

/** #154: whether Claude is connected (Core\ClaudeConnection::evaluate, the pure state machine). */
final class ClaudeConnectionTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private static function stateOf(array $facts): array
    {
        return ClaudeConnection::evaluate($facts + ['enabled' => true, 'connections' => 1, 'last_call' => null, 'expires' => null, 'schedules' => [], 'waiting' => 0, 'oldest_waiting' => null], self::NOW);
    }

    private static function at(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    public function testTheState(): void
    {
        $this->assertSame(['off', 'none', 'idle', 'idle', 'active'], [
            self::stateOf(['enabled' => false])['state'],
            self::stateOf(['connections' => 0, 'last_call' => self::at(60)])['state'],
            self::stateOf([])['state'],
            self::stateOf(['last_call' => self::at(ClaudeConnection::ACTIVE_DAYS * 86400 + 60)])['state'],
            self::stateOf(['last_call' => self::at(3600)])['state'],
        ], 'off without the extension, none without a connection that can call, idle without a call in a week, active with one');
        $none = self::stateOf(['connections' => 0, 'expires' => self::at(-86400), 'waiting' => 2, 'oldest_waiting' => self::at(3 * 86400)]);
        $this->assertSame([null, null, false, 2, false, true], [$none['pickup'], $none['expires'], $none['expiring'], $none['waiting'], ClaudeConnection::reachable($none), ClaudeConnection::blocked($none)],
            'without a connection nothing picks requests up and nothing ends');
    }

    public function testThePickup(): void
    {
        $runs = ['next_due' => self::at(-3600), 'last_status' => 'ok', 'last_at' => self::at(86400)];
        $missed = ['next_due' => self::at(-3600), 'last_status' => 'missed', 'last_at' => self::at(7200)];
        $overdue = ['next_due' => self::at((\Talea\Core\AgentSchedules::MISSED_HOURS + 1) * 3600), 'last_status' => null, 'last_at' => null];
        $this->assertSame(['manual', 'automatic', 'stalled', 'stalled', 'automatic'], [
            self::stateOf([])['pickup'],
            self::stateOf(['schedules' => [$runs]])['pickup'],
            self::stateOf(['schedules' => [$missed]])['pickup'],
            self::stateOf(['schedules' => [$overdue]])['pickup'],
            self::stateOf(['schedules' => [$missed, $runs]])['pickup'],
        ], 'no schedule: manual; one that runs: automatic; missed, failed or overdue: stalled');
        $stalled = self::stateOf(['schedules' => [$missed]]);
        $this->assertSame([self::at(7200), true, true], [$stalled['stalled_since'], ClaudeConnection::reachable($stalled), ClaudeConnection::blocked($stalled)], 'a stalled run says since when and blocks the requests');
        $this->assertFalse(ClaudeConnection::blocked(self::stateOf([])), 'manual pickup is not blocked');
    }

    public function testTheExpiry(): void
    {
        $this->assertSame([true, false, false], [
            self::stateOf(['expires' => self::at(-(ClaudeConnection::EXPIRY_DAYS - 1) * 86400)])['expiring'],
            self::stateOf(['expires' => self::at(-(ClaudeConnection::EXPIRY_DAYS + 1) * 86400)])['expiring'],
            self::stateOf(['expires' => null])['expiring'],
        ], 'a connection that ends within two weeks is expiring; one that never ends is not');
    }
}
