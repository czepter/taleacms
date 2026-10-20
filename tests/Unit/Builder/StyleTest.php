<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testFromCssColours(): void
    {
        $this->assertSame(['border_color' => '#F6F4EE'], \Talea\Builder\Style::fromCss('border-color', '#F6F4EE'), 'Style::fromCss: border colour (also for the hover state)');
        $this->assertSame(\Talea\Builder\Style::fromCss('background-color', '#EFECE5'), \Talea\Builder\Style::fromCss('background', '#EFECE5'), 'Styl::zCss: zkratka background jen s barvou');
        $this->assertNull(\Talea\Builder\Style::fromCss('background', 'url(a.png) no-repeat'), 'Style::fromCss: a background with an image stays out');
    }

    public function testScrollMotionAndHoverEffect(): void
    {
        // 2.7: a reveal and a motion while scrolling run together; a hover effect gets its own rule, its motion only without reduced motion
        $motion = \Talea\Builder\Style::css('#a', ['base' => ['animation' => 'tl-from-left', 'scroll_motion' => 'tl-parallax', 'hover_effect' => 'lift']]);
        $this->assertSame([true, true, true, ''], [str_contains($motion, 'animation: tl-from-left linear both, tl-parallax linear both; animation-timeline: view(), view(); animation-range: entry 0% cover 28%, cover 0% cover 100%'),
            str_contains($motion, '@media (prefers-reduced-motion: no-preference) { #a { transition:'), str_contains($motion, '#a:is(:hover, :focus-visible) { translate: 0 -4px;'),
            \Talea\Builder\Style::css('#b', ['base' => ['animation' => 'none', 'scroll_motion' => 'tl-nonsense']])], '2.7: scroll motion and hover effect in the CSS');
    }

    public function testSanitizeAndCss(): void
    {
        $buildStyleErrors = [];
        $this->assertSame(['base' => ['width' => '50%']], \Talea\Builder\Style::sanitize(['base' => ['color' => 'red;}body{x:y', 'neznama' => '1', 'width' => '50%'], 'tisk' => []], 's', $buildStyleErrors), 'Style::sanitize: pasted CSS, an unknown property and state drop out');
        $this->assertSame(['s.base.color', 's.base.neznama', 's.tisk'], array_keys($buildStyleErrors), 'Styl::vycisti: chyby');
        $this->assertSame("#s-a { padding-block: var(--tl-space-xl); color: var(--tl-color-primary); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n", \Talea\Builder\Style::css('#s-a', ['base' => ['padding_y' => 'xl', 'color' => 'primary', 'columns' => '3'], 'mobile' => ['columns' => '1'], 'hover' => ['color' => '#ff0000']]), 'Styl::css: tokeny, sloupce, hover a breakpoint');
    }

    public function testStatesTypographyShadowsAndGrid(): void
    {
        $this->assertSame("@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--tl-space-s); } #x:is(:hover, :focus-visible) { color: var(--tl-color-primary); } }\n", \Talea\Builder\Style::css('#x', ['mobile' => ['gap' => 's'], 'hover_mobile' => ['color' => 'primary'], 'active_tablet' => ['scale' => '0.95']]), 'Style::css: hover and press separately for tablet and mobile');
        $this->assertSame("#x { font: var(--tl-type-eyebrow); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--tl-step-3); }\n", \Talea\Builder\Style::css('#x', ['base' => ['font_size' => '3', 'text_style' => 'eyebrow']]), 'Style::css: the typography style first, single properties refine it');
        $this->assertSame(['0 8px 24px 0 var(--tl-color-primary)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--tl-color-primary)'], [\Talea\Builder\Style::value('shadow', '0 8px 24px 0 primary'), \Talea\Builder\Style::value('shadow', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), \Talea\Builder\Style::value('border', '2px dashed primary')], 'Style: a custom shadow and border with colour tokens');
        $this->assertSame([null, null, null], [\Talea\Builder\Style::value('shadow', '0 0 1px url(x)'), \Talea\Builder\Style::value('shadow', '0 0 red; color: red'), \Talea\Builder\Style::value('border', '1px solid red}')], 'Style: shadow and border let nothing dangerous through');
        $this->assertSame(// check-english: allow
            ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null], [\Talea\Builder\Style::value('rows', '3'), \Talea\Builder\Style::value('areas', 'hlava hlava / bok obsah'), \Talea\Builder\Style::value('areas', 'a b / c'), \Talea\Builder\Style::value('area', 'bok'), \Talea\Builder\Style::value('area', 'x"y')], 'Style: grid – rows, areas and the element\'s area'); // check-english: allow
        $this->assertSame([true, true, []], [str_contains(\Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::sanitize([])), '--tl-type-lead: 400 var(--tl-step-1)/1.55 var(--tl-font-body);'),
            str_contains(\Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::sanitize(['typography' => ['lead' => ['step' => '2', 'weight' => '500'], 'title' => ['step' => '99']]])), '--tl-type-lead: 500 var(--tl-step-2)/1.55'),
            \Talea\Builder\DesignSystem::sanitize(['typography' => ['title' => ['step' => '99']]])['typography']], 'DesignSystem: typography styles as tokens, edited in Appearance');
        $this->assertTrue(str_contains(\Talea\Builder\Style::css('#s', ['base' => ['background_image' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), 'Style::css: a background image from Media from the installation root');
    }

    public function testDarkModeAndCustomCss(): void
    {
        $this->assertTrue(str_contains(\Talea\Builder\Style::css('#s', ['base' => ['background' => 'white']]), '--tl-color-text: var(--tl-color-text-light); color: var(--tl-color-text-light)'), 'Style::css: a white background carries dark text in dark mode too');
        $this->assertFalse(str_contains(\Talea\Builder\Style::css('#s', ['base' => ['background' => 'white', 'color' => 'primary']]), 'text-svetle'), 'Style::css: a custom text colour on white is not overridden');
        $buildDiscarded = [];
        $this->assertSame('color: red; --tl-x: 1;', \Talea\Builder\Style::customCss('color:red; background:url(javascript:x); --tl-x: 1; @import url(x); width: expression(1); a{b:c}', $buildDiscarded), 'Style::customCss: only safe declarations');
        $this->assertSame(4, count($buildDiscarded), 'Style::customCss: dropped ones are reported');
    }

    public function testFromCssTokensShorthandsAndGrid(): void
    {
        $this->assertSame([['padding_y' => 'l', 'padding_x' => '2rem'], ['margin_top' => '0', 'margin_bottom' => '0', 'center' => 'auto'], ['columns' => 'auto:16rem'], ['color' => 'muted'], ['font_size' => '-1'], null, null], [\Talea\Builder\Style::fromCss('padding', 'var(--tl-space-l) 2rem'), \Talea\Builder\Style::fromCss('margin', '0 auto'), \Talea\Builder\Style::fromCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
            \Talea\Builder\Style::fromCss('color', 'var(--tl-color-muted)'), \Talea\Builder\Style::fromCss('font-size', 'var(--tl-step--1)'), \Talea\Builder\Style::fromCss('color', 'expression(1)'), \Talea\Builder\Style::fromCss('filter', 'blur(2px)')], 'Style::fromCss: tokens, shorthands and grid');
    }
}
