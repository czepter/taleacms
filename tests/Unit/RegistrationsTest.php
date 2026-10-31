<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Registrations;

final class RegistrationsTest extends TestCase
{
    private function course(array $over = []): array
    {
        return $over + ['mode' => 'instant', 'waiting_list' => true, 'max_seats' => 2, 'capacity' => 16, 'deadline' => '', 'ends' => '2026-12-01 12:00'];
    }

    public function testInstantOpenConfirms(): void
    {
        $this->assertSame('confirmed', Registrations::decide($this->course(), 3, 1, '2026-11-01 10:00'));
    }

    public function testConfirmModeWaits(): void
    {
        $this->assertSame('waiting', Registrations::decide($this->course(['mode' => 'confirm']), 3, 1, '2026-11-01 10:00'));
    }

    public function testFullWithListWaitlists(): void
    {
        $this->assertSame('waitlisted', Registrations::decide($this->course(), 16, 1, '2026-11-01 10:00'));
    }

    public function testFullWithoutListIsFull(): void
    {
        $this->assertSame('full', Registrations::decide($this->course(['waiting_list' => false]), 16, 1, '2026-11-01 10:00'));
    }

    public function testTooManySeatsForFreeSeatsWaitlistsWhole(): void
    {
        $this->assertSame('waitlisted', Registrations::decide($this->course(), 15, 2, '2026-11-01 10:00'));
    }

    public function testNoCapacityNeverFull(): void
    {
        $this->assertSame('confirmed', Registrations::decide($this->course(['capacity' => 0]), 500, 2, '2026-11-01 10:00'));
    }

    public function testAfterDeadlineClosed(): void
    {
        $c = $this->course(['deadline' => '2026-10-31']);
        $this->assertSame('confirmed', Registrations::decide($c, 0, 1, '2026-10-31 23:00'), 'the deadline day lasts until 23:59');
        $this->assertSame('closed', Registrations::decide($c, 0, 1, '2026-11-01 00:00'));
        $this->assertSame('closed', Registrations::decide($this->course(), 0, 1, '2026-12-02 00:00'), 'ended');
    }

    public function testPairKeepsItsPlace(): void
    {
        $list = [['enquiry_id' => 1, 'seats' => 2], ['enquiry_id' => 2, 'seats' => 1]];
        $this->assertSame(2, Registrations::nextToPromote($list, 1));
        $this->assertSame(1, Registrations::nextToPromote($list, 2));
    }

    public function testNobodyFits(): void
    {
        $this->assertNull(Registrations::nextToPromote([['enquiry_id' => 1, 'seats' => 2]], 1));
        $this->assertNull(Registrations::nextToPromote([], 5));
    }
}
