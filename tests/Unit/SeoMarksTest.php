<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\ContentCheck;

/** The marks Website mode lays over the preview ("SEO on the page"): element id => what is wrong with it. */
final class SeoMarksTest extends TestCase
{
    public function testMarks(): void
    {
        $build = ['v' => 1, 'children' => [['id' => 'sec1', 'type' => 'section', 'children' => [
            ['id' => 'img1', 'type' => 'image', 'content' => ['src' => 'media/a.jpg', 'alt' => '']],
            ['id' => 'img2', 'type' => 'image', 'content' => ['src' => 'media/b.jpg', 'alt' => 'A couple']],
            ['id' => 'img3', 'type' => 'image', 'content' => ['src' => '', 'alt' => '']], // no file: the page shows nothing to mark
            ['id' => 'h1a', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'eure Fotografen für Hochzeit']], // check-english: allow
            ['id' => 'h2a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'More']],
            ['id' => 'h1b', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Fotografen Jena']],
        ]]]];

        $this->assertSame([
            'img1' => 'Image without description (alt)',
            'h1a' => 'Main heading without the focus keyword “Fotografen Jena”',
            'h1b' => 'A second main heading',
        ], ContentCheck::marks($build, ['Fotografen Jena']));
        $this->assertSame([], ContentCheck::marks([], ['x']));
        $this->assertSame([], ContentCheck::marks(['children' => 'broken'], ['x']));
        $this->assertSame([], ContentCheck::marks(['children' => [['id' => 'h', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Jena photos']]]], ['jena']), 'the keyword is found in any case');
        $this->assertSame([], ContentCheck::marks(['children' => [['id' => 'h', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Hello']]]], []), 'without a keyword the first H1 is fine');
    }
}
