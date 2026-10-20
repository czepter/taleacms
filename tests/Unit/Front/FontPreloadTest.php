<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;

final class FontPreloadTest extends TestCase
{
    /** 2.8: font preloading – only the site's own WOFF2 files that render text above the fold */
    public function testPreload(): void
    {
        $fontsDs = ['custom_fonts' => [['name' => 'Firma Sans', 'file' => 'media/pisma/firma-sans.woff2', 'bold' => 'media/pisma/firma-sans-bold.woff2'], ['name' => 'Firma Serif', 'file' => 'media/pisma/firma-serif.woff2', 'bold' => ''], // check-english: allow
            ['name' => 'Old', 'file' => 'media/pisma/old.woff', 'bold' => '']]];
        $this->assertSame('<link rel="preload" href="/web/media/pisma/firma-sans.woff2" as="font" type="font/woff2" crossorigin>' . "\n" . '<link rel="preload" href="/web/media/pisma/firma-sans-bold.woff2" as="font" type="font/woff2" crossorigin>', \Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-1', 'font_heading' => 'custom-1'] + $fontsDs, '/web'), '2.8: DesignSystem::fontPreloads – body = the regular file, headings = the bold file of the heading font');
        $this->assertSame('<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>', \Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'modern', 'font_heading' => 'custom-2'] + $fontsDs), '2.8: DesignSystem::fontPreloads – a heading font without a bold file preloads its only file; a system body font preloads nothing');
        $this->assertSame(['<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>', '', ''], [\Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-2', 'font_heading' => 'custom-2'] + $fontsDs),
            \Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'modern', 'font_heading' => 'classic'] + $fontsDs), \Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-3', 'font_heading' => 'custom-3'] + $fontsDs)], '2.8: DesignSystem::fontPreloads – the same file once, system fonts and WOFF (not WOFF2) never');
        $fontsCss = \Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::sanitize(['font_body' => 'custom-1', 'font_heading' => 'custom-2'] + $fontsDs), '/web');
        $this->assertSame([4, 4, true, true], [substr_count($fontsCss, '@font-face'), substr_count($fontsCss, 'font-display: swap'), str_contains($fontsCss, 'url("/web/media/pisma/firma-sans-bold.woff2")'), str_contains($fontsCss, 'url("/web/media/pisma/firma-serif.woff2")')], '2.8: every @font-face of the site\'s own fonts swaps in the fallback font while the file loads, and the preloaded files are the ones @font-face uses');
    }
}
