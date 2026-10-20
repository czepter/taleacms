<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Tests\Support\FakeSettings;

final class DraftCommentsTest extends TestCase
{
    /** 2.15: comments on drafts (Core\DraftComments, Core\Preview) */
    public function testComments(): void
    {
        $dc = \Talea\Core\DraftComments::class;
        $this->assertSame([['kind' => 'page', 'id' => 12], null, null, null, null, null], [$dc::parseTarget('page:12'), $dc::parseTarget('page:0'), $dc::parseTarget('page:12x'), $dc::parseTarget('part:header:en'), $dc::parseTarget('popup:3'), $dc::parseTarget('')], '2.15 DraftComments::parseTarget: a page draft only, with a positive id');
        $this->assertSame(["Please fix & the heading\nsecond line x()", 'abc', '', 'abc'], [$dc::clean(" <b>Please</b> fix &amp; the   heading\n\n\n  second line <script>x()</script> ", 2000), $dc::clean("abcdef", 3), $dc::clean("<p></p>  \t ", 80), $dc::clean("a\x00b\x07c", 80)], '2.15 DraftComments::clean: plain text only – tags out, entities decoded, spaces collapsed, one blank line at most, trimmed to the limit');
        $this->assertSame(['nad1', 'e_1-x', null, null, null], [$dc::cleanElement('nad1'), $dc::cleanElement('e_1-x'), $dc::cleanElement('a b'), $dc::cleanElement(''), $dc::cleanElement(str_repeat('a', 41))], '2.15 DraftComments::cleanElement: builder ids only');
        $this->assertSame([true, ['read', ''], ['write', '']], [isset(\Talea\Core\Events::TYPES['comment.received']), \Talea\Mcp\Catalog::TOOLS['list_draft_comments'], \Talea\Mcp\Catalog::TOOLS['resolve_draft_comment']], '2.15: the comment event is known, the tools are a read and a write, the resolve action maps to its tool');
        // the comments flag is signed into the preview key: a plain key never allows comments and a flag added by hand breaks the signature
        $dcSettings = FakeSettings::withSecretKey(str_repeat('ef', 32));
        $dcDb = new \Talea\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''); // never connects: the secret key is set
        $dcPlain = \Talea\Core\Preview::key($dcDb, $dcSettings, 'page:5', 60);
        $dcComments = \Talea\Core\Preview::key($dcDb, $dcSettings, 'page:5', 60, true);
        $this->assertSame([true, true, true, false, false, false, 1], [\Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', $dcComments), \Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:5', $dcComments),
                \Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', $dcPlain), \Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:5', $dcPlain),
                \Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', preg_replace('/^(\d{10})\./', '$1k.', $dcPlain)), \Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:6', $dcComments),
                preg_match('/^\d{10}k\.[a-f0-9]{64}$/', $dcComments)], '2.15 Preview: a key with comments verifies and allows them, a plain key verifies and does not, a forged flag or another target fails');
    }
}
