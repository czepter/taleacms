<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContractsTest extends TestCase
{
    public function testPublicContractsKept(): void
    {
        // 2.1: public contracts – MCP tools and parameters, design tokens and builder elements are never removed or changed
        // outside the deprecation policy; an addition is recorded with php tools/contracts.php --update
        require_once TALEA_ROOT . '/tools/contracts.php';
        $this->assertSame(['broken' => [], 'added' => []], talea_contract_diff(), '2.1: public contracts kept (tools/contracts)');
    }
}
