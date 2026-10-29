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

    public function testBrokenFormRulesAreReported(): void
    {
        $form = ['id' => 'f', 'type' => 'form', 'content' => ['name' => 'Quote', 'logic' => ['rules' => [
            ['label' => 'Fine', 'match' => 'all', 'when' => [], 'then' => [['do' => 'add', 'amount' => 1]]],
            ['broken' => true, 'label' => 'Old colour', 'match' => 'all', 'when' => [], 'then' => [['do' => 'add', 'amount' => 1]]],
            ['broken' => true, 'label' => '', 'match' => 'all', 'when' => [], 'then' => [['do' => 'add', 'amount' => 1]]],
        ], 'results' => []]]];
        $findings = \Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 's', 'type' => 'section', 'children' => [$form]]]], false);
        $this->assertSame([['id' => 'f', 'message' => 'The form “Quote” has rules that do nothing until you fix them in the form logic: “Old colour”, #3.']], $findings);
        $form['content']['logic']['rules'] = [$form['content']['logic']['rules'][0]];
        $this->assertSame([], \Talea\Builder\Check::builds(['v' => 1, 'children' => [$form]], false), 'a form without broken rules is fine');
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
