<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** HF-11: the Structured data element puts typed JSON-LD into the page's single @graph, linked to the company and website nodes. */
#[Group('site')]
final class StructuredDataElementTest extends SiteTestCase
{
    /** @param list<array<string, mixed>> $elements */
    private function publish(string $slug, array $elements): string
    {
        $build = json_encode(['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'children' => $elements]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->site()->exec('INSERT INTO ka_pages (slug, title, text, visible, build) VALUES (?, ?, ?, 1, ?)', [$slug, ucfirst($slug), '', $build]);
        $this->site()->clearPageCache();

        return $this->site()->client('visitor')->get('/' . $slug)->body;
    }

    /** @return list<array<string, mixed>> the nodes of the page's JSON-LD graph */
    private function graph(string $html): array
    {
        $this->assertSame(1, preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'exactly one JSON-LD block');
        $data = json_decode($m[1][0], true);
        $this->assertIsArray($data, 'the block parses as JSON');

        return $data['@graph'];
    }

    public function testAProductNodeJoinsTheGraphLinkedToTheCompanyAndTheWebsite(): void
    {
        $html = $this->publish('chair', [['type' => 'structured_data', 'content' => ['data' => ['type' => 'Product', 'fields' => [
            'name' => 'Oak chair', 'sku' => 'C-1', 'image' => '/media/chair.jpg',
            'offers' => [['type' => 'Offer', 'fields' => ['price' => '149.9', 'priceCurrency' => 'EUR', 'availability' => 'InStock']]],
        ]]]]]);
        $graph = $this->graph($html);
        $types = array_column($graph, '@type');
        $product = $graph[array_search('Product', $types, true)];

        $this->assertContains('WebSite', $types);
        $this->assertSame('Oak chair', $product['name']);
        $this->assertStringEndsWith('/chair#data-1', $product['@id']);
        $this->assertSame('https://schema.org/InStock', $product['offers'][0]['availability']);
        $company = array_values(array_filter($graph, fn (array $n): bool => in_array($n['@type'], ['Organization', 'LocalBusiness'], true)))[0];
        $this->assertSame(['@id' => $company['@id']], $product['offers'][0]['seller'], 'the offer is linked to the company node');
        $this->assertStringNotContainsString('data-ka-structured', $html, 'nothing visible on the page');
    }

    public function testAManualFaqPageReplacesTheAutomaticOne(): void
    {
        $html = $this->publish('questions', [
            ['type' => 'faq', 'content' => ['items' => [['question' => 'Why?', 'answer' => 'Because.']]]],
            ['type' => 'structured_data', 'content' => ['data' => ['type' => 'FAQPage', 'fields' => ['mainEntity' => [
                ['type' => 'Question', 'fields' => ['name' => 'Manual?', 'acceptedAnswer' => ['type' => 'Answer', 'fields' => ['text' => 'Yes.']]]],
            ]]]]],
        ]);
        $faq = array_values(array_filter($this->graph($html), fn (array $n): bool => $n['@type'] === 'FAQPage'));

        $this->assertCount(1, $faq, 'exactly one FAQPage');
        $this->assertSame('Manual?', $faq[0]['mainEntity'][0]['name']);
    }

    public function testHostileAndUnknownValuesNeverReachThePage(): void
    {
        $html = $this->publish('evil', [['type' => 'structured_data', 'content' => ['data' => ['type' => 'Product', 'fields' => [
            'name' => '</script><script>alert(1)</script>Evil', 'url' => 'javascript:alert(1)', 'bogus' => 'x',
        ]]]], ['type' => 'structured_data', 'content' => ['data' => ['type' => 'Malware', 'fields' => ['name' => 'x']]]]]);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('Malware', $html);
        $graph = $this->graph($html);
        $product = $graph[array_search('Product', array_column($graph, '@type'), true)];
        $this->assertSame('alert(1)Evil', $product['name']);
        $this->assertArrayNotHasKey('url', $product);
        $this->assertArrayNotHasKey('bogus', $product);
    }
}
