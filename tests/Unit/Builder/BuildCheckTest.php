<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class BuildCheckTest extends TestCase
{
    public function testPrePublishCheck(): void
    {
        $buildCheck = \Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 's', 'type' => 'section', 'children' => [
            ['id' => 'a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Služby']], // check-english: allow
            ['id' => 'b', 'type' => 'heading', 'tag' => 'h4', 'content' => ['text' => 'Detail']],
            ['id' => 'c', 'type' => 'button', 'content' => ['text' => 'Poptat', 'link' => '#']],
            ['id' => 'd', 'type' => 'image', 'content' => ['src' => 'media/a.jpg', 'alt' => '']],
            ['id' => 'e', 'type' => 'image', 'content' => ['src' => '{{photo}}', 'alt' => '']],
            ['id' => 'f', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => '01']],
        ]]]], true);
        $this->assertSame(['c', 'd', 'a', 'b'], array_column($buildCheck, 'id'), 'Build check: button without a link, image without alt, missing h1 and a skipped level');
    }

    public function testTheH1OfAComponentCountsForThePage(): void
    {
        $hero = ['v' => 1, 'children' => [['id' => 'h', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => '{{title}}']]]];
        $page = ['v' => 1, 'children' => [
            ['id' => 'use', 'type' => 'component', 'content' => ['component' => 7]],
            ['id' => 'a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Services']],
        ]];
        $this->assertSame([], \Talea\Builder\Check::builds($page, true, components: [7 => $hero]), 'The hero component carries the page’s h1');
        $this->assertSame(['use', 'a'], array_column(\Talea\Builder\Check::outline($page, [7 => $hero]), 0), 'Its headings count under the use’s id');
        $this->assertSame(['a'], array_column(\Talea\Builder\Check::builds($page, true), 'id'), 'Without the component builds the h1 is missing');
        $self = ['v' => 1, 'children' => [['id' => 'loop', 'type' => 'component', 'content' => ['component' => 8]]]];
        $this->assertSame([], \Talea\Builder\Check::outline($self, [8 => $self]), 'A component inside itself ends');
    }
}
