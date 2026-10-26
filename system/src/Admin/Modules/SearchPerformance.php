<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Connectors\Google;
use Talea\Core\Response;
use Talea\Core\SearchData;
use Talea\Core\SearchInsights;
use Talea\Front\Seo;

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
        // "Both" only when both are connected; a disconnected engine's kept snapshots never count (SearchInsights)
        [$engines, $engine] = SearchInsights::choose($this->db, $this->request->get('engine'));
        $data = ['connected' => $engines, 'engine' => $engine];
        $summary = $engines === [] ? null : SearchInsights::summary($this->db, $engine);
        if ($summary === null) {
            return $this->view('performance', 'Performance', $data + ['summary' => null]);
        }

        $root = SearchInsights::root($this->app);
        $rows = fn (string $kind): array => SearchInsights::rows($this->db, $engine, $kind);
        $queries = $rows('query');

        // published pages by the address the site gives them, keyed like the search rows (SearchInsights::path)
        $published = $this->db->all('SELECT page_id, public_id, title, slug, language, translation_of, seo_score, focus_keywords FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL AND password_hash IS NULL');
        $pages = array_combine(SearchInsights::pageKeys($this->app, $published, $root), $published);
        $focus = [];
        foreach ($pages as $p) {
            $first = mb_strtolower(trim(strtok((string) $p['focus_keywords'], "\n") ?: ''));
            if ($first !== '') {
                $focus[$first] = true;
            }
        }
        $top = SearchInsights::byPath($this->db, $engine, $root);
        uasort($top, fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks'] ?: $b['impressions'] <=> $a['impressions']);

        return $this->view('performance', 'Performance', $data + [
            'summary' => $summary,
            // in "Both" the day is the older engine's, so a stale engine is flagged too
            'stale' => $summary['day'] < date('Y-m-d', (int) strtotime('-' . self::STALE_DAYS . ' days')),
            'opportunities' => SearchInsights::opportunities($queries, $rows('page_query')),
            'lowCtr' => SearchInsights::lowCtr($rows('page')),
            'queries' => array_slice($queries, 0, SearchData::TOP),
            'focus' => $focus,
            'pages' => $pages,
            'top' => array_slice($top, 0, 10, true),
            'sitemaps' => in_array(Google::KEY, $engines, true) ? SearchData::report($this->db, date('Y-m-d', strtotime('-30 days')))[Google::KEY]['sitemaps'] ?? [] : [],
            // the pages the sitemap lists: not the ones kept out of search on purpose
            'notInSearch' => SearchInsights::notInSearch($this->db, $root, array_map('strval', array_keys((new Seo($this->app))->sitemapPages()))),
            'root' => $root,
        ]);
    }
}
