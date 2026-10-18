<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Files;
use Talea\Core\Request;

/** A POST over post_max_size arrives empty: the admin says the upload was too large instead of "the form has expired". */
final class BodyTooLargeTest extends TestCase
{
    public function testIniShorthandBecomesBytes(): void
    {
        $this->assertSame(64 * 1024 ** 2, Files::iniBytes('64M'));
        $this->assertSame(2 * 1024 ** 3, Files::iniBytes('2G'));
        $this->assertSame(0, Files::iniBytes('0'));
    }

    public function testOnlyAPostOverTheLimitIsTooLarge(): void
    {
        $limit = Files::iniBytes((string) ini_get('post_max_size'));
        if ($limit === 0) {
            $this->markTestSkipped('post_max_size is unlimited here.');
        }
        $request = static fn (string $method, int $length): Request => new Request([], [], ['REQUEST_METHOD' => $method, 'CONTENT_LENGTH' => (string) $length]);

        $this->assertTrue(Files::bodyTooLarge($request('POST', $limit + 1)));
        $this->assertFalse(Files::bodyTooLarge($request('POST', $limit)));
        $this->assertFalse(Files::bodyTooLarge($request('GET', $limit + 1)));
    }
}
