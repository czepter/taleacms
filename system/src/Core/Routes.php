<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's system URLs in the language of the version: the Czech version has /novinky, /novinky/kategorie/…,
 * /novinky/stitek/… and /hledani, every other one /news, /news/category/…, /news/tag/… and /search.
 *
 * Code inside the system works with the Czech (internal) paths; App::url() converts them to public ones and Front\Kernel
 * converts public ones back to internal. The other form of a URL (e.g. the old /novinky on an English site) redirects
 * permanently to the valid one, so links and search engine rankings stay. If the site has its own page with the slug news
 * or search, the page keeps it and the system uses the Czech word.
 *
 * The setting news_slug (Settings → General) replaces the first segment of the news URLs in every language (/blog,
 * /blog/category/x); the old forms /novinky and /news redirect to it.
 */
final class Routes
{
    /** internal (Czech) word => English one */
    private const array FIRST_SEGMENTS = ['novinky' => 'news', 'hledani' => 'search'];
    private const array SECOND_SEGMENTS = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null English words that a page of the site itself occupies */
    private static ?array $taken = null;

    /** The custom news slug (setting news_slug), null = not read yet. */
    private static ?string $news = null;

    /** The custom first segment of the news URLs ('' = the default word of the version's language). */
    public static function newsSlug(?Db $db): string
    {
        if (self::$news === null && $db !== null) {
            try {
                self::$news = (string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'news_slug'") ?? '');
            } catch (\Throwable) {
                self::$news = ''; // site before installation
            }
        }

        return self::$news ?? '';
    }

    /** Settings::set keeps the value current; null forgets it (read again from the database). */
    public static function setNewsSlug(?string $slug): void
    {
        self::$news = $slug;
    }

    /** Does a page or collection slug collide with the custom news slug? */
    public static function isNewsSlug(string $slug, ?Db $db): bool
    {
        return $slug !== '' && $slug === self::newsSlug($db);
    }

    /** Why a news slug cannot be used (the message for the administrator), null = it is free. */
    public static function slugError(string $slug, Db $db): ?string
    {
        if ($slug === '') {
            return null;
        }
        $system = array_diff(\Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, ['novinky', 'news']);
        if (in_array($slug, $system, true) || isset(Language::AVAILABLE[$slug])) {
            return 'This URL is used by the system, choose another one.';
        }
        if ($db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$slug]) !== null
            || $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$slug]) !== null) {
            return 'A page or a collection already uses this URL.';
        }

        return null;
    }

    /** English words are used for every language except Czech. */
    public static function isEnglish(string $language): bool
    {
        return $language !== 'cs';
    }

    /** Internal path (without the leading slash, including a ?query) to the public one for the given version language. */
    public static function publicPath(string $path, string $language, ?Db $db): string
    {
        if (!preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path, $m)) {
            return $path;
        }
        $custom = $m[1] === 'novinky' ? self::newsSlug($db) : '';
        if ($custom === '' && (!self::isEnglish($language) || self::isTaken(self::FIRST_SEGMENTS[$m[1]], $db))) {
            return $path;
        }
        $rest = substr($path, strlen($m[1]));
        if ($m[1] === 'novinky' && self::isEnglish($language) && preg_match('#^/(kategorie|stitek)(?=/)#', $rest, $d)) {
            $rest = '/' . self::SECOND_SEGMENTS[$d[1]] . substr($rest, strlen($d[0]));
        }

        return ($custom !== '' ? $custom : self::FIRST_SEGMENTS[$m[1]]) . $rest;
    }

    /**
     * Public request path (with the leading slash, without the language prefix) to the internal one. Returns [internal, canonical]:
     * canonical is the form the URL should have in this language; when it differs from the requested one, Front\Kernel redirects.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, string $language, ?Db $db): array
    {
        $custom = self::newsSlug($db);
        if (!preg_match('#^/(novinky|hledani|news|search' . ($custom !== '' ? '|' . preg_quote($custom, '#') : '') . ')(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $word = $m[1];
        $czech = $custom !== '' && $word === $custom ? 'novinky' : array_search($word, self::FIRST_SEGMENTS, true);
        if ($czech !== false && $word !== $custom && self::isTaken($word, $db)) {
            return [$path, $path]; // the site's own page
        }
        $internal = '/' . ($czech !== false ? $czech : $word) . substr($path, strlen($m[0]));
        if (($czech !== false ? $czech : $word) === 'novinky') {
            $internal = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::SECOND_SEGMENTS, true) ?: $d[1]), $internal);
        }

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $language, $db)];
    }

    /** Does a page of the site itself occupy the English word (e.g. a page "news" from before 1.2)? */
    private static function isTaken(string $word, ?Db $db): bool
    {
        if ($db === null) {
            return false;
        }
        if (self::$taken === null) {
            self::$taken = [];
            try {
                foreach ($db->all("SELECT seo_link FROM {stranky} WHERE seo_link IN ('news', 'search') AND smazano IS NULL") as $r) {
                    self::$taken[$r['seo_link']] = true;
                }
            } catch (\Throwable) {
                // site before installation or without the table – no own page
            }
        }

        return isset(self::$taken[$word]);
    }
}
