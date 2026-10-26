<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Connectors\Bing;
use Talea\Connectors\Google;
use Talea\Core\Connectors;
use Talea\Core\Response;
use Talea\Core\SearchData;
use Talea\Core\SearchInsights;

/**
 * Search engines → Performance: the daily Google and Bing snapshot (Core\SearchData) read through Core\SearchInsights –
 * totals against four weeks earlier, the day-by-day chart, queries that almost rank, pages shown but rarely clicked, and
 * which published pages are in the search index. Read-only; Claude reads the same numbers with get_stats.
 */
final class SearchPerformance extends Module
{
    public const string SHARES_PERMISSION_OF = 'stats'; // whoever may open Statistics; the data does not depend on the stats extension
    public const string IDENT = 'search_performance';
    public const string NAME = 'Performance';
    public const string GROUP = 'Search engines';
    public const string ICON = 'stats';

    /** A snapshot older than this many days is flagged. */
    private const int STALE_DAYS = 3;

    protected function actionList(): Response
    {
        $engines = array_values(array_filter([Google::KEY, Bing::KEY], fn (string $k): bool => Connectors::isConnected($this->db, $k)));
        $engine = $this->request->get('engine');
        $engine = in_array($engine, ['google', 'bing'], true) && in_array($engine, $engines, true) ? $engine : 'both';
        $data = ['connected' => $engines, 'engine' => $engine];
        $summary = $engines === [] ? null : SearchInsights::summary($this->db, $engine);
        if ($summary === null) {
            return $this->view('performance', 'Performance', $data + ['summary' => null]);
        }

        $origin = rtrim((string) ($this->app->settings()->get('site_url') ?: $this->request->origin()), '/');
        // queries and their pages come from one engine: Google gives the page of each query, Bing only the query
        $source = $engine === Bing::KEY || !in_array(Google::KEY, $engines, true) ? Bing::KEY : Google::KEY;
        $day = SearchInsights::latestDay($this->db, $source);
        $key = $this->db->dialect()->quote('key');
        $rows = fn (string $kind): array => $day === null ? [] : $this->db->all('SELECT ' . $key . ', page, clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY clicks DESC, impressions DESC, ' . $key, [$source, $kind, $day]);
        $queries = $rows('query');

        // published pages by the address the sitemap gives them
        $home = $this->app->settings()->int('home_page');
        $pages = [];
        foreach ($this->db->all('SELECT page_id, public_id, title, slug, language, translation_of, seo_score, focus_keywords FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL AND password_hash IS NULL') as $p) {
            // the home page is '' ; a language version of it lives at /<lang>/ ; other pages at /<lang>/<slug>
            $isHome = (int) $p['page_id'] === $home || ($p['language'] !== '' && (int) $p['translation_of'] === $home);
            $pages[($p['language'] !== '' ? $p['language'] . ($isHome ? '' : '/') : '') . ($isHome ? '' : $p['slug'])] = $p;
        }
        $focus = [];
        foreach ($pages as $p) {
            $first = mb_strtolower(trim(strtok((string) $p['focus_keywords'], "\n") ?: ''));
            if ($first !== '') {
                $focus[$first] = true;
            }
        }
        $top = SearchInsights::byPath($this->db, $engine, $origin);
        uasort($top, fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks'] ?: $b['impressions'] <=> $a['impressions']);

        return $this->view('performance', 'Performance', $data + [
            'summary' => $summary,
            'stale' => strtotime($summary['day']) < strtotime('-' . self::STALE_DAYS . ' days'),
            'opportunities' => SearchInsights::opportunities($queries, $rows('page_query')),
            'lowCtr' => SearchInsights::lowCtr($rows('page')),
            'queries' => array_slice($queries, 0, SearchData::TOP),
            'focus' => $focus,
            'pages' => $pages,
            'top' => array_slice($top, 0, 10, true),
            'sitemaps' => SearchData::report($this->db, date('Y-m-d', strtotime('-30 days')))[Google::KEY]['sitemaps'] ?? [],
            'notInSearch' => in_array(Google::KEY, $engines, true) ? SearchInsights::notInSearch($this->db, $origin, array_keys($pages)) : null,
            'origin' => $origin,
        ]);
    }
}
