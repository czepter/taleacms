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
        $text = $this->rawText('stavba_uloz', ['id' => $this->zPage(), 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [
            ['typ' => 'drobecky'],
            ['typ' => 'ikona', 'obsah' => ['ikona' => 'telefon', 'tvar' => 'kruh']],
            ['typ' => 'galerie', 'obsah' => ['fotky' => [['src' => 'media/2026/01/a.jpg', 'alt' => 'Dílna'], ['src' => 'media/2026/01/b.jpg', 'alt' => '']]]],
            ['typ' => 'zalozky', 'obsah' => ['karty' => [['nazev' => 'Základ', 'obsah' => '<p>A</p>'], ['nazev' => 'Plus', 'obsah' => '<p>B</p>']]]],
            ['typ' => 'karusel', 'obsah' => ['naraz' => '2'], 'deti' => [['typ' => 'text', 'obsah' => ['html' => '<p>Snímek</p>']]]],
            ['typ' => 'mapa', 'obsah' => ['adresa' => 'Brno, Náměstí Svobody']],
            ['typ' => 'faq', 'obsah' => ['jedna' => true, 'faq' => false, 'polozky' => [['otazka' => 'Co?', 'odpoved' => '<p>To.</p>']]]],
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
