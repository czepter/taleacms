<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class SitePartsTest extends TestCase
{
    public function testHeaderOnScroll(): void
    {
        // 2.7: the header that is transparent at the top (and/or smaller after scrolling) – only in the header site part, CSS only when used
        $headerApp = new \Talea\Core\App([]);
        $headerBuild = ['v' => 1, 'children' => [['id' => 'hl1', 'type' => 'section', 'tag' => 'header', 'content' => ['on_scroll' => 'transparent_shrink', 'text_at_top' => 'light'], 'style' => ['base' => ['position' => 'sticky', 'background' => 'background']], 'children' => []]]];
        [$headerBuild] = \Talea\Builder\Build::sanitize($headerBuild);
        $headerContext = new \Talea\Builder\Context($headerApp);
        $headerContext->source = 'part:header:';
        $headerHtml = \Talea\Builder\Build::html($headerBuild, $headerContext);
        $headerCss = \Talea\Builder\Build::css((new \ReflectionClass(\Talea\Core\Db::class))->newInstanceWithoutConstructor(), $headerContext);
        $this->assertSame([true, true, true, true, true], [
            (bool) preg_match('/<header id="s-hl1" class="tl-header-scroll tl-header-scroll--transparent">/', $headerHtml),
            (bool) preg_match('/#s-hl1 \{ [^}]*position: sticky;[^}]*background-color: var\(--tl-color-background\); position: fixed; top: 0; inset-inline: 0; animation: tl-header-light linear both, tl-header-smaller linear both; animation-timeline: scroll\(root\); animation-range: 0 120px; \}/', $headerContext->css),
            str_contains($headerCss, '@keyframes tl-header-light'), str_contains($headerCss, '@keyframes tl-header-smaller { to { padding-block:'), str_contains($headerCss, 'prefers-reduced-motion: reduce) { .tl-header-scroll { animation: none !important; } }'),
        ], 'Sections on scroll: header classes, fixed position after the style (sticky), scroll animation, light text on top');
        $pageContext = new \Talea\Builder\Context($headerApp);
        $pageContext->source = 'page:5';
        $this->assertSame([false, false, false], [str_contains(\Talea\Builder\Build::html($headerBuild, $pageContext), 'tl-header'), str_contains($pageContext->css, 'animation'),
            str_contains(\Talea\Builder\Build::css((new \ReflectionClass(\Talea\Core\Db::class))->newInstanceWithoutConstructor(), $pageContext), 'tl-header')], 'Sections on scroll: outside the header nothing shows and no CSS is printed');
        $shrinkOnly = ['v' => 1, 'children' => [['id' => 'hl2', 'type' => 'section', 'content' => ['on_scroll' => 'shrink'], 'children' => []]]];
        $shrinkContext = new \Talea\Builder\Context($headerApp);
        $shrinkContext->source = 'part:header:kampan';
        $this->assertSame([true, false, true], [
            str_contains(\Talea\Builder\Build::html(\Talea\Builder\Build::sanitize($shrinkOnly)[0], $shrinkContext), '<section id="s-hl2" class="tl-header-scroll">'),
            str_contains($shrinkContext->css, 'position: fixed'), str_contains($shrinkContext->css, '#s-hl2 { animation: tl-header-smaller linear both;'),
        ], 'Sections on scroll: only shrinking keeps the header in the flow (no position: fixed), an element without style still gets an id and a rule');
    }

    public function testPageContentKeepsLayoutSpacing(): void
    {
        // the build's margin reset leaves the system content inside the Page content element of a wrapper alone (news paragraphs keep the layout's spacing)
        $wrapperBuild = ['v' => 1, 'children' => [['id' => 'pc1', 'type' => 'section', 'children' => [['id' => 'pc2', 'type' => 'page_content']]], ['id' => 'pc3', 'type' => 'section', 'children' => [['id' => 'pc4', 'type' => 'text', 'content' => ['html' => '<p>[Call to action]</p>']]]]]];
        $wrapperContext = new \Talea\Builder\Context(new \Talea\Core\App([]));
        $wrapperContext->source = 'part:news_item:';
        $wrapperContext->content = '<p>[First paragraph]</p><p>[Second paragraph]</p>';
        $wrapperHtml = \Talea\Builder\Build::html(\Talea\Builder\Build::sanitize($wrapperBuild)[0], $wrapperContext);
        $wrapperCss = \Talea\Builder\Build::css((new \ReflectionClass(\Talea\Core\Db::class))->newInstanceWithoutConstructor(), $wrapperContext);
        preg_match_all('/^([^{}\n]*)\{[^}\n]*margin-block: 0;/m', $wrapperCss, $wrapperResets);
        $this->assertSame([true, [':where(.build) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr):not(:where(.content *))']], [
            (bool) preg_match('/class="wrap content"><p>\[First paragraph\]<\/p><p>\[Second paragraph\]<\/p>/', $wrapperHtml),
            array_map('trim', $wrapperResets[1]),
        ], 'Page content in a wrapper: the content sits in .wrap.content, the only margin reset of the build skips everything inside .content and keeps zero specificity');
    }
}
