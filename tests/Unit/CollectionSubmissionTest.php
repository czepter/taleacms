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

    public function testLinksAndTheSystemFormAreRecognised(): void
    {
        $this->assertSame([30, 'references', true, false], [FormLinks::DAYS, Testimonials::PRESET, Testimonials::isSystem(['origin' => Testimonials::ORIGIN]), Testimonials::isSystem(['origin' => 'page:3|abc'])]);
        $this->assertNull(FormLinks::find(new \Talea\Core\Db('sqlite::memory:', '', ''), 'not-a-token'), 'a token that is no 32 hex characters finds nothing, without asking the database');
    }
}
