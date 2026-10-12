<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Talea\Core\Language;
use Talea\Core\WpImport;
use Talea\Core\WpLanguages;

/** Regional language versions (en-gb, de-at, pt-br, zh-hant): the tags, the dictionary fallback and what imports make of them. */
final class RegionalLanguagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en');
    }

    /** @return array<string, array{string, string}> */
    public static function tags(): array
    {
        return [
            'a language alone' => ['de', 'de'],
            'a region' => ['pt-br', 'pt-BR'],
            'another region' => ['en-gb', 'en-GB'],
            'a script' => ['zh-hant', 'zh-Hant'],
        ];
    }

    #[DataProvider('tags')]
    public function testTagIsTheCanonicalCase(string $code, string $expected): void
    {
        $this->assertSame($expected, Language::tag($code));
    }

    public function testEveryOfferedCodeMatchesTheTagPattern(): void
    {
        foreach (array_keys(Language::AVAILABLE) as $code) {
            $this->assertSame(1, preg_match('/^' . Language::TAG . '$/D', (string) $code), (string) $code);
        }
        $this->assertSame(array_keys(Language::AVAILABLE), explode('|', Language::CODES), 'CODES lists the same codes in the same order');
        foreach (['pt_BR', 'PT-br', 'pt-', 'portuguese', 'p', '../'] as $bad) {
            $this->assertSame(0, preg_match('/^' . Language::TAG . '$/D', $bad), $bad);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function foreign(): array
    {
        return [
            'a locale' => ['pt_BR', 'pt-br'],
            'Joomla' => ['en-GB', 'en-gb'],
            'a region the site has no version of' => ['en-US', 'en'],
            'Traditional Chinese by its region' => ['zh-TW', 'zh-hant'],
            'Hong Kong' => ['zh_HK', 'zh-hant'],
            'with a script and a region' => ['zh-Hant-TW', 'zh-hant'],
            'simplified Chinese' => ['zh-Hans', 'zh'],
            'Norwegian' => ['nb_NO', 'no'],
            'Austrian German' => ['de_AT', 'de-at'],
            'unknown' => ['xx-YY', ''],
            'nothing' => ['', ''],
        ];
    }

    #[DataProvider('foreign')]
    public function testFromForeignKeepsTheLongestOfferedPart(string $tag, string $expected): void
    {
        $this->assertSame($expected, Language::fromForeign($tag));
    }

    public function testARegionalCodeFoldsIntoTheLanguageTheSiteHas(): void
    {
        $this->assertSame('en', Language::forSite('en-gb', ['en', 'de']), 'the site has English but not British English');
        $this->assertSame('en-gb', Language::forSite('en-gb', ['de']), 'no English at all: the regional version stays');
        $this->assertSame('en-gb', Language::forSite('en-gb', ['en', 'en-gb']), 'the site has the version');
        $this->assertSame('de-at', Language::forSite('de', ['cs', 'de-at']), 'a language the site has only as a region becomes it');
        $this->assertSame('de', Language::forSite('de', ['cs']), 'a language the site lacks stays');
        $this->assertSame('en-gb', Language::forSite('en', ['cs', 'en-gb']), 'a language the site has only as a regional version');
        $this->assertSame('en', Language::forSite('en', ['en', 'en-gb']));
        $this->assertSame('', Language::forSite('', ['en']));
    }

    public function testARegionalVersionUsesTheDictionaryOfItsLanguage(): void
    {
        Language::set('de');
        $german = Language::t('Monday');
        $this->assertSame('Montag', $german);
        Language::set('de-at');
        $this->assertSame($german, Language::t('Monday'));
        $this->assertSame('de-at', Language::code());
        Language::set('en-gb');
        $this->assertSame('Monday', Language::t('Monday'));
        Language::set('pt-br');
        $this->assertSame('Monday', Language::t('Monday'), 'no Portuguese dictionary: English texts, like pt');
        $this->assertContains(Language::intlLocale(), [null, 'pt_BR'], 'the date in words comes from intl in the regional locale');
    }

    public function testBaseIsTheLanguageSubtag(): void
    {
        $this->assertSame(['pt', 'zh', 'cs'], [Language::base('pt-br'), Language::base('zh-hant'), Language::base('cs')]);
    }

    /** @return array<string, array{string, string, list<string>, ?string}> */
    public static function wordpress(): array
    {
        return [
            'a Polylang slug that is a regional code' => ['pt-br', '', [], 'pt-br'],
            'the locale decides' => ['en', 'en_GB', [], 'en-gb'],
            'the locale into a site with English' => ['en', 'en_GB', ['en'], 'en'],
            'the locale into a site that has the version' => ['en', 'en_GB', ['en', 'en-gb'], 'en-gb'],
            'Austrian German into a German site' => ['de', 'de_AT', ['de', 'cs'], 'de'],
            'Austrian German into a site without German' => ['de', 'de_AT', ['cs'], 'de-at'],
            'Traditional Chinese' => ['zh-tw', '', [], 'zh-hant'],
            'an odd slug and a locale' => ['cz', 'cs_CZ', [], 'cs'],
        ];
    }

    /** @param list<string> $site */
    #[DataProvider('wordpress')]
    public function testWordPressCodesKeepARegionUnlessTheSiteHasTheLanguage(string $raw, string $locale, array $site, ?string $expected): void
    {
        $this->assertSame($expected, WpLanguages::code($raw, $locale, $site));
    }

    public function testAnAddressWithARegionalPrefixNamesItsLanguage(): void
    {
        $codes = array_keys(Language::AVAILABLE);
        $this->assertSame('pt-br', WpLanguages::urlLanguage('https://old.example/pt-br/sobre/', $codes));
        $this->assertSame('en-gb', WpLanguages::urlLanguage('https://old.example/?lang=en-gb', $codes));
        $this->assertSame('en', WpImport::itemLanguage(['link' => 'https://old.example/en-gb/about/', 'language_wp' => '', 'language_plugin' => 'wpml'], ['languages' => ['plugin' => 'wpml', 'codes' => [], 'site' => ['en']]])[0]);
        $this->assertSame('en-gb', WpImport::itemLanguage(['link' => 'https://old.example/en-gb/about/', 'language_wp' => '', 'language_plugin' => 'wpml'], ['languages' => ['plugin' => 'wpml', 'codes' => [], 'site' => ['de']]])[0]);
    }

    public function testTranslationGroupsKeepRegionalKeys(): void
    {
        $this->assertSame(['pt-br' => 4, 'en' => 3], WpLanguages::translationMap('a:2:{s:5:"pt-br";i:4;s:2:"en";i:3;}'));
    }
}
