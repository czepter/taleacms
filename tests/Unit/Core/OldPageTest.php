<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Talea\Core\OldPage;

/** read_old_page: an old page in sections with the look of each, without a network (the page analysis is pure). */
final class OldPageTest extends TestCase
{
    private static function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/old-pages/styled-page.html');
    }

    /** @return array<string, mixed> */
    private static function analyse(?int $only = null, string $format = 'sections'): array
    {
        return OldPage::analyse(self::fixture(), 'https://old.example/', ['a.x { color: #0f0 }'], 1, $only, $format);
    }

    public function testThePageIsCutIntoHeaderBlocksAndFooter(): void
    {
        $page = self::analyse();
        $this->assertSame(['header', 'section', 'section', 'section', 'footer'], array_column($page['sections'], 'role'), 'the wrappers around main are descended');
        $this->assertSame([0, 1, 2, 3, 4], array_column($page['sections'], 'index'));
        $this->assertSame(['', 'Hand-made furniture', 'About us', 'Questions', ''], array_column($page['sections'], 'heading'));
        $this->assertSame(['Old Oak | Furniture', 'Furniture by hand', 'en', 'https://old.example/img/share.png'], [$page['title'], $page['description'], $page['language'], $page['share_image']]);
        $this->assertSame(['read' => 1, 'not_read' => 1], $page['stylesheets']);
        $html = implode('', array_column($page['sections'], 'html'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('href="https://old.example/contact"', $html, 'addresses are absolute');
        $this->assertSame(['accordion' => 1], $page['widgets']);
        $this->assertSame(['accordion'], $page['sections'][3]['widgets']);
    }

    public function testTheLookOfASectionComesFromItsOwnClasses(): void
    {
        $page = self::analyse();
        $hero = $page['sections'][1];
        $this->assertSame(['#1a5c3a', '#ffffff', ['Lora']], [$hero['background'], $hero['color'], $hero['fonts']], 'a variable is resolved one level');
        $this->assertSame('#ede8de', $page['sections'][2]['background'], 'hsl() becomes hex');
        $this->assertSame(['#111111', '#ffffff'], [$page['sections'][4]['background'], $page['sections'][4]['color']], 'rgba() becomes hex');
        $this->assertSame(['--brand' => '#1a5c3a', '--soft' => 'rgb(240, 236, 228)'], $page['css_variables']);
        $this->assertContains('#1a5c3a', $page['colors']);
        $this->assertSame('#1a5c3a', $page['colors'][0], 'the palette is by use, most used first');
        $this->assertContains('Lora', $page['fonts']);
        $this->assertContains('Open Sans', $page['fonts']);
    }

    public function testOneSectionAloneAndTheSizeLimits(): void
    {
        $page = self::analyse(2);
        $this->assertSame([2], array_column($page['sections'], 'index'), 'only the section asked for');
        $big = '<html><body><main><section><h2>Big</h2>' . str_repeat('<p>' . str_repeat('word ', 40) . '</p>', 200) . '</section><section><p>Second</p></section></main></body></html>';
        $all = OldPage::analyse($big, 'https://old.example/');
        $this->assertTrue($all['sections'][0]['truncated']);
        $this->assertLessThanOrEqual(OldPage::SECTION_BYTES, strlen($all['sections'][0]['html']));
        $alone = OldPage::analyse($big, 'https://old.example/', [], 0, 0);
        $this->assertGreaterThan(OldPage::SECTION_BYTES, strlen($alone['sections'][0]['html']));
        $many = '<html><body><main>' . str_repeat('<section><p>' . str_repeat('x', 11000) . '</p></section>', 20) . '</main></body></html>';
        $total = array_sum(array_map(fn (array $s): int => strlen($s['html']), OldPage::analyse($many, 'https://old.example/')['sections']));
        $this->assertLessThanOrEqual(OldPage::TOTAL_BYTES, $total, 'the pages together are capped');
        $this->assertSame(OldPage::MAX_SECTIONS, count(OldPage::analyse('<html><body><main>' . str_repeat('<section><p>a</p></section>', 45) . '</main></body></html>', 'https://old.example/')['sections']));
    }

    public function testTheCssScannerIsLinearAndBounded(): void
    {
        $css = str_repeat('.a-b-c .d { color: #123456; background: url(x.png) } @media (max-width: 600px) { .e { color: red } } /* c */ ', 4000);
        $start = microtime(true);
        $rules = OldPage::rules($css, microtime(true) + 3.0);
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertCount(OldPage::MAX_RULES, $rules, 'it stops at the limit');
        $this->assertSame(['.a-b-c .d', ' color: #123456; background: url(x.png) ', false], $rules[0]);
        $this->assertSame(['.e', true], [$rules[1][0], $rules[1][2]], 'rules inside @media are read and marked');
        $this->assertSame([], OldPage::rules('@font-face { font-family: x; src: url(y) } @keyframes k { from { top: 0 } to { top: 1px } }', microtime(true) + 1));
        $this->assertSame([], OldPage::rules(str_repeat('.a { color: red }', 100), microtime(true) - 1), 'the deadline stops it');
    }

    public function testSemanticSectionsConvertToElements(): void
    {
        $inside = function (string $name): string {
            preg_match('#<main>(.*)</main>#s', (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/old-pages/' . $name), $m);

            return $m[1];
        };
        $page = '<html><body><div id="wrap"><main>'
            . '<section class="x" style="color:red"><h2>Questions</h2>' . $inside('aria-accordion.html') . '</section>'
            . '<section><i class="icon"></i><span class="deco"></span><svg><path d="M0 0"/></svg><script>x()</script><img src="/p.gif" width="1" height="1"><h2>Wood</h2>' . $inside('aria-tabs.html') . '</section>'
            . '<section>' . $inside('counters.html') . $inside('frames.html') . $inside('forms.html') . '</section>'
            . '</main></div></body></html>';
        $result = OldPage::analyse($page, 'https://old.example/', [], 0, null, 'semantic');
        $html = implode('', array_column($result['sections'], 'html'));
        foreach (['class=', 'style=', '<script', '<svg', 'onclick', 'width="1"', 'icon'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('<section><h2>Questions</h2>', $result['sections'][0]['html'], 'the wrappers are gone, the heading stays');
        $this->assertSame(['accordion' => 1, 'tabs' => 1, 'counter' => 2, 'video' => 1, 'map' => 3, 'embed' => 1, 'frame' => 1, 'form' => 1, 'unreadable_form' => 1], $result['widgets'] + []);

        $build = \Talea\Builder\HtmlConverter::convert($html)['build'];
        $this->assertSame([], \Talea\Builder\Build::sanitize($build, false)[1]);
        $types = [];
        $walk = function (array $children) use (&$walk, &$types): void {
            foreach ($children as $el) {
                $types[$el['type']] = ($types[$el['type']] ?? 0) + 1;
                $walk($el['children'] ?? []);
            }
        };
        $walk($build['children']);
        foreach (['faq', 'tabs', 'counter', 'map', 'form', 'video', 'embed'] as $type) {
            $this->assertArrayHasKey($type, $types, $type);
        }
        $this->assertStringContainsString('[form whose fields a script draws', $html, 'what could not be read is a bracketed note');
    }
}
