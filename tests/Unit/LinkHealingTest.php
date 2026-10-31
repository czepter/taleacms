<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LinkHealingTest extends TestCase
{
    /** 2.14: self-healing internal links (Core\LinkHealing) */
    public function testInternalLinks(): void
    {
        $lh = fn (string $u, string $to = 'nove'): ?string => \Talea\Core\LinkHealing::rewrite($u, 'stare', $to, 'https://example.com');
        $this->assertSame(['/nove', '/nove/', null, '/nove?x=1', 'https://example.com/nove', '/', 'https://other.org/x#a', null, null, null, null, null, null], [
            $lh('/stare'), $lh('/stare/'), $lh('/en/stare#kontakt'), $lh('/stare?x=1'), $lh('https://example.com/stare'), $lh('/stare', ''), $lh('/stare#a', 'https://other.org/x/'),
            $lh('/stare-cenik'), $lh('/stare/podstranka'), $lh('https://other.org/stare'), $lh('stare'), $lh('/zz/stare'), $lh('/x/stare')], '2.14 LinkHealing::rewrite – own paths in every form, nothing else (a language version is another address)');
        $this->assertSame('/en/nove#kontakt', \Talea\Core\LinkHealing::rewrite('/en/stare#kontakt', 'en/stare', 'en/nove'));
        $this->assertSame(['<p><a href="/nove?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href="/nove">x</a> /stare</p>'], [
            \Talea\Core\LinkHealing::html('<p><a href="/stare?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/stare\'>x</a> /stare</p>', 'stare', 'nove')], '2.14 LinkHealing::html – only href attributes, the rest and entities kept');
        $lhJson = '{"type":"button","content":{"link":"\\/stare","text":"stare"},"children":[{"content":{"html":"<a href=\\"\\/stare\\">a<\\/a>"}}],"prazdne":[]}';
        $this->assertSame([['type' => 'button', 'content' => ['link' => '/nove', 'text' => 'stare'], 'children' => [['content' => ['html' => '<a href="/nove">a</a>']]], 'prazdne' => []], '{"a":"\/jine"}', 'neplatne'], [
            json_decode(\Talea\Core\LinkHealing::json($lhJson, 'stare', 'nove'), true), \Talea\Core\LinkHealing::json('{"a":"\/jine"}', 'stare', 'nove'), \Talea\Core\LinkHealing::json('neplatne', 'stare', 'nove')], '2.14 LinkHealing::json – link values and HTML in any key; text that only mentions it and unchanged JSON stay byte for byte');
    }

    /** Sanitized HTML keeps a raw ' in double-quoted values and raw quotes in text: that is not an href attribute. */
    public function testHrefLookalikeInTitleAndTextIsLeftAlone(): void
    {
        $safe = \Talea\Core\Html::safe('<p><a href="https://example.com/" title="see href=\'/old-offer?">Read \' " onmouseover=alert(document.domain) </a></p>');
        $this->assertSame($safe, \Talea\Core\LinkHealing::html($safe, 'old-offer', 'new-offer'));
        $build = (string) json_encode(['children' => [['content' => ['html' => $safe]]]]);
        $this->assertSame($build, \Talea\Core\LinkHealing::json($build, 'old-offer', 'new-offer'));

        $real = \Talea\Core\Html::safe('<p><a href="/old-offer?x=1#y" title="see href=\'/old-offer?">Read \' " onmouseover=alert(1) </a></p>');
        $a = \Dom\HTMLDocument::createFromString('<body>' . \Talea\Core\LinkHealing::html($real, 'old-offer', 'new-offer'), LIBXML_NOERROR)->querySelector('a');
        $this->assertSame('/new-offer?x=1#y', $a->getAttribute('href'));
        $this->assertSame(['href', 'title'], array_values(array_map(fn ($x) => $x->name, iterator_to_array($a->attributes))));
    }
}
