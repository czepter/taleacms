<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Core\Html;

/** The security hardening batch of #151: one test per item. */
final class SecurityHardeningTest extends TestCase
{
    /** content cannot reuse an id of the site frame (skip link target, menu, cookie bar) or a global of its scripts */
    public function testContentIdsDoNotTakeOverTheSiteFrame(): void
    {
        $this->assertSame([true, true, true, false, false, false, false, false, false],
            array_map(Html::isSafeId(...), ['price-list', 'über-uns', 'fn:1', 'main', 'Navigation', 'cookies-bar', 'dataLayer', 'tl-toc', 'taleaBuilder']),
            'Html::isSafeId: anchors stay, the frame\'s ids and the system namespace go');
        $this->assertSame('<p id="price-list">a</p><p>b</p><h2>c</h2><a>d</a>',
            Html::safe('<p id="price-list">a</p><p id="main">b</p><h2 id="cookies-bar">c</h2><a id="dataLayer">d</a>'),
            'Html::safe drops an id the site frame uses');
        [$build, $errors] = Build::sanitize(['children' => [['type' => 'section', 'anchor' => 'navigation'], ['type' => 'section', 'anchor' => 'tl-toc'], ['type' => 'section', 'anchor' => 'prices']]], false);
        $this->assertSame([null, null, 'prices', ['children[0].anchor', 'children[1].anchor']], [$build['children'][0]['anchor'] ?? null, $build['children'][1]['anchor'] ?? null,
            $build['children'][2]['anchor'] ?? null, array_keys($errors)], 'Build::sanitize: an anchor the site frame uses is left out and reported');
    }

    /** an uploaded SVG keeps no XML comments: inlined into HTML, a comment can turn into markup */
    public function testSvgLosesItsComments(): void
    {
        $svg = (string) \Talea\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><!--><img src=x onerror=alert(1)>--><g><!-- note --><path d="M0 0h1"/></g></svg>');
        $this->assertSame([false, false, true], [str_contains($svg, '<!--'), str_contains($svg, 'onerror'), str_contains($svg, '<path d="M0 0h1"/>')], 'Svg::sanitize drops comments, the drawing stays');
    }
}
