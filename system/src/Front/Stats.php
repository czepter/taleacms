<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Conversions;
use Talea\Core\Response;

/**
 * Own traffic measurement without cookies.
 * Visitor = hash (IP + browser + salt valid for one day); the IP itself is not stored anywhere
 * and hashes older than two days are deleted, so readers cannot be tracked over time.
 */
final class Stats
{
    private const string BOTS = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|java\/|http|scan|check|feed|lighthouse|headless/i';

    /** phone | tablet | computer, by the browser's own description (nothing is stored about the device itself) */
    public static function device(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|Kindle|Silk|(Android(?!.*Mobile))/i', $userAgent) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $userAgent) => 'phone',
            default => 'computer',
        };
    }

    /**
     * The built-in statistics are on: the Statistics feature is the only switch (3.2 – the old "stats" setting is no longer
     * read; migration 0073 switched the feature off where that setting was off). Real-user speed follows the same switch.
     */
    public static function isOn(App $app): bool
    {
        return self::enabled($app->settings());
    }

    /** The same, from the settings alone (the privacy text, the fleet heartbeat, MCP). */
    public static function enabled(\Talea\Core\Settings $settings): bool
    {
        return \Talea\Core\Extensions::isEnabled($settings, 'stats');
    }

    /** A crawler, a monitoring tool or a test browser by its own description – never counted. */
    public static function isBot(string $userAgent): bool
    {
        return preg_match(self::BOTS, $userAgent) === 1;
    }

    /** The most page views one visitor can add to the statistics in a day; the rest is a machine or a very odd reader. */
    public const int DAILY_CAP = 300;

    /** This many views inside {@see MACHINE_SECONDS} seconds are not a person: the visitor counts no more that day. */
    public const int MACHINE_VIEWS = 10;

    public const int MACHINE_SECONDS = 10;

    /**
     * A request the browser makes in advance, or for a preview, is not a visitor looking at the page: Sec-Purpose (prefetch,
     * prerender), the older Purpose, and an X-Moz / X-Purpose preview. Also not counted: a request the page itself did not
     * make (Sec-Fetch-Site from another site, Sec-Fetch-Dest other than a script beacon's "empty").
     *
     * @param array<string, mixed> $server
     */
    public static function isAdvance(array $server): bool
    {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
            if (preg_match('/prefetch|prerender|preview/i', (string) ($server[$header] ?? '')) === 1) {
                return true;
            }
        }
        $site = (string) ($server['HTTP_SEC_FETCH_SITE'] ?? '');
        $destination = (string) ($server['HTTP_SEC_FETCH_DEST'] ?? '');

        return ($site !== '' && !in_array($site, ['same-origin', 'same-site'], true)) || ($destination !== '' && $destination !== 'empty');
    }

    /** What the browser has to send back with the beacon so a made-up address is not counted: the page path and the news item, signed. */
    public static function viewSignature(App $app, string $path, string $news): string
    {
        return substr(hash_hmac('sha256', 'view|' . $path . '|' . $news, (new Antispam($app->db(), $app->settings()))->key()), 0, 32);
    }

    /**
     * The attributes image/web.js reads on its own script tag (Front\Seo::head) to confirm the view: the endpoint, the page path
     * as the statistics store it, the news item (public id) and the signature. They are part of the cached page, so a cache hit
     * carries what the beacon needs; the server request itself counts nothing.
     */
    public static function viewAttributes(App $app, string $news): string
    {
        $path = mb_substr((string) parse_url($app->url(ltrim($app->request->path(), '/')), PHP_URL_PATH), 0, 255);

        return ' data-view="' . e($app->url('_view')) . '" data-view-path="' . e($path) . '" data-view-news="' . e($news) . '" data-view-sig="' . self::viewSignature($app, $path, $news) . '"';
    }

    /**
     * The day counting changed (setting stats_counting_since, set by migration 20261115100000): since then a visit counts when the
     * visitor's browser confirms the page was shown. Null when the days before it hold no figures (a new site), so only sites
     * with older figures get the note.
     */
    public static function countingChange(\Talea\Core\Db $db, \Talea\Core\Settings $settings): ?string
    {
        $day = $settings->get('stats_counting_since');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || (int) $db->value('SELECT COUNT(*) FROM {stats_days} WHERE day < ?', [$day]) === 0) {
            return null;
        }

        return $day;
    }

    /** The note that marks the break in the figures (Statistics, dashboard, monthly report): the day counting changed. */
    public static function countingNote(string $day): string
    {
        return t('Visits are counted when the visitor’s browser confirms the page was shown. Figures before %s counted every request and are not comparable with the days after it.', $day);
    }

    /** How visits are counted, in words (the Statistics screen, get_stats). */
    public static function method(): string
    {
        return 'A visit is counted when the visitor\'s browser confirms that the page was shown: image/web.js sends a small beacon (no cookies) '
            . 'after the page is visible, so a page that was only fetched – prefetched, prerendered, a link preview, a crawler or an uptime check – '
            . 'and a visitor without JavaScript are not counted, nor are signed-in people and previews. A visit is one visitor (a hash of address and browser '
            . 'that changes every day) per day; page views are the confirmed page loads. One visitor adds at most ' . self::DAILY_CAP . ' page views a day, and '
            . self::MACHINE_VIEWS . ' views within ' . self::MACHINE_SECONDS . ' seconds are treated as a machine and not counted further that day.';
    }

    /** POST /_view: the browser confirms that a page was shown (image/web.js). Always 204; anything unwanted is simply not counted. */
    public static function view(App $app): Response
    {
        $done = new Response('', 204, ['Cache-Control' => 'no-store']);
        $server = $_SERVER;
        $request = $app->request;
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        // signed-in users (the site's own people checking their pages) are not visitors
        if (!self::isOn($app) || $ua === '' || self::isBot($ua) || self::isAdvance($server) || $app->auth()->user() !== null) {
            return $done;
        }
        $path = Conversions::path($request->post('path'));
        $news = $request->post('news');
        if ($path === null || !hash_equals(self::viewSignature($app, $path, $news), $request->post('sig'))) {
            return $done;
        }
        $db = $app->db();
        $today = date('Y-m-d');
        $salt = (new Antispam($db, $app->settings()))->key() . $today;
        $hash = substr(hash('sha256', $salt . '|' . $request->ip() . '|' . $ua), 0, 32);

        $new = $db->insertIgnore('stats_visitors', ['day' => $today, 'visitor_hash' => $hash]);
        $db->run('UPDATE {stats_visitors} SET views = views + 1 WHERE day = ? AND visitor_hash = ?', [$today, $hash]);
        $visitor = $db->one('SELECT views, machine FROM {stats_visitors} WHERE day = ? AND visitor_hash = ?', [$today, $hash]);
        if ($visitor === null || (int) $visitor['machine'] === 1 || (int) $visitor['views'] > self::DAILY_CAP) {
            return $done;
        }
        if (Antispam::tally($hash, 'view', self::MACHINE_SECONDS) >= self::MACHINE_VIEWS) {
            $db->run('UPDATE {stats_visitors} SET machine = TRUE WHERE day = ? AND visitor_hash = ?', [$today, $hash]);

            return $done;
        }
        $idc = $news === '' ? null : $db->internalId('news', $news);
        $db->upsert('stats_days', ['day' => $today, 'visits' => (int) $new, 'views' => 1], ['day'], ['visits' => '{old.visits} + {new.visits}', 'views' => '{old.views} + 1']);
        if ($idc !== null && $idc > 0) {
            $db->upsert('stats_news', ['day' => $today, 'news_id' => $idc, 'views' => 1], ['day', 'news_id'], ['views' => '{old.views} + 1']);
        }
        // views per URL (including the language version) – most read pages in the administration
        $db->upsert('stats_pages', ['day' => $today, 'path' => $path, 'views' => 1], ['day', 'path'], ['views' => '{old.views} + 1']);
        $source = strtolower((string) parse_url($request->post('ref'), PHP_URL_HOST));
        $source = preg_replace('/^www\./', '', $source) ?? '';
        // the own site is the configured site URL, not the Host header – the client can write that however it wants
        $custom = preg_replace('/^www\./', '', strtolower((string) parse_url($request->origin(), PHP_URL_HOST))) ?? '';
        if ($new && $source !== '' && $source !== $custom) {
            $db->upsert('stats_sources', ['day' => $today, 'source' => mb_substr($source, 0, 100), 'count' => 1], ['day', 'source'], ['count' => '{old.count} + 1']);
        }
        if ($new) {
            // 2.3: the device of the visit, and the campaign of the page it started on – both from the request itself
            $db->upsert('stats_devices', ['day' => $today, 'device' => self::device($ua), 'visits' => 1], ['day', 'device'], ['visits' => '{old.visits} + 1']);
            $query = mb_substr($request->post('query'), 0, 500);
            $campaign = Forms::campaignText(Forms::campaign($request->origin() . $path . ($query !== '' && $query[0] === '?' ? $query : ''), $request->origin()));
            if ($campaign !== '') {
                $db->upsert('stats_campaigns', ['day' => $today, 'campaign' => mb_substr($campaign, 0, 255), 'visits' => 1], ['day', 'campaign'], ['visits' => '{old.visits} + 1']);
            }
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stats_visitors} WHERE day < CURRENT_DATE - INTERVAL 1 DAY');
            $db->run('DELETE FROM {stats_pages} WHERE day < CURRENT_DATE - INTERVAL 400 DAY');
            $db->run('DELETE FROM {stats_campaigns} WHERE day < CURRENT_DATE - INTERVAL 400 DAY');
        }

        return $done;
    }
}
