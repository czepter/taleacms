<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Header variants for chosen pages (was: section 15 "varianty záhlaví"). */
#[Group('site')]
final class HeaderVariantsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testAnEmptyHeaderVariantHidesTheHeaderOnItsPageOnly(): void
    {
        $idz = $this->zPage();
        $this->assertPage('/admin.php?module=parts&action=variant&type=hlavicka&language=', 200, 'Název varianty', message: 'variant form');

        $created = $this->adminPost('/admin.php?module=parts&action=save_variant&type=hlavicka&language=', ['name' => 'Landing page', 'pages' => [$idz]]);
        $this->assertStringContainsString('variant=landing-page', $created->redirect, 'the variant is created and opened in the builder');

        $variant = '/admin.php?module=parts&action=%s&type=hlavicka&language=&variant=landing-page';
        $this->adminPost(sprintf($variant, 'build_save'), ['build' => '{"v":1,"deti":[]}']);
        $this->assertSame(200, $this->adminPost(sprintf($variant, 'build_publish'))->status, 'publishing the variant');
        $this->site()->clearPageCache();

        $page = $this->visit('/z-html');
        $this->assertStringNotContainsString('header class="hlavicka"', $page, 'the page with the empty variant has no header');
        $this->assertStringNotContainsString('ka-nav', $page, 'the page with the empty variant has no navigation');
        $this->assertStringContainsString('header class="hlavicka"', $this->visit('/kontakt'), 'other pages keep the default header');
        $this->assertPage('/admin.php?module=parts', 200, 'Landing page', message: 'the variant is in the list of parts');
    }
}
