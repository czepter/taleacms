<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 product catalogue without a checkout (was: section 58 of tools/test.sh). field-types from section 56 is recreated for the comparison check. */
#[Group('site')]
final class ProductCatalogueTest extends SiteTestCase
{
    use CollectionsHelpers;

    public function testProductPageAndComparison(): void
    {
        $this->mcpText('create_collection', ['name' => 'Field types', 'slug' => 'field-types', 'item_pages' => true, 'fields' => [['label' => 'Start', 'type' => 'datetime']]]);
        $this->mcpText('save_collection_item', ['collection' => 'field-types', 'name' => 'Open day', 'slug' => 'open-day', 'values' => ['start' => '2026-11-02T17:00'], 'visible' => true]);

        $this->mcpText('create_collection', ['name' => 'Products test', 'preset' => 'products']);
        $this->mcpText('save_collection_item', ['collection' => 'products-test', 'name' => 'Lounger Basic', 'slug' => 'lounger-basic', 'values' => [
            'code' => 'LB-1', 'category' => 'Loungers', 'price' => '12900', 'parameters' => "Width: 60 cm\nLoad: 150 kg", 'variants' => "Blue | LB-1-M | $129\nGrey | LB-1-S"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'products-test', 'name' => 'Lounger Pro', 'slug' => 'lounger-pro', 'values' => [
            'code' => 'LP-2', 'category' => 'Loungers', 'parameters' => "Width: 70 cm\nMotor: 2 kW"], 'visible' => true]);
        $bad = $this->mcpText('save_collection_item', ['collection' => 'products-test', 'name' => 'Bad', 'slug' => 'bad', 'values' => ['parameters' => 'text without a value']]);
        $this->assertMatchesRegularExpression('/invalid_fields.*parameters/s', $bad, 'parameters without a value are refused');

        $page = $this->visitor()->get('/products-test/lounger-basic');
        $this->assertStringContainsString('<th scope="row">Load</th><td>150 kg</td>', $page->body, 'the product page has the parameters');
        $this->assertStringContainsString('class="ka-enquiry-button"', $page->body, '... Add to enquiry');
        $this->assertStringContainsString('<option>Grey</option>', $page->body, '... with variants');
        $this->assertStringContainsString('"@type":"Product"', $page->body, '... and Product data');
        $this->assertStringContainsString('image/web.js', $page->body, 'the page keeps the basket script');
        $this->assertStringNotContainsString('href="#"', $page->body, '... and has no button to a datasheet it does not have');

        $compare = $this->visitor()->get('/products-test/_compare?i=lounger-basic,lounger-pro,neni');
        $this->assertStringContainsString('<th scope="row">Width</th><td>60 cm</td><td>70 cm</td>', $compare->body, 'the comparison puts the parameters side by side');
        $this->assertStringContainsString('<th scope="row">Motor</th><td></td><td>2 kW</td>', $compare->body);
        $this->assertStringContainsString('noindex', $compare->body, '... and is not indexed');
        $this->assertSame(404, $this->visitor()->get('/products-test/_compare?i=neni')->status, 'a comparison without known products is not found');
        $this->assertSame(404, $this->visitor()->get('/field-types/_compare?i=open-day')->status, 'a collection that is not a catalogue has no comparison');
    }

    /** Without the script: Add to enquiry opens the list page with the product, the basket field has it. */
    public function testBasketGoesIntoAnEnquiry(): void
    {
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', (int) $this->sq("SELECT page_id FROM ka_pages WHERE slug = 'products-test'")), 'visible' => true]);
        $visitor = $this->visitor();
        $page = $visitor->get('/products-test?product=products-test/lounger-basic&variant=' . rawurlencode('Grey') . '&quantity=2');
        $this->assertStringContainsString('data-basket-field', $page->body, 'the enquiry form has the basket field');
        $this->assertStringContainsString('2 × Lounger Basic – Grey (LB-1-S)', $page->body, 'the enquiry form takes the product from the address');

        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");
        $fields = ['source' => $page->field('source'), 'element' => $this->lastField($page, 'element'), 'back' => '/products-test', 'as_time' => $this->lastField($page, 'as_time'), 'as_signature' => $this->lastField($page, 'as_signature'), 'p1' => 'Eve', 'p2' => 'eva@example.cz', 'p5' => '1'];
        sleep(4); // the antispam minimum time, as the old script waited
        $send = static fn (string $basket): string => $visitor->post('/form', $fields + ['p0' => $basket])->redirect;

        $this->assertStringContainsString('result=field', $send('[{"c":"products-test","i":"lounger-pro","v":"Gold","q":1}]'), 'a variant the product does not have is refused');
        $this->assertStringContainsString('result=field', $send('[]'), 'an empty basket is refused');
        $this->assertStringContainsString('result=ok', $send('[{"c":"products-test","i":"lounger-basic","v":"Blue","q":3},{"c":"products-test","i":"lounger-pro","v":"","q":1,"n":"<script>"}]'), 'the basket is sent');

        $data = $this->sq('SELECT data FROM ka_enquiries ORDER BY enquiry_id DESC LIMIT 1');
        $this->assertStringContainsString('3 × Lounger Basic – Blue (LB-1-M)', $data, 'the enquiry lists the products as the database has them');
        $this->assertStringContainsString('1 × Lounger Pro (LP-2)', $data);
        $this->assertStringNotContainsString('script', $data, '... never the visitor\'s text');
    }
}
