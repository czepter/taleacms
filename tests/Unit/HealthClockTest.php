<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Dialect\MySql;
use Talea\Core\Dialect\Postgres;
use Talea\Core\Health;

/** System status: the site time zone, PHP and the database must count time the same way. */
final class HealthClockTest extends TestCase
{
    public function testAgreeingClocksAreOk(): void
    {
        [$state, $info] = Health::clock('Europe/Berlin', 'Europe/Berlin', 120, 120);
        $this->assertSame('ok', $state);
        $this->assertStringContainsString('UTC+02:00', $info);
    }

    public function testADatabaseInAnotherZoneWarnsWithTheDifference(): void
    {
        [$state, $info] = Health::clock('Europe/Berlin', 'Europe/Berlin', 120, 0);
        $this->assertSame('warning', $state);
        $this->assertStringContainsString('UTC+00:00', $info);
        $this->assertStringContainsString('120 minutes', $info);
        [, $negative] = Health::clock('America/St_Johns', 'America/St_Johns', -150, 60);
        $this->assertStringContainsString('UTC-02:30', $negative);
    }

    public function testAZoneThePhpDoesNotKnowWarns(): void
    {
        [$state, $info] = Health::clock('Mars/Olympus', 'UTC', 0, 0);
        $this->assertSame('warning', $state);
        $this->assertStringContainsString('Mars/Olympus', $info);
    }

    public function testBothEnginesAskForTheSessionOffset(): void
    {
        $this->assertStringContainsString('UTC_TIMESTAMP()', (new MySql())->sessionOffsetMinutes());
        $this->assertStringContainsString('EXTRACT(TIMEZONE FROM NOW())', (new Postgres())->sessionOffsetMinutes());
    }
}
