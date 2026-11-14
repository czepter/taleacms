<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;

/** build_from_html and the import turn tabs, counters, maps and embeds into their elements, and leave honeypots and captchas out. */
final class HtmlConverterWidgetsTest extends TestCase
{
    /** @return list<array<string, mixed>> every element of the build, depth first */
    private static function flat(array $children): array
    {
        $out = [];
        foreach ($children as $el) {
            $out[] = $el;
            array_push($out, ...self::flat($el['children'] ?? []));
        }

        return $out;
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} the elements of a type and the notes */
    private function convert(string $html, string $type): array
    {
        $result = HtmlConverter::convert($html);
        $this->assertSame([], Build::sanitize($result['build'], false)[1], 'the build passes the validator without a message');

        return [array_values(array_filter(self::flat($result['build']['children']), fn (array $el): bool => $el['type'] === $type)), $result['notes']];
    }

    public function testAriaTabsBecomeATabsElement(): void
    {
        [$tabs] = $this->convert('<section><div role="tablist"><button role="tab" aria-controls="p2">Prices</button><button role="tab" aria-controls="p1">About</button><button role="tab">Contact</button></div>'
            . '<div role="tabpanel" id="p1"><p>About text</p></div><div role="tabpanel" id="p2"><p>Price list</p></div><div role="tabpanel"><p>Write to us</p></div></section>', 'tabs');
        $this->assertCount(1, $tabs);
        $this->assertSame(['Prices', 'About', 'Contact'], array_column($tabs[0]['content']['tabs'], 'name'));
        $this->assertSame(['<p>Price list</p>', '<p>About text</p>', '<p>Write to us</p>'], array_column($tabs[0]['content']['tabs'], 'content'), 'panels by aria-controls, the third by order');
        [$text] = $this->convert('<section><div role="tablist"><button role="tab">Alone</button></div><p>No panel.</p></section>', 'tabs');
        $this->assertSame([], $text, 'a tab list without panels is ordinary content');
    }

    public function testAFigureWithDataIsACounter(): void
    {
        [$counters] = $this->convert('<section><figure><data value="250">+250 </data><figcaption>customers</figcaption></figure><figure><data value="99">99 %</data><figcaption>happy</figcaption></figure>'
            . '<div><data value="12">12+</data><small>years</small></div></section>', 'counter');
        $this->assertSame(['250', '+', '', 'customers'], array_values(array_map(fn ($v): string => (string) $v, [$counters[0]['content']['number'], $counters[0]['content']['prefix'], $counters[0]['content']['suffix'], $counters[0]['content']['caption']])));
        $this->assertSame(['99', ' %', 'happy'], [$counters[1]['content']['number'], $counters[1]['content']['suffix'], $counters[1]['content']['caption']]);
        $this->assertSame(['12', '+', 'years'], [$counters[2]['content']['number'], $counters[2]['content']['suffix'], $counters[2]['content']['caption']]);
    }

    public function testMapFramesBecomeMapElements(): void
    {
        $frame = fn (string $src): string => '<section><iframe src="' . $src . '"></iframe></section>';
        [$google] = $this->convert($frame('https://www.google.com/maps?q=Main+Street+1,+Springfield&z=17&output=embed'), 'map');
        $this->assertSame(['Main Street 1, Springfield', '17'], [$google[0]['content']['address'], $google[0]['content']['zoom']]);
        [$byCoordinates] = $this->convert($frame('https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2559!2d14.42!3d50.08!2m3!1f0!5e0!3m2!1sen!2s!4v1'), 'map');
        $this->assertSame('50.08, 14.42', $byCoordinates[0]['content']['address'], 'a language code after !2s is not an address');
        [$osm] = $this->convert($frame('https://www.openstreetmap.org/export/embed.html?bbox=14.3%2C50.0%2C14.5%2C50.2&marker=50.1,14.4'), 'map');
        $this->assertSame('50.1, 14.4', $osm[0]['content']['address']);
        [$mapy] = $this->convert($frame('https://frame.mapy.cz/s/abc?x=14.4&y=50.1&z=13'), 'map');
        $this->assertSame(['50.1, 14.4', '13'], [$mapy[0]['content']['address'], $mapy[0]['content']['zoom']]);

        [$none, $notes] = $this->convert($frame('https://www.google.com/maps/embed?pb=!1m2!5e0'), 'map');
        $this->assertSame([], $none, 'a map that says nothing is not guessed');
        $this->assertContains('A map frame (www.google.com) was not converted – add a Map element with the address.', $notes);
    }

    public function testKnownServiceFramesBecomeEmbedsAndMoreVideoAddresses(): void
    {
        [$embeds] = $this->convert('<section><iframe src="https://calendly.com/acme/intro" title="Book a call"></iframe><iframe src="https://tally.so/embed/abc123"></iframe></section>', 'embed');
        $this->assertSame(['https://calendly.com/acme/intro', 'Book a call'], [$embeds[0]['content']['address'], $embeds[0]['content']['title']]);
        $this->assertCount(2, $embeds);
        foreach (['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'] as $address) {
            [$video] = $this->convert('<section><iframe data-src="' . $address . '"></iframe></section>', 'video');
            $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video[0]['content']['url'], $address);
        }
        [$vimeo] = $this->convert('<section><iframe src="https://vimeo.com/123456"></iframe></section>', 'video');
        $this->assertSame('https://vimeo.com/123456', $vimeo[0]['content']['url']);
    }

    public function testHoneypotAndCaptchaFieldsAreLeftOut(): void
    {
        [$forms, $notes] = $this->convert('<section><form><label>Name <input name="name"></label><label>E-mail <input type="email" name="email"></label>'
            . '<div style="display: none"><input name="website"></div><input name="trap" tabindex="-1" autocomplete="off"><div aria-hidden="true"><input name="fax"></div>'
            . '<textarea name="g-recaptcha-response"></textarea><div data-sitekey="k"><input name="captcha"></div><textarea name="message"></textarea><button>Send</button></form></section>', 'form');
        $this->assertSame(['Name', 'E-mail', 'message'], array_column($forms[0]['content']['fields'], 'label'));
        $this->assertContains('Spam traps and captcha fields were left out – Talea protects its forms itself.', $notes);
    }
}
