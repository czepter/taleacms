<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\SeoTitle;

final class SeoTitleTest extends TestCase
{
    public function testRenders(): void
    {
        $this->assertSame('About – Studio', SeoTitle::render('%title% %sep% %sitename%', ['title' => 'About', 'sep' => '–', 'sitename' => 'Studio']));
    }

    public function testTrimsDanglingSeparator(): void
    {
        $this->assertSame('Home', SeoTitle::render('%title% %sep% %tagline%', ['title' => 'Home', 'sep' => '|', 'tagline' => '']));
        $this->assertSame('Home | Studio', SeoTitle::render('%title% %sep% %category% %sep% %sitename%', ['title' => 'Home', 'sep' => '|', 'sitename' => 'Studio']));
    }

    public function testEmptyFallsBackToTitle(): void
    {
        $this->assertSame('X', SeoTitle::render('%category%', ['title' => 'X']));
    }

    public function testCapitalize(): void
    {
        $this->assertSame('Wedding Photos In Jena', SeoTitle::render('%title%', ['title' => 'wedding photos in jena'], true));
    }

    public function testCutsAt200(): void
    {
        $this->assertSame(200, mb_strlen(SeoTitle::render('%title%', ['title' => str_repeat('a', 300)])));
    }
}
