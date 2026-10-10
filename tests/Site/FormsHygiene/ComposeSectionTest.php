<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Compose section (#23): the placement properties over MCP render as CSS grid placement, and are dropped outside a Compose section. */
#[Group('site')]
final class ComposeSectionTest extends SiteTestCase
{
    use McpHelpers;

    public function testSaveBuildPlacesChildrenOnTheGridAndTheSchemaDescribesIt(): void
    {
        $page = $this->createPage(['title' => 'Compose', 'slug' => 'compose-free', 'visible' => true]);
        $cell = ['grid_column_start' => '2', 'grid_column_end' => '8', 'grid_row_start' => '1', 'grid_row_end' => '4', 'layer' => 'above'];
        $answer = $this->mcpRawAnswer('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [
            ['type' => 'section', 'content' => ['layout' => 'compose'], 'children' => [
                ['id' => 'cmp-a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Placed'], 'style' => ['base' => $cell, 'tablet' => ['grid_column_start' => '1']]],
            ]],
            ['type' => 'section', 'children' => [['id' => 'cmp-b', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Not placed'], 'style' => ['base' => ['layer' => 'top']]]]],
        ]]]);

        $this->assertStringContainsString('children[1].children[0].style.base.layer', $answer, 'MCP: the dropped placement is reported at the element');
        $this->assertStringContainsString('Only a direct child of a Section with the layout', $answer);
        $this->site()->clearPageCache();
        $response = $this->assertPage('/compose-free', 200, 'tl-compose', message: 'the compose section renders its grid class');
        $this->assertTrue($response->contains('grid-column-start: 2; grid-column-end: 8; grid-row-start: 1; grid-row-end: 4; z-index: 1;'), 'the placement is CSS grid lines');
        $this->assertTrue($response->contains('@media (max-width: 767px) { .tl-compose { display: flex; flex-direction: column;'), 'a phone stacks the children');
        $this->assertFalse($response->contains('z-index: 2;'), 'the layer outside a compose section is gone');

        $schema = $this->mcpRawAnswer('builder_schema');
        $this->assertStringContainsString('grid_column_start', $schema);
        $this->assertStringContainsString('layout', $schema);
    }
}
