<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\NotFound;

final class NotFoundReferrerTest extends TestCase
{
    public function testHostAndPathOnlyOwnSiteAsPathAndBrokenHeadersEmpty(): void
    {
        $this->assertSame('other.example/list', NotFound::referrer('https://other.example/list?q=1#top', 'site.test'));
        $this->assertSame('/portraits', NotFound::referrer('https://site.test/portraits?a=1', 'site.test'));
        $this->assertSame('', NotFound::referrer("https://other.example/\xff\xfe", 'site.test'), 'invalid UTF-8');
        $this->assertSame('', NotFound::referrer('javascript:alert(1)', 'site.test'));
    }
}
