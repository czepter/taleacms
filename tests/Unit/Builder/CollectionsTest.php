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
}
