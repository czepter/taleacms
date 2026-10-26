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
 * A position of 0 on a total or day row means "unknown" (always so for Bing): it is never shown as a place and never
 * averaged in. The pure parts (opportunities, lowCtr) take plain rows and are tested without a database.
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

    /** The day of the engine's latest snapshot (same rule as SearchData::report), null without one. */
    public static function latestDay(Db $db, string $engine): ?string
    {
        try {
            $day = $db->value('SELECT MAX(day) FROM {search_stats} WHERE engine = ?', [$engine]);
        } catch (\Throwable) {
            return null;
        }

        return $day === null ? null : (string) $day;
    }

    /**
     * @param string $engine google | bing | both
     * @return array{day: string, totals: array{clicks: int, impressions: int, ctr: float, position: ?float}, previous: ?array{clicks: int, impressions: int, ctr: float, position: ?float}, days: list<array{day: string, clicks: int, impressions: int}>}|null
     */
    public static function summary(Db $db, string $engine): ?array
    {
        $parts = [];
        foreach (self::engines($engine) as $e) {
            if (($part = self::engineSummary($db, $e)) !== null) {
                $parts[] = $part;
            }
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
        $previous = array_values(array_filter(array_column($parts, 'previous')));

        return [
            'day' => max(array_column($parts, 'day')),
            'totals' => self::combine(array_column($parts, 'totals')),
            'previous' => $previous === [] ? null : self::combine($previous),
            'days' => array_values($days),
        ];
    }

    /** @return array{day: string, totals: array<string, mixed>, previous: ?array<string, mixed>, days: list<array{day: string, clicks: int, impressions: int}>}|null */
    private static function engineSummary(Db $db, string $engine): ?array
    {
        $day = self::latestDay($db, $engine);
        if ($day === null) {
            return null;
        }
        $total = fn (string $on): ?array => ($r = $db->all('SELECT clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [$engine, 'total', $on])[0] ?? null) === null ? null : self::numbers($r);
        $totals = $total($day);
        if ($totals === null) {
            return null; // no total in the window (Bing without data): nothing is derived
        }
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
        $days = array_map(fn (array $r): array => ['day' => (string) $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions']],
            $db->all('SELECT ' . $db->dialect()->quote('key') . ', clicks, impressions FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY ' . $db->dialect()->quote('key'), [$engine, 'day', $day]));

        return ['day' => $day, 'totals' => $totals, 'previous' => $previous, 'days' => $days];
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
     * The page rows of the latest snapshot by path (no leading slash, '' = home), for pages of this site only. URLs that
     * differ only by query or scheme are summed.
     *
     * @return array<string, array{clicks: int, impressions: int, position: ?float}>
     */
    public static function byPath(Db $db, string $engine, string $origin): array
    {
        $sums = [];
        foreach (self::engines($engine) as $e) {
            $day = self::latestDay($db, $e);
            if ($day === null) {
                continue;
            }
            foreach ($db->all('SELECT ' . $db->dialect()->quote('key') . ', clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [$e, 'page', $day]) as $r) {
                if (($path = self::pathKey((string) $r['key'], $origin)) !== null) {
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
    public static function forPath(Db $db, string $path, string $origin): array
    {
        $want = self::inputKey($path, $origin);
        $day = self::latestDay($db, Google::KEY);
        $queries = [];
        if ($day !== null) {
            $key = $db->dialect()->quote('key');
            foreach ($db->all('SELECT ' . $key . ', page, clicks, impressions, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY clicks DESC, impressions DESC, ' . $key, [Google::KEY, 'page_query', $day]) as $r) {
                if (self::pathKey((string) $r['page'], $origin) === $want) {
                    $queries[] = ['query' => (string) $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => (float) $r['position']];
                }
            }
        }

        return ['totals' => self::byPath($db, Google::KEY, $origin)[$want] ?? null, 'queries' => array_slice($queries, 0, SearchData::TOP)];
    }

    /**
     * Published paths without a row among Google's pages. Null when that cannot be said: no snapshot, or the list was cut
     * at the row limit (a page may be missing only from the cut).
     *
     * @param list<string> $publishedPaths
     * @return list<string>|null
     */
    public static function notInSearch(Db $db, string $origin, array $publishedPaths): ?array
    {
        $day = self::latestDay($db, Google::KEY);
        if ($day === null || (int) $db->value('SELECT COUNT(*) FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ?', [Google::KEY, 'page', $day]) >= SearchData::ROW_LIMIT) {
            return null;
        }
        $seen = self::byPath($db, Google::KEY, $origin);

        return array_values(array_filter($publishedPaths, fn (string $p): bool => !isset($seen[self::inputKey($p, $origin)])));
    }

    /* ---------- helpers ---------- */

    /** @return list<string> */
    private static function engines(string $engine): array
    {
        return $engine === 'both' ? [Google::KEY, Bing::KEY] : [$engine];
    }

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

    /** The path (no leading or trailing slash) of a URL on this site, null for another host. */
    private static function pathKey(string $url, string $origin): ?string
    {
        $host = fn (?string $h): string => preg_replace('/^www\./', '', mb_strtolower((string) $h)) ?? '';
        $parts = parse_url($url);
        if (!is_array($parts) || $host($parts['host'] ?? null) === '' || $host($parts['host']) !== $host(parse_url($origin, PHP_URL_HOST) ?: null)) {
            return null;
        }

        return trim($parts['path'] ?? '', '/');
    }

    /** A caller's path in the form the stored URLs have (cut at 255 characters, so a long address still matches). */
    private static function inputKey(string $path, string $origin): string
    {
        return self::pathKey(mb_substr(rtrim($origin, '/') . '/' . ltrim($path, '/'), 0, self::URL_LENGTH), $origin) ?? '';
    }
}
