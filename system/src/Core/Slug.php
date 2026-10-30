<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * A free slug in the URL (seo_link of a page, news item, category, collection item, popup slug): when the base is taken,
 * it gets a sequence number (o-nas, o-nas-2, o-nas-3…). The base is shortened so that it fits in the column with the number.
 *
 * Slugs are unique per language version: a page, a news item or a news category may have the same slug as one in another
 * language version (/contact and /de/contact). Every "is the slug taken?" check of those three tables asks scope() or taken(),
 * so it looks inside one language. The unique keys (language, slug) of the database say the same. Collections stay global
 * (/<collection> is the same in every language); collection items and their categories were per language already. System
 * addresses and language codes stay reserved in every language.
 */
final class Slug
{
    /** Tables whose slug is unique per language: table => id column. */
    public const array TABLES = ['pages' => 'page_id', 'news' => 'news_id', 'categories' => 'category_id'];

    /** @param callable(string): bool $isTaken */
    public static function makeUnique(string $base, callable $isTaken, int $max = 160): string
    {
        $url = mb_substr($base, 0, $max);
        for ($i = 2; $isTaken($url); $i++) {
            if ($i > 10000) {
                throw new \RuntimeException('No free address was found.');
            }
            $extension = '-' . $i;
            $url = rtrim(mb_substr($base, 0, $max - strlen($extension)), '-') . $extension;
        }

        return $url;
    }

    /**
     * The part of a "slug taken?" query that limits it to the language version: [" AND language = ?", [language]].
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function scope(string $language, string $column = 'language'): array
    {
        return [' AND ' . $column . ' = ?', [$language]];
    }

    /** Does a page, news item or category other than $except have the slug - in the language version? The trash counts (a restore must not collide). */
    public static function taken(Db $db, string $table, string $slug, string $language, int $except = 0): bool
    {
        $id = self::TABLES[$table] ?? throw new \InvalidArgumentException('Unknown table ' . $table);
        [$where, $params] = self::scope($language);

        return $db->value("SELECT 1 FROM {{$table}} WHERE slug = ? AND {$id} <> ?{$where} LIMIT 1", [$slug, $except, ...$params]) !== null;
    }

    /** The type word of a slug made from a number (fromId): table => [id column, word]. */
    public const array FALLBACK = ['pages' => ['page_id', 'page'], 'news' => ['news_id', 'news'], 'categories' => ['category_id', 'category'], 'collection_items' => ['item_id', 'item']];

    /**
     * A row just created with slugify's "n-a" (a title with nothing to transliterate - Hebrew, Chinese, emoji) gets its type
     * and number instead: page-12, news-40, category-3, item-7 (unique in its scope like any slug). Only right after an
     * insert - an existing slug never changes. Returns the slug the row has now.
     */
    public static function fromId(Db $db, string $table, int $id): string
    {
        [$key, $word] = self::FALLBACK[$table] ?? throw new \InvalidArgumentException('Unknown table ' . $table);
        $row = $db->one("SELECT * FROM {{$table}} WHERE {$key} = ?", [$id]) ?? [];
        $current = (string) ($row['slug'] ?? '');
        if (preg_match('/^n-a(-\d+)?$/D', $current) !== 1) {
            return $current;
        }
        $isTaken = $table === 'collection_items'
            ? fn (string $s): bool => $db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ? AND item_id <> ? LIMIT 1', [(int) $row['collection_id'], (string) $row['language'], $s, $id]) !== null
            : fn (string $s): bool => self::taken($db, $table, $s, (string) ($row['language'] ?? ''), $id);
        $slug = self::makeUnique($word . '-' . $id, $isTaken, 120);
        $db->update($table, ['slug' => $slug], [$key => $id]);

        return $slug;
    }

    /**
     * The form an address is stored under in the redirects for content of the language version $language: with the prefix
     * (en/old), as /en/old and /old may be two different pages.
     */
    public static function redirectPath(string $path, string $language): string
    {
        return $language !== '' ? $language . '/' . ltrim($path, '/') : $path;
    }
}
