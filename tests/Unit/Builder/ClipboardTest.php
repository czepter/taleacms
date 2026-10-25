<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\ElementClipboard;

final class ClipboardTest extends TestCase
{
    /** 2.7: copy and paste between Talea sites (Builder\ElementClipboard) */
    public function testCopyAndPaste(): void
    {
        $clipboardElements = [['id' => 'abc1234', 'type' => 'section', 'anchor' => 'cenik', 'classes' => ['karta'], 'children' => [
            ['id' => 'def5678', 'type' => 'image', 'content' => ['src' => 'media/2026/foto.jpg', 'alt' => 'x']],
            ['id' => 'ghi9012', 'type' => 'text', 'content' => ['html' => '<p><img src="/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>'], 'style' => ['base' => ['background_image' => 'media/bg.webp']]],
            ['id' => 'jkl3456', 'type' => 'component', 'content' => ['component' => '7', 'values' => []]],
        ]]];
        $clipboardEnvelope = ['talea' => 'elements', 'v' => 1, 'site' => 'https://Zdroj.example/', 'elements' => $clipboardElements, 'classes' => [['name' => 'karta', 'style' => [], 'css' => '']], 'components' => [['id' => 7, 'name' => 'K', 'properties' => [], 'build' => ['v' => 1, 'children' => []]]]];
        $clipboardParsed = \Talea\Builder\ElementClipboard::parse($clipboardEnvelope);
        $this->assertSame(['https://zdroj.example', 1, 1, 1], [$clipboardParsed['site'], count($clipboardParsed['elements']), count($clipboardParsed['classes']), count($clipboardParsed['components'])], '2.7: clipboard envelope – checked and normalised');
        $this->assertSame([null, null, null, null, null, ''], [
            \Talea\Builder\ElementClipboard::parse('text'), \Talea\Builder\ElementClipboard::parse(['talea' => 'page', 'v' => 1, 'elements' => $clipboardElements]),
            \Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 2, 'elements' => $clipboardElements]), \Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => []]),
            \Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => ['x', 1]]), \Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => $clipboardElements, 'site' => 'javascript:x'])['site'],
        ], '2.7: clipboard envelope – anything else is refused');
        $clipboardFresh = \Talea\Builder\ElementClipboard::fresh($clipboardElements);
        $this->assertSame([false, false, false, 'text'], [isset($clipboardFresh[0]['id']), isset($clipboardFresh[0]['anchor']), isset($clipboardFresh[0]['children'][1]['id']), $clipboardFresh[0]['children'][1]['type']], '2.7: pasted elements lose their ids and anchors (new ids come from sanitize)');
        $clipboardImages = 0;
        $clipboardHttps = \Talea\Builder\ElementClipboard::relinkMedia($clipboardElements, 'https://zdroj.example', $clipboardImages);
        $this->assertSame([3, 'https://zdroj.example/media/2026/foto.jpg', '<p><img src="https://zdroj.example/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', 'https://zdroj.example/media/bg.webp'], [$clipboardImages, $clipboardHttps[0]['children'][0]['content']['src'], $clipboardHttps[0]['children'][1]['content']['html'], $clipboardHttps[0]['children'][1]['style']['base']['background_image']], '2.7: media of an https site are pointed at it and counted');
        $clipboardImages = 0;
        $clipboardHttp = \Talea\Builder\ElementClipboard::relinkMedia($clipboardElements, 'http://zdroj.example', $clipboardImages);
        $this->assertSame([3, '', '<p><img src="" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', ''], [$clipboardImages, $clipboardHttp[0]['children'][0]['content']['src'], $clipboardHttp[0]['children'][1]['content']['html'], $clipboardHttp[0]['children'][1]['style']['base']['background_image']], '2.7: media of an http site are left out and counted');
        $this->assertSame('https://zdroj.example/media/2026/foto.jpg', \Talea\Builder\Build::sanitize(['children' => $clipboardHttps])[0]['children'][0]['children'][0]['content']['src'], '2.7: relinked media pass the build validator');
    }

    /** #148: an administrator's copy is signed by this site; only a valid signature keeps Custom HTML on paste */
    public function testSignature(): void
    {
        $key = str_repeat('k', 64);
        $envelope = ['talea' => 'elements', 'v' => 1, 'site' => 'https://here.example', 'elements' => [['type' => 'custom_html', 'content' => ['code' => '<p>ž</p>'], 'style' => ['base' => ['opacity' => 1.0, 'width' => 0.5]]]], 'classes' => [], 'components' => []];
        $envelope['sig'] = ElementClipboard::signature($envelope, $key);
        // the envelope as the browser gives it back: numeric keys first, 1.0 written as 1, other key order
        $browser = json_decode('{"sig":"' . $envelope['sig'] . '","components":[],"classes":{},"elements":[{"style":{"base":{"width":0.5,"opacity":1}},"content":{"code":"<p>ž</p>"},"type":"custom_html"}],"site":"https://here.example","v":1,"talea":"elements"}', true);
        $changed = $browser;
        $changed['elements'][0]['content']['code'] = '<img src=x onerror=alert(1)>';
        $moved = $browser;
        $moved['site'] = 'https://other.example';
        $unsigned = $browser;
        unset($unsigned['sig']);
        $this->assertSame([true, true, false, false, false, false, false, false],
            [ElementClipboard::isSigned($envelope, $key), ElementClipboard::isSigned($browser, $key), ElementClipboard::isSigned($changed, $key), ElementClipboard::isSigned($moved, $key),
                ElementClipboard::isSigned($unsigned, $key), ElementClipboard::isSigned($browser, str_repeat('x', 64)), ElementClipboard::isSigned($browser, ''), ElementClipboard::isSigned('text', $key)],
            'the signature survives the trip through the browser and fails for a changed envelope, another site, no signature or another key');
    }

    /** #148: without a valid signature the code elements are left out, also inside containers, and counted */
    public function testWithoutCode(): void
    {
        $count = 0;
        $kept = ElementClipboard::withoutCode([
            ['type' => 'custom_html', 'content' => ['code' => '<p>a</p>']],
            ['type' => 'section', 'children' => [['type' => 'heading', 'content' => ['text' => 'Stays']], ['type' => 'custom_html', 'content' => ['code' => '<p>b</p>']]]],
            'not an element',
            ['type' => 'unknown_type'],
        ], $count);
        $this->assertSame([2, ['section', 'unknown_type'], ['heading']], [$count, array_column($kept, 'type'), array_column($kept[0]['children'], 'type')],
            'Custom HTML is left out at every depth; other elements stay for Build::sanitize');
    }
}
