<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Front\Seo;

final class SeoTest extends TestCase
{
    /** FAQ */
    public function testFaq(): void
    {
        $this->assertSame([['Kdy to začne?', 'V pondělí.'], ['Kolik to stojí?', 'Nic.']], Seo::faq("Kdy to začne?\nV pondělí.\n\nKolik to stojí?\nNic."), 'Seo::faq: question and answer pairs'); // check-english: allow
        $this->assertSame([], Seo::faq(null), 'Seo::faq: empty input');
    }

    /** marketing codes and consent */
    public function testMarketingCodesAndConsent(): void
    {
        $this->assertSame('<script src="x.js"></script>', \Talea\Front\Seo::deferUntilConsent('<script src="x.js"></script>', 'none'), 'Seo::deferUntilConsent: without a bar unchanged');
        $this->assertSame('<template data-consent="marketing"><ins></ins><script>a()</script></template>', \Talea\Front\Seo::deferUntilConsent('<ins></ins><script>a()</script>', 'builtin'), 'Seo::deferUntilConsent: the built-in bar wraps in <template>');
        $this->assertSame('<ins></ins><script type="text/plain" data-cookieconsent="marketing" async src="x.js"></script><script type="application/json">{}</script>', \Talea\Front\Seo::deferUntilConsent('<ins></ins><SCRIPT async src="x.js"></script><script type="application/json">{}</script>', 'external'), 'Seo::deferUntilConsent: an external service gets marked scripts');
    }

    public function testDescriptionAndSecurityTxt(): void
    {
        // 2.1: a page without its own description gets the start of its first longer paragraph
        $this->assertSame(['We build kitchens & bathrooms in Zlín and around it since 1998.', '', 160], [\Talea\Front\Kernel::descriptionFrom('<h1>Hi</h1><p>Short.</p><p class="x">We build <strong>kitchens</strong> &amp; bathrooms in Zlín   and around it since 1998.</p><p>Later text that is long enough to be picked but comes second.</p>'), // check-english: allow
            \Talea\Front\Kernel::descriptionFrom('<p>tiny</p>'), mb_strlen(\Talea\Front\Kernel::descriptionFrom('<p>' . str_repeat('word ', 80) . '</p>'))], '2.1: description from the first longer paragraph'); // check-english: allow
        // 2.1: security.txt (RFC 9116) from the Security contact setting
        $this->assertSame(["Contact: mailto:security@example.com\nExpires: 2027-03-23T00:00:00Z\nPreferred-Languages: en, de\nCanonical: https://example.com/.well-known/security.txt\n",
            "Contact: https://example.com/security\nExpires: 2027-03-23T00:00:00Z\nCanonical: https://example.com/web/.well-known/security.txt\n"], [\Talea\Front\Seo::securityTxt('security@example.com', 'https://example.com/', ['en', 'de'], 1790000000),
            \Talea\Front\Seo::securityTxt('https://example.com/security', 'https://example.com/web', [], 1790000000)], '2.1: security.txt');
    }
}
