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

    /** media/.htaccess matches extensions in any case; the site import writes them in lower case and checks the content */
    public function testMediaRulesAndImportIgnoreTheCaseOfExtensions(): void
    {
        preg_match_all('/<FilesMatch "([^"]+)">/', (string) file_get_contents(TALEA_ROOT . '/media/.htaccess'), $rules);
        $this->assertSame(3, count($rules[1]), 'media/.htaccess has its three rules');
        $this->assertSame([], array_filter($rules[1], fn (string $rule): bool => !str_starts_with($rule, '(?i)')), 'media/.htaccess: every rule matches any case (LOGO.SVG gets the CSP)');

        $this->assertSame(['media/2026/09/Photo.jpg', 'media/2026/09/route.gpx', 'media/2026/09/photo.jpg.webp', 'media/2026/09/logo.svg', null],
            array_map(\Talea\Core\SiteImport::mediaTarget(...), ['media/2026/09/Photo.JPG', 'media/2026/09/route.GPX', 'media/2026/09/photo.JPG.webp', 'media/2026/09/logo.SVG', 'media/x.PHP']),
            'SiteImport::mediaTarget writes the extensions in lower case');
        $this->assertSame(['image' => 'media/2026/09/Photo.jpg', 'build' => '{"src":"media\\/2026\\/09\\/Photo.jpg"}', 'other' => ['media/2026/09/a.png']],
            \Talea\Core\SiteImport::renameMedia(['image' => 'media/2026/09/Photo.JPG', 'build' => '{"src":"media\\/2026\\/09\\/Photo.JPG"}', 'other' => ['media/2026/09/a.png']],
                ['media/2026/09/Photo.JPG' => 'media/2026/09/Photo.jpg']),
            'SiteImport::renameMedia: the content follows the new name, also inside JSON');

        $folder = sys_get_temp_dir() . '/talea-import-' . bin2hex(random_bytes(4));
        mkdir($folder);
        $png = $folder . '/a.png';
        file_put_contents($png, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        file_put_contents($folder . '/page.html', '<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>');
        file_put_contents($folder . '/notes.txt', 'Opening hours: [hours]');
        try {
            $this->assertSame([true, false, false, true], [
                \Talea\Core\SiteImport::contentMatches($png, 'media/2026/09/a.png'),
                \Talea\Core\SiteImport::contentMatches($folder . '/page.html', 'media/2026/09/a.jpg'),
                \Talea\Core\SiteImport::contentMatches($folder . '/page.html', 'media/2026/09/a.pdf'),
                \Talea\Core\SiteImport::contentMatches($folder . '/notes.txt', 'media/2026/09/a.txt'),
            ], 'SiteImport::contentMatches: an image must be a raster image, an attachment must not be HTML');
        } finally {
            array_map(unlink(...), glob($folder . '/*') ?: []);
            rmdir($folder);
        }
    }
}
