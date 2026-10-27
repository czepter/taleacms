<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;

final class CollectionsTest extends TestCase
{
    /** collections */
    public function testFieldsDataAndFill(): void
    {
        $collectionFields = \Talea\Builder\Collections::sanitizeFields([['label' => 'Citát zákazníka', 'type' => 'lines'], ['label' => 'Name', 'type' => 'text'], ['label' => 'Logo', 'type' => 'nesmysl'], ['label' => '']]); // check-english: allow
        $this->assertSame(['citat_zakaznika:lines', 'name_2:text', 'logo:text'], array_map(fn (array $p): string => $p['key'] . ':' . $p['type'], $collectionFields), 'Collections::sanitizeFields: key from the label, a built-in name is not overwritten, an unknown type = text');
        $collectionErrors = [];
        $collectionData = \Talea\Builder\Collections::sanitizeData([['key' => 'web', 'label' => 'Web', 'type' => 'link'], ['key' => 'photo', 'label' => 'Foto', 'type' => 'image'], ['key' => 'cena', 'label' => 'Cena', 'type' => 'number'], ['key' => 'bio', 'label' => 'Bio', 'type' => 'html']],
            ['web' => 'javascript:alert(1)', 'photo' => 'media/2026/a.jpg', 'cena' => '1 200', 'bio' => '<p onclick="x">Ahoj</p><script>1</script>'], $collectionErrors);
        $this->assertSame(['', 'media/2026/a.jpg', '1200', '<p>Ahoj</p>', ['web']], [$collectionData['web'], $collectionData['photo'], $collectionData['cena'], $collectionData['bio'], array_keys($collectionErrors)], 'Collections::sanitizeData: a dangerous link gone, an image from media, a number without spaces, HTML sanitized');
        $collectionValues = ['name' => ['Jan <b>Novák</b>', 'text'], 'bio' => ['<p>Truhlář</p>', 'html'], 'note' => ["řádek 1\nřádek 2", 'lines'], 'url' => ['/tym/jan', 'link'], 'zly' => ['javascript:x', 'link']]; // check-english: allow
        $this->assertSame('<p>Napište {{name}} nebo {{url}}.</p><p>Návod</p>', \Talea\Builder\Collections::fill('<p>{{text}}</p><p>{{name}}</p>', 'html', ['text' => ['<p>Napište {{name}} nebo {{url}}.</p>', 'html'], 'name' => ['Návod', 'text'], 'url' => ['/navod', 'text']]), 'Collections::fill: tags in a filled-in value are not filled in again'); // check-english: allow
        $this->assertSame('Viz {{name}} – X', \Talea\Builder\Collections::fill('{{description}} – {{name}}', 'inline_text', ['description' => ['Viz {{name}}', 'lines'], 'name' => ['X', 'text']]), 'Collections::fill: a single pass also in line text');
        $this->assertSame(['Jan <b>Novák</b>', 'Tým: Jan &lt;b&gt;Novák&lt;/b&gt;', '<p>Truhlář</p>', '<p>řádek 1<br>' . "\n" . 'řádek 2</p>', '/tym/jan', '', ''], [ // check-english: allow
            \Talea\Builder\Collections::fill('{{name}}', 'text', $collectionValues), \Talea\Builder\Collections::fill('Tým: {{name}}', 'inline_text', $collectionValues), // check-english: allow
            \Talea\Builder\Collections::fill('{{bio}}', 'html', $collectionValues), \Talea\Builder\Collections::fill('<p>{{note}}</p>', 'html', $collectionValues),
            \Talea\Builder\Collections::fill('{{url}}', 'link', $collectionValues), \Talea\Builder\Collections::fill('{{zly}}', 'link', $collectionValues), \Talea\Builder\Collections::fill('{{neni}}', 'inline_text', $collectionValues),
        ], 'Collections::fill: text is escaped only by the element, inline and html at once, an html field stays HTML'); // check-english: allow
        [$collectionBuild, $collectionErrors] = \Talea\Builder\Build::sanitize(['children' => [['type' => 'collection_list', 'content' => ['collection' => 'tym'], 'children' => [['type' => 'image', 'content' => ['src' => '{{photo}}']], ['type' => 'button', 'content' => ['link' => '{{url}}']]]]]]);
        $this->assertSame(['{{photo}}', '{{url}}', []], [$collectionBuild['children'][0]['children'][0]['content']['src'], $collectionBuild['children'][0]['children'][1]['content']['link'], $collectionErrors], 'Build::sanitize: {{fields}} tags in an image and a link pass');
    }

    /** 2.10: links between collections, redirect of hidden items */
    public function testLinksBetweenCollections(): void
    {
        $linkFields = \Talea\Builder\Collections::sanitizeFields([['label' => 'Pobočka', 'type' => 'item', 'collection' => 'pobocky'], ['label' => 'Bez kolekce', 'type' => 'item'], ['label' => 'Role', 'type' => 'text', 'collection' => 'x']]); // check-english: allow
        $this->assertSame([['key' => 'pobocka', 'label' => 'Pobočka', 'type' => 'item', 'collection' => 'pobocky'], ['key' => 'bez_kolekce', 'label' => 'Bez kolekce', 'type' => 'text'], ['key' => 'role', 'label' => 'Role', 'type' => 'text']], $linkFields, '2.10: an item link remembers its collection; without one it is a short text'); // check-english: allow
        $linkErrors = [];
        $this->assertSame([['pobocka' => 'praha-centrum', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => '', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => 'Pobočka']], [\Talea\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'praha-centrum'], $linkErrors), \Talea\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'Praha <b>'], $linkErrors), $linkErrors], '2.10: an item link stores the address of the item'); // check-english: allow
        $linkValues = \Talea\Builder\Collections::values(['slug' => 'lide', 'detail' => 1, 'fields' => $linkFields], ['name' => 'Jana', 'slug' => 'jana', 'created_at' => '2026-10-02 10:00:00', 'data' => ['pobocka' => 'praha-centrum']], fn (string $p): string => '/' . $p);
        $this->assertSame([['', 'text'], ['', 'link'], ['praha-centrum', 'text']], [$linkValues['pobocka'], $linkValues['pobocka_url'], $linkValues['pobocka_seo']], '2.10: {{field}}, {{field_url}} and {{field_seo}} of an item link (without a database only the address)');
        $this->assertSame(['', '/tym', 'https://example.com/team', null, null, null], array_map(\Talea\Builder\Collections::cleanRedirect(...), ['', '/tym', 'https://example.com/team', 'javascript:alert(1)', 'tym', '/a b']), '2.10: where hidden items redirect – a path on the site or https');
        $this->assertSame(['8:00–12:00, 13:30–17:00', '9:00–12:00', ''], [\Talea\Core\Hours::rangesText([['08:00', '12:00'], ['13:30', '17:00']]), \Talea\Core\Hours::rangesText('9-12'), \Talea\Core\Hours::rangesText('morning')], '2.10: Hours::rangesText – hours for people from ranges or their text, nothing from nonsense (the door sign)');
        $this->assertSame([['a4', 'a5'], [true, true, true, true, true]], [\Talea\Core\HoursSign::FORMATS,
            (fn (string $v): array => [str_contains($v, '@page'), str_contains($v, '@media print'), str_contains($v, 'data-print'), preg_match('/(href|src)="https?:/', $v) === 0, str_contains($v, '<!DOCTYPE html>')])((string) file_get_contents(TALEA_SYSTEM . '/views/admin/settings/hours_sign.php'))], '2.10: HoursSign – formats, a standalone view with print CSS, a Print button only on screen and no outside resource');
    }

    /** a number field reads as a number in the page language, {{field_raw}} as stored */
    public function testNumbersInThePageLanguage(): void
    {
        $numbers = fn (string $language): array => \Talea\Core\Language::runWith($language, fn (): array => array_map(\Talea\Builder\Collections::displayNumber(...), ['1490', '4.20', '4,5', '-12000', '', 'on request', '1 490']));
        $this->assertSame([
            ["1\u{00A0}490", '4,20', '4,5', "-12\u{00A0}000", '', 'on request', "1\u{00A0}490"],
            ['1,490', '4.20', '4.5', '-12,000', '', 'on request', '1,490'],
            ['1.490', '4,20', '4,5', '-12.000', '', 'on request', '1.490'],
        ], [$numbers('cs'), $numbers('en'), $numbers('de')], 'Collections::displayNumber: separators of the page language, the decimals as stored, text and empty values unchanged');
        $collection = ['slug' => 'products', 'detail' => false, 'preset' => '', 'fields' => [['key' => 'price', 'label' => 'Price', 'type' => 'number']]];
        $values = \Talea\Core\Language::runWith('en', fn (): array => \Talea\Builder\Collections::values($collection, ['name' => '[Product]', 'slug' => 'product', 'created_at' => '2026-10-18 10:00:00', 'data' => ['price' => '1490.50']], fn (string $p): string => $p));
        $this->assertSame([['1,490.50', 'text'], ['1490.50', 'text']], [$values['price'], $values['price_raw']], 'Collections::values: {{price}} for visitors, {{price_raw}} as stored');
    }

    /** a gallery field: photos from Media as lines, also read from a formatted text that held the photos before */
    public function testGalleryField(): void
    {
        $fields = \Talea\Builder\Collections::sanitizeFields([['label' => 'Photos', 'type' => 'gallery']]);
        $this->assertSame([['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery']], $fields, 'Collections::sanitizeFields: gallery is a known type');
        $errors = [];
        $this->assertSame([
            ['photos' => "media/a.webp | First\n/media/b.webp"],
            ['photos' => "/media/2026/c.webp | Anna & Ben 1\nmedia/d.webp"],
            ['photos' => "media/e.webp | Lake"],
            ['photos' => ''],
        ], [
            \Talea\Builder\Collections::sanitizeData($fields, ['photos' => "media/a.webp | First\r\n\n /media/b.webp "], $errors),
            \Talea\Builder\Collections::sanitizeData($fields, ['photos' => '<figure><img src="/media/2026/c.webp" alt="Anna &amp; Ben 1" width="768" height="555"></figure><p><img alt="" src="media/d.webp"></p>'], $errors),
            \Talea\Builder\Collections::sanitizeData($fields, ['photos' => [['src' => 'media/e.webp', 'alt' => "<b>Lake</b>"]]], $errors),
            \Talea\Builder\Collections::sanitizeData($fields, ['photos' => "javascript:alert(1) | x\nmedia/f.webp"], $errors),
        ], 'Collections::sanitizeData: lines, figures of a former formatted text and a list from MCP become lines; a photo that is not from Media refuses the value');
        $this->assertSame(['photos' => 'Photos'], $errors);
        $many = implode("\n", array_map(fn (int $i): string => 'media/2026/10/photo-' . $i . '.webp | Photo ' . $i, range(1, 110)));
        $this->assertSame([110, 110], [count(\Talea\Builder\Collections::galleryPhotos($many)), substr_count((string) \Talea\Builder\Collections::cleanGallery($many), "\n") + 1],
            'Collections::galleryPhotos: a wedding story with 110 photos keeps them all (shown and saved)');
        $values = \Talea\Builder\Collections::values(['slug' => 'story', 'detail' => true, 'preset' => '', 'fields' => $fields], ['name' => 'X', 'slug' => 'x', 'created_at' => '2026-10-27 10:00:00', 'data' => ['photos' => "media/a.webp | A \"quoted\"\nmedia/b.webp"]], fn (string $p): string => '/' . $p);
        $this->assertSame([["media/a.webp | A \"quoted\"\nmedia/b.webp", 'gallery'], ['2', 'text']], [$values['photos'], $values['photos_count']], 'Collections::values: {{photos}} keeps the lines, {{photos_count}} counts them');
        $figures = '<figure><img src="media/a.webp" alt="A &quot;quoted&quot;" loading="lazy"></figure>' . "\n" . '<figure><img src="media/b.webp" alt="" loading="lazy"></figure>';
        $this->assertSame([$figures, $figures, '', '', ''], [
            \Talea\Builder\Collections::fill('<p>{{photos}}</p>', 'html', $values), \Talea\Builder\Collections::fill('{{photos}}', 'html', $values),
            \Talea\Builder\Collections::fill('{{photos}}', 'text', $values), \Talea\Builder\Collections::fill('{{photos}}', 'link', $values), \Talea\Builder\Collections::fill('{{photos}}', 'image', $values),
        ], 'Collections::fill: figures in formatted text, nothing in a heading, a link or an image');
        $this->assertSame([[['src' => 'media/a.webp', 'alt' => 'A "quoted"'], ['src' => 'media/b.webp', 'alt' => '']], null], [
            \Talea\Builder\Collections::itemGallery($values), \Talea\Builder\Collections::itemGallery(['name' => ['X', 'text']]),
        ], 'Collections::itemGallery: a Gallery element without photos shows the item gallery; null without a gallery field');
        $template = \Talea\Builder\Collections::defaultTemplate(['fields' => $fields]);
        $gallery = $template['children'][0]['children'][0]['children'][1];
        $this->assertSame(['gallery', 'masonry'], [$gallery['type'], $gallery['content']['layout']], 'Collections::defaultTemplate: a gallery field becomes a Gallery element (it shows the item gallery by itself)');
    }
}
