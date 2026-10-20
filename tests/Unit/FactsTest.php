<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;

final class FactsTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en'); // a language a test switched to does not leak into the next test
    }

    /** 2.10: business facts – values by type, how they are shown, the token */
    public function testValues(): void
    {
        $this->assertSame(['1500', null, '2004', null, '1500 CZK', null, '2026-10-02', 'info@example.cz', null, '20 let', null], [\Talea\Core\Facts::clean('number', '1 500'), \Talea\Core\Facts::clean('number', 'many'), \Talea\Core\Facts::clean('year', '2004'),
            \Talea\Core\Facts::clean('year', '04'), \Talea\Core\Facts::clean('money', '1500 CZK'), \Talea\Core\Facts::clean('money', '1500,- Kč'), \Talea\Core\Facts::clean('date', '2026-10-02'), // check-english: allow
            \Talea\Core\Facts::clean('email', 'info@example.cz'), \Talea\Core\Facts::clean('url', 'javascript:alert(1)'), \Talea\Core\Facts::clean('text', '<b>20</b> let'), \Talea\Core\Facts::clean('text', 'see {{fact.other}}')], '2.10: Facts::clean – a value must fit its type');
        $this->assertSame(["12\u{00A0}500", "1\u{00A0}500\u{00A0}CZK", '2004', 'od 2004'], \Talea\Core\Language::runWith('cs', fn (): array => [
            \Talea\Core\Facts::display('number', '12500'), \Talea\Core\Facts::display('money', '1500 CZK'), \Talea\Core\Facts::display('year', '2004'), \Talea\Core\Facts::display('text', 'od 2004')]), '2.10: Facts::display – numbers with the thousands separator of the language, amounts with the currency');
        preg_match_all(\Talea\Core\Facts::TOKEN_PATTERN, 'Since {{fact.founded}} – {{ fact.projects }} projects, {{fact.Bad}}, {{fact.x}}, {{name}}', $factTokens);
        $this->assertSame(['founded', 'projects'], $factTokens[1], '2.10: the fact token – spaces inside are fine, collection fields and bad keys are not facts');
    }

    /** 2.10: computed facts – years since, counts, the proof rule */
    public function testComputedFacts(): void
    {
        \Talea\Core\Language::set('cs', 'admin-');
        $yearsSince = fn (string $value, string $now): ?int => \Talea\Core\Facts::yearsSince($value, new \DateTimeImmutable($now));
        $this->assertSame([22, 22, 21, 22, 0, null, null, null, null], [
            $yearsSince('2004', '2026-10-02 12:00'), $yearsSince('2004-10-02', '2026-10-02 00:00'), $yearsSince('2004-10-03', '2026-10-02 12:00'), $yearsSince('2004-10-01', '2026-10-02 12:00'),
            $yearsSince('2026', '2026-01-01'), $yearsSince('soon', '2026-10-02'), $yearsSince('2004-13-01', '2026-10-02'), $yearsSince('2030', '2026-10-02'), $yearsSince('2026-12-24', '2026-10-02')], '2.10: years_since – a year, a date on, before and after its anniversary, this year, a bad argument, a date ahead');
        $factApp = (new \ReflectionClass(\Talea\Core\App::class))->newInstanceWithoutConstructor();
        $this->assertSame(['22', null], [\Talea\Core\Facts::computed($factApp, 'years_since', '2004', new \DateTimeImmutable('2026-06-01')), \Talea\Core\Facts::computed($factApp, 'years_since', 'soon')], '2.10: computed() – years since a year as the site shows it, a bad argument is nothing (the audit reports it)');
        preg_match_all(\Talea\Core\Facts::COMPUTED_PATTERN, '{{years_since:2004}} {{ years_since:fact.founded }} {{count:reference-2}} {{count:news}} {{count:Velka}} {{unknown:x}} {{name}}', $computedTokens, PREG_SET_ORDER);
        $this->assertSame(['years_since:2004', 'years_since:fact.founded', 'count:reference-2', 'count:news'], array_map(fn (array $c): string => $c[1] . ':' . $c[2], $computedTokens), '2.10: the computed token – both forms with their arguments, nothing else');
        $this->assertSame([true, false, false, false], [\Talea\Core\Facts::isClaim('Na trhu jsme 22 let.'), \Talea\Core\Facts::isClaim('Na trhu jsme {{years_since:2004}} let.'), // check-english: allow
            \Talea\Core\Facts::isClaim('Máme {{fact.projects}} zakázek.'), \Talea\Core\Facts::isClaim('Otevřeno od 8 hodin.')], '2.10: claims – a sentence with a computed or fact token is not a claim any more'); // check-english: allow
        $proofBuild = ['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'children' => [['id' => 'c1', 'type' => 'counter', 'content' => ['number' => 1500]], ['id' => 'c2', 'type' => 'counter', 'content' => ['number' => '{{fact.projects}}']],
            ['id' => 'c3', 'type' => 'counter', 'content' => ['number' => ' 22 ']], ['id' => 'c4', 'type' => 'counter', 'content' => ['number' => '{{count:reference}}']], ['id' => 'n1', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => '1500']]]]]];
        $this->assertSame([['id' => 'c1', 'number' => '1500'], ['id' => 'c3', 'number' => '22']], \Talea\Core\Facts::typedNumbers($proofBuild), '2.10: proof numbers typed in – counters with digits, not with tokens and not headings');
        [$counterBuild] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'counter', 'content' => ['number' => 1500]], ['type' => 'counter', 'content' => ['number' => '{{years_since:fact.founded}}']]]], false);
        $this->assertSame(['1500', '{{years_since:fact.founded}}'], array_column(array_column($counterBuild['children'], 'content'), 'number'), '2.10: the counter keeps a number and a token alike');
        $this->assertSame("<p>1500+ " . t('spokojených zákazníků') . "</p>\n<p>{{years_since:fact.founded}}+ " . t('spokojených zákazníků') . '</p>', \Talea\Builder\Build::asText($counterBuild), '2.10: the counter is content – its number or token and label are in the text of the build (usage, the audit, the old value)'); // check-english: allow
        $counterContext = new \Talea\Builder\Context($factApp, true);
        $counterHtml = fn (string $number): string => \Talea\Builder\Elements\Counter::render(['tag' => 'div', 'content' => ['number' => $number, 'prefix' => '', 'suffix' => '+', 'caption' => 'zakázek']], '', '', $counterContext); // check-english: allow
        $this->assertSame([true, true, false], \Talea\Core\Language::runWith('cs', fn (): array => [str_contains($counterHtml('1500'), "data-counter=\"1500\">1\u{00A0}500<"),
            str_contains($counterHtml('{{fact.projects}}'), '>{{fact.projects}}<'), str_contains($counterHtml('{{fact.projects}}'), 'data-counter')]), '2.10: the counter – digits count up, the editor shows a token as it is (without the count-up)');
        $this->assertSame(['<p>Write   here,   and {{fact.real}}.</p>'], [
            \Talea\Core\Facts::withoutCode('<p>Write <code>{{fact.key}}</code> here, <pre>{{years_since:2004}}</pre> and {{fact.real}}.</p>')], '2.10.1: tokens inside <code> and <pre> are examples – never filled, not reported');
    }
}
