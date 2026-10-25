<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The archive of the monthly reports (#161, table tl_monthly_reports): once a month has turned, the background job report_archive
 * builds the previous month (MonthlyReport::build) and keeps it – whether or not the report goes out by e-mail – because what
 * the report is built from is kept 30 to 180 days only (the undo journal a month, the change log and the events half a year).
 * A month is stored once and never rebuilt; KEEP_MONTHS are kept, older rows are deleted. Nothing exists for the months before
 * the archive did.
 *
 * The JSON is what Settings → Reports prints (Core\ReportPage), what the e-mail of that month is written from and what Claude
 * reads with get_monthly_report. Counts, titles and page paths only, never personal data (MonthlyReport::scrub).
 */
final class ReportArchive
{
    /** How many months are kept: two years of reports. */
    public const int KEEP_MONTHS = 24;

    /** A month as the archive, the admin address and MCP name it. */
    public const string MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Every stored month, the newest first.
     *
     * @return list<array{id: string, month: string, created_at: string}>
     */
    public static function months(Db $db): array
    {
        return array_map(static fn (array $r): array => ['id' => (string) $r['public_id'], 'month' => (string) $r['month'], 'created_at' => (string) $r['created_at']],
            $db->all('SELECT public_id, month, created_at FROM {monthly_reports} ORDER BY month DESC'));
    }

    /**
     * One stored month with its data, null when it is not stored (or the month is not YYYY-MM).
     *
     * @return array{id: string, month: string, created_at: string, data: array<string, mixed>}|null
     */
    public static function get(Db $db, string $month): ?array
    {
        if (preg_match(self::MONTH_PATTERN, $month) !== 1) {
            return null;
        }
        $row = $db->one('SELECT public_id, month, created_at, data FROM {monthly_reports} WHERE month = ?', [$month]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);

        return ['id' => (string) $row['public_id'], 'month' => (string) $row['month'], 'created_at' => (string) $row['created_at'], 'data' => is_array($data) ? $data : ['month' => $month]];
    }

    /** The oldest stored month ('' = none yet) – the admin says honestly from when reports exist. */
    public static function firstMonth(Db $db): string
    {
        return (string) ($db->value('SELECT MIN(month) FROM {monthly_reports}') ?? '');
    }

    /** Builds a month and stores it unless it is stored already (two runs at once: the unique month keeps the first). Returns whether a row was added. */
    public static function store(App $app, \DateTimeImmutable $monthStart): bool
    {
        // collected in the site's default language, like the e-mail – the texts of System status are stored as they read
        $data = Language::runWith(Language::defaults($app->settings()), fn (): array => MonthlyReport::build($app, $monthStart), 'admin-');

        return $app->db()->insertIgnore('monthly_reports', ['public_id' => Uuid::v4(), 'month' => $monthStart->format('Y-m'), 'created_at' => date('Y-m-d H:i:s'),
            'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    /** The oldest month still kept at the given moment: the KEEP_MONTHS months before the current one. */
    public static function oldestKept(\DateTimeImmutable $now): string
    {
        return $now->modify('first day of this month')->modify('-' . self::KEEP_MONTHS . ' months')->format('Y-m');
    }

    /** Deletes the months older than KEEP_MONTHS before the given moment. Returns how many went. */
    public static function prune(Db $db, \DateTimeImmutable $now): int
    {
        return $db->run('DELETE FROM {monthly_reports} WHERE month < ?', [self::oldestKept($now)])->rowCount();
    }

    /** One run of the job (Core\Scheduler, hourly, also on visits): stores the previous month once and keeps KEEP_MONTHS. */
    public static function runIfDue(App $app): string
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $month = MonthlyReport::previousMonth($now);
        $pruned = self::prune($db, $now);
        $result = self::get($db, $month->format('Y-m')) !== null ? 'stored already' : (self::store($app, $month) ? 'stored ' . $month->format('Y-m') : 'stored already');

        return $result . ($pruned > 0 ? ', removed ' . $pruned : '');
    }
}
