<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Tests\Support\FakeSettings;

final class SecurityReleaseTest extends TestCase
{
    /** 3.3.2: attribute text never becomes markup – sanitized HTML is changed on the DOM only (N23, N30, N6) */
    public function testAttributeTextNeverBecomesMarkup(): void
    {
        $types = (new \ReflectionClass(\Talea\Front\NewsText::class))->newInstanceWithoutConstructor();
        // what a browser would run: a script-capable element or an on… attribute anywhere in the parsed HTML
        $liveMarkup = static function (string $html): bool {
            $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
            foreach ($doc->querySelectorAll('*') as $el) {
                if (in_array(strtolower($el->localName), ['svg', 'script', 'iframe', 'object', 'embed', 'math'], true)) {
                    return true;
                }
                foreach ($el->attributes as $a) {
                    if (str_starts_with(strtolower($a->name), 'on')) {
                        return true;
                    }
                }
            }

            return false;
        };
        // the two payloads of the 2026-10-06 audit: an image alt with markup, and text that fooled the website import's class/id strip
        $n23Img = '<p>Photo</p><img src="https://old.example/a.jpg" alt="q><svg onload=alert(2)>">';
        $n23Ids = '<p>' . str_repeat('Our workshop makes oak tables. ', 4) . ' id="</p><p title="><svg onload=alert(1)>">b</p>';
        $n23Clean = \Talea\Core\WpContent::sanitize($n23Img);
        $this->assertSame(["<p>Photo</p>\n<figure><img src=\"https://old.example/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" loading=\"lazy\"></figure>", '<p title="a&lt;b&gt;c">x</p>', ''], [$n23Clean, \Talea\Core\Html::safe('<p title="a<b>c">x</p>'), \Talea\Core\Html::safe('<xmp><img src=x onerror=alert(1)></xmp>')], '3.3.2 N23: the sanitizers escape < and > inside attribute values');
        $this->assertSame([false, false], [
            $liveMarkup((string) preg_replace_callback('#<img\b[^>]*>#i', fn (): string => '<img src="/media/2026/01/a.jpg" alt="">', $n23Clean)),
            $liveMarkup((string) preg_replace('/\s(?:class|id|srcset|sizes)="[^"]*"/i', '', \Talea\Core\Html::safe($n23Ids))),
        ], '3.3.2 N23: even the old regular expressions over sanitized HTML make no markup any more');
        $n23Rewritten = \Talea\Core\WpImport::rewriteImages($n23Clean, fn (string $src, string $alt): array => \Talea\Core\WpImport::mediaImage('', ['image_path' => 'media/2026/01/a.jpg', 'image_width' => 800, 'image_height' => 600, 'media_id' => 7], $alt));
        $this->assertSame([false, "<p>Photo</p>\n<figure><img src=\"/media/2026/01/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" width=\"800\" height=\"600\" data-id=\"7\" loading=\"lazy\"></figure>", $n23Clean], [$liveMarkup($n23Rewritten), $n23Rewritten, \Talea\Core\WpImport::rewriteImages($n23Clean, fn (): ?array => null)], '3.3.2 N23: importers point images at Media on the DOM, the alt stays text');
        $n23Page = \Talea\Core\WebImport::extract('<html><body><main><h1>Workshop</h1>' . $n23Ids . $n23Img . '</main></body></html>', 'https://old.example/workshop');
        $this->assertSame([false, false, true, '<img src="https://x.example/a.jpg" alt="A"><p>t</p>'], [
            $liveMarkup($n23Page['content']), $liveMarkup(\Talea\Core\Html::rewriteImages($n23Page['content'], fn (): bool => false)), str_contains($n23Page['content'], 'Our workshop'),
            \Talea\Core\WebImport::safeContent('<picture><source srcset="a.webp" type="image/webp"><img src="https://x.example/a.jpg" srcset="a.jpg 2x" alt="A"></picture><p class="x" id="y">t</p>'),
        ], '3.3.2 N23: website import – no markup from the class/id strip, nor when an image download fails');
        $this->assertSame(['<figure><img src="https://old.example/a.jpg" alt="" loading="lazy"></figure>', '<figure><img src="/media/a.jpg" alt="" data-id="7" loading="lazy"></figure>'], [\Talea\Core\WpContent::sanitize('<img src="https://old.example/a.jpg" alt="" data-id="99">'), \Talea\Core\WpContent::safeHtml('<img src="/media/a.jpg" alt="" data-id="7">')], '3.3.2 N23: WordPress images – the old site\'s data-id goes, the Media number of a rewrite stays');
        // text stored before 3.3.2 can have a raw > inside an attribute: what the site and the assistant do with it must stay inert
        $n23Legacy = '<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" title="a>b</a></p><img src=x onerror=alert(1)>">video</a></p><p>Rest</p>';
        $n23Parts = \Talea\Core\Assistant::decompose('<p title="x>y">Ahoj</p><p title=" onmouseover=alert(1) z">Svete</p>');
        $this->assertSame([false, 1, false], [
            $liveMarkup($types->embedVideoUrls($n23Legacy)), substr_count($types->embedVideoUrls($n23Legacy), 'data-insert'),
            $liveMarkup(\Talea\Core\Assistant::compose($n23Parts['skeleton'], $n23Parts['segments'])),
        ], '3.3.2 N23: video embedding and the translation skeleton work on the DOM, older text stays inert');
        $this->assertSame(['', '<style>p::after{content:"\3c b>"}</style>', '<iframe src="https://mapy.cz/x"></iframe>', '<p title="&lt;/p&gt;&lt;img src=x onerror=alert(1)&gt;">x</p>', '<svg><style><a>y</a></style></svg>'], [
            \Talea\Builder\Build::code('<select><option>a</option><img src=x onerror=alert(1)></select><datalist><option value="x"></datalist><selectedcontent></selectedcontent>'),
            \Talea\Builder\Build::code('<style>p::after{content:"<b>"}</style>'), \Talea\Builder\Build::code('<iframe src="https://mapy.cz/x"><img src=x onerror=alert(1)></iframe>'),
            \Talea\Builder\Build::code('<p title="</p><img src=x onerror=alert(1)>">x</p>'), \Talea\Builder\Build::code('<svg><style><a onclick="x()">y</a></style></svg>'),
        ], '3.3.2 N30: custom HTML keeps no <select> family, no < in raw text and no raw < or > in attributes');
        $this->assertSame(['text'], array_column(\Talea\Builder\HtmlConverter::convert('<p>Text</p><video src="https://old.example/v.mp4" controls></video><svg><circle r="1"></circle></svg>', false)['build']['children'][0]['children'] ?? [], 'type'), '3.3.2 N30: imported pages are converted without administrator rights – no Custom HTML');
        $n6Html = (new \ReflectionMethod(\Talea\Core\SiteImport::class, 'html'))->invoke(null, '<p class="lead" onclick="x()">Hi <a href="javascript:alert(1)">x</a></p><script>alert(1)</script><figure class="gallery"><img src="media/2026/01/a.jpg" alt="A" width="10" height="10" loading="lazy" data-id="5"></figure><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', 100000);
        $this->assertSame("<p class=\"lead\">Hi <a>x</a></p><figure class=\"gallery\"><img src=\"media/2026/01/a.jpg\" alt=\"A\" width=\"10\" height=\"10\" loading=\"lazy\" data-id=\"5\"></figure>\n\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>", trim($n6Html), '3.3.2 N6: Talea export – page and news HTML sanitized like a save without code rights, the content kept');
    }

    /** 3.3.2 (N25): a custom attribute never reaches a hook of the site's scripts, and never overrides the element's own */
    public function testCustomAttributes(): void
    {
        // every data-* attribute a script on the public page mentions must be refused as a custom attribute – a new hook in web.js fails here until it is reserved
        $frontScripts = ['image/web.js' => (string) file_get_contents(TALEA_ROOT . '/image/web.js'), 'image/vitals.js' => (string) file_get_contents(TALEA_ROOT . '/image/vitals.js')];
        foreach (glob(TALEA_SYSTEM . '/views/front/*.php') ?: [] as $frontView) {
            preg_match_all('#<script>(.*?)</script>#s', (string) file_get_contents($frontView), $inlineScripts);
            $frontScripts['views/front/' . basename($frontView)] = implode("\n", $inlineScripts[1]);
        }
        // image/editor.js runs on the page for editing in place: the hooks it looks up in the whole document
        preg_match_all('/document\.querySelector(?:All)?\(\'([^\']*)\'\)/', (string) file_get_contents(TALEA_ROOT . '/image/editor.js'), $editorSelectors);
        $frontScripts['image/editor.js'] = implode("\n", $editorSelectors[1]);
        $unreserved = [];
        $hidden = [];
        foreach ($frontScripts as $script => $source) {
            preg_match_all('/data-[a-z0-9-]*[a-z0-9]/', $source, $hooks);
            foreach (array_unique($hooks[0]) as $hook) {
                if (preg_match(\Talea\Builder\Build::ATTRIBUTE_PATTERN, $hook)) {
                    $unreserved[] = $script . ': ' . $hook;
                }
            }
            // a hook the scan above cannot see (dataset, a name put together) would slip through – write the full name in the script
            if (preg_match('/\.dataset\b|[\'"]data-[\'"]\s*\+|[\'"]data-[a-z0-9-]*-[\'"]\s*\+/', $source)) {
                $hidden[] = $script;
            }
        }
        $this->assertSame([true, [], []], [
            count($frontScripts) > 5, $unreserved, $hidden], '3.3.2 N25: every data-* hook of the public scripts (web.js, vitals.js, inline scripts of the site views, editor.js on the page) is reserved; each is written in full');
        [$n25Build, $n25Errors] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'store_locator', 'attributes' => ['data-leaflet' => 'https://evil.example/', 'data-attribution' => '<img src=x onerror=alert(1)>',
            'data-basket' => 'javascript:alert(1)', 'data-compare-url' => 'javascript:alert(2)', 'data-product' => '{}', 'data-popup' => '1', 'data-collection' => 'x', 'data-locator' => '', 'data-track' => 'ok']]]], false);
        $this->assertSame([['data-track' => 'ok'], true], [
            $n25Build['children'][0]['attributes'] ?? [], isset($n25Errors['children[0].attributes'])], '3.3.2 N25: the hooks of the store locator, the basket, the comparison and the popups cannot be saved as custom attributes; a harmless one stays');
        $n25App = new \Talea\Core\App([]);
        $n25Html = fn (array $build): string => \Talea\Builder\Build::html($build, new \Talea\Builder\Context($n25App));
        // a build stored before 3.3.2 (never sanitized again): the hook is left out, the element renders as before
        $n25Stored = $n25Html(['children' => [['id' => 'h1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Hi'], 'attributes' => ['data-leaflet' => 'https://evil.example/', 'data-basket' => 'javascript:alert(1)', 'onclick' => 'alert(1)', 'data-x' => 'y', 'title' => 'T']]]]);
        [$n25Button] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'button', 'content' => ['text' => 'Go', 'link' => 'https://example.com/', 'new_window' => true], 'attributes' => ['rel' => 'opener', 'title' => 'Mine']],
            ['type' => 'back_to_top', 'attributes' => ['aria-label' => 'Custom', 'data-x' => 'y']]]]);
        $n25Own = $n25Html($n25Button);
        $this->assertSame(['<h2 data-x="y" title="T">Hi</h2>', true, true], [
            $n25Stored, (bool) preg_match('/<a class="tl-button tl-button--primary" href="https:\/\/example\.com\/" target="_blank" rel="noopener" rel="opener" title="Mine">/', $n25Own),
            (bool) preg_match('/<a class="tl-back-to-top" href="#" aria-label="[^"]+" aria-label="Custom" data-x="y">/', $n25Own)], '3.3.2 N25: a stored build with a reserved hook renders without it (no error); the element\'s own attributes come before the custom ones, so the browser keeps the element\'s');
        $webJs = $frontScripts['image/web.js'];
        $this->assertSame([true, true, true, false, true, true, 0, 'image/vendor/leaflet/'], [
            (bool) preg_match('/leaflet\.origin === location\.origin && \/\\\\\/image\\\\\/vendor\\\\\/leaflet\\\\\/\$\/\.test\(leaflet\.pathname\)/', $webJs), str_contains($webJs, "attributionControl: false"), str_contains($webJs, 'link.textContent = credit;'),
            str_contains($webJs, 'attribution:'), str_contains($webJs, "safeUrl(form.getAttribute('data-basket'))"), str_contains($webJs, "safeUrl(box.form.getAttribute('data-compare-url'))"),
            substr_count($webJs, 'A(basket.page)') + substr_count($webJs, 'A(compare.url'), \Talea\Builder\Elements\StoreLocator::LEAFLET_PATH], '3.3.2 N25: web.js loads Leaflet only from the site\'s own copy, writes the map credit as text and makes basket and comparison links only from http(s) or site addresses');
    }

    /** 3.3.2 (N26): a fact filled into an address is checked like any other link */
    public function testFactsInAddresses(): void
    {
        $n25App = new \Talea\Core\App([]);
        $n25Html = fn (array $build): string => \Talea\Builder\Build::html($build, new \Talea\Builder\Context($n25App));
        $n26Cache = new \ReflectionProperty(\Talea\Core\Facts::class, 'cache');
        $n26Fact = fn (string $key, string $type, string $value): array => ['key' => $key, 'label' => $key, 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => false, 'translated' => false];
        $n26Cache->setValue(null, [\Talea\Core\Language::siteColumn() => ['promo' => $n26Fact('promo', 'text', 'javascript:alert(document.domain)'), 'shop' => $n26Fact('shop', 'url', 'https://shop.example/a?b=1&c=2'),
            'phone' => $n26Fact('phone', 'phone', '+420 123 456 789'), 'tricky' => $n26Fact('tricky', 'text', ' JaVa'), 'entity' => $n26Fact('entity', 'text', 'jav&#x61;script:alert(1)'),
            'data' => $n26Fact('data', 'text', 'data:text/html,<script>alert(1)</script>'), 'name' => $n26Fact('name', 'text', 'A "quoted" <name>')]]);
        // the harness of the audit: a text fact "javascript:…" behind a Button
        [$n26Build] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'button', 'content' => ['text' => 'Promo', 'link' => '{{fact.promo}}']], ['type' => 'button', 'content' => ['text' => 'Call', 'link' => 'tel:{{fact.phone}}']],
            ['type' => 'button', 'content' => ['text' => 'Shop', 'link' => '{{ fact.shop }}']]]]);
        $n26Button = $n25Html($n26Build);
        $n26Fill = fn (string $html): string => \Talea\Core\Facts::fill($html, $n25App);
        $this->assertSame([false, 1, true, true], [
            (bool) preg_match('/href="\s*javascript:/i', $n26Button), substr_count($n26Button, 'href="#">Promo</a>'), str_contains($n26Button, 'href="tel:+420 123 456 789">Call'), str_contains($n26Button, 'href="https://shop.example/a?b=1&amp;c=2">Shop')], '3.3.2 N26: a Button linked to a text fact "javascript:…" renders no javascript: link; a phone and a web address fact still work');
        $this->assertSame(['<p><a href="#">a</a><a href="#">b</a><a href="#">c</a><a HREF="#">d</a></p>',
             '<img src="" alt="A &quot;quoted&quot; &lt;name&gt;"><form action=""><button formaction="">x</button></form>',
             '<a href="#">e</a><a href="jav&amp;#x61;script:alert(1)">f</a><a title="x>y" href="#">g</a><a href="/javascript:alert(document.domain)">h</a>',
             '<p>javascript:alert(document.domain) A &quot;quoted&quot; &lt;name&gt;</p><code><a href="{{fact.promo}}">i</a></code>'], [
            $n26Fill('<p><a href="{{fact.promo}}">a</a><a href=\'{{fact.promo}}\'>b</a><a href={{ fact.promo }}>c</a><a HREF = "{{fact.promo}}">d</a></p>'),
            $n26Fill('<img src="{{fact.promo}}" alt="{{fact.name}}"><form action="{{fact.promo}}"><button formaction="{{fact.data}}">x</button></form>'),
            $n26Fill('<a href="{{fact.tricky}}script:alert(1)">e</a><a href="{{fact.entity}}">f</a><a title="x>y" href="{{fact.promo}}">g</a><a href="/{{fact.promo}}">h</a>'),
            $n26Fill('<p>{{fact.promo}} {{fact.name}}</p><code><a href="{{fact.promo}}">i</a></code>')], '3.3.2 N26: page, news and Custom HTML – every quoting, an image, a form, a token split around a scheme, entities, data: and a ">" in an earlier attribute; text and <code> as before');
        // 3.3.3 (N50): fact tokens in addresses – the HTML is read tag by tag, a stray src=" hides nothing
        $n50Facts = $n26Cache->getValue()[\Talea\Core\Language::siteColumn()];
        $n26Cache->setValue(null, [\Talea\Core\Language::siteColumn() => $n50Facts + ['email' => $n26Fact('email', 'email', 'info@example.com'), 'path' => $n26Fact('path', 'text', 'a b'),
            'evil' => $n26Fact('evil', 'text', 'x onmouseover=alert(1)')]]);
        $this->assertSame(['<p>Use src="</p><a href="#">x</a>', '<a title="a src=" href="#">x</a>', '<a data-x="src=" href="#">y</a>', "<p>src='</p><img alt='src=' src=\"\">"], [
            $n26Fill('<p>Use src="</p><a href="{{fact.promo}}">x</a>'), $n26Fill('<a title="a src=" href="{{fact.promo}}">x</a>'),
            $n26Fill('<a data-x="src=" href="{{fact.promo}}">y</a>'), $n26Fill("<p>src='</p><img alt='src=' src='{{fact.promo}}'>")], '3.3.3 N50: the two strings of the report – a stray src=" in text and inside another attribute – leave no javascript: link');
        $this->assertSame(['<script>var a = \'<a title="\';</script><a href="#">s</a>', '<style>a[title="</style><a href="#">st</a>', '<!-- <a title=" --><a href="#">c</a>',
             '<script><!--<script>x="</script>"</script>--></script><a href="#">d</a>', 'a < b </ x> <a href="#">lt</a>',
             '<svg><style><a href="#">sv</a></style></svg><svg><a xlink:href="#">x</a></svg>'], [
            $n26Fill('<script>var a = \'<a title="\';</script><a href="{{fact.promo}}">s</a>'), $n26Fill('<style>a[title="</style><a href="{{fact.promo}}">st</a>'),
            $n26Fill('<!-- <a title=" --><a href="{{fact.promo}}">c</a>'), $n26Fill('<script><!--<script>x="</script>"</script>--></script><a href="{{fact.promo}}">d</a>'),
            $n26Fill('a < b </ x> <a href="{{fact.promo}}">lt</a>'), $n26Fill('<svg><style><a href="{{fact.promo}}">sv</a></style></svg><svg><a xlink:href="{{fact.promo}}">x</a></svg>')], '3.3.3 N50: what hides markup from a regular expression – a script, a style, a comment, an escaped script, a "<" in text – is read as the browser reads it');
        $this->assertSame(['<a href="tel:+420 123 456 789">t</a><a href="mailto:info@example.com">m</a><a href="https://x.example/a b?q=https://shop.example/a?b=1&amp;c=2">h</a>',
             '<img srcset=""><img srcset="/a.jpg 1x, https://cdn.example/a b 2x">', '<a  href="/ok">n</a><img alt="x onmouseover=alert(1)"><img alt="x onmouseover=alert(1)">',
             '<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="#">after</a>'], [
            $n26Fill('<a href="tel:{{fact.phone}}">t</a><a href="mailto:{{fact.email}}">m</a><a href="https://x.example/{{fact.path}}?q={{fact.shop}}">h</a>'),
            $n26Fill('<img srcset="{{fact.promo}} 1x, /a.jpg 2x"><img srcset="/a.jpg 1x, https://cdn.example/{{fact.path}} 2x">'),
            $n26Fill('<a {{fact.promo}} href="/ok">n</a><img alt={{fact.evil}}><img alt="{{fact.evil}}">'),
            $n26Fill('<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="{{fact.promo}}">after</a>')], '3.3.3 N50: tel:, mailto: and https://… with a fact still work; srcset (N64) is checked address by address; a token in a tag or attribute name is left out and an unquoted value is quoted');
        [$n50Build] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'text', 'content' => ['html' => '<p>Use src="</p><p><a href="{{fact.promo}}">x</a> <a title="a src=" href="{{fact.promo}}">y</a></p>']]]], false);
        $n50Html = $n25Html($n50Build);
        $this->assertSame([false, 2], [(bool) preg_match('/href="\s*javascript:/i', $n50Html), substr_count($n50Html, 'href="#"')], '3.3.3 N50: a builder Text element saved by an editor with both strings renders no javascript: link');
        $this->assertSame(// check-english: allow
            [true, true, true, true, true, true, true, false, false, false, false, false, false, false, false, false], array_map(\Talea\Core\Facts::startsWithScheme(...),
            ['javascript:alert(1)', ' JaVa' . "\t" . 'Script:alert(1)', "\x01javascript:x", 'javascript: alert(1)', 'data:text/html,x', 'vbscript:x', 'foo:bar', 'https://example.com/', 'mailto:a@b.cz', 'tel:+420',
             'Note: open daily', 'Open 8:00–16:00', 'Po–Pá 8:00', 'Pozn.: viz níže', 'Price 100 CZK', '']), '3.3.3 N50: Facts::save refuses a text fact that starts with another scheme than http(s), mailto or tel; other text stays allowed'); // check-english: allow
        $n26Cache->setValue(null, [\Talea\Core\Language::siteColumn() => []]); // no facts – the N38 builds below are filled without a database
    }

    /** 3.3.2 (N38): add-on tokens only in what editors wrote, never in what a visitor sent */
    public function testAddonTokens(): void
    {
        $reg = \Talea\Extension\Registry::get();
        $n25App = new \Talea\Core\App([]);
        $n26Cache = new \ReflectionProperty(\Talea\Core\Facts::class, 'cache');
        $n26Fact = fn (string $key, string $type, string $value): array => ['key' => $key, 'label' => $key, 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => false, 'translated' => false];
        $n26Cache->setValue(null, [\Talea\Core\Language::siteColumn() => ['promo' => $n26Fact('promo', 'text', 'javascript:alert(document.domain)'), 'shop' => $n26Fact('shop', 'url', 'https://shop.example/a?b=1&c=2'),
            'phone' => $n26Fact('phone', 'phone', '+420 123 456 789'), 'tricky' => $n26Fact('tricky', 'text', ' JaVa'), 'entity' => $n26Fact('entity', 'text', 'jav&#x61;script:alert(1)'),
            'data' => $n26Fact('data', 'text', 'data:text/html,<script>alert(1)</script>'), 'name' => $n26Fact('name', 'text', 'A "quoted" <name>')]]);
        $reg->addToken('naive', 'echo', fn (array $a): string => (string) ($a['x'] ?? 'NAIVE')); // an add-on that prints its attribute as it is
        $n38Query = '<input type="search" name="q" value="' . e('{{ext.naive.echo x="<img src=x onerror=alert(1)>"}}') . '">';
        $n38Visitor = $n38Query . '<p>' . e('{{ext.naive.echo}}') . '</p>';
        $n38Context = new \Talea\Builder\Context($n25App);
        $n38Context->content = $n38Visitor;
        $n38Wrapper = \Talea\Builder\Build::html(['children' => [['id' => 't1', 'type' => 'text', 'tag' => 'div', 'content' => ['html' => '<p>{{ext.naive.echo x="own"}}</p>']], ['id' => 'o1', 'type' => 'page_content', 'tag' => 'div', 'content' => []]]], $n38Context);
        $kernelSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Kernel.php');
        preg_match('/private function page\(.*?\n    }\n/s', $kernelSource, $pageMethod);
        $this->assertSame([$n38Query, true, true, false, false, true, true, true], [
            \Talea\Extension\Registry::fillTokens($n38Query), str_contains($n38Wrapper, '<p>own</p>'), str_contains($n38Wrapper, $n38Visitor), str_contains($n38Wrapper, '<img'), str_contains($n38Wrapper, 'NAIVE'),
            $n38Context->content === $n38Visitor, ($pageMethod[0] ?? '') !== '' && !str_contains($pageMethod[0], 'fillTokens('), substr_count($kernelSource, 'self::authored(') >= 5], '3.3.2 N38: an escaped-quote token (a visitor\'s search query) is never run; a site-part wrapper fills its own tokens but not the page content it wraps; the page as a whole is not filled');
        $n26Cache->setValue(null, []);
    }

    /** 3.3.2: security release, workstream C */
    public function testWorkstreamC(): void
    {
        $mcpSettings = (new \ReflectionClassConstant(\Talea\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue();
        $this->assertSame([[0, 0, 0, 1, 1], true, null], [array_map(fn (string $key): int => preg_match($mcpSettings, $key), ['gtm_id', 'matomo_url', 'matomo_id', 'ga4_id', 'plausible_domain']),
                str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/SettingsTools.php'), "['gtm_id', 'matomo_url', 'matomo_id']"), \Talea\Admin\Modules\Settings::verifyValue('gtm_id', 'GTM-<x>')], '3.3.2 (N27): Claude cannot set GTM or Matomo (script chosen by their owner); GA4 and Plausible load from a fixed host and stay; the tool says why');
        $this->assertSame([false, false, false, false, false, true, true], array_map(\Talea\Core\AgentJournal::journaled(...),
            ['enquiries', 'testimonial_requests', 'bookings', 'subscribers', 'mail', 'pages', 'settings']), '3.3.2 (N29): visitors\' personal data are never journaled for undo; content still is');
        $settingsScreens = array_values(array_filter(\Talea\Admin\Kernel::MODULES, fn (string $c): bool => is_a($c, \Talea\Admin\Modules\Settings::class, true)));
        $this->assertSame([true, [], false, true, false, true, true, false], [
            count($settingsScreens) >= 5,
            array_values(array_filter(array_map(fn (string $c): string => $c::IDENT, $settingsScreens), fn (string $ident): bool => !\Talea\Core\Demo::blocksAdmin($ident, 'backup', '', true)
                || !\Talea\Core\Demo::blocksAdmin($ident, 'download_backup', '', false) || !\Talea\Core\Demo::blocksAdmin($ident, 'restore_backup', '', true)
                || !\Talea\Core\Demo::blocksAdmin($ident, 'delete_backup', '', true) || !\Talea\Core\Demo::blocksAdmin($ident, 'fleet_pair', '', true)
                || \Talea\Core\Demo::blocksAdmin($ident, 'list', '', false))),
            \Talea\Core\Demo::blocksAdmin('settings', 'save', 'general', true), \Talea\Core\Demo::blocksAdmin('settings', 'save', 'mail', true), \Talea\Core\Demo::blocksAdmin('business', 'hours_add', '', true),
            \Talea\Core\Demo::blocksAdmin('status', 'save', '', true), \Talea\Core\Demo::blocksAdmin('claude_settings', 'save', '', true), \Talea\Core\Demo::blocksAdmin('pages', 'save', '', true)], '3.3.2 (N24): the demo filters every Settings screen by its class – no backup, restore, download or pairing action through any of them; looking is allowed');
        $this->assertSame([false, false, false, true, true, true, true, true, true, true, true], array_map(fn (array $k): bool => \Talea\Core\Demo::blocksSetting($k[0], $k[1]),
            [['site_name', 'text'], ['company_city', 'text'], ['ga4_id', 'pattern'], ['matomo_url', 'url'], ['gtm_id', 'pattern'], ['head_code', 'code'], ['captcha_secret', 'secret'], ['cookies_policy_url', 'text'], ['maintenance', 'flag'], ['webhook_url', 'url'], ['site_email', 'email']]), '3.3.2 (N24): the demo saves only allow-listed settings – never script hosts, code, secrets, the policy link or maintenance');
        $this->assertSame([['/privacy-policy', 'https://example.com/p', '', null, null, null, null], ['/zasady', 'http://old.example/p', '', '', '', '', '/x']], [
            array_map(fn (string $v): ?string => \Talea\Admin\Modules\Settings::verifyValue('cookies_policy_url', $v), ['/privacy-policy', 'https://example.com/p', '', 'javascript:alert(1)', '//evil.example', 'http://example.com/p', 'data:text/html,x']),
            array_map(fn (string $v): string => \Talea\Core\Privacy::policyUrl(FakeSettings::site(['cookies_policy_url' => $v])), ['/zasady', 'http://old.example/p', 'javascript:alert(1)', 'JaVaScRiPt:x', '//evil.example', '/\\evil', ' /x '])], '3.3.2 (N17): the privacy policy link is a path or https on saving, and a path or http(s) when printed – never javascript:, data: or //host');
        $this->assertSame([5, 5, null, null, 5, 5], [
            \Talea\Core\Guardrails::targetPage('save_build', ['id' => 5, 'popup' => 0]), \Talea\Core\Guardrails::targetPage('edit_build', ['id' => 5, 'part' => '', 'component' => '0', 'collection' => null]),
            \Talea\Core\Guardrails::targetPage('save_build', ['id' => 5, 'popup' => 3]), \Talea\Core\Guardrails::targetPage('publish_build', ['id' => 5, 'part' => 'header']),
            \Talea\Core\Guardrails::targetPage('update_page', ['id' => 5, 'popup' => 3]), \Talea\Core\Guardrails::targetPage('trash_page', ['id' => 5, 'collection' => 'x'])], '3.3.2 (N31): an empty other build target does not hide the page from the protected-pages guardrail; update_page and trash_page have no other target');
        $this->assertSame([['save_redirect' => 'delete', 'save_part_variant' => 'delete', 'restore_item_version' => '', 'request_testimonial' => 'send']], [
            (new \ReflectionClassConstant(\Talea\Core\Guardrails::class, 'DESTRUCTIVE_CALLS'))->getValue()], '3.3.2 (N32): with deleting switched off, deleting a redirect or a part variant, restoring an item version and e-mailing a testimonial request count as destructive');
        $this->assertSame(['203.0.113.7', '2001:db8:1:2::/64', '2001:db8:1:2::/64', '203.0.113.7', 'unknown'], array_map(\Talea\Core\Antispam::network(...),
            ['203.0.113.7', '2001:db8:1:2:3:4:5:6', '2001:db8:1:2:ffff::1', '::ffff:203.0.113.7', 'unknown']), '3.3.2 (N34): tries are counted per IPv4 address and per IPv6 /64 (an IPv4 address written as IPv6 counts as itself)');
        $this->assertSame([10, true, true], [\Talea\Core\PageLock::ATTEMPTS, \Talea\Core\PageLock::PAGE_ATTEMPTS > \Talea\Core\PageLock::ATTEMPTS,
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/PageLock.php'), 'Antispam::network(')], '3.3.2 (N34): a page password has a limit per address and one per page across all addresses');
        $htaccess332 = (string) file_get_contents(TALEA_ROOT . '/.htaccess');
        $router332 = (string) file_get_contents(TALEA_SYSTEM . '/dev-router.php');
        preg_match("/if \\(preg_match\\('(#\\^\\/\\(system.+?#i)', \\\$path\\)\\)/", $router332, $routerRule);
        $this->assertSame([true, true, [1, 1, 1, 1, 1, 1, 1, 0, 0]], [
            str_contains($htaccess332, 'RewriteRule ^extensions/(?![^/]+/public/) - [F,L,NC]'), str_contains($htaccess332, 'RewriteRule ^extensions/[^/]+/public/.+\.(php\d?|pht|phps|phtml|phar)$ - [F,L,NC]'),
            array_map(fn (string $path): int => preg_match($routerRule[1] ?? '/$^/', $path), ['/extensions/hello/Extension.php', '/extensions/hello/extension.json', '/extensions/README.md', '/extensions/hello/public/x.PHP',
                '/extensions/hello/public/x.pht', '/extensions/hello/public/x.PHPS', '/Extensions/hello/Extension.php', '/extensions/hello/public/app.css', '/media/a.jpg'])], '3.3.2 (N36): .htaccess and the development router serve only extensions/<slug>/public/ and no PHP from it'); // 3.3.3 (N64): .pht and .phps too, and the second rule ignores case
        $this->assertSame([['author', 'editor', 'admin'], ['read' => 'author', 'draft' => 'author', 'write' => 'editor', 'destructive' => 'admin'], ['name', 'description', 'schema', 'access', 'handler', 'requires?']], [
            \Talea\Extension\Api::TOOL_ROLES, (new \ReflectionClassConstant(\Talea\Extension\Api::class, 'DEFAULT_ROLE'))->getValue(),
            array_map(fn (\ReflectionParameter $p): string => $p->getName() . ($p->isOptional() ? '?' : ''), (new \ReflectionMethod(\Talea\Extension\Api::class, 'mcpTool'))->getParameters())], '3.3.2 (N39): add-on tools may name a role or a section; without it read and draft are open, write needs an editor, destructive an administrator');
        $this->assertSame([true, true, false, false, false], [
            \Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/json', 'Authorization: Bearer x']),
            \Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api?key=abc', ['Accept: application/json']),
            \Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/vnd.api+json']),
            \Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'https://www.old.example/api', ['Authorization: Bearer x']),
            \Talea\Import\Fetch::downgradesCredentials('http://old.example/api', 'http://old.example/api2', ['X-Joomla-Token: x'])], '3.3.2 (N43): an API fetch that carries a token is not redirected from https to plain http');
    }

    /** 3.3.3: security release, workstream A */
    public function testWorkstreamA(): void
    {
        // N52: one normalized host for the lookup, the pin and the request
        $this->assertSame(// check-english: allow
            [null, null, 'xn--etina-gya30d.example', 'www.example.com', 'example.com', null, null, null, null, null, '::1', '::1', '93.184.216.34', 'example.com.', null, null, null], array_map(\Talea\Core\Outbound::host(...),
            ['%61.attacker.tld', '%70inme.localhost', 'čeština.example', 'WWW.Example.COM', 'ｅxample.com', 'a_b.example', 'a..b', '0x7f.1', '2130706433', '127.1', '[::1]', '[0:0::1]', '93.184.216.34', 'example.com.', 'ex ample.com', '[not-ip]', '']), '3.3.3 N52: Outbound::host – IDN to punycode, lowercase; a percent sign, other characters and numeric IPv4 spellings are refused'); // check-english: allow
        $this->assertSame([['url' => 'https://xn--etina-gya30d.example:8443/a/%C4%8D?x=%41#f', 'host' => 'xn--etina-gya30d.example', 'port' => 8443, 'scheme' => 'https'],
             ['url' => 'http://[::1]/x', 'host' => '::1', 'port' => 80, 'scheme' => 'http'], null, null, null, null], [
            \Talea\Core\Outbound::url('https://Čeština.Example:8443/a/%C4%8D?x=%41#f'), \Talea\Core\Outbound::url('http://[::1]/x'), \Talea\Core\Outbound::url('https://%61.example/'),
            \Talea\Core\Outbound::url('https://u:p@example.com/'), \Talea\Core\Outbound::url('ftp://example.com/'), \Talea\Core\Outbound::url('https://example.com\\@169.254.169.254/')], '3.3.3 N52: Outbound::url writes the normalized host into the URL – the name checked, pinned and requested is one string');
        $n52Wp = new \Talea\Core\ImageDownloader('https://stary-web.example');
        $n52Any = new \Talea\Core\ImageDownloader('https://čeština.example', true); // check-english: allow
        $this->assertSame(// check-english: allow
            [false, false, true, 'xn--etina-gya30d.example', true, null, false, true, false, false, true, null, [0], false, false], [
            $n52Wp->isAllowedUrl('https://%73tary-web.example/a.png'), $n52Any->isAllowedUrl('https://%61.attacker.tld/x.jpg'), $n52Any->isAllowedUrl('https://čeština.example/a.png'), $n52Any->domain(), // check-english: allow
            (new \Talea\Core\ImageDownloader('https://xn--etina-gya30d.example'))->isAllowedUrl('https://www.čeština.example/a.png'), $n52Wp->verifiedIp('%61.example'), // check-english: allow
            \Talea\Import\Fetch::allowedUrl('https://%6fld.example/api', 'https://old.example'), \Talea\Import\Fetch::allowedUrl('https://čeština.example/api', 'https://čeština.example'), \Talea\Import\Fetch::allowedSite('https://%6fld.example'), // check-english: allow
            \Talea\Fleet\Http::allowedUrl('https://%61.example/'), \Talea\Fleet\Http::allowedUrl('https://čeština.example/'), \Talea\Fleet\Http::pin('https://%61.example/'), \Talea\Fleet\Http::statuses(['https://%61.example/']), // check-english: allow
            \Talea\Core\Links::isPublic('https://%61.example/'), \Talea\Core\Links::target('https://čeština.invalid/')], '3.3.3 N52: every checker refuses a %xx host and accepts an IDN host in its normalized form (images, fetch, fleet, links)'); // check-english: allow
        $this->assertSame([false, false, false, false, ['url' => 'https://[2606:4700:4700::1111]/x', 'host' => '2606:4700:4700::1111', 'port' => 443, 'scheme' => 'https', 'ip' => '2606:4700:4700::1111'], false, null], [
            \Talea\Core\Links::isPublic('http://100.64.0.1/'), \Talea\Core\Links::isPublic('http://[::1]/'), \Talea\Core\Links::isPublic('http://[fd00::1]/'), \Talea\Core\Links::isPublic('http://[64:ff9b::a9fe:a9fe]/'),
            \Talea\Core\Links::target('https://[2606:4700:4700::1111]/x'), \Talea\Core\Links::target('https://nothing-here.invalid/'), \Talea\Core\Links::target('https://example.com:8443/')], '3.3.3 N9: the link check pins every resolved address – IPv6, CGNAT 100.64/10 and NAT64 are internal, a name that does not resolve is not requested');
        $this->assertSame([false, false, false, false, false, false, true, true], array_map(\Talea\Core\ImageDownloader::isPublicIp(...),
            ['64:ff9b::a9fe:a9fe', '64:ff9b:1::a00:1', '64:ff9b:1:ffff::1', '::ffff:0:a9fe:a9fe', '::ffff:0:7f00:1', '::ffff:0:5db8:d822', '64:ff9b:2::1', '2606:4700::1111']), '3.3.3 N22: NAT64 (64:ff9b::/96, 64:ff9b:1::/48) and SIIT (::ffff:0:a.b.c.d) are not public; ordinary IPv6 still is');
        $this->assertSame([true, true, true, true, false, false], [
            defined('CURLOPT_PREREQFUNCTION') ? str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/Outbound.php'), 'CURLOPT_PREREQFUNCTION') : true,
            \Talea\Core\Outbound::sameAddress('127.0.0.1', '::ffff:127.0.0.1'), \Talea\Core\Outbound::sameAddress('::1', '0:0::1'), \Talea\Core\Outbound::sameAddress('[::1]', '::1'),
            \Talea\Core\Outbound::sameAddress('127.0.0.1', '127.0.0.2'), \Talea\Core\Outbound::sameAddress('', '127.0.0.1')], '3.3.3 N52: curl compares the address it connected to with the pinned one (any notation of the same address)');
        $n52Sources = array_map(fn (string $f): string => (string) file_get_contents(TALEA_SYSTEM . '/src/' . $f), ['Core/ImageDownloader.php', 'Import/Fetch.php', 'Fleet/Http.php', 'Core/Links.php']);
        $this->assertSame([0, [true, true, true, true]], [array_sum(array_map(fn (string $s): int => substr_count($s, 'CURLOPT_RESOLVE'), $n52Sources)),
            array_map(fn (string $s): bool => str_contains($s, 'Outbound::pin('), $n52Sources)], '3.3.3 N52: no curl caller builds its own CURLOPT_RESOLVE entry – all pin through Outbound::pin');

        // N55: a Talea archive brings settings through the same validation as the admin form and MCP
        $this->assertSame([true, true, false, null, null, 'https://www.linkedin.com/company/x', '', true], [
            \Talea\Admin\Modules\Settings::checkable('company_map'), \Talea\Admin\Modules\Settings::checkable('social_facebook'), \Talea\Admin\Modules\Settings::checkable('design_system'),
            \Talea\Admin\Modules\Settings::verifyValue('company_map', 'javascript:alert(1)'), \Talea\Admin\Modules\Settings::verifyValue('social_x', ' JavaScript:alert(2)'),
            \Talea\Admin\Modules\Settings::verifyValue('social_linkedin', 'https://www.linkedin.com/company/x'), \Talea\Admin\Modules\Settings::verifyValue('company_map', ''),
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/SiteImport.php'), 'Settings::checkable($key) ? \Talea\Admin\Modules\Settings::verifyValue($key, $value)')], '3.3.3 N55: imported company_map and social_* must be web addresses; texts and numbers are checked by their field type');

        // N63: imported content checked again – only risky markup changes
        $n63Wp = \Talea\Core\WpContent::safeHtml(...);
        $this->assertSame([0, 0, 1, 1, 2, 2, 2, 2, 2, 0, 2, 2, 2, 0], array_map(\Talea\Core\ImportRecheck::risk(...), [
            '<p>Hi <a href="https://x.example/">x</a></p>', '<p class="lead">Fine&nbsp;text</p>', '<p><img alt="<b>x</b>" src="a.jpg"></p>', '<p title="a > b">x</p>', '<img src=x onerror=alert(1)>',
            '<a href=" java&#10;script:alert(1)">x</a>', '<script>x()</script>', '<object data="x.swf"></object>', '<iframe srcdoc="<b>x</b>"></iframe>', '<img src="data:image/png;base64,AAAA">',
            '<a href="data:text/html,x">x</a>', '<img srcset="/a.jpg 1x, javascript:x 2x">', '<meta http-equiv="refresh" content="0;url=/">', 'plain text']), '3.3.3 N63: ImportRecheck::risk – script, handlers, script addresses and plugins are risky (2), a raw < or > in an attribute value is (1), the rest is fine (0)');
        $this->assertSame(['<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', '<p><img alt="&lt;b&gt;x&lt;/b&gt;" src="a.jpg"></p>', '<p>Hi<img src="a.jpg"></p>', '<p>x ok</p>'], [
            \Talea\Core\ImportRecheck::html('<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', $n63Wp), \Talea\Core\ImportRecheck::html('<p><img alt="<b>x</b>" src="a.jpg"></p>', $n63Wp),
            \Talea\Core\ImportRecheck::html('<p>Hi<img src="a.jpg" onerror="alert(1)"></p>', \Talea\Core\Html::safe(...)), \Talea\Core\ImportRecheck::html('<p><a href="javascript:alert(1)">x</a> ok</p>', $n63Wp)], '3.3.3 N63: ImportRecheck::html keeps safe HTML byte for byte, writes raw attribute text out again, and sanitizes risky HTML the way the import did');
        $n63Safe = '{"v":1,"children":[{"id":"txt001","type":"text","tag":"div","content":{"html":"<p>Fine</p>"}},{"id":"htm001","type":"custom_html","tag":"div","content":{"html":"<script>own()</script>"}}]}';
        $n63Risky = \Talea\Core\ImportRecheck::build('{"v":1,"children":[{"id":"txt001","type":"text","tag":"div","content":{"html":"<p onclick=\\"x()\\">T</p>"}},{"id":"btn001","type":"button","content":{"text":"Go","link":"javascript:alert(1)"}},{"id":"htm001","type":"custom_html","tag":"div","content":{"html":"<script>own()</script>"}}]}');
        $this->assertSame([true, false, false, true, true, null], [
            \Talea\Core\ImportRecheck::build($n63Safe) === $n63Safe, str_contains((string) $n63Risky, 'onclick'), str_contains((string) $n63Risky, 'javascript:'), str_contains((string) $n63Risky, '<script>own()</script>'),
            \Talea\Core\ImportRecheck::build((string) $n63Risky) === $n63Risky, \Talea\Core\ImportRecheck::build('not json')], '3.3.3 N63: ImportRecheck::build leaves a safe build as it is (Custom HTML is the administrator\'s), sanitizes a risky one and keeps its Custom HTML; a second pass changes nothing');
        $this->assertSame([0, true, ''], [
            \Talea\Core\Scheduler::JOBS['import_recheck'][0] ?? null,
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/Health.php'), 'ImportRecheck::state('), \Talea\Core\Settings::DEFAULTS['imported_recheck'] ?? null], '3.3.3 N63: the imported-content recheck runs as a background job and System status reports it');
    }
}
