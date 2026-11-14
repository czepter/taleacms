<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\Backup;

#[CoversClass(Backup::class)]
final class BackupPruneTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/talea-prune-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function make(int $n, int $time): string
    {
        $name = sprintf('talea-202601%02d-0000%02d-x-aaaaaaaa.sql', $n, $n);
        touch($this->dir . '/' . $name, $time);

        return $name;
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map('basename', glob($this->dir . '/talea-*') ?: []);
    }

    public function testTheKeptFileSurvivesAndDoesNotCount(): void
    {
        $oldest = '';
        for ($n = 1; $n <= 11; $n++) {
            $name = $this->make($n, 1_700_000_000 + $n * 60);
            $oldest = $oldest === '' ? $name : $oldest;
        }
        Backup::prune($this->dir, $oldest);
        $this->assertCount(11, $this->names(), 'the oldest plus the ten newest others');
        $this->assertContains($oldest, $this->names());

        Backup::prune($this->dir);
        $this->assertCount(10, $this->names());
        $this->assertNotContains($oldest, $this->names());
    }

    public function testTiesSortByName(): void
    {
        $first = '';
        for ($n = 1; $n <= 11; $n++) {
            $name = $this->make($n, 1_700_000_000);
            $first = $first === '' ? $name : $first;
        }
        Backup::prune($this->dir);
        $this->assertCount(10, $this->names());
        $this->assertNotContains($first, $this->names(), 'the alphabetically smallest is the oldest on a tie');
    }
}
