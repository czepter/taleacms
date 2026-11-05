<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Modules\Sites;

/** The mark of a site in the picker (Sites::mark): its initial on a hue of its own, the same for the same site everywhere. */
final class SiteMarkTest extends TestCase
{
    public function testTheSameSiteAlwaysGetsTheSameMark(): void
    {
        $id = '1a3aa0c9-c3da-47bc-8fa3-688e8d122966';
        $this->assertSame(Sites::mark('Lindner Bakery', $id), Sites::mark('Lindner Bakery', $id));
        $this->assertMatchesRegularExpression('#^<span class="site-mark" style="--site-hue: \d{1,3}" aria-hidden="true">L</span>$#', Sites::mark('lindner bakery', $id), 'the initial, upper-case');
    }

    public function testTheInitialIsEscapedAndNeverEmpty(): void
    {
        $this->assertStringContainsString('>&lt;</span>', Sites::mark('<b>', 'x'));
        $this->assertStringContainsString('>Ž</span>', Sites::mark('žlutý kůň', 'x')); // check-english: allow
        $this->assertStringContainsString('>?</span>', Sites::mark('  ', 'x'), 'a site without a name still has a mark');
    }
}
