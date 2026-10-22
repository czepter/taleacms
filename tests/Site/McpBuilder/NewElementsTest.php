<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** New builder elements: breadcrumbs, icon, gallery, tabs, carousel, map, accordion (was: section 31). */
#[Group('site')]
final class NewElementsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testNewElementsPassTheValidatorAndAreShown(): void
    {
        $text = $this->rawText('save_build', ['id' => $this->site()->publicId('pages', $this->zPage()), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'breadcrumbs'],
            ['type' => 'icon', 'content' => ['icon' => 'phone', 'shape' => 'circle']],
            ['type' => 'gallery', 'content' => ['photos' => [['src' => 'media/2026/01/a.jpg', 'alt' => 'Workshop'], ['src' => 'media/2026/01/b.jpg', 'alt' => '']]]],
            ['type' => 'tabs', 'content' => ['tabs' => [['name' => 'Basics', 'content' => '<p>A</p>'], ['name' => 'Plus', 'content' => '<p>B</p>']]]],
            ['type' => 'carousel', 'content' => ['per_view' => '2'], 'children' => [['type' => 'text', 'content' => ['html' => '<p>Slide</p>']]]],
            ['type' => 'carousel', 'content' => ['navigation' => 'scrollbar', 'arrow_position' => 'below'], 'children' => [['type' => 'text', 'content' => ['html' => '<p>Old slide</p>']]]],
            ['type' => 'map', 'content' => ['address' => 'Brno, Freedom Square']],
            ['type' => 'faq', 'content' => ['single_open' => true, 'faq_schema' => false, 'items' => [['question' => 'What?', 'answer' => '<p>That.</p>']]]],
        ]]]]]);
        $this->assertStringContainsString('"errors":[]', $text, 'the new elements pass the validator');
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');
        foreach (['class="tl-breadcrumbs"', 'aria-current="page">Z HTML', 'class="tl-icon tl-icon--circle" aria-hidden="true"><svg', 'class="tl-gallery"', 'alt="Workshop"', 'role="tablist"', 'aria-controls="zp-', 'data-carousel', '--tl-per-view:2', 'data-insert="https://maps.google.com/maps?q=Brno', 'name="faq-'] as $pattern) {
            $this->assertStringContainsString($pattern, $body, "new element on the site: $pattern");
        }
        // the carousel navigation: arrows beside the slides and dots without a scrollbar by default; the scrollbar stays only when chosen
        $this->assertStringContainsString('class="tl-carousel tl-slider tl-slider--no-scrollbar tl-slider--sides"', $body);
        $this->assertStringContainsString('class="tl-carousel tl-slider tl-slider--below"', $body);
        $this->assertSame(1, substr_count($body, 'class="tl-slider-dots"'), 'dots only where chosen');
        $this->assertSame(2, substr_count($body, 'class="tl-slider-arrows"'));
        $this->assertSame(1, substr_count($body, '.tl-slider-dots button::before'), 'the slider CSS once per page, in the builder layer');
        $this->assertStringContainsString('var(--tl-slider-dot-active-color, var(--tl-color-primary))', $body, 'the look can be set with --tl-slider-* properties');
        $this->assertStringContainsString('"BreadcrumbList"', $body, 'breadcrumbs for search engines too');
        $this->assertStringNotContainsString('"FAQPage"', $body, 'an accordion with faq off has no FAQPage data');
    }
}
