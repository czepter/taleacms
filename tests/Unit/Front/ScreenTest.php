<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;

final class ScreenTest extends TestCase
{
    /** 2.11 F1: screen mode (Front\Screen) */
    public function testScreenMode(): void
    {
        $this->assertSame([10, 5, 60, 20, 10], array_map(\Talea\Front\Screen::seconds(...), ['', '3', '90', '20', 0]), '2.11 Screen::seconds – within 5–60, empty = 10');
        $f1Secret = str_repeat('ab', 16);
        $this->assertSame([true, false, false, false, false], [\Talea\Front\Screen::opens(true, $f1Secret, $f1Secret), \Talea\Front\Screen::opens(false, $f1Secret, $f1Secret), \Talea\Front\Screen::opens(true, $f1Secret, str_repeat('ba', 16)),
            \Talea\Front\Screen::opens(true, '', ''), \Talea\Front\Screen::opens(true, $f1Secret, 'ABAB')], '2.11 Screen::opens – only on, with a secret, and the right one');
        $this->assertSame([['start', 'end'], ['when', ''], null], [\Talea\Front\Screen::dateFields([['key' => 'place', 'type' => 'text'], ['key' => 'start', 'type' => 'datetime'], ['key' => 'end', 'type' => 'datetime']]),
            \Talea\Front\Screen::dateFields([['key' => 'when', 'type' => 'datetime']]), \Talea\Front\Screen::dateFields([['key' => 'day', 'type' => 'date']])], '2.11 Screen::dateFields – the first date-and-time field is the start, the second the end');
        $f1Course = ['name' => 'Kurzy', 'preset' => 'courses', 'fields' => [['key' => 'start', 'label' => 'Start', 'type' => 'datetime'], ['key' => 'end', 'label' => 'End', 'type' => 'datetime'], ['key' => 'place', 'label' => 'Place', 'type' => 'text'],
            ['key' => 'price', 'label' => 'Price', 'type' => 'number'], ['key' => 'description', 'label' => 'Description', 'type' => 'html'], ['key' => 'image', 'label' => 'Image', 'type' => 'image']]];
        $f1Slide = \Talea\Front\Screen::card($f1Course, ['name' => ['Welding basics', 'text'], 'start' => ['2. 11. 2026 09:00', 'text'], 'end' => ['2. 11. 2026 16:00', 'text'], 'place' => ['Brno', 'text'], 'price' => ['1900', 'number'],
            'description' => ['<p>Long</p>', 'html'], 'image' => ['media/2026/kurz.jpg', 'image']]);
        $this->assertSame(['item', 'Kurzy', 'Welding basics', '2. 11. 2026 09:00', ['Brno'], '', 'media/2026/kurz.jpg'], [$f1Slide['kind'], $f1Slide['label'], $f1Slide['title'], $f1Slide['date'], $f1Slide['lines'], $f1Slide['text'], $f1Slide['image']], '2.11 Screen::card – a preset collection shows its card fields: the start as the date, the place as a line, the first image');
        $f1Plain = ['name' => 'Stroje', 'preset' => '', 'fields' => [['key' => 'photo', 'label' => 'Photo', 'type' => 'image'], ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'weight', 'label' => 'Weight', 'type' => 'number'],
            ['key' => 'about', 'label' => 'About', 'type' => 'lines'], ['key' => 'sheet', 'label' => 'Sheet', 'type' => 'file'], ['key' => 'extra', 'label' => 'Extra', 'type' => 'text']]];
        $f1Slide = \Talea\Front\Screen::card($f1Plain, ['name' => ['CNC', 'text'], 'model' => ['X-200', 'text'], 'weight' => ['1200', 'number'], 'about' => [str_repeat('word ', 80), 'lines'], 'sheet' => ['/media/x.pdf', 'link'], 'extra' => ['not shown', 'text']]);
        $this->assertSame([['X-200', 'Weight: 1200'], true, true, ''], [$f1Slide['lines'], mb_strlen($f1Slide['text']) <= 240, str_ends_with($f1Slide['text'], '…'), $f1Slide['image']], '2.11 Screen::card – without a preset the first three short fields: a number with its label, a longer text shortened, the rest left out');
        $this->assertSame('Open day at 9', \Talea\Core\Html::plain("<p>Open&nbsp;day</p>\n<ul><li>at  9</li></ul>"), '2.11 Html::plain (the screen) – formatted text as one line');
        $this->assertSame([true, '10', '0', true, false], [in_array('screen', \Talea\Front\Kernel::reservedSlugs(), true), \Talea\Core\Settings::DEFAULTS['screen_seconds'], \Talea\Core\Settings::DEFAULTS['screen_mode'],
            in_array('screen_collections', \Talea\Core\SiteExport::SETTINGS, true), in_array('screen_secret', \Talea\Core\SiteExport::SETTINGS, true)], '2.11 screen: the reserved address, the settings and their export without the secret');
    }
}
