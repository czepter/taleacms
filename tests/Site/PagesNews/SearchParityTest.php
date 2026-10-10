<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * HF-14: search finds the same news items on MySQL and PostgreSQL, whatever the accents and the case. The site search (full text over the
 * normalised search_text, a title LIKE for words under three letters) and the administration's search box (LIKE through the dialect) are asked
 * with the same words; the expected results are written once and must hold on both engines.
 */
#[Group('site')]
final class SearchParityTest extends SiteTestCase
{
    /** title => text */
    private const array NEWS = [
        "Caf\u{e9} Cr\u{e8}me Br\u{fb}l\u{e9}e" => 'A classic dessert with a caramel crust.',
        "Z\u{fc}rich in autumn" => "The na\u{ef}ve visitor takes the tram.",
        'Plain porridge' => 'No accents here, only oats and milk.',
    ];

    public function testTheNewsItemsAreCreated(): void
    {
        $site = $this->site();
        $category = (string) $site->value('SELECT name FROM tl_categories ORDER BY category_id LIMIT 1');
        foreach (self::NEWS as $title => $text) {
            $site->mcpResult('create_news', ['title' => $title, 'category' => $category, 'content' => '<p>' . $text . '</p>']);
        }
        $titles = array_keys(self::NEWS);
        $site->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE title IN (?, ?, ?)', $titles);
        $this->assertSame('3', (string) $site->value('SELECT COUNT(*) FROM tl_news WHERE visible = 1 AND title IN (?, ?, ?)', $titles), 'three published news items');
    }

    /** @return array<string, array{string, list<string>, list<string>}> query => found titles, not found titles */
    public static function queries(): array
    {
        $cafe = "Caf\u{e9} Cr\u{e8}me Br\u{fb}l\u{e9}e";
        $zurich = "Z\u{fc}rich in autumn";
        $plain = 'Plain porridge';

        return [
            'without accents' => ['cafe', [$cafe], [$zurich, $plain]],
            'with accents' => ["caf\u{e9}", [$cafe], [$zurich, $plain]],
            'upper case' => ["CR\u{c8}ME", [$cafe], [$zurich, $plain]],
            'a prefix' => ['brul', [$cafe], [$zurich, $plain]],
            'two words, both needed' => ['cafe caramel', [$cafe], [$zurich, $plain]],
            'a word of the text only' => ['tram', [$zurich], [$cafe, $plain]],
            'a diaeresis' => ['naive', [$zurich], [$cafe, $plain]],
            'inside a word: only the title LIKE finds it' => ['URI', [$zurich], [$cafe, $plain]],
            'nothing' => ['zzqqxx', [], [$cafe, $zurich, $plain]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\Depends('testTheNewsItemsAreCreated')]
    #[\PHPUnit\Framework\Attributes\DataProvider('queries')]
    public function testTheSiteSearchFindsTheSameOnBothEngines(string $query, array $found, array $missing): void
    {
        $body = $this->site()->client('visitor')->get('/search?q=' . rawurlencode($query))->body;
        foreach ($found as $title) {
            $this->assertStringContainsString(htmlspecialchars($title, ENT_QUOTES), $body, "site search for “{$query}” finds “{$title}”");
        }
        foreach ($missing as $title) {
            $this->assertStringNotContainsString(htmlspecialchars($title, ENT_QUOTES), $body, "site search for “{$query}” does not find “{$title}”");
        }
    }

    #[\PHPUnit\Framework\Attributes\Depends('testTheNewsItemsAreCreated')]
    public function testTheAdministrationSearchBoxIgnoresCaseAndAccents(): void
    {
        $admin = $this->site()->admin();
        foreach (["cr\u{e8}me", 'CREME', 'zurich', 'PORRIDGE'] as $word) {
            $body = $admin->get('/admin.php?module=news&search=' . rawurlencode($word))->body;
            $title = $word === 'PORRIDGE' ? 'Plain porridge' : ($word === 'zurich' ? "Z\u{fc}rich in autumn" : "Caf\u{e9} Cr\u{e8}me Br\u{fb}l\u{e9}e");
            $this->assertStringContainsString(htmlspecialchars($title, ENT_QUOTES), $body, "admin search for “{$word}” finds “{$title}”");
        }
        $this->assertStringNotContainsString('Plain porridge', $admin->get('/admin.php?module=news&search=' . rawurlencode("cr\u{e8}me"))->body, 'and only what matches');
    }
}
