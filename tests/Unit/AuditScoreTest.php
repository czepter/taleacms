<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Audit;

/** The site audit score: weights of the kinds without findings, categories. */
final class AuditScoreTest extends TestCase
{
    public function testScore(): void
    {
        $r = Audit::score([['kind' => 'description'], ['kind' => 'description'], ['kind' => 'speed']]);
        $this->assertSame(82, $r['score']);
        $this->assertSame(['description' => 2, 'speed' => 1], $r['failed']);
        $this->assertContains('link', $r['passed']);
        $this->assertNotContains('speed', $r['passed']);
        $this->assertSame(100, Audit::score([])['score']);
    }

    public function testWeightsSumTo100(): void
    {
        $this->assertSame(100, array_sum(Audit::WEIGHT));
        foreach (array_keys(Audit::KINDS) as $kind) {
            $this->assertArrayHasKey($kind, Audit::WEIGHT, $kind);
            $this->assertArrayHasKey($kind, Audit::CATEGORY, $kind);
        }
        $this->assertEqualsCanonicalizing(['basics', 'advanced', 'speed', 'security'], array_values(array_unique(Audit::CATEGORY)));
    }
}
