<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\CollectionSubmission;
use Talea\Core\FormLinks;
use Talea\Core\Testimonials;

final class CollectionSubmissionTest extends TestCase
{
    /** A template is filled from the answers by their labels; a missing label gives nothing and a dangling comma goes. */
    public function testTemplatesAreFilledByLabel(): void
    {
        $data = [['Your name', 'Eve'], ['Role', ''], ['Your words', 'Great <b>work</b>']];
        $this->assertSame(
            ['Eve', 'Eve', 'Great <b>work</b>', '', 'Eve – Great <b>work</b>'],
            [CollectionSubmission::fill('{Your name}', $data), CollectionSubmission::fill('{your name}, {Role}', $data), CollectionSubmission::fill('{Your words}', $data),
                CollectionSubmission::fill('{Unknown}', $data), CollectionSubmission::fill('{Your name} – {Your words}', $data)],
            'a label is matched without regard to case, an empty answer leaves no comma behind, markup stays for the field type to clean',
        );
    }

    /** A visitor's text becomes escaped paragraphs in formatted text, and formatted text comes back as the plain text a form shows. */
    public function testFormattedTextGoesToPlainTextAndBack(): void
    {
        $this->assertSame("<p>A &amp; <b></p>", str_replace('&lt;b&gt;', '<b>', CollectionSubmission::htmlFromText("A & <b>")), 'markup a visitor types is escaped');
        $this->assertSame("<p>One<br>\ntwo</p>\n<p>Three</p>", CollectionSubmission::htmlFromText("One\r\ntwo\n\n\nThree\n"), 'an empty line starts a paragraph, a line break stays');
        $this->assertSame('', CollectionSubmission::htmlFromText("  \n "), 'nothing written gives nothing');
        $this->assertSame("We loved it.\n\nLine\nbreak &\n\nOne\nTwo", CollectionSubmission::textFromHtml("<p>We <strong>loved</strong> it.</p>\n<p>Line<br>break &amp;</p><ul><li>One</li><li>Two</li></ul>"));
        $text = "First\nsecond\n\nThird & <i>fourth</i>";
        $this->assertSame($text, CollectionSubmission::textFromHtml(CollectionSubmission::htmlFromText($text)), 'the way there and back keeps the text');
    }

    public function testLinksAndTheSystemFormAreRecognised(): void
    {
        $this->assertSame([30, 'references', true, false], [FormLinks::DAYS, Testimonials::PRESET, Testimonials::isSystem(['origin' => Testimonials::ORIGIN]), Testimonials::isSystem(['origin' => 'page:3|abc'])]);
        $this->assertNull(FormLinks::find(new \Talea\Core\Db('sqlite::memory:', '', ''), 'not-a-token'), 'a token that is no 32 hex characters finds nothing, without asking the database');
    }
}
