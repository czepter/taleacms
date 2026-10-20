<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;

final class TextTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en'); // a language a test switched to does not leak into the next test
    }

    /** text conversions */
    public function testTextConversions(): void
    {
        $this->assertSame('prilis-zlutoucky-kun', slugify('Příliš žluťoučký kůň!'), 'slugify: diacritics and spaces'); // check-english: allow
        $this->assertSame('n-a', slugify('***'), 'slugify: empty input');
        $this->assertTrue(strlen(slugify(str_repeat('abc ', 100), 20)) <= 20, 'slugify: length');
        $this->assertSame('Dabelske ODY – Strasse', remove_diacritics('Ďábelské ÓDY – Straße'), 'remove_diacritics'); // check-english: allow
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&lt;/a&gt;', e('<a href="x">\'</a>'), 'e(): quotes and tags');
        $this->assertSame('5. 9. 2026 07:03', \Talea\Core\Language::runWith('cs', fn () => format_date('2026-09-05 07:03:00', true)), 'date: Czech format');
    }

    /** version comparison */
    public function testVersionComparison(): void
    {
        $r = \Talea\Core\Diff::html('<p>Radnice schválila plán.</p><p>Druhý odstavec.</p>', '<p>Radnice včera schválila nový plán.</p><p>Druhý odstavec.</p><p>Třetí.</p>'); // check-english: allow
        $this->assertTrue(str_contains($r['html'], '<ins>včera </ins>') && str_contains($r['html'], '<ins>nový </ins>'), 'Diff: words in a changed paragraph'); // check-english: allow
        $this->assertTrue(str_contains($r['html'], '<p>Druhý odstavec.</p>'), 'Diff: an unchanged paragraph has no marks'); // check-english: allow
        $this->assertTrue(str_contains($r['html'], '<p><ins>Třetí.</ins></p>'), 'Diff: a new paragraph'); // check-english: allow
        $this->assertFalse(str_contains(\Talea\Core\Diff::html('', '<p>a &lt;script&gt; b</p>')['html'], '<script>'), 'Rozdil: HTML ve vstupu se escapuje');
        $this->assertSame(0, \Talea\Core\Diff::html('<p>Stejné</p>', '<p>Stejné</p>')['added'], 'Diff: identical texts'); // check-english: allow
    }

    /** temporary language switch (e-mails in the recipient's language) */
    public function testTemporaryLanguageSwitch(): void
    {
        \Talea\Core\Language::set('cs');
        $this->assertSame('en|Read article →', \Talea\Core\Language::runWith('en', fn (): string => \Talea\Core\Language::code() . '|' . t('Read article →')), 'Language::runWith: the other language applies inside');
        $this->assertSame('cs|Číst článek →', \Talea\Core\Language::code() . '|' . t('Read article →'), 'Language::runWith: the language is restored afterwards'); // check-english: allow
        try {
            \Talea\Core\Language::runWith('en', function (): never { throw new \RuntimeException('x'); });
        } catch (\RuntimeException) {
        }
        $this->assertSame('cs', \Talea\Core\Language::code(), 'Language::runWith: the language is restored even after an exception');
    }

    /** numbers by language */
    public function testNumbersByLanguage(): void
    {
        $this->assertSame("1\u{00A0}234\u{00A0}567", \Talea\Core\Language::runWith('cs', fn () => format_count(1234567)), 'count: Czech uses a space as the thousands separator');
        $this->assertSame('12,345.68', \Talea\Core\Language::runWith('en', fn () => format_count(12345.678, 2)), 'count: English uses a comma and a decimal point');
        $this->assertSame('3.5 MB', \Talea\Core\Language::runWith('en', fn () => \Talea\Core\Files::size(3 * 1048576 + 524288)), 'Files::size: English decimal point');
    }

    /** date in words in the administration (3.2.1: the English and German admin showed Czech months) */
    public function testDateInWords(): void
    {
        \Talea\Core\Language::set('cs', 'admin-');
        $this->assertSame('středa 7. října 2026', format_date_long('2026-10-07'), 'Date in words: Czech admin'); // check-english: allow
        \Talea\Core\Language::set('en', 'admin-');
        $this->assertSame('Wednesday 7 October 2026', format_date_long('2026-10-07'), 'Date in words: English admin');
        \Talea\Core\Language::set('de', 'admin-');
        $this->assertSame('Mittwoch, 7. Oktober 2026', format_date_long('2026-10-07'), 'Date in words: German admin');
        \Talea\Core\Language::set('cs', 'admin-');
    }
}
