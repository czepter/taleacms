<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The owner's overview (Sites area → Overview, multisite): the figures of every set-up site next to each other for 7 and 30 days, read
 * from each site's own tables. The numbers are those of the Statistics screen (Core\Report::glance); only the bookings are added.
 */
final class SiteFigures
{
    public const array PERIODS = [7, 30];

    /**
     * @param array<string, mixed>|null $user whoever asks: only the sites they may work on are listed (nobody given = all)
     * @return array{sites: list<array{site_id: int, public_id: string, name: string, status: string, periods: array<int, array<string, int|float|null>>}>, totals: array<int, array<string, int|float|null>>}
     */
    public static function overview(Db $db, ?array $user = null): array
    {
        $sites = [];
        $totals = array_fill_keys(self::PERIODS, ['visits' => 0, 'views' => 0, 'enquiries' => 0, 'bookings' => 0, 'signups' => 0]);
        foreach (Sites::overview($db, false) as $s) {
            if (!$s['ready'] || $s['status'] === 'trashed' || !Sites::allows($db, $user, (int) $s['site_id'])) { // roles per site
                continue;
            }
            $siteDb = $db->forSite((string) $s['prefix']);
            $periods = [];
            foreach (self::PERIODS as $days) {
                $periods[$days] = self::figures($siteDb, $days);
                foreach ($totals[$days] as $key => $sum) {
                    $totals[$days][$key] = $sum + $periods[$days][$key];
                }
            }
            $sites[] = ['site_id' => (int) $s['site_id'], 'public_id' => (string) $s['public_id'], 'name' => $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'], 'status' => (string) $s['status'], 'periods' => $periods];
        }
        foreach ($totals as $days => $t) {
            $totals[$days]['conversion'] = self::rate($t['enquiries'] + $t['signups'], $t['visits']);
        }

        return ['sites' => $sites, 'totals' => $totals];
    }

    /** @return array<string, int|float|null> visits, views, enquiries, bookings, signups of the last $days days (today included) of one site and the conversion rate in percent */
    public static function figures(Db $db, int $days): array
    {
        $glance = Report::glance($db, $days)['totals'];
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $bookings = (int) $db->value('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ?', [$since]);

        return ['visits' => $glance['visits'], 'views' => $glance['views'], 'enquiries' => $glance['enquiries'], 'bookings' => $bookings, 'signups' => $glance['signups'],
            'conversion' => self::rate($glance['enquiries'] + $glance['signups'], $glance['visits'])];
    }

    private static function rate(int $leads, int $visits): ?float
    {
        return $visits > 0 ? round(100 * $leads / $visits, 1) : null;
    }
}
