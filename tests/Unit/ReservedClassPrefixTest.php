<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\App;
use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\HtmlConverter;
use Talea\Builder\Library;
use Talea\Builder\Renderer;
use Talea\Core\Html;
use Talea\Core\WpContent;

/** #331: "tl-" class names are Talea's own, and the collection list script fetches only its own page. */
final class ReservedClassPrefixTest extends TestCase
{
    public function testCollectionListFetchesOnlyItsOwnPage(): void
    {
        $js = (string) file_get_contents(TALEA_ROOT . '/image/lists.js');
        $swap = substr($js, (int) strpos($js, 'function swapList('), 1500);

        self::assertStringContainsString("return u.origin === location.origin && u.pathname.replace(/\\/+\$/, '') === location.pathname.replace(/\\/+\$/, '');", $js);
        self::assertSame(1, preg_match('/^function swapList\(wrapper, url, push\) \{\s+if \(!ownPage\(url\)\) \{ location\.href = url; return; \}/', $swap), 'swapList refuses another page first');
        self::assertStringContainsString("!ownPage(r.url) || !/^text\\/html\\b/i.test(r.headers.get('Content-Type') || '')", $swap);
        self::assertLessThan(
            (int) strpos($js, "swapList(link.closest('[data-collection]'), link.href, true)"),
            (int) strpos($js, 'if (!ownPage(link.href)) { return; }'),
        );
        self::assertSame(3, substr_count($js, 'swapList('));
        self::assertSame(1, substr_count((string) file_get_contents(TALEA_ROOT . '/image/web.js'), 'fetch('), 'a new fetch in web.js: check that it never puts markup from another origin into the page (#331), then update this count');
        self::assertSame(1, substr_count($js, 'fetch('), 'a new fetch in lists.js: check that it never puts markup from another origin into the page (#331), then update this count');
    }

    public function testClassPatternReservesThePrefix(): void
    {
        $names = ['card', 'card--highlighted', 'tlx', 'tl', 'title-big', 'tl-', 'tl-x', 'tl-collection-filters', 'tl-button--primary', "card\n"];
        self::assertSame([1, 1, 1, 1, 1, 0, 0, 0, 0, 0], array_map(static fn (string $c): int|false => preg_match(Build::CLASS_PATTERN, $c), $names));
        self::assertTrue(Build::isReservedClass('TL-x'));
        self::assertFalse(Build::isReservedClass('atl-x'));
        self::assertSame(['card'], Build::userClasses(['tl-locator-list', 'card', 'card', 5, 'Bad Name']));
        self::assertSame([], Build::userClasses('tl-x'));
    }

    public function testEveryScriptClassHookIsReserved(): void
    {
        $js = (string) file_get_contents(TALEA_ROOT . '/image/web.js') . (string) file_get_contents(TALEA_ROOT . '/image/forms.js') . (string) file_get_contents(TALEA_ROOT . '/image/lists.js');
        preg_match_all('/\.(tl-[a-z0-9_-]*[a-z0-9])/', $js, $m);
        $hooks = array_values(array_unique($m[1]));

        self::assertGreaterThan(10, count($hooks));
        self::assertContains('tl-collection-filters', $hooks);
        self::assertContains('tl-collection-pages', $hooks);
        self::assertSame([], array_values(array_filter($hooks, static fn (string $c): bool => preg_match(Build::CLASS_PATTERN, $c) === 1)), 'a class the scripts hook on can be a user class');
    }

    public function testBuilderFieldUsesTheServerPattern(): void
    {
        $js = (string) file_get_contents(TALEA_ROOT . '/image/builder/inspector.js');

        self::assertStringContainsString('/' . substr(Build::CLASS_PATTERN, 1, -2) . '/.test(name)', $js);
    }

    public function testSanitizeLeavesReservedClassesOutWithANote(): void
    {
        [$build, $errors] = Build::sanitize(['children' => [['type' => 'text', 'content' => ['html' => '<p><a href="https://evil.example/p">More</a></p>'], 'classes' => ['tl-collection-pages', 'card', 'tl-collection-filters']]]], false);
        self::assertSame(['card'], $build['children'][0]['classes']);
        self::assertSame(Build::RESERVED_CLASS_NOTE, $errors['children[0].classes']);

        [, $errors] = Build::sanitize(['children' => [['type' => 'text', 'content' => ['html' => '<p>x</p>'], 'classes' => ['card']]]], false);
        self::assertArrayNotHasKey('children[0].classes', $errors);
    }

    public function testStoredBuildRendersWithoutReservedClasses(): void
    {
        $html = Renderer::html(['children' => [['id' => 'c331', 'type' => 'container', 'tag' => 'div', 'content' => [], 'classes' => ['tl-collection-filters', 'card'], 'children' => [
            ['id' => 'b331', 'type' => 'button', 'tag' => 'a', 'content' => ['text' => 'More', 'link' => 'https://evil.example/p']],
        ]]]], new Context(new App([])));

        self::assertStringNotContainsString('tl-collection-filters', $html);
        self::assertSame(1, preg_match('/class="[^"]*\bcard\b[^"]*"/', $html));
        self::assertStringContainsString('href="https://evil.example/p"', $html, 'the element and its link stay – only the hook goes');
    }

    public function testHtmlConversionReportsReservedClasses(): void
    {
        $c = HtmlConverter::convert('<style>.tl-collection-filters { color: red } .card331 { color: blue }</style><div class="tl-collection-pages card331"><p>x</p></div>', false);

        self::assertSame(['card331'], array_keys($c['classes']));
        self::assertContains(Build::RESERVED_CLASS_NOTE, $c['notes']);
        self::assertStringNotContainsString('tl-collection', (string) json_encode($c['build']));
    }

    public function testBundledContentUsesNoReservedClass(): void
    {
        foreach (array_keys(Library::CLASSES) as $name) {
            self::assertSame(1, preg_match(Build::CLASS_PATTERN, (string) $name), 'library class ' . $name);
        }
        $files = array_merge(glob(TALEA_ROOT . '/system/looks/*.json') ?: [], glob(TALEA_ROOT . '/system/blueprints/*.json') ?: [], glob(TALEA_ROOT . '/system/presets/*') ?: []);
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            if (is_file($file)) {
                self::assertSame(0, preg_match('/"classes"\s*:\s*\[[^\]]*"tl-/', (string) file_get_contents($file)), basename($file));
            }
        }
    }

    public function testRichTextDropsReservedClasses(): void
    {
        self::assertSame(
            ['card', '', 'gallery', ' a  b ', 'card large', 'atl-x tl_x tlx'],
            [Html::textClasses('tl-collection-pages card'), Html::textClasses('TL-X Tl-y'), Html::textClasses('gallery'), Html::textClasses(' a  b '), Html::textClasses("card\ttl-button\nlarge"), Html::textClasses('atl-x tl_x tlx')],
        );
        self::assertSame('<p class="card"><a href="https://example.com/x">x</a></p>', Html::safe('<p class="tl-collection-pages card"><a class="TL-Button" href="https://example.com/x">x</a></p>'));
        self::assertSame('<div><p id="price-list">t</p></div>', Html::safe('<div class="tl-locator-list"><p id="price-list" class="tl-x">t</p></div>'));
        $figure = '<figure class="gallery"><img src="/media/a.jpg" alt="A" data-id="12"></figure>';
        self::assertSame($figure, Html::safe($figure));
        // the builder and collection path (not Html::safe) keeps no tl- class either
        self::assertStringNotContainsString('tl-', WpContent::safeHtml('<p class="tl-collection-filters">x</p><figure class="tl-gallery gallery"><img src="/media/a.jpg" alt=""></figure>'));
        // the editor writes no tl- class into a text: if it ever does, textClasses needs an allow-list
        preg_match_all('/(?<![a-z0-9_-])tl-[a-z0-9-]+/', (string) file_get_contents(TALEA_ROOT . '/image/editor.js'), $m);
        self::assertSame([], $m[0]);
    }
}
