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
        $this->assertSame(['/nove', '/nove/', '/en/nove#kontakt', '/nove?x=1', 'https://example.com/nove', '/', 'https://other.org/x#a', null, null, null, null, null, null], [
            $lh('/stare'), $lh('/stare/'), $lh('/en/stare#kontakt'), $lh('/stare?x=1'), $lh('https://example.com/stare'), $lh('/stare', ''), $lh('/stare#a', 'https://other.org/x/'),
            $lh('/stare-cenik'), $lh('/stare/podstranka'), $lh('https://other.org/stare'), $lh('stare'), $lh('/zz/stare'), $lh('/x/stare')], '2.14 LinkHealing::rewrite – own paths in every form, nothing else');
        $this->assertSame(['<p><a href="/nove?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/nove\'>x</a> /stare</p>'], [
            \Talea\Core\LinkHealing::html('<p><a href="/stare?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/stare\'>x</a> /stare</p>', 'stare', 'nove')], '2.14 LinkHealing::html – only href attributes, the rest and entities kept');
        $lhJson = '{"type":"button","content":{"link":"\\/stare","text":"stare"},"children":[{"content":{"html":"<a href=\\"\\/stare\\">a<\\/a>"}}],"prazdne":[]}';
        $this->assertSame([['type' => 'button', 'content' => ['link' => '/nove', 'text' => 'stare'], 'children' => [['content' => ['html' => '<a href="/nove">a</a>']]], 'prazdne' => []], '{"a":"\/jine"}', 'neplatne'], [
            json_decode(\Talea\Core\LinkHealing::json($lhJson, 'stare', 'nove'), true), \Talea\Core\LinkHealing::json('{"a":"\/jine"}', 'stare', 'nove'), \Talea\Core\LinkHealing::json('neplatne', 'stare', 'nove')], '2.14 LinkHealing::json – link values and HTML in any key; text that only mentions it and unchanged JSON stay byte for byte');
    }
}
