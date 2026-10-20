<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class ComposeTest extends TestCase
{
    public function testPlacement(): void
    {
        $headerApp = new \Talea\Core\App([]);
        // Compose phase B (#23): placement by grid lines and a layer scale, valid only on the direct children of a Compose section
        $composeCell = ['grid_column_start' => '2', 'grid_column_end' => '13', 'grid_row_start' => '1', 'grid_row_end' => '40', 'layer' => 'top'];
        $this->assertSame(['base' => $composeCell, 'tablet' => ['layer' => 'below']], \Talea\Builder\Style::sanitize(['base' => $composeCell, 'tablet' => ['layer' => 'below']]), 'Style::sanitize: grid lines 1–13 / 1–40 and the layer scale are accepted');
        $composeErrors = [];
        $this->assertSame([[], 5], [\Talea\Builder\Style::sanitize(['base' => ['grid_column_start' => '0', 'grid_column_end' => '14', 'grid_row_start' => '41', 'grid_row_end' => 'span 2', 'layer' => 'z99']], '', $composeErrors), count($composeErrors)], 'Style::sanitize: out-of-range lines and an unknown layer are rejected');
        $this->assertSame("#x { grid-column-start: 2; grid-column-end: 13; grid-row-start: 1; grid-row-end: 40; z-index: 2; }\n", \Talea\Builder\Style::css('#x', ['base' => $composeCell]), 'Style::css: placement as grid lines and the layer as z-index');
        $this->assertSame([['grid_column_start' => '3'], null], [\Talea\Builder\Style::fromCss('grid-column-start', '3'), \Talea\Builder\Style::fromCss('grid-row-end', '41')], 'Style::fromCss: grid lines come back as the style properties');
        $composeBuild = ['v' => 1, 'children' => [
            ['id' => 'cs1', 'type' => 'section', 'content' => ['layout' => 'compose'], 'children' => [
                ['id' => 'cs2', 'type' => 'heading', 'content' => ['text' => 'A'], 'style' => ['base' => $composeCell, 'tablet' => ['grid_column_start' => '1']]],
                ['id' => 'cs3', 'type' => 'container', 'children' => [['id' => 'cs4', 'type' => 'heading', 'content' => ['text' => 'B'], 'style' => ['base' => ['layer' => 'above', 'gap' => 'm']]]]],
            ]],
            ['id' => 'cs5', 'type' => 'section', 'children' => [['id' => 'cs6', 'type' => 'heading', 'content' => ['text' => 'C'], 'style' => ['base' => ['grid_column_start' => '2']]]]],
        ]];
        [$composeClean, $composeSanitizeErrors] = \Talea\Builder\Build::sanitize($composeBuild);
        $this->assertSame([['base' => $composeCell, 'tablet' => ['grid_column_start' => '1']], ['base' => ['gap' => 'm']], false,
            ['children[0].children[1].children[0].style.base.layer', 'children[1].children[0].style.base.grid_column_start']], [
            $composeClean['children'][0]['children'][0]['style'], $composeClean['children'][0]['children'][1]['children'][0]['style'], isset($composeClean['children'][1]['children'][0]['style']),
            array_keys($composeSanitizeErrors),
        ], 'Build::sanitize: placement stays on a child of a compose section, is dropped (and reported) anywhere else');
        [$composeToStack] = \Talea\Builder\Build::sanitize(['children' => [['id' => 'cs1', 'type' => 'section', 'content' => ['layout' => 'stack'], 'children' => [['id' => 'cs2', 'type' => 'heading', 'style' => ['base' => $composeCell + ['gap' => 's']]]]]]]);
        $this->assertSame(['base' => ['gap' => 's']], $composeToStack['children'][0]['children'][0]['style'], 'Build::sanitize: switching a section back to stack drops every placement and keeps the rest of the style');
        $composeContext = new \Talea\Builder\Context($headerApp);
        $composeContext->source = 'page:6';
        $composeHtml = \Talea\Builder\Build::html($composeClean, $composeContext);
        $composeCss = \Talea\Builder\Build::css((new \ReflectionClass(\Talea\Core\Db::class))->newInstanceWithoutConstructor(), $composeContext);
        $this->assertSame([true, true, true, true, true], [
            str_contains($composeHtml, '<div class="tl-wrap tl-compose">'), str_contains($composeContext->css, '#s-cs2 { grid-column-start: 2; grid-column-end: 13; grid-row-start: 1; grid-row-end: 40; z-index: 2; }'),
            str_contains($composeCss, '.tl-compose { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr));'),
            str_contains($composeCss, '@media (max-width: 1023px) { .tl-compose:not(.tl-compose--phone) { display: flex; flex-direction: column;'), str_contains($composeCss, '@media (max-width: 767px) { .tl-compose { display: flex; flex-direction: column;'),
        ], 'Compose: the wrapper is the grid, the children are placed by lines, at tablet and phone width the CSS stacks them in source order');
        $composePhone = \Talea\Builder\Build::html(\Talea\Builder\Build::sanitize(['children' => [['id' => 'cs7', 'type' => 'section', 'content' => ['layout' => 'compose', 'stack_from' => 'phone', 'width' => 'full'], 'children' => []]]])[0], new \Talea\Builder\Context($headerApp));
        $this->assertSame([true, false], [str_contains($composePhone, 'class="tl-compose tl-compose--phone"'), str_contains($composePhone, 'tl-wrap')], 'Compose: "phone only" keeps the grid on a tablet, a full-width section is the grid itself');
        $plainContext = new \Talea\Builder\Context($headerApp);
        $plainContext->source = 'page:7';
        \Talea\Builder\Build::html(\Talea\Builder\Build::sanitize(['children' => [['id' => 'cs8', 'type' => 'section', 'children' => []]]])[0], $plainContext);
        $this->assertFalse(str_contains(\Talea\Builder\Build::css((new \ReflectionClass(\Talea\Core\Db::class))->newInstanceWithoutConstructor(), $plainContext), 'tl-compose'), 'Compose: a page without a compose section carries none of its CSS');
    }
}
