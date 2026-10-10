<?php

declare(strict_types=1);

namespace Talea\Tests\Site\McpBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** HF-11: the Structured data element puts typed JSON-LD into the page's single @graph, linked to the company and website nodes. */
#[Group('site')]
final class StructuredDataElementTest extends SiteTestCase
{
    /** @param list<array<string, mixed>> $elements */
    private function publish(string $slug, array $elements): string
    {
        $build = json_encode(['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'children' => $elements]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->site()->exec('INSERT INTO tl_pages (slug, title, text, visible, build) VALUES (?, ?, ?, 1, ?)', [$slug, ucfirst($slug), '', $build]);
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
        $this->assertStringNotContainsString('data-tl-structured', $html, 'nothing visible on the page');
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

    public function testTheSchemaTypeToolDescribesTheVocabularyAndTheBuildToolsReportWhatIsMissing(): void
    {
        $types = $this->site()->mcpResult('list_schema_types', ['type' => 'Product']);
        $this->assertSame(['Product'], array_keys($types['types']));
        $this->assertSame('text required', $types['types']['Product']['properties']['name']);
        $this->assertStringContainsString('thing(Offer)[]', $types['types']['Product']['properties']['offers']);
        $this->assertGreaterThan(30, count($this->site()->mcpResult('list_schema_types')['types']));
        $this->assertStringContainsString('Unknown type', $this->site()->mcpText('list_schema_types', ['type' => 'Nope']));

        $this->site()->mcpText('create_page', ['title' => 'Lamp', 'slug' => 'lamp', 'visible' => true]);
        $id = $this->site()->publicId('pages', (int) $this->site()->value("SELECT page_id FROM tl_pages WHERE slug = 'lamp'"));
        $build = ['v' => 1, 'children' => [['id' => 'sd1', 'type' => 'structured_data', 'content' => ['data' => ['type' => 'Product', 'fields' => ['sku' => 'L-1']]]]]];

        $saved = $this->site()->mcpResult('save_build', ['id' => $id, 'build' => $build]);
        $this->assertSame([], $saved['errors']);
        $this->assertStringContainsString('missing required properties: name', json_encode($saved['check']));
        $read = $this->site()->mcpResult('get_build', ['id' => $id]);
        $this->assertSame('Product', $read['build']['children'][0]['content']['data']['type'] ?? null);
        $this->assertStringContainsString('missing required properties: name', json_encode($read['check']), 'get_build warns too');

        $edited = $this->site()->mcpResult('edit_build', ['id' => $id, 'operations' => [['op' => 'update', 'id' => 'sd1', 'content' => ['data' => ['type' => 'Product', 'fields' => ['name' => 'Lamp']]]]]]);
        $this->assertStringNotContainsString('structured data', json_encode($edited['check'] ?? []), 'a complete node has nothing to report');
    }

    public function testTheSiteAuditReportsAnIncompleteNode(): void
    {
        $this->publish('incomplete', [['type' => 'structured_data', 'content' => ['data' => ['type' => 'Product', 'fields' => ['sku' => 'X-1']]]]]);

        $audit = $this->site()->mcpText('site_audit', []);
        $this->assertStringContainsString('missing required properties: name', $audit);
        $this->assertPage('/admin.php?module=audit', 200, 'missing required properties', message: 'Site audit lists the incomplete structured data');
    }
}
