<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\SiteUrl;

/** The site address in one shape (#332). */
final class SiteUrlTest extends TestCase
{
    public function testNormalize(): void
    {
        $this->assertSame('https://example.com', SiteUrl::normalize('HTTPS://Example.COM:443/'));
        $this->assertSame('http://a.test', SiteUrl::normalize('http://a.test:80'));
        $this->assertSame('https://a.test:8443', SiteUrl::normalize('https://a.test.:8443'));
        $this->assertSame('http://a.test:443', SiteUrl::normalize('http://a.test:443'), 'a port that is not the default of the scheme stays');
        $this->assertSame('https://a.test', SiteUrl::normalize('  https://a.test/  '));
        $this->assertSame('https://a.test/x', SiteUrl::normalize('https://a.test/x/'), 'an address with a path is left for the validator to refuse');
        $this->assertSame('https://a.test/?x=1', SiteUrl::normalize('https://a.test/?x=1'));
        $this->assertSame('example.com', SiteUrl::normalize('example.com/'), 'no scheme: left as it is');
        $this->assertSame('', SiteUrl::normalize(''));
    }
}
