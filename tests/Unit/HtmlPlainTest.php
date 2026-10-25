<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Html;

final class HtmlPlainTest extends TestCase
{
    /** Html::plain(): stored HTML as one line of text for meta and og descriptions, JSON-LD, feeds and llms.txt */
    public function testPlain(): void
    {
        $this->assertSame('Opened in May 2026 with R&D… Second', Html::plain('<p>Opened in&nbsp;May 2026&nbsp;with <b>R&amp;D</b>&hellip;</p><p>Second</p>'), 'Html::plain: entities as characters, no tags, a block end is a space');
        $this->assertSame('word word word w…', Html::plain(str_repeat('word&nbsp;', 60), 17), 'Html::plain: shortened with an ellipsis');
        $this->assertSame('a b c d', Html::plain("a\u{00A0}\u{00A0}b <br>c\n\t d"), 'Html::plain: every run of whitespace, U+00A0 too, is one space');
        $intro = '<p>We are glad that in&nbsp;December&nbsp;we got the permit.</p><p>R&amp;D &lt;new&gt;</p>';
        $this->assertSame('We are glad that in December we got the permit. R&amp;D &lt;new&gt;', e(Html::plain($intro, 300)), 'Html::plain: a meta description from the intro is escaped once (no &amp;nbsp;)');
        $this->assertSame('{"description":"We are glad that in December we got the permit. R&D \u003Cnew\u003E"}', json_encode(['description' => Html::plain($intro, 300)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), 'Html::plain: the JSON-LD description is text without entities or tags');
    }

    /** every description source goes through Html::plain(), never through a bare strip_tags() */
    public function testDescriptionSources(): void
    {
        $kernel = (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Kernel.php');
        $seo = (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Seo.php');
        $this->assertSame([0, 0, 0], [
            preg_match_all('/strip_tags\(\(?(string\)\s*)?\$(newsItem|category|tag|h|paragraph)\b/', $kernel),
            preg_match_all('/html_entity_decode\(strip_tags/', $kernel),
            substr_count($seo, "strip_tags(\$c['intro'])"),
        ], 'news, categories, tags, collection items, feeds and llms.txt describe themselves through Html::plain()');
    }
}
