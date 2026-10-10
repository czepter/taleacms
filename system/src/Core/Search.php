<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Search index of articles: the column tl_news.search_text holds the text in lowercase without diacritics, so a reader
 * finds "café" even after typing "cafe". For locked articles only the title and the intro are indexed - so the
 * locked text cannot be pieced together from search results.
 */
final class Search
{
    /** Lowercase without diacritics, only letters and digits separated by a space. */
    public static function normalize(string $text): string
    {
        $text = remove_diacritics(mb_strtolower(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Recomputes the index of one article; called after every save (admin, Claude). */
    public static function index(Db $db, int $idc): void
    {
        $c = $db->one('SELECT title, intro, text, keywords FROM {news} WHERE news_id = ?', [$idc]);
        if ($c !== null) {
            $db->update('news', ['search_text' => self::normalize($c['title'] . ' ' . $c['keywords'] . ' ' . $c['intro'] . ' ' . $c['text'])], ['news_id' => $idc]);
        }
    }

    /** Fills in the index for articles that do not have it yet (after a system update); in batches so it does not hold up the request. */
    public static function complete(Db $db, int $batch = 100): int
    {
        $ids = array_column($db->all('SELECT news_id FROM {news} WHERE search_text IS NULL LIMIT ' . max(1, $batch)), 'news_id');
        foreach ($ids as $idc) {
            self::index($db, (int) $idc);
        }

        return count($ids);
    }

    /**
     * Search in pages and collection items without an index (a company site has hundreds of them, not thousands): all words
     * of the query regardless of diacritics and letter case. Returns matches with an excerpt of the text around the first
     * word found, sorted by relevance (a match in the title weighs most, then the number of occurrences in the text; on a tie
     * the site's order stays).
     *
     * @param list<array{title: string, url: string, text: string}> $candidates
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public static function find(string $q, array $candidates, int $limit = 20): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 2));
        if ($words === []) {
            return [];
        }
        $results = [];
        $score = [];
        foreach ($candidates as $k) {
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $k['text'])), ENT_QUOTES | ENT_HTML5)));
            $search = self::normalize($k['title'] . ' ' . $plain);
            foreach ($words as $word) {
                if (!str_contains($search, $word)) {
                    continue 2;
                }
            }
            // excerpt: Czech characters map 1:1 without diacritics, so a position in the text without diacritics matches the original too
            $position = mb_strpos(remove_diacritics(mb_strtolower($plain)), $words[0]);
            $from = $position === false ? 0 : max(0, $position - 60);
            $excerpt = mb_substr($plain, $from, 180);
            $results[] = ['title' => $k['title'], 'url' => $k['url'], 'snippet' => ($from > 0 ? '…' : '') . $excerpt . (mb_strlen($plain) > $from + 180 ? '…' : '')];
            $name = self::normalize($k['title']);
            $text = self::normalize($plain);
            $score[] = array_sum(array_map(fn (string $s): int => (str_contains($name, $s) ? 100 : 0) + min(20, substr_count($text, $s)), $words));
        }
        // stable sort: the same score = the order in which the site sorts them
        $order = array_keys($results);
        array_multisort($score, SORT_DESC, $order, SORT_ASC, $results);

        return array_slice($results, 0, $limit);
    }

    /** The words of a full-text query: normalised, 3 or more characters, at most 8; every one is a prefix and all are required (Dialect::fulltextQuery()). @return list<string> */
    public static function words(string $q): array
    {
        return array_slice(array_values(array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 3)), 0, 8);
    }
}
