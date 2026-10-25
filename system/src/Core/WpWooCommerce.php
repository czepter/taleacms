<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * WooCommerce products of a WordPress export as a product catalogue: the post type "product" goes to a collection with
 * the fields of the Products preset (system/presets/products.php – an enquiry basket instead of a checkout):
 *
 *  - the featured image as the picture, the SKU as the code, the first product category as the category text, the excerpt
 *    as the summary, the content as the description, the attributes as parameters, the current price and the stock status
 *    as the availability;
 *  - the items keep the old address prefix (/product/<slug>), and every other old address redirects (Core\WpImport);
 *  - variations, orders and coupons have no counterpart and are listed with the other foreign types in the preview.
 *
 * Product categories as category pages wait until collections have categories. Pure functions, covered by
 * tests/Unit/Import/WooCommerceTest.php; Core\WpFile reads the values, Core\WpImport writes.
 */
final class WpWooCommerce
{
    public const string TYPE = 'product';

    /** The taxonomy of product categories; global attributes are taxonomies pa_<name> (pa_colour: Red). */
    public const string CATEGORIES = 'product_cat';
    public const string ATTRIBUTE_PREFIX = 'pa_';

    /** The product meta that is read: SKU, prices, stock status and the product's own attributes (serialized). */
    public const array META = ['_sku', '_regular_price', '_sale_price', '_price', '_stock_status', '_product_attributes'];

    /** The preset the products go to. */
    public const string PRESET = 'products';

    /** Stock status => the text of the availability field (a site text, translated into the product's language). */
    public const array AVAILABILITY = ['instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder'];

    /** @param array<string, mixed> $p a post from WpFile::item() */
    public static function isProduct(array $p): bool
    {
        return ($p['type'] ?? '') === self::TYPE && ($p['shop'] ?? []) !== [];
    }

    /**
     * The price a visitor saw: WooCommerce keeps the current one (the sale price while it runs) in _price, else the sale or
     * the regular price. A number with a dot; '' = none.
     *
     * @param array<string, string> $shop
     */
    public static function price(array $shop): string
    {
        foreach (['_price', '_sale_price', '_regular_price'] as $key) {
            $value = str_replace(',', '.', trim((string) ($shop[$key] ?? '')));
            if (preg_match('/^\d{1,12}(\.\d{1,6})?$/D', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The attributes as "Name: value" lines (the Parameters field): the product's own attributes from _product_attributes
     * (values "Red | Blue" become "Red, Blue"), the global ones by their terms. The serialized value is read by
     * WpLanguages::unserializeArray, never by unserialize().
     *
     * @param list<array{0: string, 1: string}> $terms [taxonomy, term name] of the global attributes
     */
    public static function parameters(string $serialized, array $terms): string
    {
        $global = [];
        foreach ($terms as [$taxonomy, $name]) {
            if ($name !== '') {
                $global[$taxonomy][] = $name;
            }
        }
        $lines = [];
        foreach (WpLanguages::unserializeArray($serialized) ?? [] as $key => $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $taxonomy = (string) ($attribute['name'] ?? $key);
            $isTaxonomy = (bool) ($attribute['is_taxonomy'] ?? false);
            $values = $isTaxonomy ? ($global[$taxonomy] ?? []) : explode('|', (string) ($attribute['value'] ?? ''));
            unset($global[$taxonomy]);
            $lines[] = self::line($isTaxonomy ? self::attributeLabel($taxonomy) : $taxonomy, $values);
        }
        foreach ($global as $taxonomy => $values) {
            $lines[] = self::line(self::attributeLabel((string) $taxonomy), $values);
        }

        return implode("\n", array_slice(array_values(array_filter($lines)), 0, 40));
    }

    /** pa_colour → Colour */
    private static function attributeLabel(string $taxonomy): string
    {
        return WpTypes::label(str_starts_with($taxonomy, self::ATTRIBUTE_PREFIX) ? substr($taxonomy, strlen(self::ATTRIBUTE_PREFIX)) : $taxonomy);
    }

    /** One line within the limits of Products::cleanParameters (no colon in the name, 80 and 200 characters); '' = nothing to say. @param list<string> $values */
    private static function line(string $label, array $values): string
    {
        $plain = fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags($s)));
        $label = mb_substr($plain(str_replace(':', ' ', $label)), 0, 80);
        $values = array_values(array_unique(array_filter(array_map($plain, $values), fn (string $v): bool => $v !== '')));

        return $label === '' || $values === [] ? '' : $label . ': ' . mb_substr(implode(', ', $values), 0, 200);
    }

    /**
     * The values of the preset's fields for one product (before Collections::sanitizeData); the image is the address of the
     * featured image, downloaded with the other images.
     *
     * @param array<string, mixed> $p a post from WpFile::item()
     * @param array<int, string> $attachments attachment number => address
     * @param callable(string): string $text translates a site text into the product's language
     * @return array<string, string>
     */
    public static function values(array $p, array $attachments, callable $text): array
    {
        $shop = (array) ($p['shop'] ?? []);
        $status = (string) ($shop['_stock_status'] ?? '');

        return [
            'image' => (string) ($attachments[(int) ($p['preview'] ?? 0)] ?? ''),
            'code' => mb_substr(trim((string) ($shop['_sku'] ?? '')), 0, 200),
            'category' => (string) (array_values((array) ($p['product_categories'] ?? []))[0] ?? ''),
            'summary' => trim(html_entity_decode(strip_tags((string) $p['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'description' => WpContent::sanitize((string) $p['content'], $attachments),
            'parameters' => self::parameters((string) ($shop['_product_attributes'] ?? ''), (array) ($p['attributes'] ?? [])),
            'price' => self::price($shop),
            'availability' => isset(self::AVAILABILITY[$status]) ? $text(self::AVAILABILITY[$status]) : '',
        ];
    }
}
