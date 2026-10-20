<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class EditsTest extends TestCase
{
    public function testApply(): void
    {
        $editBuild = ['v' => 1, 'children' => [['id' => 'sek1', 'type' => 'section', 'children' => [['id' => 'nad1', 'type' => 'heading', 'content' => ['text' => 'A'], 'style' => ['base' => ['color' => 'primary']]], ['id' => 'tl1', 'type' => 'button', 'content' => ['text' => 'B', 'link' => '/docs']]]], ['id' => 'sek2', 'type' => 'section']]];
        $editErrors = [];
        $edit = \Talea\Builder\Edits::apply($editBuild, [
            ['op' => 'update', 'id' => 'tl1', 'content' => ['link' => '/guide']],
            ['op' => 'update', 'id' => 'nad1', 'style' => ['base' => ['color' => null], 'mobile' => ['text_align' => 'center']], 'classes' => ['nadpis-sekce']],
            ['op' => 'insert', 'into' => 'sek2', 'elements' => [['id' => 'txt1', 'type' => 'text']]],
            ['op' => 'move', 'id' => 'tl1', 'after' => 'txt1'],
            ['op' => 'insert', 'position' => 0, 'element' => ['id' => 'sek0', 'type' => 'section']],
            ['op' => 'delete', 'id' => 'neni'],
            ['op' => 'move', 'id' => 'sek2', 'into' => 'txt1'],
            ['op' => 'kouzlo'],
        ], $editErrors);
        $this->assertSame([['sek0', 'sek1', 'sek2'], ['mobile' => ['text_align' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide'], [array_column($edit['children'], 'id'), $edit['children'][1]['children'][0]['style'], $edit['children'][1]['children'][0]['classes'], array_column($edit['children'][2]['children'], 'id'), $edit['children'][2]['children'][1]['content']['link']], 'Edits: edit content and style (null removes), insert, move, insert at the start');
        $this->assertSame(['op[5]', 'op[6]', 'op[7]'], array_keys($editErrors), 'Edits: wrong operations are reported and skipped (also a move into a descendant)');
        $editErrors2 = [];
        $this->assertTrue(\Talea\Builder\Edits::apply($editBuild, [['op' => 'move', 'id' => 'sek1', 'into' => 'nad1']], $editErrors2) === $editBuild && isset($editErrors2['op[0]']), 'Edits: moving an element into its descendant is not possible');
    }
}
