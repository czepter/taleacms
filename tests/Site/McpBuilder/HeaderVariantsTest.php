<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Header variants for chosen pages (was: section 15). */
#[Group('site')]
final class HeaderVariantsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    public function testAnEmptyHeaderVariantHidesTheHeaderOnItsPageOnly(): void
    {
        $idz = $this->zPage();
        $this->assertPage('/admin.php?module=parts&action=variant&type=header&language=', 200, 'Variant name', message: 'variant form');

        $created = $this->adminPost('/admin.php?module=parts&action=save_variant&type=header&language=', ['name' => 'Landing page', 'pages' => [$this->site()->publicId('pages', $idz)]]);
        $this->assertStringContainsString('variant=landing-page', $created->redirect, 'the variant is created and opened in the builder');

        $variant = '/admin.php?module=parts&action=%s&type=header&language=&variant=landing-page';
        $this->adminPost(sprintf($variant, 'build_save'), ['build' => '{"v":1,"children":[]}']);
        $this->assertSame(200, $this->adminPost(sprintf($variant, 'build_publish'))->status, 'publishing the variant');
        $this->site()->clearPageCache();

        $page = $this->visit('/z-html');
        $this->assertStringNotContainsString('header class="header"', $page, 'the page with the empty variant has no header');
        $this->assertStringNotContainsString('ka-nav', $page, 'the page with the empty variant has no navigation');
        $this->assertStringContainsString('header class="header"', $this->visit('/contact'), 'other pages keep the default header');
        $this->assertPage('/admin.php?module=parts', 200, 'Landing page', message: 'the variant is in the list of parts');
    }
}
