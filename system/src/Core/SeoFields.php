<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The "Search engines" fields shared by pages, news and collection items. fromInput() is the one cleaner of them for
 * the admin forms, MCP and imports; it returns only the keys it was given.
 */
final class SeoFields
{
    public const array COLUMNS = ['focus_keywords', 'key_page', 'robots', 'canonical_url', 'breadcrumb_title', 'share_title', 'share_description'];

    public const int MAX_KEYWORDS = 5;
    private const int KEYWORD_LENGTH = 100;
    private const int COLUMN_LENGTH = 500;

    /** @return array<string, string|int> */
    public static function fromInput(array $in): array
    {
        $out = [];
        if (array_key_exists('focus_keywords', $in)) {
            $out['focus_keywords'] = implode("\n", self::split($in['focus_keywords']));
        }
        if (array_key_exists('key_page', $in)) {
            $out['key_page'] = (int) filter_var($in['key_page'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('robots', $in)) {
            $out['robots'] = Robots::normalize(is_array($in['robots']) ? $in['robots'] : Robots::scalar($in['robots']));
        }
        if (array_key_exists('canonical_url', $in)) {
            $out['canonical_url'] = Robots::canonical(Robots::scalar($in['canonical_url']));
        }
        foreach (['breadcrumb_title' => 200, 'share_title' => 200, 'share_description' => 320] as $key => $length) {
            if (array_key_exists($key, $in)) {
                $out[$key] = mb_substr(trim(Robots::scalar($in[$key])), 0, $length);
            }
        }

        return $out;
    }

    /** The seven fields of a row as MCP returns them (key_page as a boolean). @return array<string, string|bool> */
    public static function output(array $row): array
    {
        $out = array_intersect_key($row, array_flip(self::COLUMNS));
        if (isset($out['key_page'])) {
            $out['key_page'] = (bool) $out['key_page'];
        }

        return $out;
    }

    /** @return list<string> the stored keywords, the first is the scored one */
    public static function keywords(string $stored): array
    {
        return self::split($stored);
    }

    /** Whether a text (a search that led to a page) is stored as exactly one focus keyword: no comma or line, not too long. */
    public static function fitsAsKeyword(string $text): bool
    {
        return trim($text) !== '' && !preg_match('~[,\r\n]~', $text) && mb_strlen(trim($text)) <= self::KEYWORD_LENGTH;
    }

    /** @return list<string> */
    private static function split(mixed $value): array
    {
        $list = is_array($value) ? $value : preg_split('~[,\r\n]+~', Robots::scalar($value));
        $seen = [];
        foreach ($list as $keyword) {
            $keyword = mb_substr(trim(Robots::scalar($keyword)), 0, self::KEYWORD_LENGTH);
            if ($keyword !== '') {
                $seen[mb_strtolower($keyword)] ??= $keyword;
            }
        }

        $kept = array_slice(array_values($seen), 0, self::MAX_KEYWORDS);
        while (count($kept) > 1 && mb_strlen(implode("\n", $kept)) > self::COLUMN_LENGTH) {
            array_pop($kept); // the column holds 500 characters: drop trailing keywords, never cut one
        }

        return $kept;
    }
}
