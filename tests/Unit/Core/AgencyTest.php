<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\Agency;
use Talea\Core\Deployment;

#[CoversClass(Agency::class)]
final class AgencyTest extends TestCase
{
    public function testVisibilityByLevelSwitchAndRole(): void
    {
        foreach ([Deployment::SINGLE, Deployment::MULTISITE] as $level) {
            $this->assertFalse(Agency::decide($level, false, false), "$level: hidden by default");
            $this->assertTrue(Agency::decide($level, true, false), "$level: the switch shows it");
            $this->assertTrue(Agency::decide($level, false, true), "$level: an Agency role shows it");
        }
        $this->assertTrue(Agency::decide(Deployment::AGENCY, false, false), 'agency mode: always');
    }
}
