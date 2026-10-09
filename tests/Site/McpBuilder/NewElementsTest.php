<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** New builder elements: breadcrumbs, icon, gallery, tabs, carousel, map, accordion (was: section 31 "nové prvky builderu"). */
#[Group('site')]
final class NewElementsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testNewElementsPassTheValidatorAndAreShown(): void
    {
        $text = $this->rawText('stavba_uloz', ['id' => $this->zPage(), 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'breadcrumbs'],
            ['type' => 'icon', 'content' => ['icon' => 'phone', 'shape' => 'circle']],
            ['type' => 'gallery', 'content' => ['photos' => [['src' => 'media/2026/01/a.jpg', 'alt' => 'Dílna'], ['src' => 'media/2026/01/b.jpg', 'alt' => '']]]],
            ['type' => 'tabs', 'content' => ['tabs' => [['name' => 'Základ', 'content' => '<p>A</p>'], ['name' => 'Plus', 'content' => '<p>B</p>']]]],
            ['type' => 'carousel', 'content' => ['per_view' => '2'], 'children' => [['type' => 'text', 'content' => ['html' => '<p>Snímek</p>']]]],
            ['type' => 'map', 'content' => ['address' => 'Brno, Náměstí Svobody']],
            ['type' => 'faq', 'content' => ['single_open' => true, 'faq_schema' => false, 'items' => [['question' => 'Co?', 'answer' => '<p>To.</p>']]]],
        ]]]]]);
        $this->assertStringContainsString('"chyby":[]', $text, 'the new elements pass the validator');
        $this->site()->clearPageCache();

        $body = $this->visit('/z-html');
        foreach (['class="ka-drobecky"', 'aria-current="page">Z HTML', 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg', 'class="ka-galerie"', 'alt="Dílna"', 'role="tablist"', 'aria-controls="zp-', 'data-karusel', '--ka-naraz:2', 'data-vlozit="https://maps.google.com/maps?q=Brno', 'name="faq-'] as $pattern) {
            $this->assertStringContainsString($pattern, $body, "new element on the site: $pattern");
        }
        $this->assertStringContainsString('"BreadcrumbList"', $body, 'breadcrumbs for search engines too');
        $this->assertStringNotContainsString('"FAQPage"', $body, 'an accordion with faq off has no FAQPage data');
    }
}
