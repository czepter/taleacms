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

    /** #46: topics re-rank the gallery, palettes and font pairs are the look's own variants – every one readable and a library font. */
    public function testEveryLookHasTopicsAndReadablePalettesAndLibraryFontPairs(): void
    {
        foreach ($this->files() as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $look = Looks::get(basename($file, '.json'));
            $this->assertNotNull($look, $file);
            $this->assertNotEmpty($look['topics'], $look['key'] . ': fits at least one topic');
            $this->assertSame($raw['topics'], $look['topics'], $look['key'] . ': every topic is a known one');
            $this->assertGreaterThanOrEqual(3, count($look['palettes']), $look['key'] . ': its own colours and at least two palettes');
            $this->assertGreaterThanOrEqual(3, count($look['fonts']), $look['key'] . ': its own fonts and at least two pairs');
            foreach ($raw['palettes'] as $i => $p) {
                $this->assertSame($p['colors'], array_intersect_key($look['palettes'][$i + 1]['colors'], $p['colors']), $look['key'] . ': palette colours survive the sanitizer');
            }
            foreach ($look['palettes'] as $i => $p) {
                $ds = Looks::variant($look['key'], $i);
                foreach (['light' => DesignSystem::contrasts($ds), 'dark' => DesignSystem::contrastsDark($ds)] as $mode => $pairs) {
                    foreach ($pairs as $pair) {
                        $this->assertTrue($pair['ok'], "{$look['key']} palette $i ($mode): {$pair['description']} {$pair['ratio']}:1");
                    }
                }
            }
            foreach ($raw['fonts'] as $i => $f) {
                $this->assertSame($f, $look['fonts'][$i + 1], $look['key'] . ': font pair ' . ($i + 1) . ' is library fonts');
            }
        }
        $this->assertNull(Looks::variant('atelier', 99), 'an unknown palette is no variant');
        $this->assertSame(Looks::get('atelier')['design_system'], Looks::variant('atelier'), 'variant 0 is the look as it is');
    }

    public function testATopicReranksButNeverFilters(): void
    {
        $this->assertSame(array_keys(Looks::all()), array_keys(Looks::ranked('')), 'no topic: the gallery order');
        $this->assertSame(array_keys(Looks::all()), array_keys(Looks::ranked('nothing')), 'an unknown topic: the gallery order');
        foreach (array_keys(Looks::TOPICS) as $topic) {
            $ranked = Looks::ranked($topic);
            $this->assertEqualsCanonicalizing(array_keys(Looks::all()), array_keys($ranked), $topic . ': every look stays');
            $places = array_map(fn (array $l): int => ($i = array_search($topic, $l['topics'], true)) === false ? PHP_INT_MAX : $i, array_values($ranked));
            $sorted = $places;
            sort($sorted);
            $this->assertSame($sorted, $places, $topic . ': best fits first');
        }
        $this->assertSame('brasserie', array_key_first(Looks::ranked('restaurant')));
        $this->assertSame(['photography', 'real-estate', ''], [Looks::topic(' Photography '), Looks::topic('real-estate'), Looks::topic('knitting')]);
    }
}
