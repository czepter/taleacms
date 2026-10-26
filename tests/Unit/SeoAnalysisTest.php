<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\ContentCheck;

final class SeoAnalysisTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private static function input(string $html, array $over = []): array
    {
        return $over + [
            'title' => 'Wedding photographer in Leipzig for 2026 couples',
            'description' => 'A wedding photographer who tells your day as a story, with relaxed portraits and honest moments from the first look.',
            'html' => $html,
            'title_is_h1' => false,
            'keywords' => ['wedding photographer'],
            'slug' => 'wedding-photographer',
            'site_host' => 'example.com',
            'keyword_taken' => false,
            'has_outline' => true,
        ];
    }

    private static function filler(int $words): string
    {
        return trim(str_repeat('lorem ipsum dolor sit amet ', intdiv($words, 5) + 1));
    }

    /** @return array<string, array{check: string, ok: bool, message: string}> */
    private static function flat(array $result): array
    {
        $flat = [];
        foreach ($result['groups'] as $checks) {
            foreach ($checks as $check) {
                $flat[$check['check']] = $check;
            }
        }

        return $flat;
    }

    public function testGoodPageScoresHigh(): void
    {
        $filler = self::filler(100);
        $html = '<h1>Photography for couples</h1><p>A wedding photographer for your day. ' . $filler . '</p>'
            . '<h2>Why a wedding photographer</h2><p>' . $filler . '</p>'
            . '<img src="/a.jpg" alt="Wedding photographer at work">'
            . '<p>' . $filler . ' wedding photographer wedding photographer</p>'
            . '<p>' . $filler . ' wedding photographer wedding photographer</p>'
            . '<p>' . $filler . ' wedding photographer wedding photographer</p>'
            . '<p>' . $filler . '</p><p>' . $filler . '</p>'
            . '<p>Read <a href="https://other.org/guide">a guide</a> and <a href="/contact">contact us</a>.</p>';
        $result = ContentCheck::analyse(self::input($html));

        $this->assertSame([], array_keys(array_filter(self::flat($result), fn (array $c): bool => !$c['ok'])));
        $this->assertGreaterThanOrEqual(95, $result['score']);
        foreach ($result['groups']['basics'] as $check) {
            $this->assertTrue($check['ok'], $check['check'] . ': ' . $check['message']);
        }
    }

    public function testNoKeywordNoScore(): void
    {
        $result = ContentCheck::analyse(self::input('<p>Hello</p>', ['keywords' => []]));

        $this->assertNull($result['score']);
        $keys = array_column($result['groups']['basics'], 'check');
        $this->assertContains('title_length', $keys);
        $this->assertNotContains('kw_title', $keys);
    }

    public function testDensity(): void
    {
        $html = '<p>' . self::filler(400) . ' photo shoot</p>';
        $result = self::flat(ContentCheck::analyse(self::input($html, ['keywords' => ['photo shoot']])));

        $this->assertFalse($result['kw_density']['ok']);
        $this->assertStringContainsString('0.5%', $result['kw_density']['message']);
    }

    public function testDensityBoundaryUsesRoundedValue(): void
    {
        $html = '<p>' . trim(str_repeat('lorem ', 103)) . ' photo</p>'; // 1 of 104 words = 0.96 %
        $result = self::flat(ContentCheck::analyse(self::input($html, ['keywords' => ['photo']])));

        $this->assertTrue($result['kw_density']['ok']);
        $this->assertStringContainsString('1.0%', $result['kw_density']['message']);
    }

    public function testNoExternalLinksMakesFollowNotApplicable(): void
    {
        $result = self::flat(ContentCheck::analyse(self::input('<p>See <a href="/contact">contact</a>.</p>')));

        $this->assertArrayNotHasKey('links_out_follow', $result);
        $this->assertFalse($result['links_out']['ok']);
    }

    public function testAddressMatchesWholeSegments(): void
    {
        $match = fn (string $keyword, string $slug): bool => self::flat(ContentCheck::analyse(self::input('<p>x</p>', ['keywords' => [$keyword], 'slug' => $slug])))['kw_address']['ok'];

        $this->assertTrue($match('wedding photographer', 'wedding-photographer-leipzig'));
        $this->assertTrue($match('wedding photographer', 'best-wedding-photographer'));
        $this->assertFalse($match('art', 'party'));
        $this->assertTrue($match('wedding photographer', 'services/wedding-photographer'), 'a subpage: the parent\'s part of the path counts as a boundary');
        $this->assertTrue($match('services', 'services/wedding-photographer'));
        $this->assertFalse($match('photographer', 'services/wedding-photographers'));
    }

    public function testOutlineIsNotApplicableWithoutABuild(): void
    {
        $without = ContentCheck::analyse(self::input('<p>x</p>', ['has_outline' => null]));
        $missing = ContentCheck::analyse(self::input('<p>x</p>', ['has_outline' => false]));

        $this->assertArrayNotHasKey('has_outline', self::flat($without), 'a text page, news or an item has no build to hold an outline');
        $this->assertFalse(self::flat($missing)['has_outline']['ok']);
        $this->assertGreaterThan($missing['score'], $without['score'], 'a check that does not apply costs no points');
    }

    public function testVowelledArabicWords(): void
    {
        $result = self::flat(ContentCheck::analyse(self::input('<p>مَرْحَبًا بِكُمْ هُنَا</p>')));

        $this->assertStringContainsString('3', $result['word_count']['message']);
        $this->assertStringNotContainsString('9', $result['word_count']['message']);
    }

    public function testDiacriticsAndCase(): void
    {
        $result = self::flat(ContentCheck::analyse(self::input('<p>kuchyne NA miru od truhlare</p>', ['keywords' => ['Kuchyně na míru']]))); // check-english: allow

        $this->assertTrue($result['kw_text']['ok']);
    }

    public function testArabicWords(): void
    {
        $result = self::flat(ContentCheck::analyse(self::input('<p>مرحبا بكم هنا</p>')));

        $this->assertStringContainsString('3', $result['word_count']['message']);
    }

    public function testEmptyText(): void
    {
        $result = ContentCheck::analyse(self::input('', ['keywords' => ['x']]));

        $this->assertFalse(self::flat($result)['kw_density']['ok']);
        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testNofollowOnlyFails(): void
    {
        $html = '<p>See <a href="https://a.org" rel="nofollow">a</a> and <a href="https://b.org/x" rel="noopener nofollow">b</a>.</p>';
        $result = self::flat(ContentCheck::analyse(self::input($html)));

        $this->assertTrue($result['links_out']['ok']);
        $this->assertFalse($result['links_out_follow']['ok']);
    }
}
