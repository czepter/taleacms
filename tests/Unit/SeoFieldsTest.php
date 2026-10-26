<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\SeoFields;

final class SeoFieldsTest extends TestCase
{
    public function testKeywords(): void
    {
        $this->assertSame("Wedding photographer\nphotos\na\nb\nc", SeoFields::fromInput(['focus_keywords' => ' Wedding photographer, wedding photographer ,photos,a,b,c,d'])['focus_keywords']);
        $this->assertSame(['x', 'y'], SeoFields::keywords("x\ny"));
        $this->assertSame("a\nb", SeoFields::fromInput(['focus_keywords' => ['a', ' A ', 'b']])['focus_keywords']);
    }

    public function testKeywordsFitTheColumn(): void
    {
        $in = implode(',', array_map(fn (string $c): string => str_repeat($c, 100), ['a', 'b', 'c', 'd', 'e']));
        $stored = SeoFields::fromInput(['focus_keywords' => $in])['focus_keywords'];
        $this->assertLessThanOrEqual(500, mb_strlen($stored));
        $this->assertSame([str_repeat('a', 100), str_repeat('b', 100), str_repeat('c', 100), str_repeat('d', 100)], explode("\n", $stored));
    }

    public function testOnlyPresentKeys(): void
    {
        $this->assertSame(['robots' => 'nofollow'], SeoFields::fromInput(['robots' => 'nofollow']));
        $this->assertSame(['robots' => 'nofollow,noarchive'], SeoFields::fromInput(['robots' => ['noarchive', 'nofollow', 'bogus']]));
    }

    public function testCanonicalRefused(): void
    {
        $this->assertSame(['canonical_url' => ''], SeoFields::fromInput(['canonical_url' => 'ftp://x']));
    }

    public function testKeyPageAndTexts(): void
    {
        $this->assertSame(['key_page' => 1, 'share_title' => 'T'], SeoFields::fromInput(['key_page' => '1', 'share_title' => ' T ']));
        $this->assertSame(['key_page' => 0], SeoFields::fromInput(['key_page' => false]));
        $this->assertSame(320, mb_strlen(SeoFields::fromInput(['share_description' => str_repeat('x', 400)])['share_description']));
    }
}
