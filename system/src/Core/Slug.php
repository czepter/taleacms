<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * A free slug in the URL (seo_link of a page, news item, category, collection item, popup slug): when the base is taken,
 * it gets a sequence number (o-nas, o-nas-2, o-nas-3…). The base is shortened so that it fits in the column with the number.
 *
 * Slugs per language (setting slugs_per_language, off by default): a page, a news item or a news category may have the same
 * slug as one in another language version (/contact and /en/contact). Every "is the slug taken?" check of those three tables
 * asks scope() or taken(), so it is per language exactly when the setting is on. The setting changes only through
 * switchPerLanguage(), which drops the global unique keys (on) or puts them back (off, refused while two language versions share
 * a slug); the per-language keys (language, slug) of the migration always stay. Collections stay global (/<collection> is the
 * same in every language); collection items and their categories were per language already. System addresses and language codes
 * stay reserved in every language.
 */
final class Slug
{
    /** Tables with a global slug key next to the per-language one: table => [id column, global key, per-language key]. */
    public const array TABLES = [
        'pages' => ['page_id', 'uq_pages_slug', 'uq_pages_language_slug'],
        'news' => ['news_id', 'uq_news_slug', 'uq_news_language_slug'],
        'categories' => ['category_id', 'uq_categories_slug', 'uq_categories_language_slug'],
    ];

    /** The setting slugs_per_language once per request; null = not read yet. */
    private static ?bool $perLanguage = null;

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

    /** Are slugs of pages, news and categories unique per language version? Off without a database (a site before installation). */
    public static function perLanguage(?Db $db): bool
    {
        if (self::$perLanguage === null && $db !== null) {
            try {
                self::$perLanguage = $db->value("SELECT value FROM {settings} WHERE name = 'slugs_per_language'") === '1';
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$perLanguage ?? false;
    }

    /** Sets the value without the database (tests); null forgets it, so it is read again (Settings::set does that). */
    public static function setPerLanguage(?bool $on): void
    {
        self::$perLanguage = $on;
    }

    /**
     * The part of a "slug taken?" query that limits it to the language version when slugs are per language:
     * [" AND language = ?", [language]], otherwise ["", []] - the slug is then taken by any language version.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function scope(?Db $db, string $language, string $column = 'language'): array
    {
        return self::perLanguage($db) ? [' AND ' . $column . ' = ?', [$language]] : ['', []];
    }

    /** Does a page, news item or category other than $except have the slug - in the language version when slugs are per language, in any otherwise? The trash counts (a restore must not collide). */
    public static function taken(Db $db, string $table, string $slug, string $language, int $except = 0): bool
    {
        [$id] = self::TABLES[$table] ?? throw new \InvalidArgumentException('Unknown table ' . $table);
        [$where, $params] = self::scope($db, $language);

        return $db->value("SELECT 1 FROM {{$table}} WHERE slug = ? AND {$id} <> ?{$where} LIMIT 1", [$slug, $except, ...$params]) !== null;
    }

    /**
     * Slugs that more than one language version uses: table => slugs (at most $limit per table, trash included, as the global key
     * covers it too). Empty = the global keys can come back.
     *
     * @return array<string, list<string>>
     */
    public static function duplicates(Db $db, int $limit = 10): array
    {
        $found = [];
        foreach (array_keys(self::TABLES) as $table) {
            $slugs = array_map('strval', array_column($db->all("SELECT slug FROM {{$table}} GROUP BY slug HAVING COUNT(*) > 1 ORDER BY slug LIMIT " . max(1, $limit)), 'slug'));
            if ($slugs !== []) {
                $found[$table] = $slugs;
            }
        }

        return $found;
    }

    /**
     * Switches slugs per language on or off - the only way the setting changes. On: the global unique keys are dropped (the
     * per-language ones stay), then the setting is written. Off: refused while a slug is shared by two language versions;
     * otherwise the setting goes first (checks are global again from that moment), then the global keys come back. When a key
     * cannot come back (a second language version took a shared slug in the meantime), the keys this call already put back go
     * again and the setting returns to on, so no table refuses a shared slug while the setting allows it. Returns null when
     * done, otherwise the reason: [English source text for t(), the slugs it names for %s].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function switchPerLanguage(Db $db, Settings $settings, bool $on): ?array
    {
        if ($on === ($settings->get('slugs_per_language') === '1') && self::globalKeysMatch($db, $on)) {
            return null;
        }
        if ($on) {
            foreach (self::TABLES as $table => [, $global, $perLanguage]) {
                if (!array_key_exists($perLanguage, $db->uniqueKeys($table))) {
                    return ['The database is not updated yet - run the migrations and try again.', ''];
                }
                if (array_key_exists($global, $db->uniqueKeys($table))) {
                    $db->dropUniqueKey($table, $global);
                }
            }
            $settings->set('slugs_per_language', '1');

            return null;
        }
        if (($shared = self::duplicates($db)) !== []) {
            return self::refusal($shared);
        }
        $settings->set('slugs_per_language', '0');
        $added = [];
        try {
            foreach (self::TABLES as $table => [, $global]) {
                if (!array_key_exists($global, $db->uniqueKeys($table))) {
                    $db->addUniqueKey($table, $global, ['slug']);
                    $added[$table] = $global;
                }
            }
        } catch (\PDOException $e) {
            foreach ($added as $table => $global) {
                $db->dropUniqueKey($table, $global);
            }
            $settings->set('slugs_per_language', '1');

            return ($shared = self::duplicates($db)) !== [] ? self::refusal($shared) : throw $e;
        }

        return null;
    }

    /**
     * The form an address is stored under in the redirects for content of the language version $language: with the prefix
     * (en/old) while slugs are per language - /en/old and /old may then be two different pages -, otherwise without it
     * (the redirect then answers in every version and Front\Kernel adds the visitor's prefix to the target).
     */
    public static function redirectPath(?Db $db, string $path, string $language): string
    {
        return $language !== '' && self::perLanguage($db) ? $language . '/' . ltrim($path, '/') : $path;
    }

    /** @param array<string, list<string>> $shared @return array{0: string, 1: string} */
    private static function refusal(array $shared): array
    {
        return ['The same address in every language cannot be switched off while two language versions share an address: %s. Change one of each pair first.',
            implode(', ', array_map(fn (string $s): string => '/' . $s, array_unique(array_merge(...array_values($shared)))))];
    }

    /** Do the global keys exist exactly when slugs are not per language? */
    private static function globalKeysMatch(Db $db, bool $on): bool
    {
        foreach (self::TABLES as $table => [, $global]) {
            if (array_key_exists($global, $db->uniqueKeys($table)) === $on) {
                return false;
            }
        }

        return true;
    }
}
