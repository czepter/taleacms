<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class HtmlConverterTest extends TestCase
{
    public function testConvert(): void
    {
        $fromHtml = \Talea\Builder\HtmlConverter::convert('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
            . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>' // check-english: allow
            . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>'); // check-english: allow
        $fromHtmlTypes = fn (array $children): array => array_map(fn (array $p): string => $p['type'] . '<' . $p['tag'] . '>', $children);
        $this->assertSame(['heading<h1>', 'text<div>', 'button<a>'], $fromHtmlTypes($fromHtml['build']['children'][0]['children']), 'HtmlConverter: a section from <header>, an inner wrapper without style drops');
        $this->assertSame('<p>Jedna.</p><p>Dvě.</p>', $fromHtml['build']['children'][0]['children'][1]['content']['html'], 'HtmlConverter: consecutive paragraphs in one Text element'); // check-english: allow
        $this->assertSame(['outline', ['btn', 'btn-outline']], [$fromHtml['build']['children'][0]['children'][2]['content']['variant'], $fromHtml['build']['children'][0]['children'][2]['classes']], 'HtmlConverter: a button with a variant by class');
        $this->assertSame(['text<div>', 'faq<div>', 'form<form>'], $fromHtmlTypes($fromHtml['build']['children'][1]['children']), 'HtmlConverter: loose elements at the end are wrapped in a section, details → FAQ, form → Form');
        $this->assertSame(['hero' => 'padding: 2rem;'], $fromHtml['classes'], 'HtmlConverter: a class from <style> only with safe declarations');
        $this->assertSame(6, count($fromHtml['notes']), 'HtmlConverter: reports about @media, a complex selector, url(), a form, SVG and a script');
        $fromHtml2 = \Talea\Builder\HtmlConverter::convert('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--tl-space-l) } .karta:hover { box-shadow: var(--tl-shadow-m); transform: translateY(-4px) }'
            . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--tl-space-m) } .karta { padding: var(--tl-space-m) var(--tl-space-s) } }'
            . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="card"><h3>A</h3></div><div>B</div></div></section>');
        $this->assertSame(['mriz' => ['tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1', 'gap' => 'm']],
            'karta' => ['mobile' => ['padding_y' => 'm', 'padding_x' => 's'], 'hover' => ['shadow' => 'm', 'translate' => '0 -4px']]], $fromHtml2['class_styles'], 'HtmlConverter: @media (max-width) and :hover as class states');
        $this->assertSame([[], 'flex'], [$fromHtml2['build']['children'][0]['children'][0]['style'], $fromHtml2['build']['children'][0]['children'][0]['children'][1]['style']['base']['display'] ?? null], 'HtmlConverter: an element with a styled class has no default style (it would override the class), without a class it has');
        $this->assertSame(1, count(array_filter($fromHtml2['notes'], fn (string $h): bool => str_contains($h, 'min-width'))), 'HtmlConverter: mobile-first @media (min-width) is reported');
        $this->assertSame([], \Talea\Builder\Build::sanitize($fromHtml['build'])[1], 'HtmlConverter: the result passes the validator without errors');
        $this->assertSame("<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>", \Talea\Builder\Build::asText($fromHtml['build']), 'Build::asText: semantic content without layout'); // check-english: allow
    }
}
