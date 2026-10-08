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
 */
final class Routes
{
    /** internal (Czech) word => English one */
    private const array FIRST_SEGMENTS = ['novinky' => 'news', 'hledani' => 'search'];
    private const array SECOND_SEGMENTS = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null English words that a page of the site itself occupies */
    private static ?array $taken = null;

    /** English words are used for every language except Czech. */
    public static function isEnglish(string $language): bool
    {
        return $language !== 'cs';
    }

    /** Internal path (without the leading slash, including a ?query) to the public one for the given version language. */
    public static function publicPath(string $path, string $language, ?Db $db): string
    {
        if (!self::isEnglish($language) || !preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path, $m) || self::isTaken(self::FIRST_SEGMENTS[$m[1]], $db)) {
            return $path;
        }
        $rest = substr($path, strlen($m[1]));
        if ($m[1] === 'novinky' && preg_match('#^/(kategorie|stitek)(?=/)#', $rest, $d)) {
            $rest = '/' . self::SECOND_SEGMENTS[$d[1]] . substr($rest, strlen($d[0]));
        }

        return self::FIRST_SEGMENTS[$m[1]] . $rest;
    }

    /**
     * Public request path (with the leading slash, without the language prefix) to the internal one. Returns [internal, canonical]:
     * canonical is the form the URL should have in this language; when it differs from the requested one, Front\Kernel redirects.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, string $language, ?Db $db): array
    {
        if (!preg_match('#^/(novinky|hledani|news|search)(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $word = $m[1];
        $czech = array_search($word, self::FIRST_SEGMENTS, true);
        if ($czech !== false && self::isTaken($word, $db)) {
            return [$path, $path]; // the site's own page
        }
        $internal = '/' . ($czech !== false ? $czech : $word) . substr($path, strlen($m[0]));
        if (($czech !== false ? $czech : $word) === 'novinky') {
            $internal = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::SECOND_SEGMENTS, true) ?: $d[1]), $internal);
        }

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $language, $db)];
    }

    /** Is it a page-like URL (no file extension other than .html, not api/mcp/oauth/system)? Only those follow the url_slash setting. */
    public static function pageLike(string $path): bool
    {
        $path = (string) preg_replace('#\.html$#', '', $path);

        return $path !== '' && $path !== '/' && !str_contains(basename($path), '.') && !preg_match('#^/(api|mcp|oauth|popup|formular|vitals|ulohy|_[^/]*)(/|$)#', $path);
    }

    /** Ending of a page URL by the url_slash setting: bez | s | html. */
    public static function suffix(string $mode): string
    {
        return ['s' => '/', 'html' => '.html'][$mode] ?? '';
    }

    /** Redirect target (path + query) when the request URI is not in the preferred form, else null. $internal = path without language prefix. */
    public static function slashRedirect(string $internal, string $requestUri, string $mode): ?string
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        if (!self::pageLike($internal) || $path === '/' || str_ends_with($path, '//')) {
            return null;
        }
        $target = preg_replace('#(\.html|/)$#', '', $path) . self::suffix($mode);
        if ($target === $path) {
            return null;
        }
        $query = (string) parse_url($requestUri, PHP_URL_QUERY);

        return $target . ($query !== '' ? '?' . $query : '');
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
