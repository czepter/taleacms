<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\ShortLinks;

#[CoversClass(ShortLinks::class)]
final class ShortLinksTest extends TestCase
{
    public function testRandomCodeShape(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('#^s/[23456789abcdefghjkmnpqrstuvwxyz]{6}$#', ShortLinks::randomCode());
        }
    }

    public function testMergeAppendsAndOverrides(): void
    {
        $utm = ['utm_source' => 'flyer', 'utm_medium' => 'print', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => ''];
        $this->assertSame('/offers?utm_source=flyer&utm_medium=print', ShortLinks::merge('/offers', $utm));
        $this->assertSame('https://x.test/p?a=1&utm_source=flyer&utm_medium=print#top', ShortLinks::merge('https://x.test/p?utm_source=old&a=1#top', $utm));
        $this->assertSame('/offers', ShortLinks::merge('/offers', array_fill_keys(ShortLinks::UTM, '')));
    }
}
