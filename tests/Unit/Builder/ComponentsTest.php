<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Components;

final class ComponentsTest extends TestCase
{
    /** a choice property keeps its options; its default and the value of a use must be one of them */
    public function testChoiceProperty(): void
    {
        $properties = Components::sanitizeProperties([
            ['label' => 'Layout', 'type' => 'radio', 'options' => "grid\nlarge first\ngrid", 'default' => 'large first'],
            ['label' => 'Frame', 'type' => 'radio', 'options' => "box\nopen", 'default' => 'nonsense'],
            ['label' => 'Size', 'type' => 'radio', 'options' => '', 'default' => 'big'],
        ]);

        $this->assertSame(['layout', 'radio', ['grid', 'large first'], 'large first'], [$properties[0]['key'], $properties[0]['type'], $properties[0]['options'], $properties[0]['default']], 'options without duplicates, a valid default stays');
        $this->assertSame('', $properties[1]['default'], 'a default that is not an option is dropped');
        $this->assertSame(['text', 'big'], [$properties[2]['type'], $properties[2]['default']], 'a choice without options is a short text');

        $component = ['properties' => $properties];
        $this->assertSame(['grid', 'radio'], Components::values($component, ['layout' => 'grid'])['layout'], 'the value of a use');
        $this->assertSame(['large first', 'radio'], Components::values($component, ['layout' => '<b>other</b>'])['layout'], 'a value that is not an option falls back to the default');
    }
}
