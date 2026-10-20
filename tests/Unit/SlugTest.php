<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testMakeUnique(): void
    {
        $takenSlugs = ['o-nas' => 1, 'o-nas-2' => 1, str_repeat('a', 10) => 1];
        $this->assertSame(['o-nas-3', 'sluzby', 'aaaaaaaa-2'], [ // check-english: allow
            \Talea\Core\Slug::makeUnique('o-nas', fn (string $a): bool => isset($takenSlugs[$a])),
            \Talea\Core\Slug::makeUnique('sluzby', fn (string $a): bool => isset($takenSlugs[$a])),
            \Talea\Core\Slug::makeUnique(str_repeat('a', 12), fn (string $a): bool => isset($takenSlugs[$a]), 10),
        ], 'Free address: the number after a taken one, with a number it fits the column'); // check-english: allow
    }
}
