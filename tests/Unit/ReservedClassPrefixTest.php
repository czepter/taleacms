<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

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
}
