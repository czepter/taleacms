<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Robots;

final class RobotsTest extends TestCase
{
    public function testNormalizeDropsUnknownAndOrders(): void
    {
        $this->assertSame('nofollow,nosnippet,max-snippet:-1', Robots::normalize('nosnippet, NOFOLLOW,evil,max-snippet:-1,max-image-preview:huge'));
    }

    public function testNormalizeAcceptsList(): void
    {
        $this->assertSame('noarchive,max-image-preview:large', Robots::normalize(['noarchive', 'max-image-preview:large']));
    }

    public function testMetaMergesSiteAndPage(): void
    {
        $this->assertSame('index, nofollow', Robots::meta(false, 'nofollow', ''));
        $this->assertSame('noindex, noarchive', Robots::meta(true, '', 'noarchive'));
        $this->assertSame('', Robots::meta(false, '', ''));
        $this->assertSame('index, noarchive, max-snippet:50', Robots::meta(false, 'max-snippet:50', 'max-snippet:-1,noarchive'));
    }

    public function testCanonical(): void
    {
        $this->assertSame('https://example.com/a', Robots::canonical('https://example.com/a'));
        $this->assertSame('', Robots::canonical('javascript:alert(1)'));
        $this->assertSame('', Robots::canonical('/relative'));
        $this->assertSame('', Robots::canonical('https://example.com/' . str_repeat('a', 481)));
    }

    public function testEdgeCases(): void
    {
        $this->assertNotSame('', Robots::canonical('https://example.com/' . str_repeat('a', 480)), '500 characters pass');
        $this->assertSame(500, strlen('https://example.com/' . str_repeat('a', 480)));
        $this->assertSame('', Robots::canonical('https://example.com/' . str_repeat('a', 481)), '501 are refused');
        $this->assertSame('', Robots::canonical('http://:80'));
        $this->assertSame('nofollow', Robots::normalize('nofollow nofollow,NOFOLLOW'));
        $this->assertSame('noindex, max-snippet:20', Robots::meta(true, 'max-snippet:20', ''));
        $this->assertSame('', Robots::normalize('evil, junk'));
        $this->assertSame('', Robots::meta(false, 'evil', 'junk'));
    }

    public function testArraysFromACraftedRequestAreIgnored(): void
    {
        $this->assertSame('nofollow', Robots::normalize(['nofollow', ['x'], 'bad']));
        $this->assertSame('', Robots::scalar(['x']));
        $this->assertSame('5', Robots::scalar(5));
    }
}
