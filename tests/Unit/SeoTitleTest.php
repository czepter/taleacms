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

    private function settings(array $values): \Talea\Core\Settings
    {
        $settings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($settings, $values + ['site_name' => 'Studio', 'seo_separator' => '|', 'seo_title_page' => '%title% %sep% %sitename%', 'site_description' => '']);

        return $settings;
    }

    public function testComposeOwnTitleTemplateOtherAndEmpty(): void
    {
        $s = $this->settings([]);
        $this->assertSame('Our prices – Studio', SeoTitle::compose($s, 'page', 'Our prices', true));
        $this->assertSame('Our Studio prices', SeoTitle::compose($s, 'page', 'Our Studio prices', true), 'the site name is not added twice');
        $this->assertSame('Our prices | Studio', SeoTitle::compose($s, 'page', 'Our prices %sep% %sitename%', true), 'variables in an own title');
        $this->assertSame('About | Studio', SeoTitle::compose($s, 'page', 'About', false));
        $this->assertSame('Login – Studio', SeoTitle::compose($s, 'other', 'Login', false));
        $this->assertSame('Studio', SeoTitle::compose($s, 'other', '', false));
        $this->assertSame('Studio', SeoTitle::compose($this->settings(['seo_title_page' => '%category%']), 'page', '', false), 'an empty result falls back to the site name');
    }
}
