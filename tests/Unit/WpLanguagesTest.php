<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Talea\Core\WpFile;
use Talea\Core\WpImport;
use Talea\Core\WpLanguages;

/** Languages and translation groups of Polylang and WPML exports: the pure readers, the header of the fixtures and the size cap. */
final class WpLanguagesTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/tools/fixtures/' . $name;
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function codes(): array
    {
        return [
            'a plain code' => ['en', '', 'en'],
            'the locale wins over an odd slug' => ['cz', 'cs_CZ', 'cs'],
            'a regional code' => ['pt-br', '', 'pt-br'],
            'Norwegian Bokmål' => ['nb', '', 'no'],
            'a language this site cannot offer' => ['ar', 'ar', null],
            'nothing' => ['', '', null],
        ];
    }

    #[DataProvider('codes')]
    public function testCode(string $raw, string $locale, ?string $expected): void
    {
        $this->assertSame($expected, WpLanguages::code($raw, $locale));
    }

    public function testUrlLanguageReadsThePathPrefixAndThePolylangParameter(): void
    {
        $codes = ['cs', 'en'];
        $this->assertSame('en', WpLanguages::urlLanguage('https://old.example/en/about/', $codes));
        $this->assertSame('en', WpLanguages::urlLanguage('https://old.example/?lang=en', $codes));
        $this->assertSame('', WpLanguages::urlLanguage('https://old.example/about/', $codes));
        $this->assertSame('', WpLanguages::urlLanguage('https://old.example/fr/about/', $codes));
    }

    public function testTranslationMapKeepsOnlyLanguageIds(): void
    {
        $this->assertSame(['cs' => 102, 'en' => 103], WpLanguages::translationMap('a:3:{s:2:"cs";i:102;s:2:"en";i:103;s:4:"sync";a:1:{s:2:"en";s:2:"cs";}}'));
        $this->assertSame([], WpLanguages::translationMap('O:8:"stdClass":0:{}'), 'an object is never unserialized');
        $this->assertSame([], WpLanguages::translationMap('a:1:{s:2:"cs";i:-4;}'), 'an id must be positive');
        $this->assertSame('cs_CZ', WpLanguages::locale('a:3:{s:6:"locale";s:5:"cs_CZ";s:3:"rtl";i:0;s:9:"flag_code";s:2:"cz";}'));
    }

    public function testMajorityTakesTheLanguageMostItemsHave(): void
    {
        $this->assertSame('en', WpLanguages::majority(['cs', 'en', '', 'en']));
        $this->assertSame('', WpLanguages::majority(['', '']));
    }

    public function testHeaderOfAPolylangExport(): void
    {
        $header = (new WpFile(self::fixture('wordpress-polylang.xml')))->header();
        $this->assertSame('polylang', $header['plugin']);
        $this->assertSame(['cs' => 'cs_CZ', 'en' => 'en_GB', 'ar' => 'ar'], $header['languages']);
        $this->assertSame(['cs', 'en'], [$header['term_language'][5], $header['term_language'][6]], 'the two categories are translations of each other');
        $this->assertSame($header['term_group'][5], $header['term_group'][6]);
        $this->assertSame('ICTX Blog', $header['term_name'][6], 'the name of the second category with the shared slug is kept');
    }

    public function testItemsOfAPolylangAndAWpmlExportCarryLanguageAndGroup(): void
    {
        $items = iterator_to_array((new WpFile(self::fixture('wordpress-polylang.xml')))->items(), false);
        $this->assertSame(['en', 'pll:pll_66f0a1b2c3d50', 'polylang'], [$items[0]['language_wp'], $items[0]['group'], $items[0]['language_plugin']]);
        $wpml = iterator_to_array((new WpFile(self::fixture('wordpress-wpml.xml')))->items(), false);
        $this->assertSame(['cs', 'wpml:1', 'wpml'], [$wpml[0]['language_wp'], $wpml[0]['group'], $wpml[0]['language_plugin']]);
        $duplicate = array_values(array_filter($wpml, fn (array $p): bool => $p['id'] === 306))[0];
        $this->assertSame('dup:305', $duplicate['group'], 'a WPML duplicate names its original');
        $address = array_values(array_filter($wpml, fn (array $p): bool => $p['id'] === 307))[0];
        $this->assertSame(['', 'wpml'], [$address['language_wp'], $address['language_plugin']], 'only the address shows the language, the plugin leaves a sign');
        $this->assertSame(['en', ''], WpImport::itemLanguage($address, ['languages' => ['plugin' => 'wpml', 'codes' => []]]));
    }

    public function testThePreviewCountsLanguagesAndLeavesUnsupportedOnesOut(): void
    {
        $state = WpImport::newState('wordpress-polylang.xml', ['cs', 'en']);
        WpImport::analyze($state, 30, self::fixture('wordpress-polylang.xml'));
        $this->assertEquals(['cs' => 3, 'en' => 3], $state['languages']['found'], 'items per language (the export is en_GB, the site has English)');
        $this->assertSame(['ar' => 1], $state['languages']['unsupported'], 'Arabic cannot be offered');
        $this->assertTrue(WpImport::isMultilingual($state));
    }

    public function testARegionalLanguageOfTheExportIsKeptWhenTheSiteLacksItsLanguage(): void
    {
        $state = WpImport::newState('wordpress-polylang.xml', ['cs']);
        WpImport::analyze($state, 30, self::fixture('wordpress-polylang.xml'));
        $this->assertEquals(['cs' => 3, 'en-gb' => 3], $state['languages']['found']);
    }

    public function testTermsKeptFromTheHeaderAreCapped(): void
    {
        $over = WpFile::MAX_TERMS + 5;
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title><link>https://t.example</link>';
        for ($i = 1; $i <= $over; $i++) {
            $xml .= '<wp:category><wp:term_id>' . $i . '</wp:term_id><wp:category_nicename>c' . $i . '</wp:category_nicename><wp:cat_name>C' . $i . '</wp:cat_name></wp:category>'
                . '<wp:tag><wp:term_id>' . ($over + $i) . '</wp:term_id><wp:tag_slug>t' . $i . '</wp:tag_slug><wp:tag_name>T' . $i . '</wp:tag_name></wp:tag>'
                . '<wp:term><wp:term_id>' . (2 * $over + $i) . '</wp:term_id><wp:term_taxonomy>term_translations</wp:term_taxonomy><wp:term_slug>pll_' . $i . '</wp:term_slug><wp:term_description>a:1:{s:2:"cs";i:' . $i . ';}</wp:term_description></wp:term>';
        }
        $path = tempnam(sys_get_temp_dir(), 'wxr');
        file_put_contents($path, $xml . '</channel></rss>');
        try {
            $header = (new WpFile($path))->header();
        } finally {
            unlink($path);
        }
        $this->assertCount(WpFile::MAX_TERMS, $header['categories'], 'categories');
        $this->assertCount(WpFile::MAX_TERMS, $header['tags'], 'tags');
        $this->assertLessThanOrEqual(WpFile::MAX_TERMS, count($header['terms']), 'terms by number');
        $this->assertLessThanOrEqual(WpFile::MAX_TERMS + 1, count($header['term_language']), 'term languages');
    }
}
