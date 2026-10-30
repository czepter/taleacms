<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * One monthly report for all sites (multisite, setting report_all_sites on site 1): site 1's e-mail carries a section for each of the
 * owner's other set-up sites, and the other sites send none of their own (MonthlyReport::runIfDue). Counts only, like the report itself.
 */
final class AllSitesReport
{
    /** The tick box is shown, and saved, on site 1 with multisite on. */
    public static function available(App $app): bool
    {
        return Sites::enabled() && Sites::currentId($app->db()) === 1;
    }

    /** Site 1 reports for every site. */
    public static function on(App $app): bool
    {
        return self::available($app) && $app->settings()->bool('report_all_sites');
    }

    /** This is one of the further sites and site 1 reports for it: it sends no report of its own. */
    public static function coveredBySiteOne(App $app): bool
    {
        $db = $app->db();

        return Sites::enabled() && Sites::currentId($db) !== 1 && (new Settings($db->forSite($db->ownerPrefix)))->bool('report_all_sites');
    }

    /**
     * The figures of the month for each of the other set-up sites; [] unless site 1 reports for all.
     *
     * @return list<array{name: string, visits: int, views: int, previous_visits: int, previous_views: int, enquiries: int, signups: int, bookings: int}>
     */
    public static function collect(App $app, \DateTimeImmutable $monthStart): array
    {
        if (!self::on($app)) {
            return [];
        }
        $monthEnd = $monthStart->modify('first day of next month');
        $previous = $monthStart->modify('first day of last month');
        [$from, $to] = [$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];
        $out = [];
        foreach (Sites::otherReady($app->db()) as ['name' => $name, 'db' => $db]) {
            $days = fn (string $a, string $b): array => $db->one('SELECT COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(views), 0) AS views FROM {stats_days} WHERE day >= ? AND day < ?', [$a, $b]) ?? ['visits' => 0, 'views' => 0];
            $now = $days($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
            $before = $days($previous->format('Y-m-d'), $monthStart->format('Y-m-d'));
            $out[] = ['name' => (string) $name, 'visits' => (int) $now['visits'], 'views' => (int) $now['views'], 'previous_visits' => (int) $before['visits'], 'previous_views' => (int) $before['views'],
                'enquiries' => (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?', [$from, $to]),
                'signups' => (int) $db->value('SELECT COUNT(*) FROM {subscribers} WHERE status = 1 AND created_at >= ? AND created_at < ?', [$from, $to]),
                'bookings' => (int) $db->value('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$from, $to])];
        }

        return $out;
    }
}
