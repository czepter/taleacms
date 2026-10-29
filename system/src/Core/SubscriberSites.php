<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Newsletter persons across the owner's sites (Subscribers → "All sites", multisite): one row per e-mail address with the sites it is on
 * and its state there. Every site keeps its own list; nothing is merged, written or sent from here.
 */
final class SubscriberSites
{
    public const int PER_PAGE = 100;

    /**
     * @param list<array{site_id: int, prefix: string}> $sites the sites to span (Admin\Modules\Sites::inboxSites)
     * @return array{rows: list<array{email: string, last: string, sites: array<int, int>}>, total: int, confirmed: int} sites: site number => status (1 confirmed, 0 awaiting)
     */
    public static function list(Db $db, array $sites, string $search, int $page): array
    {
        $dialect = $db->dialect();
        $where = $search !== '' ? ' WHERE ' . $dialect->likeInsensitive('email') : '';
        $one = $search !== '' ? ['%' . addcslashes($search, '%_\\') . '%'] : [];
        $union = implode(' UNION ALL ', array_map(fn (array $s): string => 'SELECT ' . (int) $s['site_id'] . ' AS site_id, LOWER(email) AS email, status, created_at FROM ' . $dialect->quote($db->forSite($s['prefix'])->table('subscribers')) . $where, $sites));
        $params = array_merge(...array_fill(0, count($sites), $one));

        $emails = array_map('strval', array_column($db->all('SELECT email FROM (' . $union . ') u GROUP BY email ORDER BY MAX(created_at) DESC, email LIMIT ' . self::PER_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PER_PAGE, $params), 'email'));
        $rows = [];
        if ($emails !== []) {
            foreach ($db->all('SELECT site_id, email, status, created_at FROM (' . $union . ') u WHERE email IN (' . implode(', ', array_fill(0, count($emails), '?')) . ')', [...$params, ...$emails]) as $r) {
                $row = &$rows[(string) $r['email']];
                $row ??= ['email' => (string) $r['email'], 'last' => '', 'sites' => []];
                $row['sites'][(int) $r['site_id']] = (int) $r['status'];
                $row['last'] = max($row['last'], (string) $r['created_at']);
                unset($row);
            }
        }

        return [
            'rows' => array_values(array_filter(array_map(fn (string $e): ?array => $rows[$e] ?? null, $emails))),
            'total' => (int) $db->value('SELECT COUNT(*) FROM (SELECT email FROM (' . $union . ') u GROUP BY email) g', $params),
            'confirmed' => (int) $db->value('SELECT COUNT(*) FROM (SELECT email FROM (' . $union . ') u WHERE status = 1 GROUP BY email) g', $params),
        ];
    }
}
