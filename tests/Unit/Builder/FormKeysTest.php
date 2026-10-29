<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;

final class FormKeysTest extends TestCase
{
    /** @param list<array<string, mixed>> $fields */
    private function form(array $fields): array
    {
        return ['v' => 1, 'children' => [['type' => 'form', 'content' => ['fields' => $fields]]]];
    }

    public function testMissingKeysAreAssignedAndUnique(): void
    {
        [$build] = Build::sanitize($this->form([
            ['label' => 'A', 'type' => 'text'], ['label' => 'B', 'type' => 'text', 'key' => 'kabcd'], ['label' => 'C', 'type' => 'text', 'key' => 'kabcd'],
        ]));
        $keys = array_column($build['children'][0]['content']['fields'], 'key');
        $this->assertCount(3, array_unique($keys));
        $this->assertSame('kabcd', $keys[1]);
        foreach ($keys as $k) {
            $this->assertMatchesRegularExpression('/^k[a-z0-9]{4}$/', $k);
        }
    }

    public function testKeysSurviveLabelChange(): void
    {
        [$first] = Build::sanitize($this->form([['label' => 'Guests', 'type' => 'number']]));
        $first['children'][0]['content']['fields'][0]['label'] = 'Number of guests';
        [$second] = Build::sanitize($first);
        $this->assertSame($first['children'][0]['content']['fields'][0]['key'], $second['children'][0]['content']['fields'][0]['key']);
    }

    public function testFortyFieldsAreKept(): void
    {
        $fields = array_map(fn (int $i): array => ['label' => 'F' . $i, 'type' => 'text'], range(1, 45));
        [$build] = Build::sanitize($this->form($fields));
        $this->assertCount(40, $build['children'][0]['content']['fields']);
    }
}
