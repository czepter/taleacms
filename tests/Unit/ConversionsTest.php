<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConversionsTest extends TestCase
{
    /** 2.12: calls and e-mail clicks counted as conversions (Core\Conversions) */
    public function testConversions(): void
    {
        $clickType = \Talea\Core\Conversions::type(...);
        $clickPath = \Talea\Core\Conversions::path(...);
        $this->assertSame(['tel', 'mailto', 'whatsapp', null, null, null, null], [$clickType('tel'), $clickType(' MAILTO '), $clickType('whatsapp'), $clickType('fax'), $clickType(''), $clickType('tel:+420'), $clickType('click_phone')], '2.12 Conversions::type – tel, mailto and whatsapp only, case and spaces forgiven, anything else is not counted');
        $this->assertSame(['/kontakt', '/kontakt', '/en/contact', '/', null, null, null, null, null, '/' . str_repeat('a', 254), null], [$clickPath('/kontakt'), $clickPath('/kontakt?utm_source=x#telefon'), $clickPath(' /en/contact '), $clickPath('/'), $clickPath('kontakt'), $clickPath('https://example.com/kontakt'), $clickPath('/kon takt'), $clickPath("/a\nb"), $clickPath(''),
                $clickPath('/' . str_repeat('a', 254)), $clickPath('/' . str_repeat('a', 255))], '2.12 Conversions::path – an absolute path without the query string and the fragment, at most 255 characters; anything else is a forged request');
        $clickLink = fn (string $href): int => preg_match(\Talea\Core\Conversions::LINK_PATTERN, '<p><a href="' . $href . '">x</a></p>');
        $this->assertSame([1, 1, 1, 1, 1, 0, 0, \Talea\Core\Conversions::TYPES, true], [$clickLink('tel:+420123456789'), $clickLink('mailto:info@example.com'), $clickLink('https://wa.me/420123456789'), $clickLink('https://api.whatsapp.com/send?phone=1'), $clickLink('whatsapp://send?phone=1'), $clickLink('https://example.com/tel:'), $clickLink('/kontakt'),
                array_keys(\Talea\Core\Conversions::KEYS), in_array('conversion', \Talea\Admin\Modules\Pages::RESERVED_SLUGS, true)], '2.12 Conversions::LINK_PATTERN – the links the script counts (tel:, mailto:, wa.me, api.whatsapp.com, whatsapp:), not an ordinary link; KEYS name every type');
        $this->assertSame([7, 1, 0], [\Talea\Core\Conversions::total(['calls' => 2, 'emails' => 1, 'whatsapp' => 4]), \Talea\Core\Conversions::total(['path' => '/x', 'calls' => 1]), \Talea\Core\Conversions::total([])], '2.12 Conversions::total – every type together, missing keys are zero');
    }
}
