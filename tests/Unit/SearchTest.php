<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Search;

final class SearchTest extends TestCase
{
    /** search */
    public function testNormalizeAndWords(): void
    {
        $this->assertSame('nabrezi vltavy promena', Search::normalize('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'Search::normalize'); // check-english: allow
        $this->assertSame(['nabrezi'], Search::words('co je na Nábřeží'), 'Search::words: short words drop out'); // check-english: allow
        $this->assertSame(['tajne', 'verejne', 'fraze'], Search::words('+tajne -verejne "fraze" (x) ~y*'), 'Search::words: fulltext operators do not take effect');
        $this->assertSame(8, count(Search::words('aaa bbb ccc ddd eee fff ggg hhh iii jjj')), 'Search::words: at most 8 words');
    }

    public function testFind(): void
    {
        $this->assertSame(['site-search', 'seo', 'menus'], array_column(\Talea\Core\Search::find('search', [
            ['title' => 'Menus', 'url' => 'menus', 'text' => 'Link to site search from the menu.'],
            ['title' => 'Site search', 'url' => 'site-search', 'text' => 'How search works.'],
            ['title' => 'SEO', 'url' => 'seo', 'text' => 'Search engines and search results; search console.'],
        ]), 'url'), 'Search::find: a match in the title comes first');
        $this->assertSame([['title' => 'Kuchyně', 'url' => 'kuchyne', 'snippet' => 'Kuchyně na míru – bohaté zkušenosti.']], \Talea\Core\Search::find('zkusenosti kuchyne', [ // check-english: allow
            ['title' => 'O nás', 'url' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'], // check-english: allow
            ['title' => 'Kuchyně', 'url' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'], // check-english: allow
        ]), 'Search::find: without diacritics, all words, excerpt'); // check-english: allow
    }
}
