<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\Antispam;
use Talea\Core\App;

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

    public static function record(App $app, ?int $idc): void
    {
        $server = $_SERVER;
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (!self::isOn($app) || $ua === '' || self::isBot($ua) || $app->request->get('preview') !== '') {
            return;
        }
        $db = $app->db();
        $today = date('Y-m-d');
        $salt = (new Antispam($db, $app->settings()))->key() . $today;
        $hash = substr(hash('sha256', $salt . '|' . $app->request->ip() . '|' . $ua), 0, 32);

        $new = $db->insertIgnore('stats_visitors', ['day' => $today, 'visitor_hash' => $hash]);
        $db->upsert('stats_days', ['day' => $today, 'visits' => (int) $new, 'views' => 1], ['day'], ['visits' => '{old.visits} + {new.visits}', 'views' => '{old.views} + 1']);
        if ($idc !== null) {
            $db->upsert('stats_news', ['day' => $today, 'news_id' => $idc, 'views' => 1], ['day', 'news_id'], ['views' => '{old.views} + 1']);
        }
        // views per URL (including the language version) – most read pages in the administration
        $path = mb_substr((string) parse_url($app->url(ltrim($app->request->path(), '/')), PHP_URL_PATH), 0, 255);
        $db->upsert('stats_pages', ['day' => $today, 'path' => $path, 'views' => 1], ['day', 'path'], ['views' => '{old.views} + 1']);
        $source = strtolower((string) parse_url((string) ($server['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $source = preg_replace('/^www\./', '', $source) ?? '';
        // the own site is the configured site URL, not the Host header – the client can write that however it wants
        $custom = preg_replace('/^www\./', '', strtolower((string) parse_url($app->request->origin(), PHP_URL_HOST))) ?? '';
        if ($new && $source !== '' && $source !== $custom) {
            $db->upsert('stats_sources', ['day' => $today, 'source' => mb_substr($source, 0, 100), 'count' => 1], ['day', 'source'], ['count' => '{old.count} + 1']);
        }
        if ($new) {
            // 2.3: the device of the visit, and the campaign of the page it started on – both from the request itself
            $db->upsert('stats_devices', ['day' => $today, 'device' => self::device($ua), 'visits' => 1], ['day', 'device'], ['visits' => '{old.visits} + 1']);
            $campaign = Forms::campaignText(Forms::campaign($app->request->origin() . ($server['REQUEST_URI'] ?? '/'), $app->request->origin()));
            if ($campaign !== '') {
                $db->upsert('stats_campaigns', ['day' => $today, 'campaign' => mb_substr($campaign, 0, 255), 'visits' => 1], ['day', 'campaign'], ['visits' => '{old.visits} + 1']);
            }
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stats_visitors} WHERE day < CURRENT_DATE - INTERVAL 1 DAY');
            $db->run('DELETE FROM {stats_pages} WHERE day < CURRENT_DATE - INTERVAL 400 DAY');
            $db->run('DELETE FROM {stats_campaigns} WHERE day < CURRENT_DATE - INTERVAL 400 DAY');
        }
    }
}
