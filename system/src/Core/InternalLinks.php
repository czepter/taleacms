<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;

/**
 * Internal links (2.14): orphan pages and where a link to them would fit.
 *
 * An orphan is a published page (other than the home page), news item or item page that no published build, menu or text
 * links to: visitors and search engines reach it only by its address. A page in the navigation (v_menu) is linked; news
 * items are linked when the news listing is reachable (the home page, a News element, a menu item); items of a collection
 * are linked when a published build lists the collection. Everything is computed from stored content – on demand, cached
 * for an hour in storage/cache (any change in the administration clears the cache), never per visit.
 *
 * suggest_internal_links adds candidate source pages to each orphan: published pages whose title or text share words of
 * the orphan's title, so Claude can add the link as a draft with the usual build tools. Nothing is edited by itself.
 */
final class InternalLinks
{
    private const string CACHE = TALEA_ROOT . '/storage/cache/pages/orphan-links.html';

    private const int CACHE_SECONDS = 3600;

    /** Words shorter than this say nothing about a page. */
    private const int MIN_WORD = 4;

    /**
     * @return list<array{kind: string, id: int, title: string, path: string, edit: string, target: array<string, int|string>}>
     */
    public static function orphans(App $app): array
    {
        if (is_file(self::CACHE) && filemtime(self::CACHE) >= time() - self::CACHE_SECONDS && ($json = file_get_contents(self::CACHE)) !== false && is_array($cached = json_decode($json, true))) {
            return $cached;
        }
        $orphans = self::compute($app);
        if (!is_dir(dirname(self::CACHE))) {
            @mkdir(dirname(self::CACHE), 0775, true);
        }
        @file_put_contents(self::CACHE, json_encode($orphans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $orphans;
    }

    public static function forget(): void
    {
        @unlink(self::CACHE);
    }

    /** @return list<array{kind: string, id: int, title: string, path: string, edit: string, target: array<string, int|string>}> */
    public static function compute(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $home = (int) $s->get('home_page');
        $additional = Language::additional($s);
        $prefix = fn (string $language): string => in_array($language, $additional, true) ? $language . '/' : '';
        $linked = [];
        $listedCollections = [];
        $newsListed = $home === 0;
        $builds = '';
        $take = function (string $content) use (&$linked, &$builds, $app): void {
            $builds .= $content;
            foreach (self::paths($app, $content) as $path) {
                $linked[$path] = true;
            }
        };
        foreach ($db->all('SELECT slug, language, in_menu, text, build FROM {pages} WHERE visible = 1 AND deleted_at IS NULL') as $p) {
            $take((string) ($p['build'] ?? $p['text']));
            if ($p['in_menu']) {
                $linked[$prefix((string) $p['language']) . $p['slug']] = true; // the automatic navigation and the footer list it
            }
        }
        foreach ($db->all('SELECT build FROM {site_parts} WHERE build IS NOT NULL UNION ALL SELECT build FROM {components} WHERE build IS NOT NULL UNION ALL SELECT build FROM {popups} WHERE build IS NOT NULL AND active = 1 UNION ALL SELECT build FROM {collections} WHERE build IS NOT NULL AND detail = 1') as $c) {
            $take((string) $c['build']);
        }
        foreach ($db->all('SELECT intro, text FROM {news} WHERE visible = 1 AND published_at <= NOW() AND deleted_at IS NULL LIMIT 2000') as $c) {
            $take($c['intro'] . ' ' . $c['text']);
        }
        foreach ($db->all('SELECT data FROM {collection_items} WHERE visible = 1 AND deleted_at IS NULL LIMIT 5000') as $p) {
            $take((string) $p['data']);
        }
        $pageIds = [];
        foreach ($db->all('SELECT items FROM {menus}') as $m) {
            foreach (Menu::flatten(json_decode((string) $m['items'], true) ?: []) as $i) {
                if (($i['type'] ?? '') === 'page') {
                    $pageIds[(int) ($i['page_id'] ?? 0)] = true;
                } elseif (($i['type'] ?? '') === 'link') {
                    $take('"url":' . json_encode((string) ($i['url'] ?? '')));
                } elseif (($i['type'] ?? '') === 'news') {
                    $newsListed = true;
                }
            }
        }
        if ($pageIds !== []) {
            foreach ($db->all('SELECT slug, language FROM {pages} WHERE page_id IN (' . implode(',', array_map(intval(...), array_keys($pageIds))) . ')') as $p) {
                $linked[$prefix((string) $p['language']) . $p['slug']] = true;
            }
        }
        // list elements reach every item of their collections and every news item
        if (preg_match_all('#"type":"collection_list"[^}]*?"collection":"([^"]*)"#', $builds, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/[\s,]+/', $list) ?: [] as $slug) {
                    if ($slug !== '') {
                        $listedCollections[$slug] = true;
                    }
                }
            }
        }
        $newsListed = $newsListed || str_contains($builds, '"type":"news_list"') || isset($linked['news']) || isset($linked[Routes::publicPath('news', $db)]);

        $out = [];
        foreach ($db->all('SELECT page_id, title, slug, language, build IS NOT NULL AS build FROM {pages} WHERE visible = 1 AND deleted_at IS NULL AND page_id <> ? ORDER BY sort_order, title', [$home]) as $p) {
            $path = $prefix((string) $p['language']) . $p['slug'];
            if (!isset($linked[$path])) {
                $out[] = ['kind' => 'page', 'id' => (int) $p['page_id'], 'title' => (string) $p['title'], 'path' => $path, 'edit' => 'admin.php?module=pages&action=' . ($p['build'] ? 'builder' : 'edit') . '&id=' . $db->publicId('pages', (int) $p['page_id']), 'target' => ['page' => (int) $p['page_id']]];
            }
        }
        if (!$newsListed && Extensions::isEnabled($s, 'news')) {
            $base = strlen($app->request->basePath());
            foreach ($db->all('SELECT news_id, title, slug, language FROM {news} WHERE visible = 1 AND published_at <= NOW() AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000') as $c) {
                $path = ltrim(substr($app->newsItemUrl((string) $c['slug'], (string) $c['language']), $base), '/');
                if (!isset($linked[$path])) {
                    $out[] = ['kind' => 'news', 'id' => (int) $c['news_id'], 'title' => (string) $c['title'], 'path' => $path, 'edit' => 'admin.php?module=news&action=edit&id=' . $db->publicId('news', (int) $c['news_id']), 'target' => ['news' => (int) $c['news_id']]];
                }
            }
        }
        foreach ($db->all('SELECT p.item_id, p.collection_id, p.name, p.slug, p.language, k.slug AS collection FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = 1 AND p.visible = 1 AND p.deleted_at IS NULL ORDER BY p.collection_id, p.sort_order LIMIT 3000') as $p) {
            $path = $prefix((string) $p['language']) . $p['collection'] . '/' . $p['slug'];
            if (!isset($listedCollections[(string) $p['collection']]) && !isset($linked[$path])) {
                $out[] = ['kind' => 'item', 'id' => (int) $p['item_id'], 'title' => (string) $p['name'], 'path' => $path, 'edit' => 'admin.php?module=collections&action=item&id=' . $db->publicId('collections', (int) $p['collection_id']) . '&item=' . $db->publicId('collection_items', (int) $p['item_id']), 'target' => ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']]];
            }
        }

        return $out;
    }

    /**
     * Orphans with the published pages a link to them would fit on: pages whose title or text share words of the
     * orphan's title, best first.
     *
     * @return list<array{kind: string, id: int, title: string, path: string, target: array<string, int|string>, title_words: list<string>, candidates: list<array{id: int, title: string, path: string, shared_words: list<string>, target: array{page: int}}>}>
     */
    public static function suggestions(App $app, int $limit = 20): array
    {
        $orphans = array_slice(self::orphans($app), 0, max(1, min(100, $limit)));
        if ($orphans === []) {
            return [];
        }
        $home = (int) $app->settings()->get('home_page');
        $additional = Language::additional($app->settings());
        $sources = [];
        foreach ($app->db()->all('SELECT page_id, title, slug, language, text, build FROM {pages} WHERE visible = 1 AND deleted_at IS NULL LIMIT 1000') as $p) {
            $build = Build::fromJson(is_string($p['build']) ? $p['build'] : null);
            $text = $p['title'] . ' ' . strip_tags($build !== null ? Build::asText($build) : (string) $p['text']);
            $sources[] = ['id' => (int) $p['page_id'], 'title' => (string) $p['title'], 'path' => (int) $p['page_id'] === $home ? '' : (in_array((string) $p['language'], $additional, true) ? $p['language'] . '/' : '') . $p['slug'],
                'words' => array_fill_keys(self::words($text), true)];
        }
        $out = [];
        foreach ($orphans as $o) {
            $words = self::words($o['title']);
            $candidates = [];
            foreach ($sources as $src) {
                if ($o['kind'] === 'page' && $src['id'] === $o['id']) {
                    continue;
                }
                $shared = array_values(array_filter($words, fn (string $w): bool => isset($src['words'][$w])));
                if ($shared !== []) {
                    $candidates[] = ['id' => $src['id'], 'title' => $src['title'], 'path' => $src['path'], 'shared_words' => $shared, 'target' => ['page' => $src['id']]];
                }
            }
            usort($candidates, fn (array $a, array $b): int => count($b['shared_words']) <=> count($a['shared_words']) ?: $a['id'] <=> $b['id']);
            $out[] = ['kind' => $o['kind'], 'id' => $o['id'], 'title' => $o['title'], 'path' => $o['path'], 'target' => $o['target'], 'title_words' => $words, 'candidates' => array_slice($candidates, 0, 5)];
        }

        return $out;
    }

    /** @return list<string> distinct lower-case words without diacritics, at least MIN_WORD letters */
    public static function words(string $text): array
    {
        preg_match_all('/\p{L}{' . self::MIN_WORD . ',}/u', mb_strtolower(remove_diacritics(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Internal paths linked from a build (JSON), HTML or item data: without the leading slash, as the site stores addresses.
     *
     * @return list<string>
     */
    private static function paths(App $app, string $content): array
    {
        preg_match_all('#"(?:link|url|href)":"((?:[^"\\\\]|\\\\.)*)"|href=\\\\?"([^"\\\\]*)\\\\?"#', $content, $m, PREG_SET_ORDER);
        $origin = strtolower($app->request->origin() . $app->request->basePath());
        $out = [];
        foreach ($m as $match) {
            $link = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($link === '' || str_contains($link, '{{') || preg_match('#^(\#|mailto:|tel:|javascript:)#i', $link)) {
                continue;
            }
            if (preg_match('#^https?://#i', $link)) {
                if (!str_starts_with(strtolower($link), $origin . '/')) {
                    continue;
                }
                $link = substr($link, strlen($origin));
            } elseif (str_starts_with($link, '/')) {
                $link = substr($link, strlen($app->request->basePath()));
            } else {
                continue;
            }
            $out[] = trim(rawurldecode((string) parse_url($link, PHP_URL_PATH)), '/');
        }

        return $out;
    }
}
