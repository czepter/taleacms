<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Builder\FontScripts;
use Talea\Core\Language;

/** #159: Appearance and the site audit warn when a language version is written in a script the heading or text font has no letters for. */
#[CoversClass(FontScripts::class)]
final class FontScriptsTest extends TestCase
{
    protected function setUp(): void
    {
        Language::set('en');
    }

    public function testALibraryFontWithoutArabicIsReportedWithFontsThatHaveIt(): void
    {
        $problems = FontScripts::problems(['font_heading' => 'lib:inter', 'font_body' => 'modern'], ['en', 'ar']);
        $this->assertCount(1, $problems, 'the system font of the text has every script');
        $this->assertSame(['key' => 'font_heading', 'font' => 'Inter', 'script' => 'arabic', 'languages' => ['ar']], array_diff_key($problems[0], ['suggestions' => true]));
        $this->assertContains('Modern sans-serif', $problems[0]['suggestions']);
        $message = FontScripts::message($problems[0]);
        $this->assertStringContainsString('The heading font Inter has no Arabic letters', $message);
        $this->assertStringContainsString('العربية', $message);
        $this->assertStringContainsString('Modern sans-serif', $message);
    }

    public function testSystemStacksByTheirFirstFonts(): void
    {
        $problems = FontScripts::problems(['font_heading' => 'elegant', 'font_body' => 'serif'], ['cs', 'ru', 'uk', 'el']);
        $this->assertSame([['font_heading', 'cyrillic', ['ru', 'uk']], ['font_heading', 'greek', ['el']]], array_map(fn (array $p): array => [$p['key'], $p['script'], $p['languages']], $problems));
        $this->assertSame('Sans-serif', FontScripts::problems(['font_heading' => 'modern', 'font_body' => 'serif'], ['ar'])[0]['suggestions'][0], 'the text picker offers its own system fonts');
    }

    public function testLatinSitesAndUploadedFontsAreNotReported(): void
    {
        $this->assertSame([], FontScripts::problems(['font_heading' => 'lib:inter', 'font_body' => 'lib:lora'], ['en', 'de-at', 'pt-br', 'hu']));
        $this->assertSame([], FontScripts::problems(['font_heading' => 'custom-1', 'font_body' => 'custom-2'], ['ar', 'ru']), 'Talea cannot read the letters of a WOFF2 file');
    }
}
