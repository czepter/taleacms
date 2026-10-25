<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Collections;
use Talea\Builder\Products;
use Talea\Core\ImageDownloader;
use Talea\Core\WpFile;
use Talea\Core\WpImport;
use Talea\Core\WpWooCommerce;

/** Import from WordPress: a WooCommerce shop as a product catalogue (tools/fixtures/wordpress-shop.xml), parallel image downloads. */
final class WooCommerceTest extends TestCase
{
    private const string FIXTURE = TALEA_ROOT . '/tools/fixtures/wordpress-shop.xml';

    public function testPricesAndParameters(): void
    {
        $this->assertSame(['99.90', '120', '45.50', '', '10'], [
            WpWooCommerce::price(['_price' => '99.90', '_regular_price' => '120']),
            WpWooCommerce::price(['_price' => '', '_regular_price' => '120']),
            WpWooCommerce::price(['_regular_price' => ' 45,50 ']),
            WpWooCommerce::price(['_price' => 'free', '_regular_price' => '1 200']),
            WpWooCommerce::price(['_price' => '', '_sale_price' => '10', '_regular_price' => '12']),
        ], 'price: the current price, else the sale or the regular one; a decimal comma becomes a dot, text is no price');

        $this->assertSame('Size EU: 40, 42', WpWooCommerce::parameters('a:1:{i:0;a:2:{s:4:"name";s:8:"Size: EU";s:5:"value";s:7:"40 | 42";}}', []),
            'parameters: a colon in the name would break the "Name: value" line');
        $this->assertSame('Size: L, XL', WpWooCommerce::parameters('', [['pa_size', 'L'], ['pa_size', 'XL'], ['pa_size', 'L'], ['pa_size', '']]),
            'parameters: global attributes by their terms, without duplicates and empty names');
        $this->assertSame(['', ''], [WpWooCommerce::parameters('O:8:"stdClass":0:{}', []), WpWooCommerce::parameters('a:1:{i:0;a:1:{s:4:"name";s:4:"Size";}}', [])],
            'parameters: an object is never read, an attribute without values says nothing');
    }

    public function testTheShopExportIsRead(): void
    {
        $items = iterator_to_array((new WpFile(self::FIXTURE))->items());
        $this->assertSame(['attachment', 'product', 'product', 'product_variation', 'shop_order'], array_column($items, 'type'), 'WpFile: the shop export in file order');
        $chair = $items[1];
        $this->assertSame(['_sku', '_regular_price', '_sale_price', '_price', '_stock_status', '_product_attributes'], array_keys($chair['shop']),
            'WpFile: the WooCommerce meta of a product (not _edit_lock, _stock or total_sales)');
        $this->assertSame([['chairs' => 'Chairs'], [['pa_colour', 'Natural'], ['pa_colour', 'Walnut']], 501, ['warranty']],
            [$chair['product_categories'], $chair['attributes'], $chair['preview'], array_keys(\Talea\Core\WpTypes::fields($chair['fields']))],
            'WpFile: product categories, global attribute terms, the featured image and the plain custom fields');
        $this->assertSame([[], []], [$items[0]['shop'], $items[3]['shop']], 'WpFile: the meta of other post types is not read as shop data');
        $this->assertSame([true, true, false, false], [WpWooCommerce::isProduct($items[1]), WpWooCommerce::isProduct($items[2]), WpWooCommerce::isProduct($items[3]),
            WpWooCommerce::isProduct(['type' => 'product', 'shop' => []])], 'isProduct: the post type with WooCommerce data');

        $state = WpImport::newState('wordpress-shop.xml');
        WpImport::analyze($state, 30, self::FIXTURE);
        $this->assertSame([['product' => [
            'count' => 2, 'prefixes' => ['product' => 2], 'fields' => ['warranty' => ['text' => 2]], 'left_out' => [], 'content' => true, 'excerpt' => true, 'product' => true]],
            ['product_variation' => 1, 'shop_order' => 1]], [$state['overview']['types'], $state['overview']['other']],
            'WpImport preview: products go to a product catalogue at /product/…, variations and orders are listed as foreign types');

        $values = WpWooCommerce::values($chair, $state['attachments'], fn (string $text): string => '[' . $text . ']');
        $this->assertSame([
            'image' => 'https://www.shop.example/wp-content/uploads/2026/03/oak-chair.jpg', 'code' => 'CH-100', 'category' => 'Chairs', 'summary' => 'Solid oak, oiled.',
            'parameters' => "Material: Oak, Ash\nColour: Natural, Walnut", 'price' => '99.90', 'availability' => '[In stock]',
        ], array_diff_key($values, ['description' => true]), 'values: picture, code, category, summary, parameters, price and the availability as a translated site text');
        $this->assertStringContainsString('<p>A solid chair for the dining room.</p>', $values['description'], 'values: the content is the description, cleaned like any WordPress content');
        $table = WpWooCommerce::values($items[2], $state['attachments'], fn (string $text): string => $text);
        $this->assertSame(['', '', '', '45.50', 'Out of stock'], [$table['image'], $table['category'], $table['parameters'], $table['price'], $table['availability']],
            'values: a product without a picture, category and attributes; the regular price when _price is empty');

        // the values pass the field rules of the Products preset unchanged
        $fields = array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], \Talea\Builder\Presets::get(WpWooCommerce::PRESET)['fields'] ?? []);
        $errors = [];
        $data = Collections::sanitizeData($fields, $values, $errors);
        $this->assertSame([[], 'CH-100', '99.90', [['Material', 'Oak, Ash'], ['Colour', 'Natural, Walnut']]], [$errors, $data['code'], $data['price'], Products::parameters($data['parameters'])],
            'the Products preset keeps code, price and the parameters table');
    }

    public function testParallelDownloadsKeepTheAddressRules(): void
    {
        $downloader = new ImageDownloader('https://www.shop.example');
        $results = $downloader->downloadMany(['https://other.example/a.jpg', 'http://169.254.169.254/latest/meta-data', 'https://user:pw@shop.example/a.jpg']);
        $this->assertSame(['https://other.example/a.jpg', 'http://169.254.169.254/latest/meta-data', 'https://user:pw@shop.example/a.jpg'], array_keys($results),
            'downloadMany: one answer per address');
        $this->assertSame('The address does not belong to the old site.', $results['https://other.example/a.jpg']->getMessage(), 'downloadMany: another host is refused before any request');
        $this->assertSame('The address does not belong to the old site.', $results['http://169.254.169.254/latest/meta-data']->getMessage(), 'downloadMany: an internal address is refused');
        $this->assertSame('The address does not belong to the old site.', $results['https://user:pw@shop.example/a.jpg']->getMessage(), 'downloadMany: credentials in the address are refused');
        $source = (string) file_get_contents(TALEA_ROOT . '/system/src/Core/ImageDownloader.php');
        $this->assertSame([1, 1, true], [substr_count($source, 'curl_init('), substr_count($source, 'Outbound::pin('), str_contains($source, 'CURLMOPT_MAX_HOST_CONNECTIONS')],
            'downloadMany: one place builds a curl handle and pins it, the parallel requests are limited per host');
    }
}
