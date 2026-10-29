<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;

final class LibraryTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en'); // a language a test switched to does not leak into the next test
    }

    public function testSectionsAreValidBuilds(): void
    {
        // every library section is a valid build
        $libraryProblems = [];
        foreach (\Talea\Builder\Library::listAll() as $librarySection) {
            $element = \Talea\Builder\Library::section($librarySection['key'])['element'];
            [, $sectionErrors] = \Talea\Builder\Build::sanitize(['v' => 1, 'children' => [$element]]);
            if ($sectionErrors !== []) {
                $libraryProblems[] = $librarySection['key'];
            }
        }
        $this->assertSame([], $libraryProblems, 'Builder model: every library section is a valid build');
    }

    public function testReadyMadeSectionsPassTheValidator(): void
    {
        $buildLibraryErrors = [];
        // raw builds (section() already cleans them, so an invalid value would disappear silently)
        foreach ((new \ReflectionMethod(\Talea\Builder\Library::class, 'sections'))->invoke(null) as $buildKey => $buildSection) {
            $buildSection['key'] = $buildKey;
            [, $buildErrors] = \Talea\Builder\Build::sanitize(['children' => [($buildSection['build'])()]]);
            $buildLibraryErrors += array_map(fn (string $c): string => $buildSection['key'] . ': ' . $c, $buildErrors);
        }
        $this->assertSame([], $buildLibraryErrors, 'Library: all ready-made sections pass the validator');
        $this->assertFalse(in_array('html', array_column(\Talea\Builder\ElementSchema::schema(false)['elements'], 'type'), true), 'ElementSchema::schema: without custom HTML for non-administrators');
    }

    public function testQuickCheckSectionSanitizesAndIsFlaggedAsSample(): void
    {
        $this->assertContains('quick-check', array_column(\Talea\Builder\Library::listAll(), 'key'));
        $element = \Talea\Builder\Library::section('quick-check')['element'];
        [$build, $errors] = \Talea\Builder\Build::sanitize(['v' => 1, 'children' => [$element]]);
        $this->assertSame([], $errors);
        $this->assertNotSame([], \Talea\Builder\SampleContent::find($build), 'the placeholders are found as sample content');
        $form = array_values(array_filter($build['children'][0]['children'], fn (array $c): bool => $c['type'] === 'form'))[0]['content'];
        $this->assertSame('after_contact', $form['calculation']);
        // the first question opens the form without a step field of its own: a step field before it would make an empty first screen
        $types = array_column($form['fields'], 'type');
        $this->assertNotSame('step', $types[0], 'no empty first step');
        $this->assertStringNotContainsString('step,step', implode(',', $types), 'no empty step between two steps');
        $this->assertCount(3, array_keys($types, 'step', true), 'three questions and the contact step: three step fields after the first');
        foreach (array_filter($form['fields'], fn (array $f): bool => ($f['display'] ?? '') === 'icon_tiles') as $tiles) {
            $this->assertCount(3, \Talea\Builder\Elements\Form::optionPrices($tiles), 'three distinct options per tile question: ' . $tiles['label']);
            $this->assertSame([0.0, 0.0, 0.0], array_values(\Talea\Builder\Elements\Form::optionPrices($tiles)), 'no made-up amounts');
        }
        $estimate = array_values(array_filter($form['fields'], fn (array $f): bool => $f['type'] === 'estimate'))[0];
        $this->assertSame([10, 15, 50, '[unit]'], [$estimate['range_below'], $estimate['range_above'], $estimate['round_to'], $estimate['unit_suffix']]);
        $this->assertCount(1, $form['logic']['results']);
    }

    public function testDisabledExtensions(): void
    {
        // disabled extensions: the builder offers neither their elements nor sections using them
        $schemaTypes = array_column(\Talea\Builder\ElementSchema::schema(true, 'cs', false, ['stats'])['elements'], 'type');
        $this->assertSame([false, false, true], [in_array('news_list', $schemaTypes, true), in_array('form', $schemaTypes, true), in_array('heading', $schemaTypes, true)], 'Extensions: without news and enquiries the schema has no such elements');
        $libraryKey = array_column(\Talea\Builder\Library::listAll(['stats']), 'key');
        $this->assertSame([false, false, true], [in_array('contact-form', $libraryKey, true), in_array('news', $libraryKey, true), in_array('hero', $libraryKey, true)], 'Extensions: the library without sections with a form and news');
        $this->assertTrue(count(\Talea\Builder\Library::listAll()) > count($libraryKey), 'Extensions: without a restriction the library is whole');
    }

    public function testLanguage(): void
    {
        \Talea\Core\Language::set('cs', 'admin-');
        $libraryEn = \Talea\Builder\Library::section('hero', 'en')['element'];
        $this->assertSame(['We help businesses grow – quickly and hassle-free', '/contact', '/services'], [$libraryEn['children'][0]['content']['text'], $libraryEn['children'][2]['children'][0]['content']['link'], $libraryEn['children'][2]['children'][1]['content']['link']], 'Library: sections in English including page links');
        $this->assertSame('/kontakt', \Talea\Core\Language::runWith('cs', fn () => \Talea\Builder\Library::section('hero', 'cs')['element']['children'][2]['children'][0]['content']['link']), 'Library: in Czech it links to Czech addresses'); // check-english: allow
        $this->assertSame('cs', \Talea\Core\Language::code(), 'Library: the language is restored after a section is built');
        $librarySchema = array_column(\Talea\Builder\ElementSchema::schema(true, 'en')['elements'], 'properties', 'type');
        $this->assertSame(['Heading', 'Contact us'], [$librarySchema['heading']['text']['default'], $librarySchema['button']['text']['default']], 'ElementSchema::schema: default element content in the page language');
        $libraryEnDictionary = (require TALEA_ROOT . '/system/languages/en.php') + (require TALEA_ROOT . '/system/languages/cs.php');
        preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(TALEA_ROOT . '/system/src/Builder/Library.php') . implode('', array_map('file_get_contents', glob(TALEA_ROOT . '/system/src/Builder/Elements/*.php'))), $libraryTexts);
        $this->assertSame([], array_values(array_diff(array_unique(array_map('stripslashes', $libraryTexts[1])), array_keys($libraryEnDictionary), ['Menu', 'Standard', 'Video'])), 'Library and elements: all sample texts have an English translation');
    }

    public function testSampleSites(): void
    {
        $siteErrors = [];
        $siteSections = array_column(\Talea\Builder\Library::listAll(), 'key');
        foreach (\Talea\Builder\Library::SITES as $siteKey => $networks) {
            if (!isset(\Talea\Builder\DesignSystem::PRESETS[$networks['preset']])) {
                $siteErrors[] = $siteKey . ': preset ' . $networks['preset'];
            }
            foreach ($networks['pages'] as $pageSections) {
                foreach (array_diff($pageSections, $siteSections) as $missing) {
                    $siteErrors[] = $siteKey . ': section ' . $missing;
                }
            }
        }
        $this->assertSame([], $siteErrors, 'Library::SITES: the presets and sections of the sample sites exist');

        // pages of the sample sites pass the builder's pre-publish check (image/builder/panels.js, check()): buttons with a link,
        // no empty image, a single h1 and an outline without a skipped level – in Czech and English, with all extensions and without them
        $pageCheck = function (array $build): array {
            $findings = [];
            $headings = [];
            $walk = function (array $children) use (&$walk, &$findings, &$headings): void {
                foreach ($children as $p) {
                    $o = $p['content'] ?? [];
                    if ($p['type'] === 'button' && in_array($o['link'] ?? '', ['', '#'], true)) {
                        $findings[] = 'button without a link: ' . ($o['text'] ?? '');
                    }
                    if ($p['type'] === 'image' && (($o['src'] ?? '') === '' || ($o['alt'] ?? '') === '')) {
                        $findings[] = 'image without a file or description';
                    }
                    if ($p['type'] === 'heading' && preg_match('/^h([1-6])$/', $p['tag'] ?? 'h2', $m)) {
                        $headings[] = (int) $m[1];
                    }
                    $walk($p['children'] ?? []);
                }
            };
            $walk($build['children']);
            if (count(array_keys($headings, 1)) !== 1) {
                $findings[] = count(array_keys($headings, 1)) . '× h1';
            }
            foreach ($headings as $i => $u) {
                if ($i > 0 && $u > $headings[$i - 1] + 1) {
                    $findings[] = 'h' . $headings[$i - 1] . ' → h' . $u;
                }
            }

            return $findings;
        };
        $sitesCheck = [];
        foreach (\Talea\Builder\Library::SITES as $siteKey => $networks) {
            foreach (['cs', 'en'] as $language) {
                foreach (['all extensions' => array_keys(\Talea\Core\Extensions::CATALOG), 'no extensions' => []] as $variant => $enabled) {
                    foreach ($networks['pages'] as $i => $pageSections) {
                        if ($pageSections === []) {
                            continue; // text page: the layout provides the h1 heading
                        }
                        [$build] = \Talea\Builder\Library::assemble($pageSections, 'Stránka', $language, \Talea\Builder\ElementSchema::disabledTypes($enabled), true); // check-english: allow
                        foreach ($pageCheck($build) as $finding) {
                            $sitesCheck[] = "$siteKey/$language/$variant/page $i: $finding";
                        }
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($sitesCheck)), 'Library::SITES: the pages of the sample sites pass the pre-publish check');
        $contactWithoutForm = \Talea\Builder\Library::assemble(\Talea\Builder\Library::SITES['crafts']['pages'][3], 'Kontakt', 'cs', \Talea\Builder\ElementSchema::disabledTypes([]), true)[0]; // check-english: allow
        $this->assertTrue(str_contains((string) json_encode($contactWithoutForm), '"detail":"address"'), 'Library: a contact without the Forms extension has the company details');
    }
}
