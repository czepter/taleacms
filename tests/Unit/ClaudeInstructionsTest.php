<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\ClaudeInstructions;

final class ClaudeInstructionsTest extends TestCase
{
    public function testTheWatchedTextIsTheOwnersAloneWhenNoBlueprintBringsInstructions(): void
    {
        $this->assertSame('A', ClaudeInstructions::combine('A', []), 'a fingerprint made before blueprints counted stays valid');
    }

    public function testBlueprintInstructionsFollowTheOwnersTextByKey(): void
    {
        $this->assertSame("A\n\nb: x\nc: y", ClaudeInstructions::combine('A', ['b' => 'x', 'c' => 'y']));
        $this->assertSame("\n\nb: x", ClaudeInstructions::combine('', ['b' => 'x']));
        $this->assertNotSame(ClaudeInstructions::fingerprint(ClaudeInstructions::combine('A', ['b' => 'x'])), ClaudeInstructions::fingerprint(ClaudeInstructions::combine('A', ['b' => 'y'])));
    }
}
