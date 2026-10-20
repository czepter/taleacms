<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

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
}
