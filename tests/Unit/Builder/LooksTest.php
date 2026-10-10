<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use Talea\Builder\DesignSystem;
use Talea\Builder\Looks;
use Talea\Builder\PartTemplates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #24: the looks gallery (system/looks/<key>.json) – every look validates, is readable in light and dark mode, and records its provenance. */
#[CoversClass(Looks::class)]
final class LooksTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        return glob(dirname(__DIR__, 3) . '/system/looks/*.json') ?: [];
    }

    public function testTheGalleryHasTwelveToFifteenLooksAndEveryFileLoads(): void
    {
        $this->assertGreaterThanOrEqual(12, count($this->files()));
        $this->assertLessThanOrEqual(15, count($this->files()));
        $this->assertSame(count($this->files()), count(Looks::all()), 'a look file that fails validation is skipped, so none may fail');
    }

    public function testEveryLookValidatesAndKeepsWhatItSays(): void
    {
        foreach ($this->files() as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $look = Looks::get(basename($file, '.json'));
            $this->assertNotNull($look, $file);
            $ds = $look['design_system'];
            $this->assertSame($raw['design_system']['colors'], $ds['colors'], $look['key'] . ': colours survive the sanitizer');
            $this->assertSame($raw['design_system']['colors_dark'], $ds['colors_dark'], $look['key'] . ': dark colours survive (brand colours included)');
            foreach (['font_heading', 'font_body'] as $f) {
                $this->assertSame($raw['design_system'][$f], $ds[$f], $look['key'] . ': ' . $f . ' is a library font');
                $this->assertArrayHasKey(substr($ds[$f], 4), DesignSystem::libraryFonts());
            }
            $this->assertSame($raw['design_system']['radius'], $ds['radius']);
            $this->assertArrayHasKey($raw['header'], PartTemplates::LIST['header']);
            $this->assertArrayHasKey($raw['footer'], PartTemplates::LIST['footer']);
            $this->assertNotNull(PartTemplates::build('header', $raw['header'], 'en'), $look['key'] . ' header');
            $this->assertNotNull(PartTemplates::build('footer', $raw['footer'], 'en'), $look['key'] . ' footer');
            $this->assertSame(count($raw['sections']), count($look['sections']), $look['key'] . ': every recommended section exists in the library');
            $this->assertGreaterThanOrEqual(5, count($look['sections']));
        }
    }

    public function testEveryLookPassesTheContrastCheckInLightAndDarkMode(): void
    {
        foreach (Looks::all() as $key => $look) {
            foreach (['light' => DesignSystem::contrasts($look['design_system']), 'dark' => DesignSystem::contrastsDark($look['design_system'])] as $mode => $pairs) {
                foreach ($pairs as $pair) {
                    $this->assertTrue($pair['ok'], "$key ($mode): {$pair['description']} {$pair['ratio']}:1");
                }
            }
        }
    }

    public function testProvenanceAndNoExternalReferences(): void
    {
        foreach ($this->files() as $file) {
            $text = (string) file_get_contents($file);
            $raw = json_decode($text, true);
            $this->assertSame('none-third-party', $raw['assets'], basename($file));
            $this->assertGreaterThan(40, strlen((string) ($raw['source'] ?? '')), basename($file) . ' records how it was built');
            $this->assertDoesNotMatchRegularExpression('~https?:|//|url\(|@import~i', $text, basename($file) . ' references nothing external');
        }
    }
}
