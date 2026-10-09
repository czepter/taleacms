<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's system URLs: /news, /news/category/…, /news/tag/… and /search. Code inside the system works with these words;
 * App::url() and Front\Kernel only swap the first segment of the news URLs when the setting news_slug (Settings → General)
 * replaces it in every language (/blog, /blog/category/x); the plain /news then redirects to it.
 */
final class Routes
{
    /** The custom news slug (setting news_slug), null = not read yet. */
    private static ?string $news = null;

    /** The custom first segment of the news URLs ('' = the default /news). */
    public static function newsSlug(?Db $db): string
    {
        if (self::$news === null && $db !== null) {
            try {
                $stored = (string) ($db->value("SELECT value FROM {settings} WHERE name = 'news_slug'") ?? '');
                self::$news = self::systemSlugError($stored) === null ? $stored : ''; // an import or a direct write must not point /mcp or /api at the news
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

    /** Format and system addresses only (no database): the slug is one lowercase segment and not a path of the system. */
    public static function systemSlugError(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }
        $system = array_diff(\Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, ['news']);
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 40 || in_array($slug, $system, true) || isset(Language::AVAILABLE[$slug])) {
            return 'This URL is used by the system, choose another one.';
        }

        return null;
    }

    /** Why a news slug cannot be used (the message for the administrator), null = it is free. */
    public static function slugError(string $slug, Db $db): ?string
    {
        if (($error = self::systemSlugError($slug)) !== null || $slug === '') {
            return $error;
        }
        if ($db->value('SELECT 1 FROM {pages} WHERE slug = ? AND deleted_at IS NULL', [$slug]) !== null
            || $db->value('SELECT 1 FROM {collections} WHERE slug = ?', [$slug]) !== null) {
            return 'A page or a collection already uses this URL.';
        }

        return null;
    }

    /** Internal path (without the leading slash, including a ?query) to the public one: only the custom news slug differs. */
    public static function publicPath(string $path, ?Db $db): string
    {
        $custom = self::newsSlug($db);
        if ($custom === '' || !preg_match('#^news(?=$|[/?.])#', $path)) {
            return $path;
        }

        return $custom . substr($path, 4);
    }

    /**
     * Public request path (with the leading slash, without the language prefix) to the internal one. Returns [internal, canonical]:
     * canonical is the form the URL should have; when it differs from the requested one, Front\Kernel redirects.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, ?Db $db): array
    {
        $custom = self::newsSlug($db);
        if (!preg_match('#^/(news' . ($custom !== '' ? '|' . preg_quote($custom, '#') : '') . ')(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $internal = '/news' . substr($path, strlen($m[0]));

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $db)];
    }

    /** Is it a page-like URL (no file extension other than .html, not api/mcp/oauth/system)? Only those follow the url_slash setting. */
    public static function pageLike(string $path): bool
    {
        $path = (string) preg_replace('#\.html$#', '', $path);

        return $path !== '' && $path !== '/' && !str_contains(basename($path), '.') && !preg_match('#^/(api|mcp|oauth|popup|form|vitals|tasks|_[^/]*)(/|$)#', $path);
    }

    /** Ending of a page URL by the url_slash setting: none | slash | html. */
    public static function suffix(string $mode): string
    {
        return ['slash' => '/', 'html' => '.html'][$mode] ?? '';
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
}
