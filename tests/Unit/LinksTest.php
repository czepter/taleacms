<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase
{
    /** link check: only public URLs (protection against probing the internal network) */
    public function testOnlyPublicUrls(): void
    {
        $this->assertSame(['https://example.com/a?x=1&y=2', '/clanek/muj'], \Talea\Core\Links::links('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), 'Links: picking links from HTML');
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $internal) {
            $this->assertFalse(\Talea\Core\Links::isPublic($internal), 'Odkazy: nekontroluje se ' . $internal);
        }
        $this->assertTrue(\Talea\Core\Links::isPublic('https://93.184.216.34/stranka'), 'Links: a public address is checked');
    }

    /** 2.14: links that look after themselves (Core\RedirectMatcher, Core\Links, Core\InternalLinks) */
    public function testRedirectMatcherAndInternalLinks(): void
    {
        $f15Score = fn (string $missing, string $candidate): int => \Talea\Core\RedirectMatcher::score($missing, $candidate, ['cs', 'en']);
        $this->assertSame([100, 100, 100], [$f15Score('/O-nas/', 'o-nas'), $f15Score('o-nas.html', 'o-nas'), $f15Score('/kontakt/index.php', 'kontakt')], '2.14 RedirectMatcher: the same address written differently scores 100'); // check-english: allow
        $this->assertSame([95, 95, 95, 95], [$f15Score('novinky/moje-novinka', 'news/moje-novinka'), $f15Score('/blog/clanek-x', 'novinky/clanek-x'), $f15Score('en/kontakt', 'kontakt'), $f15Score('kontakt', 'en/kontakt')], '2.14 RedirectMatcher: an old prefix or a moved language prefix scores 95'); // check-english: allow
        $this->assertSame([90, 90, true, true], [$f15Score('sluzby/weby', 'weby'), $f15Score('weby', 'sluzby/weby'), $f15Score('kontakty', 'kontakt') < 90 && $f15Score('kontakty', 'kontakt') >= \Talea\Core\RedirectMatcher::MIN_SCORE, // check-english: allow
            $f15Score('cenik-2019', 'cenik') < 90 && $f15Score('cenik-2019', 'cenik') >= \Talea\Core\RedirectMatcher::MIN_SCORE], '2.14 RedirectMatcher: the same last segment elsewhere scores 90, a similar slug below');
        $this->assertSame([0, 0, 0, 0, 0], [$f15Score('asdkjh-qwe', 'kontakt'), $f15Score('wp-content/uploads/x.jpg', 'kontakt'), $f15Score('o-nas', 'o-firme'), $f15Score('xy', 'xyz'), $f15Score('', 'kontakt')], '2.14 RedirectMatcher: random paths, bot probes and unrelated pages do not match'); // check-english: allow
        $f15Build = ['v' => 1, 'children' => [['id' => 'e1', 'type' => 'section', 'children' => [['id' => 'e2', 'type' => 'button', 'content' => ['text' => 'Go', 'link' => 'http://127.0.0.1:1/dead']],
            ['id' => 'e3', 'type' => 'text', 'content' => ['html' => '<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="{{fact.web}}">f</a></p>']],
            ['id' => 'e4', 'type' => 'pricing_table', 'content' => ['plans' => [['name' => 'A', 'link' => '/kontakt'], ['name' => 'B', 'link' => '#']]]]]]]];
        $this->assertSame([['http://127.0.0.1:1/dead', 'e2'], ['https://example.com/a?x=1&y=2', 'e3'], ['/kontakt', 'e4']], \Talea\Core\Links::collect('page', ['build' => json_encode($f15Build), 'text' => '']), '2.14 Links::collect: links of a page build with their element ids, texts, nested plans; tokens, anchors and mailto left out');
        $this->assertSame([[['https://example.com/', '']], [['https://example.org/firma', ''], ['https://example.com/b', '']]], [\Talea\Core\Links::collect('page', ['build' => null, 'text' => '<a href="https://example.com/">x</a>']),
            \Talea\Core\Links::collect('item', ['data' => json_encode(['web' => 'https://example.org/firma', 'description' => '<p><a href="https://example.com/b">b</a></p>', 'cislo' => 5])])], '2.14 Links::collect: a text page and a collection item (link fields and links in texts)');
        $this->assertSame([true, true], [str_contains(\Talea\Core\Links::hint('https://example.com/x', 404), 'https://web.archive.org/web/2020/https://example.com/x'), str_contains(\Talea\Core\Links::hint('/news/stara', 404), 'no longer exists')], '2.14 Links::hint: the archived copy only as a suggestion for outside addresses');
        $this->assertFalse(\Talea\Core\Links::isPublic('http://127.0.0.1:1/x'), '2.14 Links::isPublic: the test seam is off without the environment variable');
        $this->assertSame(['reference', 'portfolio', 'zatepleni', 'domu', 'brne'], \Talea\Core\InternalLinks::words('Reference &amp; portfolio: Zateplení domů v Brně'), '2.14 InternalLinks::words: title words for the term overlap – lower case, no diacritics, four letters or more'); // check-english: allow
        $this->assertSame([86400, true, true], [\Talea\Core\Scheduler::JOBS['redirects'][0], isset(\Talea\Core\Events::TYPES['redirect.auto']), isset(\Talea\Core\Audit::KINDS['orphan'])], '2.14: the redirects job runs daily, redirect.auto is a known event, orphan is an audit kind');
    }
}
