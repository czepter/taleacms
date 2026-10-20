<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContentHygieneTest extends TestCase
{
    /** 2.14: content hygiene (Core\MediaHygiene, Core\ContentCheck, Core\Translations) */
    public function testHygiene(): void
    {
        $f16Rows = [
            ['media_id' => 1, 'image_path' => 'media/2026/01/foto-aa11bb.jpg', 'thumb_path' => 'media/2026/01/foto-aa11bb-nahled.jpg'],
            ['media_id' => 2, 'image_path' => 'media/2026/01/logo-cc22dd.svg', 'thumb_path' => 'media/2026/01/logo-cc22dd.svg'],
            ['media_id' => 3, 'image_path' => 'media/2026/02/cenik-ee33ff.pdf', 'thumb_path' => ''],
            ['media_id' => 4, 'image_path' => 'media/2026/02/hala-0011aa.png', 'thumb_path' => 'media/2026/02/hala-0011aa-nahled.png'],
            ['media_id' => 5, 'image_path' => 'media/2026/03/tym-2233bb.webp', 'thumb_path' => 'media/2026/03/tym-2233bb-nahled.webp'],
        ];
        $f16Content = '{"src":"media\/2026\/01\/foto-aa11bb-1200.jpg"} <a href="https://example.com/media/2026/02/cenik-ee33ff.pdf">PDF</a> <img src="/media/2026/02/hala-0011aa.png.webp"> media/2026/02/hala-0011aa-nahled.png';
        $this->assertSame(['media/2026/01/foto-aa11bb.jpg', 'media/2026/02/cenik-ee33ff.pdf', 'media/2026/02/hala-0011aa.png'], \Talea\Core\MediaHygiene::paths($f16Content), '2.14 MediaHygiene::paths – JSON escapes, the site URL and the -1200, -nahled, .webp variants all point at the original file');
        $this->assertSame([2], array_column(\Talea\Core\MediaHygiene::unused($f16Rows, \Talea\Core\MediaHygiene::paths($f16Content), [5]), 'media_id'), '2.14 MediaHygiene::unused – the referenced files and the one a news item uses stay, the rest is unused');
        $this->assertSame([[1, 2, 3, 4], ['media/2026/03/tym-2233bb.webp']], [array_column(\Talea\Core\MediaHygiene::unused($f16Rows, ['media/2026/03/tym-2233bb-nahled.webp']), 'media_id'),
            \Talea\Core\MediaHygiene::paths('media/2026/03/tym-2233bb.webp')], '2.14 MediaHygiene::unused – the thumbnail path counts as a reference; a .webp upload keeps its name');
        $this->assertSame([[['media_id' => 1, 'sha1' => 'a'], ['media_id' => 3, 'sha1' => 'a'], ['media_id' => 7, 'sha1' => 'a']], [['media_id' => 5, 'sha1' => 'b'], ['media_id' => 9, 'sha1' => 'b']]], \Talea\Core\MediaHygiene::duplicates([
            ['media_id' => 9, 'sha1' => 'b'], ['media_id' => 3, 'sha1' => 'a'], ['media_id' => 7, 'sha1' => 'a'], ['media_id' => 4, 'sha1' => 'c'], ['media_id' => 5, 'sha1' => 'b'], ['media_id' => 6, 'sha1' => ''], ['media_id' => 1, 'sha1' => 'a']]), '2.14 MediaHygiene::duplicates – groups of the same hash with two or more files, oldest first, files without a hash left out');
        $this->assertSame([true, true, false, false, false], array_map([\Talea\Core\MediaHygiene::class, 'isOversized'], [
            ['image_path' => 'media/2026/01/a.jpg', 'thumb_path' => 'media/2026/01/a-nahled.jpg', 'image_size' => 2 * 1024 * 1024 + 1, 'image_width' => 1600],
            ['image_path' => 'media/2026/01/b.jpg', 'thumb_path' => 'media/2026/01/b-nahled.jpg', 'image_size' => 900_000, 'image_width' => 4000],
            ['image_path' => 'media/2026/01/c.jpg', 'thumb_path' => 'media/2026/01/c-nahled.jpg', 'image_size' => 900_000, 'image_width' => 2000],
            ['image_path' => 'media/2026/01/d.svg', 'thumb_path' => 'media/2026/01/d.svg', 'image_size' => 3_000_000, 'image_width' => 5000],
            ['image_path' => 'media/2026/01/e.pdf', 'thumb_path' => '', 'image_size' => 30_000_000, 'image_width' => 0]]), '2.14 MediaHygiene::isOversized – over 2 MB or wider than 2560 px, never an SVG or an attachment');
        $f16Check = fn (array $input): array => array_column(\Talea\Core\ContentCheck::run($input), 'ok', 'check');
        $this->assertSame(// check-english: allow
            ['title_length' => true, 'description_length' => true, 'single_h1' => true, 'heading_order' => true, 'keyword' => true, 'images_alt' => true, 'sample_content' => true], $f16Check([
            'title' => 'Kuchyně na míru pro rodinné domy v Brně', 'description' => 'Navrhujeme a vyrábíme kuchyně na míru pro rodinné domy v Brně a okolí – od zaměření po montáž, se zárukou pěti let.', // check-english: allow
            'html' => '<p>Kuchyně na míru stavíme už dvacet let.</p><h2>Jak pracujeme</h2><p>Text</p><h3>Zaměření</h3><img src="/media/a.jpg" alt="Kuchyň">', 'title_is_h1' => true]), '2.14 ContentCheck – a good text page: the title is the H1, H2 follows, the keyword from the title is in the description and the first paragraph, images described'); // check-english: allow
        $this->assertSame(// check-english: allow
            [['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => false, 'keyword' => false, 'images_alt' => false, 'sample_content' => true],
                t('Headings skip a level (%s) – screen readers and search engines read the outline.', 'H1 → H3')], [$f16Check([
            'title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><p>Vyrábíme nábytek.</p><h3>Skok</h3><img src="/media/a.jpg"><img src="/media/b.jpg" alt="">', 'title_is_h1' => true]), // check-english: allow
            array_column(\Talea\Core\ContentCheck::run(['title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><h3>Skok</h3>', 'title_is_h1' => true]), 'message', 'check')['heading_order']], '2.14 ContentCheck – a short title, no description, two H1s, a skipped level, the keyword missing, an image without alt'); // check-english: allow
        $this->assertSame(// check-english: allow
            ['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => true, 'keyword' => false, 'images_alt' => true, 'sample_content' => true], $f16Check([
            'title' => str_repeat('Dlouhý titulek ', 5), 'description' => str_repeat('Popis stránky pro vyhledávače. ', 6), // check-english: allow
            'html' => '<h2>DLOUHY titulek bez diakritiky</h2><p>dlouhy TITULEK v odstavci</p>', 'title_is_h1' => false]), '2.14 ContentCheck – a build page has its own H1 (none = a warning), a too long title and description are warnings, the keyword search ignores case and diacritics'); // check-english: allow
        $this->assertSame([true, true], [
            array_column(\Talea\Core\ContentCheck::forPage(['title' => 'O nás', 'seo_title' => '', 'description' => '', 'text' => '<h2>Tým</h2>', 'build' => null, 'build_draft' => null]), 'ok', 'check')['single_h1'], // check-english: allow
            array_column(\Talea\Core\ContentCheck::forPage(['title' => 'O nás', 'seo_title' => '', 'description' => '', 'text' => '', 'build' => null, // check-english: allow
                'build_draft' => \Talea\Builder\Build::toJson(\Talea\Builder\Build::fromText('Náš tým', '<p>Lidé.</p>'))]), 'ok', 'check')['single_h1']], '2.14 ContentCheck::forPage – a text page is judged with its title as the H1, a build page by its draft'); // check-english: allow
        $this->assertSame(['missing', 'present', 'outdated', 'outdated', 'present'], [
            \Talea\Core\Translations::status(null, '2026-01-10 10:00:00'), \Talea\Core\Translations::status(['updated_at' => '2026-01-11 10:00:00'], '2026-01-10 10:00:00'),
            \Talea\Core\Translations::status(['updated_at' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'), \Talea\Core\Translations::status(['updated_at' => null, 'created_at' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'),
            \Talea\Core\Translations::status(['updated_at' => '2026-01-09 10:00:00'], null)], '2.14 Translations::status – missing, present, outdated (the original changed after the translation was saved); a never-changed row counts by its date');
        $this->assertSame(['read', 'read', true], [\Talea\Mcp\Catalog::access('list_media_without_alt'), \Talea\Mcp\Catalog::access('translation_status'), \Talea\Mcp\Tools::annotations('translation_status')['readOnlyHint']], '2.14 MCP: the two read-only tools are in the catalog as reads');
    }
}
