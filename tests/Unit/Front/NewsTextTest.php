<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Front\NewsText;

final class NewsTextTest extends TestCase
{
    /** player and embedded URLs */
    public function testPlayerAndEmbeddedUrls(): void
    {
        $this->assertTrue(str_contains(NewsText::player('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', 'T'), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'prehravac: YouTube bez cookies');
        $this->assertTrue(str_contains(NewsText::player('https://youtu.be/dQw4w9WgXcQ', '', 'T'), 'embed/dQw4w9WgXcQ'), 'prehravac: youtu.be');
        $this->assertTrue(str_contains(NewsText::player('media/2026/09/epizoda.mp3', '/magazin', 'T'), '<audio controls preload="none" src="/magazin/media/2026/09/epizoda.mp3">'), 'prehravac: MP3 je <audio>');
        $this->assertSame('', NewsText::player('https://example.com/video', '', 'T', true), 'player: unknown address in knownOnly mode');
        $this->assertFalse(str_contains(NewsText::player('https://vimeo.com/123', '', '"><script>'), '<script>'), 'player: the title is escaped');
        $types = (new \ReflectionClass(NewsText::class))->newInstanceWithoutConstructor();
        $html = $types->embedVideoUrls('<p>Úvod</p><p>https://youtu.be/dQw4w9WgXcQ</p><p>Viz https://youtu.be/dQw4w9WgXcQ v textu.</p>'); // check-english: allow
        $this->assertSame([1, 1], [substr_count($html, 'data-insert'), substr_count($html, 'Viz https://youtu.be')], 'embeddedUrls: only a standalone line');
    }
}
