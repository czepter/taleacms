<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 product catalogue without a checkout (was: section 58 of tools/test.sh). typy-poli from section 56 is recreated for the comparison check. */
#[Group('site')]
final class ProductCatalogueTest extends SiteTestCase
{
    use CollectionsHelpers;

    public function testProductPageAndComparison(): void
    {
        $this->mcpText('create_collection', ['name' => 'Typy polí', 'slug' => 'typy-poli', 'item_pages' => true, 'fields' => [['label' => 'Začátek', 'type' => 'datetime']]]);
        $this->mcpText('save_collection_item', ['collection' => 'typy-poli', 'name' => 'Den otevřených dveří', 'slug' => 'den-otevrenych-dveri', 'values' => ['zacatek' => '2026-11-02T17:00'], 'visible' => true]);

        $this->mcpText('create_collection', ['name' => 'Produkty test', 'preset' => 'products']);
        $this->mcpText('save_collection_item', ['collection' => 'produkty-test', 'name' => 'Lehátko Basic', 'slug' => 'lehatko-basic', 'values' => [
            'code' => 'LB-1', 'category' => 'Lehátka', 'price' => '12900', 'parameters' => "Šířka: 60 cm\nNosnost: 150 kg", 'variants' => "Modrá | LB-1-M | 12 900 Kč\nŠedá | LB-1-S"], 'visible' => true]);
        $this->mcpText('save_collection_item', ['collection' => 'produkty-test', 'name' => 'Lehátko Pro', 'slug' => 'lehatko-pro', 'values' => [
            'code' => 'LP-2', 'category' => 'Lehátka', 'parameters' => "Šířka: 70 cm\nMotor: 2 kW"], 'visible' => true]);
        $bad = $this->mcpText('save_collection_item', ['collection' => 'produkty-test', 'name' => 'Špatné', 'slug' => 'spatne', 'values' => ['parameters' => 'jen text bez hodnoty']]);
        $this->assertMatchesRegularExpression('/invalid_fields.*parameters/s', $bad, 'parameters without a value are refused');

        $page = $this->visitor()->get('/produkty-test/lehatko-basic');
        $this->assertStringContainsString('<th scope="row">Nosnost</th><td>150 kg</td>', $page->body, 'the product page has the parameters');
        $this->assertStringContainsString('class="ka-do-poptavky"', $page->body, '... Add to enquiry');
        $this->assertStringContainsString('<option>Šedá</option>', $page->body, '... with variants');
        $this->assertStringContainsString('"@type":"Product"', $page->body, '... and Product data');
        $this->assertStringContainsString('image/web.js', $page->body, 'the page keeps the basket script');
        $this->assertStringNotContainsString('href="#"', $page->body, '... and has no button to a datasheet it does not have');

        $compare = $this->visitor()->get('/produkty-test/_porovnat?i=lehatko-basic,lehatko-pro,neni');
        $this->assertStringContainsString('<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>', $compare->body, 'the comparison puts the parameters side by side');
        $this->assertStringContainsString('<th scope="row">Motor</th><td></td><td>2 kW</td>', $compare->body);
        $this->assertStringContainsString('noindex', $compare->body, '... and is not indexed');
        $this->assertSame(404, $this->visitor()->get('/produkty-test/_porovnat?i=neni')->status, 'a comparison without known products is not found');
        $this->assertSame(404, $this->visitor()->get('/typy-poli/_porovnat?i=den-otevrenych-dveri')->status, 'a collection that is not a catalogue has no comparison');
    }

    /** Without the script: Add to enquiry opens the list page with the product, the basket field has it. */
    public function testBasketGoesIntoAnEnquiry(): void
    {
        $this->mcpText('update_page', ['id' => (int) $this->sq("SELECT page_id FROM ka_pages WHERE slug = 'produkty-test'"), 'visible' => true]);
        $visitor = $this->visitor();
        $page = $visitor->get('/produkty-test?product=produkty-test/lehatko-basic&variant=' . rawurlencode('Šedá') . '&quantity=2');
        $this->assertStringContainsString('data-kosik-pole', $page->body, 'the enquiry form has the basket field');
        $this->assertStringContainsString('2 × Lehátko Basic – Šedá (LB-1-S)', $page->body, 'the enquiry form takes the product from the address');

        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'formular'");
        $fields = ['source' => $page->field('source'), 'element' => $this->lastField($page, 'element'), 'zpet' => '/produkty-test', 'as_cas' => $this->lastField($page, 'as_cas'), 'as_podpis' => $this->lastField($page, 'as_podpis'), 'p1' => 'Eva', 'p2' => 'eva@example.cz', 'p5' => '1'];
        sleep(4); // the antispam minimum time, as the old script waited
        $send = static fn (string $basket): string => $visitor->post('/formular', $fields + ['p0' => $basket])->redirect;

        $this->assertStringContainsString('result=pole', $send('[{"c":"produkty-test","i":"lehatko-pro","v":"Zlatá","q":1}]'), 'a variant the product does not have is refused');
        $this->assertStringContainsString('result=pole', $send('[]'), 'an empty basket is refused');
        $this->assertStringContainsString('result=ok', $send('[{"c":"produkty-test","i":"lehatko-basic","v":"Modrá","q":3},{"c":"produkty-test","i":"lehatko-pro","v":"","q":1,"n":"<script>"}]'), 'the basket is sent');

        $data = $this->sq('SELECT data FROM ka_enquiries ORDER BY enquiry_id DESC LIMIT 1');
        $this->assertStringContainsString('3 × Lehátko Basic – Modrá (LB-1-M)', $data, 'the enquiry lists the products as the database has them');
        $this->assertStringContainsString('1 × Lehátko Pro (LP-2)', $data);
        $this->assertStringNotContainsString('script', $data, '... never the visitor\'s text');
    }
}
