<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Front\OAuth;
use Talea\Mcp\Catalog;

final class ConnectionAccessLabelsTest extends TestCase
{
    public function testEveryAccessLevelHasOneName(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Catalog::CONNECTION_ACCESS), array_keys(OAuth::ACCESS_LABELS));
        $this->assertSame('Drafts only – you publish', OAuth::ACCESS_LABELS['drafts']);
    }

    public function testAnUnknownAccessIsReadOnlyAndTheStrongestComesFirst(): void
    {
        $this->assertSame('read', OAuth::access('anything'));
        $sql = OAuth::strongestAccessSql('t.access');
        $this->assertStringContainsString("CASE t.access WHEN 'full' THEN 0 WHEN 'drafts' THEN 1 ELSE 2 END", $sql);
        $this->assertStringContainsString('MIN(', $sql);
    }
}
