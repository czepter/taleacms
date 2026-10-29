<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class CodeFilterTest extends TestCase
{
    public function testDomFilter(): void
    {
        $this->assertSame([false, '<iframe src="https://mapy.cz/x"></iframe>'], [str_contains(\Talea\Builder\CodeFilter::code('<scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt>'), '<script'), \Talea\Builder\CodeFilter::code('<iframe src="https://mapy.cz/x"></iframe>')], 'CodeFilter::code: a nested script does not reassemble');
        $this->assertSame('<a>A</a><iframe></iframe>', \Talea\Builder\CodeFilter::code('<a href="javascript:alert(1)" onclick="x()">A</a><iframe srcdoc="data:text/html,x"></iframe>'), 'CodeFilter::code: event handlers and javascript: disappear');
        // 2.5.1: a DOM filter instead of regular expressions – the bypasses of the old filter stay closed
        $codeBypasses = ['<img/onerror=alert(1) src=x>', '<svg/onload=alert(1)>', '<a href=javascript:alert(1)>x</a>', '<a href="&#106;avascript:alert(1)">x</a>',
            '<a href="java&#x09;script:alert(1)">x</a>', '<a href="javascript:alert(\'1\')">x</a>', '<iframe srcdoc="&lt;svg/onload=alert(1)&gt;"></iframe>',
            '<object data="javascript:alert(1)"></object>', '<form action=javascript:alert(1)><button>x</button></form>', '<svg><animate attributeName=href values=javascript:alert(1) /></svg>',
            '<base href="https://evil.example/">', '<meta http-equiv=refresh content="0;url=https://evil.example">', '<div style="background:url(javascript:alert(1))">x</div>',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'];
        $this->assertSame([], array_filter($codeBypasses, fn (string $h): bool => (bool) preg_match('/\son[a-z]+=|javascript:|srcdoc|<base|<meta|<object|<animate|evil\.example/i', \Talea\Builder\CodeFilter::code($h))), 'CodeFilter::code: bypassing the old filter fails');
        $this->assertSame('<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen=""></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{name}}</a>', \Talea\Builder\CodeFilter::code('<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{name}}</a>'), 'CodeFilter::code: map, service form and placeholder values stay');
    }

    /** the CSS checks read the CSS as the browser does: escapes decoded, comments dropped */
    public function testCssEscapesAreDecodedBeforeTheCheck(): void
    {
        $this->assertSame(['<div>x</div>', '<div>y</div>', '', '', '<div style="color: \\72 ed">z</div><style>p{color:#c00}</style>'], [
            \Talea\Builder\CodeFilter::code('<div style="background:\\75 rl(javascript:alert(1))">x</div>'),
            \Talea\Builder\CodeFilter::code('<div style="background:url(java\\script:alert(1))">y</div>'),
            \Talea\Builder\CodeFilter::code('<style>@\\69mport "https://evil.example/x.css";</style>'),
            \Talea\Builder\CodeFilter::code('<style>@im/**/port "https://evil.example/x.css";</style>'),
            \Talea\Builder\CodeFilter::code('<div style="color: \\72 ed">z</div><style>p{color:#c00}</style>'),
        ], 'CodeFilter::code: an escaped url(, javascript: or @import goes; harmless escaped CSS stays as typed');
    }

    /** the CSS reading knows strings and fails closed: a "/*" in a string hides nothing, unreadable or oversized CSS goes */
    public function testCssCheckKnowsStringsAndFailsClosed(): void
    {
        $this->assertSame(['', '<div>x</div>', '', '<style>p::before{content:"/* not a comment */"}</style>'], [
            \Talea\Builder\CodeFilter::code('<style>p{content:"/*"} a{background:url(java\\script:alert(1))} b{content:"*/"}</style>'),
            \Talea\Builder\CodeFilter::code('<div style="content:\'/*\'; background:\\75 rl(javascript:alert(1)); content:\'*/\'">x</div>'),
            \Talea\Builder\CodeFilter::code('<style>p{color:red}' . str_repeat(' ', 600_000) . '</style>'),
            \Talea\Builder\CodeFilter::code('<style>p::before{content:"/* not a comment */"}</style>'),
        ], 'CodeFilter::code: a comment opener inside a string hides no escaped payload; oversized CSS goes; harmless CSS stays');
        $views = new \ReflectionMethod(\Talea\Builder\CodeFilter::class, 'cssViews');
        $this->assertSame([null, ['a{b:/* c */\\75 rl(x)}', 'a{b:/* c */url(x)}', 'a{b:url(x)}', 'a{b:url(x)}'], 'p{content:"/*"}'],
            [$views->invoke(null, "p{color:\xff}"), $views->invoke(null, 'a{b:/* c */\\75 rl(x)}'), $views->invoke(null, 'p{content:"/*"}')[2]],
            'CodeFilter::cssViews: invalid UTF-8 cannot be read (null = removed); the views decode escapes, drop comments, keep strings');
    }

    /** every CSS line break ends a string, and a "/*" inside an unquoted url( is no comment – each reading is checked */
    public function testCssLineBreaksAndUnquotedUrlsHideNothing(): void
    {
        $this->assertSame(['<div>f</div>', '<div>r</div>', '<div>u</div>', '<div style="background:url(/a/*.png)">ok</div>'], [
            \Talea\Builder\CodeFilter::code("<div style='a:\"x\f; b:\"/*\"; background:\\75 rl(javascript:alert(1)); c:\"*/\"'>f</div>"),
            \Talea\Builder\CodeFilter::code("<div style='a:\"x\r; b:\"/*\"; background:\\75 rl(javascript:alert(1)); c:\"*/\"'>r</div>"),
            \Talea\Builder\CodeFilter::code('<div style="background:url(/*);background:\\75 rl(javascript:alert(1));y:url(*/)">u</div>'),
            \Talea\Builder\CodeFilter::code('<div style="background:url(/a/*.png)">ok</div>'),
        ], 'CodeFilter::code: a string ended by \\f or \\r and a "/*" inside url( hide no escaped payload; a harmless url stays');
    }
}
