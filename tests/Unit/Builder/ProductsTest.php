<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Products;

final class ProductsTest extends TestCase
{
    /** 2.11: product catalogue (Builder\Products) */
    public function testCatalogue(): void
    {
        $this->assertSame(["Weight: 12 kg\nWidth: 60 cm", null, ''], [Products::cleanParameters("Weight:12 kg\n\n <b>Width</b>: 60 cm "), Products::cleanParameters('Weight'), Products::cleanParameters('')], '2.11 Products::cleanParameters – "Name: value" lines, tags gone; a line without a value is not valid');
        $this->assertSame(// check-english: allow
            ["S | A-1 | 1 200 Kč\nM\nL |  | from 900", null, null], [Products::cleanVariants("S | A-1 | 1 200 Kč\nM\nL | | from 900"), Products::cleanVariants('| X'), Products::cleanVariants('a | b | c | d')], '2.11 Products::cleanVariants – name | code | price, the empty end trimmed; too many parts or no name are not valid'); // check-english: allow
        $this->assertSame(['<table class="tl-parameters"><tbody><tr><th scope="row">Weight</th><td>12 &amp; &quot;13&quot; kg</td></tr></tbody></table>', '<table class="tl-variants"><thead><tr><th scope="col">' . t('Variant') . '</th></tr></thead><tbody><tr><td>S</td></tr><tr><td>M</td></tr></tbody></table>', ''], [Products::parametersTable('Weight: 12 & "13" kg'), Products::variantsTable("S\nM"), Products::parametersTable('')], '2.11 Products tables – escaped, a column only when some variant has it');
        $this->assertSame([['Weight', ['12 kg', '']], ['Width', ['60 cm', '80 cm']], ['Motor', ['', '2 kW']]], Products::comparison('p', [['data' => ['p' => "Weight: 12 kg\nWidth: 60 cm"]], ['data' => ['p' => "Width: 80 cm\nMotor: 2 kW"]]]), '2.11 Products::comparison – every parameter name once in the order it first appears, values side by side');
        $this->assertSame([null, 'variants'], [Products::fields(['preset' => 'people', 'fields' => []]), Products::fields(['preset' => 'products', 'fields' => [['key' => 'variants', 'type' => 'variants']]])['variants'] ?? null], '2.11 Products::fields – only a collection made from the products preset');
    }
}
