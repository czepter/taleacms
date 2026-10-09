<?php

declare(strict_types=1);

namespace Kaleta\Tests\Unit\Builder;

use Kaleta\Builder\StructuredData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructuredData::class)]
final class StructuredDataTest extends TestCase
{
    private function origin(): callable
    {
        return static fn (string $u): string => str_starts_with($u, '/') ? 'https://example.test' . $u : $u;
    }

    public function testTheVocabularyIsWellFormed(): void
    {
        $vocabulary = StructuredData::vocabulary();
        $this->assertGreaterThanOrEqual(30, count($vocabulary));
        foreach ($vocabulary as $type => $t) {
            $this->assertNotSame([], $t['properties'], $type);
            foreach ($t['properties'] as $name => $p) {
                $this->assertContains($p['type'], ['text', 'long_text', 'url', 'date', 'datetime', 'number', 'integer', 'boolean', 'image', 'enum', 'thing', 'duration'], "$type.$name");
                foreach ($p['of'] ?? [] as $of) {
                    $this->assertArrayHasKey($of, $vocabulary, "$type.$name nested type");
                }
            }
        }
    }

    public function testAProductIsSanitizedAndRenderedWithNestedOffers(): void
    {
        [$clean, $notes] = StructuredData::sanitize(['type' => 'Product', 'fields' => [
            'name' => '  Chair <b>Oak</b> ', 'image' => '/media/chair.jpg', 'sku' => 'C-1',
            'offers' => [['type' => 'Offer', 'fields' => ['price' => '149.90', 'priceCurrency' => 'EUR', 'availability' => 'InStock']]],
            'bogus' => 'x',
        ]]);

        $this->assertSame('Chair Oak', $clean['fields']['name']);
        $this->assertSame([], array_diff($notes, ['Product: property bogus is not known and was dropped']));
        $node = StructuredData::render($clean, $this->origin());
        $this->assertSame('Product', $node['@type']);
        $this->assertSame(['@type' => 'ImageObject', 'url' => 'https://example.test/media/chair.jpg'], $node['image']);
        $this->assertSame('https://schema.org/InStock', $node['offers'][0]['availability']);
        $this->assertSame(149.9, $node['offers'][0]['price']);
    }

    public function testHostileValuesAreDropped(): void
    {
        [$clean, $notes] = StructuredData::sanitize(['type' => 'Product', 'fields' => [
            'name' => '</script><script>alert(1)</script>Evil', 'url' => 'javascript:alert(1)', 'image' => 'data:text/html,x', 'sku' => ['array'],
        ]]);

        $this->assertStringNotContainsString('<', json_encode($clean['fields']));
        $this->assertArrayNotHasKey('url', $clean['fields']);
        $this->assertArrayNotHasKey('image', $clean['fields']);
        $this->assertNotSame([], $notes);
        $json = (string) json_encode(StructuredData::render(['type' => 'Product', 'fields' => ['name' => 'a<b']], $this->origin()), JSON_HEX_TAG);
        $this->assertFalse(str_contains($json, '<'), $json);
    }

    public function testUnknownTypesAndBadValuesAreRejected(): void
    {
        $this->assertNull(StructuredData::sanitize(['type' => 'Malware', 'fields' => []])[0]);
        $this->assertNull(StructuredData::sanitize('Product')[0]);
        [$clean] = StructuredData::sanitize(['type' => 'Event', 'fields' => ['name' => 'Open day', 'startDate' => 'next friday', 'eventStatus' => 'Evil']]);
        $this->assertSame(['name' => 'Open day'], $clean['fields']);
    }

    public function testMissingPropertiesAreListed(): void
    {
        [$clean] = StructuredData::sanitize(['type' => 'Event', 'fields' => ['name' => 'Open day']]);
        $missing = StructuredData::missing($clean);

        $this->assertSame(['startDate', 'location'], $missing['required']);
        $this->assertContains('endDate', $missing['recommended']);
    }

    public function testNestingIsLimitedAndListsAreCapped(): void
    {
        $deep = ['type' => 'Review', 'fields' => ['author' => ['type' => 'Person', 'fields' => ['worksFor' => ['type' => 'Organization', 'fields' => ['name' => 'X']]]]]];
        $this->assertNotNull(StructuredData::sanitize($deep)[0]);

        [$clean] = StructuredData::sanitize(['type' => 'Recipe', 'fields' => ['name' => 'Soup', 'recipeIngredient' => array_map(fn (int $i): string => "item $i", range(1, 120))]]);
        $this->assertCount(StructuredData::MAX_LIST, $clean['fields']['recipeIngredient']);
    }

    public function testDurationsAndDates(): void
    {
        [$clean] = StructuredData::sanitize(['type' => 'Recipe', 'fields' => ['name' => 'Soup', 'prepTime' => 'PT15M', 'cookTime' => '15 minutes', 'datePublished' => '2026-10-09']]);

        $this->assertSame('PT15M', $clean['fields']['prepTime']);
        $this->assertArrayNotHasKey('cookTime', $clean['fields']);
        $this->assertSame('2026-10-09', $clean['fields']['datePublished']);
    }
}
