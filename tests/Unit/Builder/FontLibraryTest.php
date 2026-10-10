<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use Talea\Builder\DesignSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** HF-25: the self-hosted font library (image/fonts/<slug>/) and how the design system uses it. */
#[CoversClass(DesignSystem::class)]
final class FontLibraryTest extends TestCase
{
    /** Measured size of all library WOFF2 files (2 365 900 bytes) + 10 %. Adding a family means raising this on purpose. */
    private const int BUDGET_BYTES = 2_600_000;

    private function dir(): string
    {
        return dirname(__DIR__, 3) . '/image/fonts';
    }

    public function testEveryFamilyCarriesItsLicenceAndItsFiles(): void
    {
        $folders = glob($this->dir() . '/*/font.json') ?: [];
        $this->assertGreaterThanOrEqual(25, count($folders), 'about 30 families');
        foreach ($folders as $json) {
            $slug = basename(dirname($json));
            $font = json_decode((string) file_get_contents($json), true);
            $this->assertIsArray($font, $slug);
            foreach (['name', 'category', 'weights', 'italic', 'variable', 'scripts', 'files', 'source', 'licence'] as $key) {
                $this->assertArrayHasKey($key, $font, "$slug font.json $key");
            }
            $this->assertArrayHasKey($font['category'], DesignSystem::FONT_CATEGORIES, $slug);
            $this->assertSame(['latin', 'latin-ext'], $font['scripts'], $slug);
            $this->assertStringStartsWith('https://github.com/google/fonts/', $font['source'], $slug);
            $this->assertFileExists(dirname($json) . '/OFL.txt', "$slug has no licence file");
            $this->assertStringContainsString('SIL OPEN FONT LICENSE', (string) file_get_contents(dirname($json) . '/OFL.txt'), $slug);
            foreach ($font['files'] as $file) {
                $this->assertFileExists(dirname($json) . '/' . $file['file'], "$slug references a missing file");
                $this->assertSame("wOF2", substr((string) file_get_contents(dirname($json) . '/' . $file['file'], false, null, 0, 4), 0, 4), "$slug {$file['file']} is not WOFF2");
            }
            $this->assertSame($font['variable'], str_contains((string) $font['files'][0]['weight'], ' '), "$slug variable flag");
        }
        $this->assertSame(count($folders), count(DesignSystem::libraryFonts()), 'every folder is loaded by DesignSystem::libraryFonts()');
    }

    public function testTheLibraryStaysWithinTheSizeBudget(): void
    {
        $bytes = 0;
        foreach (glob($this->dir() . '/*/*.woff2') ?: [] as $file) {
            $bytes += (int) filesize($file);
        }
        $this->assertLessThanOrEqual(self::BUDGET_BYTES, $bytes, 'the font library grew past its budget: ' . $bytes);
    }

    public function testSuggestedPairingsNameFamiliesOfTheLibrary(): void
    {
        foreach (DesignSystem::PAIRINGS as [$heading, $body, $character]) {
            $this->assertArrayHasKey($heading, DesignSystem::libraryFonts(), $character);
            $this->assertArrayHasKey($body, DesignSystem::libraryFonts(), $character);
        }
    }

    public function testALibraryFontIsChosenByKeySlugOrName(): void
    {
        $this->assertSame('lib:source-sans-3', DesignSystem::libraryKey('lib:source-sans-3'));
        $this->assertSame('lib:source-sans-3', DesignSystem::libraryKey('Source Sans 3'));
        $this->assertSame('lib:eb-garamond', DesignSystem::libraryKey('eb garamond'));
        $this->assertNull(DesignSystem::libraryKey('Comic Neue'));
        $ds = DesignSystem::sanitize(['font_heading' => 'Playfair Display', 'font_body' => 'lib:nope']);
        $this->assertSame('lib:playfair-display', $ds['font_heading']);
        $this->assertSame(DesignSystem::DEFAULTS['font_body'], $ds['font_body'], 'an unknown font falls back to the default');
        $this->assertSame('"Playfair Display", Georgia, "Times New Roman", Times, serif', DesignSystem::fontFamily($ds, $ds['font_heading'], true));
    }

    public function testOnlyTheChosenFamiliesGetFontFaceAndPreloads(): void
    {
        $ds = DesignSystem::sanitize(['font_heading' => 'lib:playfair-display', 'font_body' => 'lib:source-sans-3']);
        $css = DesignSystem::css($ds, '/web');

        $this->assertStringContainsString('font-family: "Playfair Display"; src: url("/web/image/fonts/playfair-display/playfair-display.woff2") format("woff2"); font-weight: 400 900; font-style: normal; font-display: swap;', $css);
        $this->assertStringContainsString('font-family: "Source Sans 3"', $css);
        $this->assertStringContainsString('font-style: italic', $css, 'the italic file is declared');
        foreach (['Inter', 'Roboto', 'Lato', 'Caveat'] as $other) {
            $this->assertStringNotContainsString('"' . $other . '"', $css, "$other is not chosen and must not be declared");
        }
        $this->assertSame(
            '<link rel="preload" href="/web/image/fonts/source-sans-3/source-sans-3.woff2" as="font" type="font/woff2" crossorigin>' . "\n"
            . '<link rel="preload" href="/web/image/fonts/playfair-display/playfair-display.woff2" as="font" type="font/woff2" crossorigin>',
            DesignSystem::fontPreloads($ds, '/web'),
            'text and heading file only – no italics, nothing else',
        );
        $this->assertSame('', DesignSystem::fontPreloads(DesignSystem::sanitize([])), 'system fonts download nothing');
        $this->assertStringNotContainsString('@font-face', DesignSystem::css(DesignSystem::sanitize([])));
    }

    public function testStaticFilesAnswerLighterAndHeavierWeightsWithoutFakeBold(): void
    {
        $one = DesignSystem::libraryFontFaces(['bebas-neue']);
        $this->assertStringContainsString('font-weight: 100 900', $one, 'a single-weight family covers every weight, so the browser never fakes a bold');
        $lato = DesignSystem::libraryFontFaces(['lato']);
        $this->assertStringContainsString('font-weight: 100 300', $lato);
        $this->assertStringContainsString('font-weight: 400;', $lato);
        $this->assertStringContainsString('font-weight: 900;', $lato, 'the heaviest file answers 900');
        $this->assertStringContainsString('lato-700.woff2', DesignSystem::fontPreloads(['font_heading' => 'lib:lato', 'font_body' => 'modern']), 'headings preload the file nearest to bold');
        $this->assertStringContainsString('lato-400.woff2', DesignSystem::fontPreloads(['font_heading' => 'modern', 'font_body' => 'lib:lato']), 'text preloads the regular file');
    }

    public function testNothingReferencesAnExternalFontHost(): void
    {
        $all = '';
        foreach (array_keys(DesignSystem::libraryFonts()) as $slug) {
            $ds = DesignSystem::sanitize(['font_heading' => 'lib:' . $slug, 'font_body' => 'lib:' . $slug]);
            $all .= DesignSystem::css($ds, '') . DesignSystem::fontPreloads($ds, '') . json_encode(DesignSystem::toDtcg($ds));
        }
        $all .= DesignSystem::libraryFontFaces(null, '');
        foreach (glob(dirname(__DIR__, 3) . '/image/*.css') ?: [] as $css) {
            $all .= (string) file_get_contents($css);
        }
        $all .= (string) file_get_contents(dirname(__DIR__, 3) . '/system/views/front/base.php');

        $this->assertDoesNotMatchRegularExpression('~fonts\.googleapis|fonts\.gstatic|typekit|use\.fontawesome|bunny\.net|@import\s+url\(\s*["\']?https?:~i', $all);
        $this->assertDoesNotMatchRegularExpression('~url\(\s*["\']?(https?:)?//~i', $all, 'no @font-face or style loads anything from another host');
    }
}
