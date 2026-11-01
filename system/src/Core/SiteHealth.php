<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * What an agency looks at across its clients' sites (Sites area and Owners area, docs/specs/multisite.md, "Agency tools"): per site the
 * open enquiries and the broken links, per owner the media without an alternative text (media files belong to the owner, not to a site),
 * and the usage of every site per month as a CSV. Everything is read through a connection of ONE owner; only the Owners area (operators)
 * walks several owners, one after the other.
 */
final class SiteHealth
{
    /** The most months a usage file goes back. */
    public const int MAX_MONTHS = 24;

    /** @return array{enquiries: int, broken_links: int} open enquiries (not resolved, not spam, not in the trash) and the links that do not work, of the site $siteDb is connected to */
    public static function site(Db $siteDb): array
    {
        return [
            'enquiries' => (int) $siteDb->value("SELECT COUNT(*) FROM {enquiries} WHERE status < 2 AND deleted_at IS NULL AND category <> 'spam'"),
            'broken_links' => (int) $siteDb->value('SELECT COUNT(*) FROM {broken_links}'),
        ];
    }

    /** When the owner's newest backup file was made (Unix time), 0 = none. The first owner's backups are the installation's. */
    public static function lastBackup(Db $ownerDb): int
    {
        return (int) ((Owners::isFirst($ownerDb) ? Backup::listAll() : Backup::listForOwner($ownerDb))[0]['time'] ?? 0);
    }

    /** Images in the owner's media without a description for blind visitors (the same test as the clean-up in Media, without reading any file). */
    public static function mediaWithoutAlt(Db $ownerDb): int
    {
        return (int) $ownerDb->value("SELECT COUNT(*) FROM {media} WHERE thumb_path <> '' AND TRIM(name) = ''");
    }

    /**
     * The health of one owner: the sums over its set-up sites that are not in the trash.
     *
     * @return array{sites: int, enquiries: int, broken_links: int, media_without_alt: int}
     */
    public static function owner(Db $ownerDb): array
    {
        $sum = ['sites' => 0, 'enquiries' => 0, 'broken_links' => 0, 'media_without_alt' => self::mediaWithoutAlt($ownerDb)];
        foreach ($ownerDb->all("SELECT prefix FROM {sites} WHERE ready_at IS NOT NULL AND status <> 'trashed'") as $s) {
            $h = self::site($ownerDb->forSite((string) $s['prefix']));
            $sum['sites']++;
            $sum['enquiries'] += $h['enquiries'];
            $sum['broken_links'] += $h['broken_links'];
        }

        return $sum;
    }

    /**
     * The first day of each of the last $months months, newest first (this month included).
     *
     * @return list<string> 'Y-m-01'
     */
    public static function months(int $months): array
    {
        $months = $months < 1 ? 12 : min($months, self::MAX_MONTHS);
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $out[] = date('Y-m-01', (int) strtotime(date('Y-m-01') . ' -' . $i . ' months'));
        }

        return $out;
    }

    /**
     * The usage rows of one owner's sites: owner, site, month, storage (the tables' size now, on the current month's row only: it is not
     * recorded over time), e-mails sent (the mail queue keeps 30 days, so older months read low), bookings and enquiries received.
     * There is no column for the writing assistant: its calls are not recorded.
     *
     * @return list<list<string|int>>
     */
    public static function usageRows(Db $ownerDb, string $ownerName, int $months): array
    {
        $rows = [];
        $list = self::months($months);
        foreach ($ownerDb->all("SELECT name, slug, prefix FROM {sites} WHERE ready_at IS NOT NULL AND status <> 'trashed' ORDER BY site_id") as $s) {
            $db = $ownerDb->forSite((string) $s['prefix']);
            $name = $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'];
            foreach ($list as $i => $first) {
                $from = $first . ' 00:00:00';
                $to = date('Y-m-d 00:00:00', (int) strtotime($first . ' +1 month'));
                $rows[] = [$ownerName, $name, substr($first, 0, 7), $i === 0 ? $db->tablesSize() : '',
                    (int) $db->value('SELECT COUNT(*) FROM {mail} WHERE sent_at >= ? AND sent_at < ?', [$from, $to]),
                    (int) $db->value('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                    (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE created_at >= ? AND created_at < ?', [$from, $to])];
            }
        }

        return $rows;
    }

    /** The usage file of the owner $ownerDb is connected to (Sites area). */
    public static function usageCsv(Db $ownerDb, int $months): string
    {
        return self::csv(self::usageRows($ownerDb, Owners::nameOf($ownerDb, $ownerDb->ownerPrefix), $months));
    }

    /** The usage file of every set-up owner (Owners area, operators only). */
    public static function usageCsvAll(Db $db, int $months): string
    {
        $rows = [];
        foreach (Owners::readyPrefixes($db) as $prefix) {
            $owner = $db->forOwner($prefix);
            $rows = array_merge($rows, self::usageRows($owner, Owners::nameOf($db, $prefix), $months));
        }

        return self::csv($rows);
    }

    /** @param list<list<string|int>> $rows */
    private static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Owner'), t('Site'), t('Month'), t('Storage (bytes)'), t('E-mails sent'), t('Bookings'), t('Enquiries')], ';', '"', '');
        foreach ($rows as $row) {
            // a cell starting with = + - @ would run as a formula in a spreadsheet
            fputcsv($f, array_map(fn (string|int $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $row), ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);

        return $csv;
    }
}
