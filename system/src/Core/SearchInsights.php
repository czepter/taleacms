<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Connectors\Bing;
use Talea\Connectors\Google;

/**
 * Reads the stored search snapshots (Core\SearchData) for the Search engines screens, the page check and the audit:
 * totals against four weeks earlier, queries that almost rank, pages that are shown but rarely clicked, the numbers of one
 * page, and published pages that do not appear in search yet.
 *
 * Only engines that are connected now are read; a disconnected engine's kept snapshots drop out. "Both" reads every
 * engine's snapshot of the same day (snapshots()), so the totals, the chart and the lists cover the same days.
 * A position of 0 on a total or day row means "unknown" (always so for Bing): it is never shown as a place and never
 * averaged in. The pure parts (opportunities, lowCtr, path) take plain values and are tested without a database.
 */
final class SearchInsights
{
    public const int OPPORTUNITY_MIN_IMPRESSIONS = 50;
    public const float OPPORTUNITY_FROM = 8.0;
    public const float OPPORTUNITY_TO = 20.0;
    public const int LOW_CTR_MIN_IMPRESSIONS = 200;
    /** Position ceiling → the CTR in per cent a page at that place usually gets. */
    public const array EXPECTED_CTR = [1 => 25.0, 2 => 15.0, 3 => 10.0, 5 => 6.0, 10 => 3.0, 100 => 1.0];
    private const int LIST = 10;
    private const int URL_LENGTH = 255;

    /** The day of the engine's latest snapshot (same rule as SearchData::report), on or before $upTo when given; null without one. */
    public static function latestDay(Db $db, string $engine, ?string $upTo = null): ?string
    {
        try {
            $day = $upTo === null ? $db->value('SELECT MAX(day) FROM {search_stats} WHERE engine = ?', [$engine])
                : $db->value('SELECT MAX(day) FROM {search_stats} WHERE engine = ? AND day <= ?', [$engine, $upTo]);
        } catch (\Throwable) {
            return null;
        }

        return $day === null ? null : (string) $day;
    }

    /**
     * The snapshot day each connected engine is read from: its latest one; with more than one engine the latest on or
     * before the earliest of theirs, so all cover the same days (an engine without one by then is left out).
     *
     * @param string $engine google | bing | both
     * @return array<string, string> engine => day
     */
    public static function snapshots(Db $db, string $engine): array
    {
        $days = [];
        foreach ($engine === 'both' ? SearchData::ENGINES : [$engine] as $e) {
            if (Connectors::isConnected($db, $e) && ($day = self::latestDay($db, $e)) !== null) {
                $days[$e] = $day;
            }
        }
        if (count($days) > 1) {
            $common = min($days);
            foreach ($days as $e => $day) {
                $days[$e] = $day === $common ? $day : self::latestDay($db, $e, $common);
            }
        }

        return array_filter($days, fn (?string $d): bool => $d !== null);
    }

    /**
     * The totals of the snapshots(), four weeks earlier and by day. `day` is the (earliest) snapshot day, `to` the last day
     * the numbers cover; totals are null when a snapshot has neither a total nor days.
     *
     * @param string $engine google | bing | both
     * @return array{day: string, to: string, totals: ?array{clicks: int, impressions: int, ctr: float, position: ?float}, previous: ?array{clicks: int, impressions: int, ctr: float, position: ?float}, days: list<array{day: string, clicks: int, impressions: int}>}|null
     */
    public static function summary(Db $db, string $engine): ?array
    {
        $parts = [];
        foreach (self::snapshots($db, $engine) as $e => $day) {
            $parts[] = self::engineSummary($db, $e, $day);
        }
        if ($parts === []) {
            return null;
        }
        $days = [];
        foreach ($parts as $p) {
            foreach ($p['days'] as $d) {
                $days[$d['day']] ??= ['day' => $d['day'], 'clicks' => 0, 'impressions' => 0];
                $days[$d['day']]['clicks'] += $d['clicks'];
                $days[$d['day']]['impressions'] += $d['impressions'];
            }
        }
        ksort($days);
        $totals = array_values(array_filter(array_column($parts, 'totals')));
        // the same engines as the totals, or no comparison at all
        $previous = array_values(array_filter(array_column($parts, 'previous')));
        if (count($previous) !== count($parts) || count($totals) !== count($parts)) {
            $previous = [];
        }
        $day = min(array_column($parts, 'day'));

        return [
            'day' => $day,
            'to' => SearchData::window($day)[1],
            'totals' => $totals === [] ? null : self::combine($totals),
            'previous' => $previous === [] ? null : self::combine($previous),
            'days' => array_values($days),
        ];
    }

    /** @return array{day: string, totals: ?array<string, mixed>, previous: ?array<string, mixed>, days: list<array{day: string, clicks: int, impressions: int}>} */
    private static function engineSummary(Db $db, string $engine, string $day): array
    {
        $total = fn (string $on): ?array => ($r = $db->all('SELECT clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [$engine, 'total', $on])[0] ?? null) === null ? null : self::numbers($r);
        $dayRows = $db->all('SELECT ' . $db->dialect()->quote('key') . ', clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY ' . $db->dialect()->quote('key'), [$engine, 'day', $day]);
        // the total is a bonus call: without it the days add up to the same numbers; without either there is nothing to show
        $totals = $total($day) ?? ($dayRows === [] ? null : self::combine(array_map(self::numbers(...), $dayRows)));
        $previous = null;
        $target = strtotime($day . ' -28 days');
        $best = null;
        foreach ($db->all('SELECT DISTINCT day FROM {search_stats} WHERE engine = ? AND kind = ? AND day >= ? AND day <= ?', [$engine, 'total', date('Y-m-d', $target - 3 * 86400), date('Y-m-d', $target + 3 * 86400)]) as $r) {
            $distance = abs(strtotime((string) $r['day']) - $target);
            if ($best === null || $distance < $best[0]) {
                $best = [$distance, (string) $r['day']];
            }
        }
        if ($best !== null) {
            $previous = $total($best[1]);
        }
        $days = array_map(fn (array $r): array => ['day' => (string) $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions']], $dayRows);

        return ['day' => $day, 'totals' => $totals, 'previous' => $previous, 'days' => $days];
    }

    /**
     * The connected search services and the engine to read: the one asked for when it is connected, "both" when both are
     * and nothing else is asked, else the only one ('both' with none, which reads nothing).
     *
     * @return array{0: list<string>, 1: string} connected engines, the engine
     */
    public static function choose(Db $db, string $asked): array
    {
        $engines = array_values(array_filter(SearchData::ENGINES, fn (string $e): bool => Connectors::isConnected($db, $e)));

        return [$engines, count($engines) === 1 ? $engines[0] : (in_array($asked, $engines, true) ? $asked : 'both')];
    }

    /**
     * The rows of one kind (query, page, page_query) for the lists, most clicked first: queries and their pages come from one
     * engine – Google when it is asked for and connected (it gives the page of each query), else Bing – from the snapshot of
     * the same day as the totals.
     *
     * @param string $engine google | bing | both
     * @return list<array<string, mixed>> key, page, clicks, impressions, ctr, position
     */
    public static function rows(Db $db, string $engine, string $kind): array
    {
        $source = $engine !== Bing::KEY && Connectors::isConnected($db, Google::KEY) ? Google::KEY : Bing::KEY;
        $day = self::snapshots($db, $engine)[$source] ?? null;
        $key = $db->dialect()->quote('key');

        return $day === null ? [] : $db->all('SELECT ' . $key . ', page, clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY clicks DESC, impressions DESC, ' . $key, [$source, $kind, $day]);
    }

    /**
     * Queries that almost rank: a place between 8 and 20 with enough impressions, the most seen first, with the page that
     * gets most of that query's impressions.
     *
     * @param list<array<string, mixed>> $queryRows rows of kind query (key, impressions, position)
     * @param list<array<string, mixed>> $pageQueryRows rows of kind page_query (key = query, page)
     * @return list<array{query: string, position: float, impressions: int, page: ?string}>
     */
    public static function opportunities(array $queryRows, array $pageQueryRows): array
    {
        $pages = [];
        foreach ($pageQueryRows as $r) {
            $q = (string) $r['key'];
            if (!isset($pages[$q]) || (int) $r['impressions'] > $pages[$q][0]) {
                $pages[$q] = [(int) $r['impressions'], (string) ($r['page'] ?? '')];
            }
        }
        $out = [];
        foreach ($queryRows as $r) {
            $position = (float) $r['position'];
            if ((int) $r['impressions'] >= self::OPPORTUNITY_MIN_IMPRESSIONS && $position >= self::OPPORTUNITY_FROM && $position <= self::OPPORTUNITY_TO) {
                $page = $pages[(string) $r['key']][1] ?? '';
                $out[] = ['query' => (string) $r['key'], 'position' => $position, 'impressions' => (int) $r['impressions'], 'page' => $page === '' ? null : $page];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return array_slice($out, 0, self::LIST);
    }

    /**
     * Pages that are shown a lot but clicked less than half as often as their place usually earns.
     *
     * @param list<array<string, mixed>> $pageRows rows of kind page (key, impressions, ctr in per cent, position)
     * @return list<array{page: string, impressions: int, ctr: float, position: float}>
     */
    public static function lowCtr(array $pageRows): array
    {
        $out = [];
        foreach ($pageRows as $r) {
            $position = (float) $r['position'];
            if ((int) $r['impressions'] < self::LOW_CTR_MIN_IMPRESSIONS || $position <= 0) {
                continue;
            }
            foreach (self::EXPECTED_CTR as $ceiling => $expected) {
                if ($position <= $ceiling) {
                    if ((float) $r['ctr'] < 0.5 * $expected) {
                        $out[] = ['page' => (string) $r['key'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => $position];
                    }
                    break;
                }
            }
        }
        usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return array_slice($out, 0, self::LIST);
    }

    /**
     * The page rows of the snapshots() by path (path(): no leading slash, '' = home), for pages of this site only. URLs
     * that differ only by query or scheme are summed.
     *
     * @param string $root the site's address with the installation's folder (https://example.com/magazin)
     * @return array<string, array{clicks: int, impressions: int, position: ?float}>
     */
    public static function byPath(Db $db, string $engine, string $root): array
    {
        $sums = [];
        foreach (self::snapshots($db, $engine) as $e => $day) {
            foreach ($db->all('SELECT ' . $db->dialect()->quote('key') . ', clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [$e, 'page', $day]) as $r) {
                if (($path = self::path((string) $r['key'], $root)) !== null) {
                    $sums[$path][] = self::numbers($r);
                }
            }
        }
        $out = [];
        foreach ($sums as $path => $list) {
            $c = self::combine($list);
            $out[$path] = ['clicks' => $c['clicks'], 'impressions' => $c['impressions'], 'position' => $c['position']];
        }

        return $out;
    }

    /**
     * What Google knows about one page: its numbers and the queries that led to it.
     *
     * @return array{totals: ?array{clicks: int, impressions: int, position: ?float}, queries: list<array{query: string, clicks: int, impressions: int, position: float}>}
     */
    public static function forPath(Db $db, string $path, string $root): array
    {
        $want = self::key($path, $root);
        $day = self::snapshots($db, Google::KEY)[Google::KEY] ?? null;
        $queries = [];
        if ($day !== null) {
            $key = $db->dialect()->quote('key');
            foreach ($db->all('SELECT ' . $key . ', page, clicks, impressions, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY clicks DESC, impressions DESC, ' . $key, [Google::KEY, 'page_query', $day]) as $r) {
                if (self::path((string) $r['page'], $root) === $want) {
                    $queries[] = ['query' => (string) $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => (float) $r['position']];
                }
            }
        }

        return ['totals' => self::byPath($db, Google::KEY, $root)[$want] ?? null, 'queries' => array_slice($queries, 0, SearchData::TOP)];
    }

    /**
     * The addresses (under the site root, as Front\Seo::sitemapPages() gives them) without a row among Google's pages.
     * Null when that cannot be said: Google not connected, no snapshot, or the list was cut at the row limit (a page may
     * be missing only from the cut).
     *
     * @param list<string> $addresses
     * @return list<string>|null
     */
    public static function notInSearch(Db $db, string $root, array $addresses): ?array
    {
        $day = self::snapshots($db, Google::KEY)[Google::KEY] ?? null;
        if ($day === null || (int) $db->value('SELECT COUNT(*) FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [Google::KEY, 'page', $day]) >= SearchData::ROW_LIMIT) {
            return null;
        }
        $seen = self::byPath($db, Google::KEY, $root);

        return array_values(array_filter($addresses, fn (string $a): bool => !isset($seen[self::key($a, $root)])));
    }

    /** The site's address with the installation's folder (https://example.com/magazin): what search URLs are matched against. */
    public static function root(App $app): string
    {
        return rtrim((string) ($app->settings()->get('site_url') ?: $app->request->origin()), '/') . $app->request->basePath();
    }

    /**
     * The key of each page row's address as path() keys the search URLs; the home page and its language versions live at
     * the root of their language.
     *
     * @param list<array<string, mixed>> $pages rows with page_id, slug, language and translation_of
     * @return array<int, string> page id => key
     */
    public static function pageKeys(App $app, array $pages, string $root): array
    {
        $seo = new \Talea\Front\Seo($app);
        $home = $app->settings()->int('home_page');
        $keys = [];
        foreach ($pages as $p) {
            $isHome = (int) $p['page_id'] === $home || ($p['language'] !== '' && (int) $p['translation_of'] === $home);
            $keys[(int) $p['page_id']] = self::key($seo->address($isHome ? '' : (string) $p['slug'], (string) $p['language']), $root);
        }

        return $keys;
    }

    /**
     * A total's change against four weeks earlier for a tile: a percentage; for the position, places (lower is better).
     * '' when there is nothing to compare.
     *
     * @param array{clicks: int, impressions: int, ctr: float, position: ?float} $totals
     * @param array{clicks: int, impressions: int, ctr: float, position: ?float}|null $previous
     */
    public static function change(array $totals, ?array $previous, string $field): string
    {
        if ($previous === null) {
            return '';
        }
        if ($field === 'position') {
            if ($totals['position'] === null || $previous['position'] === null || abs($previous['position'] - $totals['position']) < 0.05) {
                return '';
            }
            $places = $previous['position'] - $totals['position'];

            return $places > 0 ? t('%s higher', format_count($places, 1)) : t('%s lower', format_count(-$places, 1));
        }
        if ((float) $previous[$field] <= 0) {
            return '';
        }
        $pct = 100 * ($totals[$field] - $previous[$field]) / $previous[$field];

        return ($pct >= 0 ? '+' : '–') . format_count(abs($pct), 0) . ' %';
    }

    /**
     * The path of a URL under the site root, as byPath() keys it: decoded, without the root's folder and without leading
     * or trailing slashes ('' = the home page). Null for another host or a URL outside the root's folder. A "www." in
     * front of the host does not count.
     *
     * @param string $root the site's address with the installation's folder (https://example.com/magazin)
     */
    public static function path(string $url, string $root): ?string
    {
        $host = fn (?string $h): string => preg_replace('/^www\./', '', mb_strtolower((string) $h)) ?? '';
        $parts = parse_url($url);
        if (!is_array($parts) || $host($parts['host'] ?? null) === '' || $host($parts['host']) !== $host(parse_url($root, PHP_URL_HOST) ?: null)) {
            return null;
        }
        $path = '/' . trim(rawurldecode($parts['path'] ?? ''), '/'); // the path keeps its case; stored URLs are percent-encoded
        $folder = rtrim(rawurldecode((string) parse_url($root, PHP_URL_PATH)), '/');
        if ($folder !== '' && $path !== $folder && !str_starts_with($path, $folder . '/')) {
            return null;
        }

        return trim(substr($path, strlen($folder)), '/');
    }

    /**
     * An address under the site root ('services', 'de/kontakt.html', '' = home) as path() keys the stored URL for it –
     * encoded and cut at 255 characters like the stored URLs, so a long address still matches.
     */
    public static function key(string $address, string $root): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', trim(rawurldecode($address), '/'))));

        return self::path(mb_substr(rtrim($root, '/') . '/' . $encoded, 0, self::URL_LENGTH), $root) ?? '';
    }

    /* ---------- helpers ---------- */

    /** @param array<string, mixed> $r @return array{clicks: int, impressions: int, ctr: float, position: ?float} */
    private static function numbers(array $r): array
    {
        $position = (float) $r['position'];

        return ['clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => $position > 0 ? $position : null];
    }

    /**
     * Sums clicks and impressions; the position is the mean of those that have one, weighted by impressions.
     *
     * @param list<array{clicks: int, impressions: int, ctr: float, position: ?float}> $list
     * @return array{clicks: int, impressions: int, ctr: float, position: ?float}
     */
    private static function combine(array $list): array
    {
        $clicks = array_sum(array_column($list, 'clicks'));
        $impressions = array_sum(array_column($list, 'impressions'));
        $weight = 0;
        $placed = 0.0;
        foreach ($list as $n) {
            if ($n['position'] !== null) {
                $w = max(1, $n['impressions']);
                $weight += $w;
                $placed += $n['position'] * $w;
            }
        }

        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? round(100 * $clicks / $impressions, 2) : 0.0, 'position' => $weight > 0 ? round($placed / $weight, 1) : null];
    }
}
