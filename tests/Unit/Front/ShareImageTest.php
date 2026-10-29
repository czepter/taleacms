<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;

final class ShareImageTest extends TestCase
{
    /** 2.12: share images drawn by the site (Front\ShareImage) */
    public function testShareImages(): void
    {
        $ogChars = fn (string $s): int => mb_strlen($s) * 10; // a stand-in for GD: every character 10 px wide
        $this->assertSame(// check-english: allow
            [['Dřevěné schody', 'na míru'], ['Nejneobhos', 'podařováva', 'telnějšími', 'a'], ['']], [ // check-english: allow
            \Talea\Front\ShareImage::wrap('Dřevěné schody na míru', $ogChars, 150), \Talea\Front\ShareImage::wrap('Nejneobhospodařovávatelnějšími a', $ogChars, 100), \Talea\Front\ShareImage::wrap('', $ogChars, 100)], '2.12 ShareImage::wrap – words fill the line, a word wider than the line is broken by characters, no text = one empty line'); // check-english: allow
        $ogWidth = fn (string $s, int $size): int => (int) round(mb_strlen($s) * $size * 0.6); // 0.6 em per character
        $this->assertSame([[60, ['Kontakt']], [48, ['slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo']]], [ // check-english: allow
            \Talea\Front\ShareImage::fit("Kontakt \n", $ogWidth, 1040, 3, 60, 34), \Talea\Front\ShareImage::fit(str_repeat('slovo ', 16), $ogWidth, 1040, 3, 60, 34)], '2.12 ShareImage::fit – a short title at the largest size, a long one goes down until three lines hold it'); // check-english: allow
        [$ogSize, $ogLines] = \Talea\Front\ShareImage::fit(str_repeat('slovo ', 60), $ogWidth, 1040, 3, 60, 34);
        $this->assertSame([34, 3, true, true], [$ogSize, count($ogLines), str_ends_with($ogLines[2], 'slovo…'), $ogWidth($ogLines[2], 34) <= 1040], '2.12 ShareImage::fit – what the smallest size cannot hold is cut, the last line ends with an ellipsis');
        $ogBrief = ['v' => 1, 'title' => 'Kontakt', 'site' => 'Firma', 'colors' => ['#2b5be3', '#ffffff', '#16181d'], 'logo' => '', 'logo_time' => 0]; // check-english: allow
        $ogHash = \Talea\Front\ShareImage::hash($ogBrief, 'key-a');
        $this->assertSame([1, true, false, false, false], [
            preg_match('/^[a-f0-9]{32}$/', $ogHash), $ogHash === \Talea\Front\ShareImage::hash($ogBrief, 'key-a'), $ogHash === \Talea\Front\ShareImage::hash(array_replace($ogBrief, ['title' => 'Kontakty']), 'key-a'),
            $ogHash === \Talea\Front\ShareImage::hash(array_replace($ogBrief, ['colors' => ['#000000', '#ffffff', '#16181d']]), 'key-a'), $ogHash === \Talea\Front\ShareImage::hash($ogBrief, 'key-b')], '2.12 ShareImage::hash – 32 hex characters; the same brief gives the same address, another title, colour or key a different one');
        $this->assertSame([true, false, false, false, false], [
            \Talea\Front\ShareImage::matches($ogHash, $ogBrief, 'key-a'), \Talea\Front\ShareImage::matches(strrev($ogHash), $ogBrief, 'key-a'), \Talea\Front\ShareImage::matches(substr($ogHash, 1) . '0', $ogBrief, 'key-a'),
            \Talea\Front\ShareImage::matches('../' . $ogHash, $ogBrief, 'key-a'), \Talea\Front\ShareImage::matches($ogHash, $ogBrief, 'key-b')], '2.12 ShareImage::matches – the site\'s own hash passes; a tampered, malformed or foreign-key one never draws');
        $this->assertSame(['1', true, true, '1200×630'], [\Talea\Core\Settings::DEFAULTS['share_image_auto'], in_array('share_image_auto', \Talea\Core\SiteExport::SETTINGS, true),
            in_array('og', \Talea\Front\Kernel::reservedSlugs(), true), \Talea\Front\ShareImage::WIDTH . '×' . \Talea\Front\ShareImage::HEIGHT], '2.12 share images: on by default, exported with the site, /og reserved, the Open Graph size');
    }
}
