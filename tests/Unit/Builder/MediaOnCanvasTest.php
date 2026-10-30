<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\Renderer;
use Talea\Builder\Style;

/** Visual compose phase C: the focal point of a placed image and of a background (docs/specs/visual-compose.md, C4). */
final class MediaOnCanvasTest extends TestCase
{
    public function testFocalValues(): void
    {
        $this->assertSame(['base' => ['focal_point' => '62% 38%', 'background_focal' => '0% 100%']], Style::sanitize(['base' => ['focal_point' => '62% 38%', 'background_focal' => '0% 100%']]), 'whole numbers 0–100 are accepted');
        foreach (['101% 50%', '50% 101%', '-1% 5%', '50 50', '50%', '50% 50', '5.5% 50%', 'center', '50% 50%; x', 'top left', '050% 50%'] as $bad) {
            $errors = [];
            $this->assertSame([], Style::sanitize(['base' => ['focal_point' => $bad]], '', $errors), "rejected, not clamped: $bad");
            $this->assertCount(1, $errors, "reported: $bad");
        }
    }

    public function testCssPerState(): void
    {
        $this->assertSame("#x { object-position: 62% 38%; --tl-focal: 62% 38%; }\n@media (max-width: 1023px) { #x { object-position: 50% 0%; --tl-focal: 50% 0%; } }\n",
            Style::css('#x', ['base' => ['focal_point' => '62% 38%'], 'tablet' => ['focal_point' => '50% 0%']]), 'object-position and the custom property, per breakpoint');
        $this->assertSame("#x { background-image: linear-gradient(var(--tl-overlay, transparent), var(--tl-overlay, transparent)), url(\"/media/a.jpg\"); background-size: cover; background-position: 20% 80%; --tl-bg-focal: 20% 80%; }\n",
            Style::css('#x', ['base' => ['background_image' => 'media/a.jpg', 'background_focal' => '20% 80%']]), 'the point replaces the fixed centre');
        $this->assertSame("@media (max-width: 767px) { #x { background-position: 10% 10%; --tl-bg-focal: 10% 10%; } }\n",
            Style::css('#x', ['mobile' => ['background_focal' => '10% 10%']]), 'a state without an image of its own still writes the position');
        $this->assertStringContainsString('background-position: center;', Style::css('#x', ['mobile' => ['background_image' => 'media/b.jpg']]), 'no point: the centre, as before');
    }

    public function testNothingLeaksIntoPagesThatDoNotUseIt(): void
    {
        $plain = ['base' => ['background_image' => 'media/a.jpg', 'width' => '100%', 'aspect_ratio' => '16/9']];
        $css = Style::css('#x', $plain);
        $this->assertStringNotContainsString('focal', $css);
        $this->assertStringNotContainsString('--tl-', str_replace('--tl-overlay', '', $css));
        $this->assertStringContainsString('background-position: center;', $css);
    }

    public function testSanitizeDropsMisplacedPoints(): void
    {
        [$clean, $errors] = Build::sanitize(['children' => [
            ['id' => 'mc1', 'type' => 'image', 'content' => ['src' => 'media/a.jpg'], 'style' => [
                'base' => ['focal_point' => '10% 20%', 'background_focal' => '1% 1%'], 'hover' => ['focal_point' => '1% 1%', 'width' => '50%'], 'active_mobile' => ['focal_point' => '1% 1%'],
                'mobile' => ['focal_point' => '101% 5%']]],
            ['id' => 'mc2', 'type' => 'heading', 'content' => ['text' => 'A'], 'style' => ['base' => ['focal_point' => '10% 20%']]],
            ['id' => 'mc3', 'type' => 'section', 'style' => ['base' => ['background_image' => 'media/a.jpg', 'background_focal' => '30% 70%'], 'hover' => ['background_focal' => '5% 5%'], 'tablet' => ['background_focal' => '40% 40%']]],
            ['id' => 'mc4', 'type' => 'section', 'content' => ['background_video' => 'media/v.mp4'], 'style' => ['base' => ['background_focal' => '30% 70%']]],
            ['id' => 'mc5', 'type' => 'section', 'style' => ['base' => ['background_focal' => '30% 70%']]],
        ]]);
        $this->assertSame(['base' => ['focal_point' => '10% 20%'], 'hover' => ['width' => '50%']], $clean['children'][0]['style'], 'an image keeps the point in base/tablet/mobile; the point of a background, hover and active states and a bad value go');
        $this->assertArrayNotHasKey('style', $clean['children'][1], 'a heading has no focal point');
        $this->assertSame(['base' => ['background_image' => 'media/a.jpg', 'background_focal' => '30% 70%'], 'tablet' => ['background_focal' => '40% 40%']], $clean['children'][2]['style'], 'a background point stays with a background image, not in hover');
        $this->assertSame(['base' => ['background_focal' => '30% 70%']], $clean['children'][3]['style'], 'a background video counts as a background');
        $this->assertArrayNotHasKey('style', $clean['children'][4], 'no background, no point');
        foreach (['children[0].style.base.background_focal', 'children[0].style.hover.focal_point', 'children[0].style.active_mobile.focal_point', 'children[0].style.mobile.focal_point',
            'children[1].style.base.focal_point', 'children[2].style.hover.background_focal', 'children[4].style.base.background_focal'] as $path) {
            $this->assertArrayHasKey($path, $errors, "reported: $path");
        }
    }

    public function testPlacedImageCropReachesThePhoto(): void
    {
        $app = new \Talea\Core\App([]);
        $style = ['base' => ['aspect_ratio' => '16/9', 'object_fit' => 'cover', 'focal_point' => '30% 70%']];
        $render = function (array $content) use ($app, $style): array {
            $k = new Context($app);
            $k->source = 'page:1';
            $html = Renderer::html(Build::sanitize(['children' => [['id' => 'im1', 'type' => 'image', 'content' => ['src' => 'media/2026/10/a.jpg'] + $content, 'style' => $style]]])[0], $k);

            return [$html, $k->css];
        };
        [$html] = $render(['caption' => 'Cap']);
        $this->assertStringContainsString('<figure id="s-im1" class="tl-figure"><img src', $html, 'caption: the element (and so its style) sits on the figure');
        [$html] = $render(['link' => '/x']);
        $this->assertStringContainsString('<a id="s-im1" class="tl-linked" href="/x"><img', $html, 'link: the element sits on the anchor');
        [$html, $css] = $render([]);
        $this->assertStringContainsString('<img id="s-im1" src', $html, 'plain image: the style is on the photo itself');
        $this->assertStringContainsString('#s-im1 { aspect-ratio: 16/9; object-fit: cover; object-position: 30% 70%; --tl-focal: 30% 70%; }', $css);

        $base = \Talea\Builder\Elements\Image::baseCss();
        $this->assertStringContainsString(':where(.tl-figure, .tl-linked, .tl-figure > a) > img { aspect-ratio: inherit; object-fit: inherit; object-position: inherit; }', $base, 'the crop group is handed down from the wrapper to the photo');
    }

    public function testBackgroundVideoFollowsTheBackgroundPoint(): void
    {
        $this->assertStringContainsString('object-position: var(--tl-bg-focal, center);', \Talea\Builder\Elements\Section::baseCss(), 'the video reads the point from the base CSS, not from an inline style');
    }
}
