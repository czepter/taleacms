<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Context;
use Talea\Builder\Elements\BeforeAfter;
use Talea\Builder\Elements\BulletList;
use Talea\Builder\Elements\Hotspots;
use Talea\Builder\Elements\PricingTable;
use Talea\Builder\Elements\Progress;
use Talea\Builder\Elements\Timeline;
use Talea\Builder\Icons;
use Talea\Builder\Renderer;
use Talea\Builder\Style;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Language;
use Talea\Core\Mail;
use Talea\Core\Search;
use Talea\Core\Slug;
use Talea\Core\WpImport;
use Talea\Front\Seo;

/** Arabic and right-to-left sites: direction by language, Latin slugs, search, bidi isolation, the CSS only RTL pages get, UTF-8 line splits. */
final class RightToLeftTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en');
    }

    public function testDirectionByLanguageAndScript(): void
    {
        $this->assertSame([true, true, true, true, true, false, false, false, true, false, true, false, false],
            array_map(Language::isRtl(...), ['ar', 'he', 'fa', 'ur', 'ar-eg', 'cs', 'en', 'en-gb', 'az-arab', 'ar-latn', 'pa-arab', 'zh-hant', '']));
        $this->assertSame(['rtl', 'ltr', ' dir="rtl"', ''], [Language::dir('ar'), Language::dir('de'), Language::dirAttribute('ar'), Language::dirAttribute('cs')]);
    }

    public function testArabicIsOfferedWithItsLocaleAndCode(): void
    {
        $this->assertSame(['العربية', 'ar_SA'], [Language::AVAILABLE['ar'][0], Language::AVAILABLE['ar'][1]]);
        $this->assertStringEndsWith('|ar', Language::CODES);
        $this->assertSame('ar', \Talea\Core\WpLanguages::code('ar', 'ar_SA'));
        $this->assertNull(\Talea\Core\WpLanguages::code('he', 'he_IL'), 'Hebrew is ready for a dictionary but not offered');
    }

    public function testSlugifyTransliteratesArabicAndLeavesOtherScriptsToTheNumber(): void
    {
        $this->assertSame(['sfha-alkhdmat', 'shryt-lasq', 'shryt-kynyzyw-5-cm', 'alamn-2026', 'mdwna-fxm', 'n-a', 'n-a', 'prilis-zlutoucky'],
            array_map(fn (string $t): string => slugify($t), ['صفحة الخدمات', 'شريط لاصق', 'شريط كينيزيو 5 cm', 'الأَمْنُ ٢٠٢٦', 'مدونة FXM', 'עברית', '你好', 'Příliš žluťoučký'])); // check-english: allow
        $this->assertSame(['pages', 'news', 'categories', 'collection_items'], array_keys(Slug::FALLBACK));
    }

    public function testSearchKeepsArabicLettersAndFoldsTheTypedForms(): void
    {
        $this->assertSame('الامن ٢٠٢٦ fxm', Search::normalize('<p>الأَمْنُ ٢٠٢٦ - FXM</p>'));
        $this->assertSame(Search::normalize('الإمارات'), Search::normalize('الامارات'), 'alef with hamza and plain alef are one letter');
        $this->assertSame(Search::normalize('مصطفى'), Search::normalize('مصطفي'), 'alef maqsura and ya are one letter');
        $this->assertSame(Search::normalize('مدرسة'), Search::normalize('مدرسه'), 'ta marbuta is read as ha');
        $this->assertSame(Search::normalize('ٱلله'), Search::normalize('الله'), 'alef wasla is a plain alef');
        $this->assertSame('cafe 12', Search::normalize('Café, 12!'), 'Latin text gives the tokens it always did');
        $results = Search::find('الخدمات', [['title' => 'صفحة الخدمات', 'url' => '/a', 'text' => '<p>نقدم الخدمات</p>'], ['title' => 'Home', 'url' => '/b', 'text' => 'x']]);
        $this->assertSame(['/a'], array_column($results, 'url'));
    }

    public function testBdiIsolatesAndEscapes(): void
    {
        $this->assertSame(['<bdi>سارة &lt;x&gt;</bdi>', '<bdi></bdi>'], [bdi('سارة <x>'), bdi(null)]);
    }

    public function testNumbersKeepWesternDigitsAndAValueInASentenceIsIsolated(): void
    {
        Language::set('ar');
        $this->assertSame(['12,345', '4.5'], [format_count(12345), format_number(4.5)]);
        $this->assertSame("\u{2068}Kinesio Tape (5 cm)\u{2069}", str_replace('%s', '%s', t('%s', 'Kinesio Tape (5 cm)')));
        $this->assertSame('2', t('%d', 2), 'numbers are not isolated');
        Language::set('en');
        $this->assertSame('x', t('%s', 'x'), 'a left-to-right sentence is as it was');
    }

    public function testAPlainArabicMessageGetsAnRtlHtmlPart(): void
    {
        $html = Mail::rightToLeft("مرحبا Fysio\nhttps://example.test/a?b=1&c=2", 'ar');
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertStringContainsString('white-space: pre-wrap', $html);
        $this->assertStringContainsString('<a href="https://example.test/a?b=1&amp;c=2" dir="ltr">', $html);
        $this->assertStringNotContainsString('<script', Mail::rightToLeft('<script>x</script>', 'ar'));
    }

    public function testDirectionalIconsMirrorOnlyOnARightToLeftPage(): void
    {
        $this->assertStringNotContainsString('matrix(-1 0 0 1 24 0)', Icons::svg('arrow'));
        Language::set('ar');
        $this->assertStringContainsString('matrix(-1 0 0 1 24 0)', Icons::svg('arrow'));
        $this->assertStringContainsString('matrix(-1 0 0 1 24 0)', Icons::svg('send'));
        $this->assertStringNotContainsString('matrix(-1 0 0 1 24 0)', Icons::svg('check'));
    }

    private function css(string $code): string
    {
        $context = new Context((new \ReflectionClass(App::class))->newInstanceWithoutConstructor());
        $context->types = [Timeline::TYPE => true, BeforeAfter::TYPE => true, Hotspots::TYPE => true, PricingTable::TYPE => true, Progress::TYPE => true];

        return Language::runWith($code, fn (): string => Renderer::css((new \ReflectionClass(Db::class))->newInstanceWithoutConstructor(), $context));
    }

    public function testAnArabicPageAddsTheRulesLogicalPropertiesCannotSay(): void
    {
        $arabic = $this->css('ar');
        $english = $this->css('en');
        $this->assertStringContainsString('.tl-timeline::before, .tl-timeline-item::before { translate: 50% 0; }', $arabic);
        $this->assertStringContainsString('clip-path: inset(0 var(--tl-split) 0 0)', $arabic);
        $this->assertStringContainsString('.tl-hotspots-point { inset-inline-start: auto; left: var(--x); }', $arabic);
        $this->assertStringContainsString('transform-origin: right', $arabic);
        $this->assertStringNotContainsString('translate: 50%', $english);
        $rtl = array_map(fn (string $c): string => $c::rtlCss() . "\n", [Timeline::class, BeforeAfter::class, Hotspots::class, PricingTable::class, Progress::class]);
        $this->assertSame($english, str_replace($rtl, '', $arabic), 'the English page is the Arabic one without the right-to-left rules');
    }

    public function testStyleInsetsAreTheStartAndEndSides(): void
    {
        $this->assertStringContainsString('inset-inline-start', Style::css('#a', ['base' => ['left' => 'm']]));
        $this->assertStringContainsString('inset-inline-end', Style::css('#a', ['base' => ['right' => 'm']]));
        $this->assertSame(['left' => 'm'], Style::fromCss('left', 'var(--tl-space-m)'));
        $this->assertSame(['right' => 's'], Style::fromCss('right', 'var(--tl-space-s)'));
    }

    public function testLinesSplitAsUtf8SoArabicWordsAndAreStayWhole(): void
    {
        // the byte U+0645 م ends in 0x85, which a byte-wise \R treats as a line break
        $list = BulletList::render(['tag' => 'ul', 'content' => ['items' => "مرحبا بكم\nÅland\nعمل", 'style' => 'plain']], '', '', new Context((new \ReflectionClass(App::class))->newInstanceWithoutConstructor()));
        $this->assertSame(3, substr_count($list, '<li>'));
        $this->assertStringContainsString('<li>مرحبا بكم</li>', $list);
        $this->assertStringContainsString('<li>Åland</li>', $list);
        $this->assertSame([['ما هي الخدمات؟', 'نقدم كل شيء.'], ['سؤال أم', 'جواب Å']], Seo::faq("ما هي الخدمات؟\nنقدم كل شيء.\n\nسؤال أم\nجواب Å"));
    }

    public function testAnArabicWordPressExportImportsAsAnArabicVersion(): void
    {
        $state = WpImport::newState('wordpress-arabic.xml', ['en', 'ar']);
        WpImport::analyze($state, 30, dirname(__DIR__, 2) . '/tools/fixtures/wordpress-arabic.xml');
        $this->assertSame('polylang', $state['languages']['plugin']);
        $this->assertSame(['en' => 'en', 'ar' => 'ar'], $state['languages']['codes']);
        $this->assertEquals(['en' => 2, 'ar' => 2], $state['languages']['found']);
        $this->assertSame([], $state['languages']['unsupported'], 'nothing is left out');
        $this->assertCount(2, $state['languages']['groups']);
    }

    public function testTheArabicVisitorDictionaryCoversWhatTheOtherLanguagesTranslate(): void
    {
        $arabic = require TALEA_SYSTEM . '/languages/ar.php';
        $others = [];
        foreach (['de', 'es', 'fr', 'it', 'pl', 'sk'] as $code) {
            $others += require TALEA_SYSTEM . '/languages/' . $code . '.php';
        }
        $this->assertSame([], array_values(array_diff(array_keys($others), array_keys($arabic))));
        $this->assertSame([], array_keys(array_filter($arabic, fn (string $v): bool => trim($v) === '')));
        foreach ($arabic as $key => $text) {
            $this->assertSame(preg_match_all('/%(?:\\d+\\$)?[sd]/', $key), preg_match_all('/%(?:\\d+\\$)?[sd]/', $text), 'placeholders of ' . $key);
        }
    }

    public function testArabicDatesAndNumbersUseTheDictionary(): void
    {
        Language::set('ar');
        $this->assertSame('07/10/2026', format_date('2026-10-07'));
        $this->assertSame('الأربعاء، 7 أكتوبر 2026', format_date_long('2026-10-07'));
        $this->assertNotSame('Read more', t('Read more'));
    }
}
