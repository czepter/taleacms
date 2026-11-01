<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Monthly report by e-mail (2.9): once a month the site tells its owner what happened in the previous calendar month –
 * traffic, the top searches (2.13, Core\SearchData), enquiries and sign-ups, backups, who changed what (people
 * and Claude), the problems right now and what needs a decision. With the agency's branding (agency_* settings, 2.4) when it is set, otherwise the site's name.
 *
 * Counts and page paths only: no names, no e-mail addresses, no enquiry contents – the report may be forwarded.
 * The job (Core\Scheduler, hourly) sends it when report_monthly is on and report_last_month is not yet the previous month,
 * so a site without cron still gets it with the first visit of the new month. Recipients: report_recipients, otherwise the
 * site e-mail. The e-mail is written in the site's default language (Language::runWith, like the alerts).
 *
 * #161: build() is also the archived report (Core\ReportArchive, Settings → Reports, printed by Core\ReportPage, MCP
 * get_monthly_report): requests to Claude, pages and news published, Claude sessions and scheduled runs, security counts, the
 * site audit, enquiry categories, bookings, newsletters sent and what waits at the month's end. A section is null when its
 * feature is off; scrub() masks e-mail addresses and phone numbers in every string. A summary – no hours, no prices.
 */
final class MonthlyReport
{
    public const int MAX_RECIPIENTS = 10;
    public const int MAX_PROBLEMS = 10;
    private const int TOP = 5;

    /** Month names in the nominative – the subject says "September 2026", not "5 September" (format_date_long uses the genitive keys). */
    public const array MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];


    /** The first day of the month before the given moment, in its time zone – January goes to December of the previous year. */
    public static function previousMonth(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('first day of last month')->setTime(0, 0);
    }

    /**
     * The data of one calendar month (the site's time zone). Everything is a count, a path or a system message.
     *
     * @return array<string, mixed>
     */
    public static function build(App $app, \DateTimeImmutable $monthStart): array
    {
        $db = $app->db();
        $s = $app->settings();
        $monthStart = $monthStart->modify('first day of this month')->setTime(0, 0);
        $monthEnd = $monthStart->modify('first day of next month');
        [$from, $to] = [$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];
        [$fromDay, $toDay] = [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')];
        $count = fn (string $sql, array $params): int => (int) $db->value($sql, $params);
        $top = fn (string $sql, array $params, string $key): array => array_map(fn (array $r): array => [$key => (string) $r['k'], 'n' => (int) $r['n']], $db->all($sql, $params));
        // enquiries and sign-ups store the page as a full URL: only its path goes into the report
        $path = static fn (string $url): string => (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        $stats = null;
        if (\Talea\Front\Stats::isOn($app)) {
            $previousStart = $monthStart->modify('first day of last month');
            $totals = fn (string $a, string $b): array => $db->one('SELECT COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(views), 0) AS views FROM {stats_days} WHERE day >= ? AND day < ?', [$a, $b]) ?? ['visits' => 0, 'views' => 0];
            $now = $totals($fromDay, $toDay);
            $before = $totals($previousStart->format('Y-m-d'), $fromDay);
            $stats = [
                'visits' => (int) $now['visits'], 'views' => (int) $now['views'],
                'previous_visits' => (int) $before['visits'], 'previous_views' => (int) $before['views'],
                'pages' => $top('SELECT path AS k, SUM(views) AS n FROM {stats_pages} WHERE day >= ? AND day < ? GROUP BY path ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'path'),
                'sources' => $top('SELECT source AS k, SUM(count) AS n FROM {stats_sources} WHERE day >= ? AND day < ? GROUP BY source ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'site'),
                'campaigns' => $top('SELECT campaign AS k, SUM(visits) AS n FROM {stats_campaigns} WHERE day >= ? AND day < ? GROUP BY campaign ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'campaign'),
                // contact clicks (2.12, Core\Conversions): calls, e-mails and WhatsApp – leads next to the enquiries, counts only
                'contact_clicks' => array_diff_key(Conversions::summary($db, $fromDay, $toDay), ['by_page' => true]),
            ];
        }

        $enquiries = null;
        if (Extensions::isEnabled($s, 'enquiries')) {
            $byPage = [];
            foreach ($db->all('SELECT page AS k, COUNT(*) AS n FROM {enquiries} WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY page ORDER BY n DESC LIMIT 20', [$from, $to]) as $r) {
                $p = $path((string) $r['k']);
                $byPage[$p] = ($byPage[$p] ?? 0) + (int) $r['n'];
            }
            arsort($byPage);
            $enquiries = [
                'total' => $count('SELECT COUNT(*) FROM {enquiries} WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?', [$from, $to]),
                'forms' => $top('SELECT form AS k, COUNT(*) AS n FROM {enquiries} WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY form ORDER BY n DESC LIMIT ' . self::TOP, [$from, $to], 'form'),
                'pages' => array_map(fn (string $p, int $n): array => ['path' => $p, 'n' => $n], array_keys(array_slice($byPage, 0, self::TOP, true)), array_slice($byPage, 0, self::TOP, true)),
                'unanswered' => $count('SELECT COUNT(*) FROM {enquiries} WHERE status = 0 AND deleted_at IS NULL', []), // still "new" right now – whatever month they came in
            ];
        }
        $signups = Extensions::isEnabled($s, 'newsletter_signup') ? $count('SELECT COUNT(*) FROM {subscribers} WHERE status = 1 AND created_at >= ? AND created_at < ?', [$from, $to]) : null;

        $lastBackup = Owners::isFirst($db) ? Backup::listAll()[0]['time'] ?? null : null; // the backups are the install's
        $backups = [
            'created' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.created', $from, $to]),
            'failed' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.failed', $from, $to]),
            'last' => $lastBackup === null ? null : date('Y-m-d H:i:s', $lastBackup),
        ];
        // the change log names the Claude connection a change came through (ChangeLog::write); empty = a person in the admin;
        // sign-ins are no change (they are counted under security)
        $changes = [
            'people' => $count("SELECT COUNT(*) FROM {change_log} WHERE created_at >= ? AND created_at < ? AND via = '' AND module <> 'signed_in'", [$from, $to]),
            'claude' => $count("SELECT COUNT(*) FROM {change_log} WHERE created_at >= ? AND created_at < ? AND via <> '' AND module <> 'signed_in'", [$from, $to]),
        ];
        $problems = [];
        $errors = 0;
        foreach (Health::checks($app) as $check) {
            if ($check['status'] === 'ok') {
                continue;
            }
            $errors += (int) ($check['status'] === 'error');
            if (count($problems) < self::MAX_PROBLEMS) {
                $problems[] = ['group' => $check['group'], 'name' => $check['name'], 'state' => $check['status'], 'info' => $check['info']];
            }
        }

        // #161: what the archived report adds – a section whose source fails (a table of a newer migration, an error in the
        // audit) is left out instead of losing the whole month; a section whose feature is off is null
        $section = static function (callable $collect): ?array {
            try {
                return $collect();
            } catch (\Throwable) {
                return null;
            }
        };
        $claude = Extensions::isEnabled($s, 'claude');
        if (is_array($enquiries)) {
            // the category the triage gave (Core\Triage), never the contents
            $enquiries['categories'] = $section(fn (): array => $top("SELECT category AS k, COUNT(*) AS n FROM {enquiries} WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? AND category <> '' GROUP BY category ORDER BY n DESC", [$from, $to], 'category')) ?? [];
        }

        return self::scrub([
            'month' => $monthStart->format('Y-m'),
            'generated_at' => date('Y-m-d H:i:s'),
            'stats' => $stats,
            // what people searched for (2.13, Core\SearchData): the top 3 queries of the month's last snapshot per engine
            'search' => SearchData::topQueries($db, $fromDay, $toDay),
            'enquiries' => $enquiries,
            'signups' => $signups,
            'backups' => $backups,
            'changes' => $changes,
            'problems' => $problems,
            'decisions' => ['enquiries' => $enquiries['unanswered'] ?? 0, 'errors' => $errors],
            'requests' => $claude ? $section(fn (): array => self::requests($db, $from, $to)) : null,
            'published' => $section(fn (): array => self::published($app, $from, $to)),
            'claude' => $claude ? $section(fn (): array => self::claude($db, $from, $to)) : null,
            'security' => $section(fn (): array => self::security($db, $from, $to)),
            'audit' => $section(fn (): array => self::audit($app, $monthStart)),
            'sites' => AllSitesReport::collect($app, $monthStart), // multisite, report_all_sites: one section per further site
            'bookings' => Extensions::isEnabled($s, 'bookings') ? $section(fn (): array => [
                'total' => $count('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                'statuses' => array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ? GROUP BY status ORDER BY status', [$from, $to])),
                'appointments' => $count("SELECT COUNT(*) FROM {bookings} WHERE starts_at >= ? AND starts_at < ? AND status IN ('confirmed', 'done', 'no_show')", [$from, $to]),
            ]) : null,
            'newsletters' => Extensions::isEnabled($s, 'newsletter_signup') ? $section(fn (): array => array_map(static fn (array $r): array => ['subject' => (string) $r['subject'], 'date' => (string) $r['finished_at'], 'sent' => (int) $r['sent_count']],
                $db->all("SELECT subject, finished_at, sent_count FROM {newsletters} WHERE status = 'sent' AND finished_at >= ? AND finished_at < ? ORDER BY finished_at LIMIT " . self::LIST_LIMIT, [$from, $to]))) : null,
            // what waits for a person when the report is made (the month has just ended): the kinds of "Waiting for you"
            'waiting' => $section(fn (): array => array_column(PendingReview::all($app, array_fill_keys(array_column(PendingReview::KINDS, 1), '')), 'count', 'kind')),
        ]);
    }

    /** At most this many titles in one list of the archived report (requests, pages, news, newsletters). */
    public const int LIST_LIMIT = 20;

    /** Security events the report counts – the types only, never an address or an account. */
    public const array SECURITY_EVENTS = ['firewall.blocked', 'security.account_suspended', 'security.connection_revoked'];

    /**
     * Requests to Claude (2.15): handled this month, and the ones still open at its end – title, status and dates only.
     *
     * @return array{created: int, done: list<array{title: string, status: string, created: string, done: string}>, done_count: int, open: list<array{title: string, status: string, created: string, done: string}>, open_count: int}
     */
    private static function requests(Db $db, string $from, string $to): array
    {
        $done = "FROM {requests} WHERE status IN ('done', 'declined') AND done_at >= ? AND done_at < ?";
        $open = "FROM {requests} WHERE status IN ('new', 'in_progress') AND created_at < ?";
        $row = static fn (array $r): array => ['title' => (string) $r['title'], 'status' => (string) $r['status'], 'created' => (string) $r['created_at'], 'done' => (string) ($r['done_at'] ?? '')];

        return [
            'created' => (int) $db->value('SELECT COUNT(*) FROM {requests} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
            'done' => array_map($row, $db->all('SELECT title, status, created_at, done_at ' . $done . ' ORDER BY done_at LIMIT ' . self::LIST_LIMIT, [$from, $to])),
            'done_count' => (int) $db->value('SELECT COUNT(*) ' . $done, [$from, $to]),
            'open' => array_map($row, $db->all('SELECT title, status, created_at, done_at ' . $open . ' ORDER BY created_at LIMIT ' . self::LIST_LIMIT, [$to])),
            'open_count' => (int) $db->value('SELECT COUNT(*) ' . $open, [$to]),
        ];
    }

    /**
     * Pages published this month (the build.published events: one row per page, the last publishing, by a person or through
     * Claude) and news published this month.
     *
     * @return array{pages: list<array{title: string, date: string, by: string}>, pages_count: int, news: list<array{title: string, date: string}>|null, news_count: int|null}
     */
    private static function published(App $app, string $from, string $to): array
    {
        $db = $app->db();
        $pages = [];
        foreach ($db->all("SELECT created_at, data FROM {events} WHERE type = 'build.published' AND created_at >= ? AND created_at < ? ORDER BY id", [$from, $to]) as $r) {
            $info = json_decode((string) $r['data'], true);
            if (is_array($info) && (int) ($info['page_id'] ?? 0) > 0) {
                $pages[(int) $info['page_id']] = ['date' => (string) $r['created_at'], 'by' => !empty($info['claude']) ? 'claude' : 'person'];
            }
        }
        $titles = $pages === [] ? [] : $db->pairs('SELECT page_id, title FROM {pages} WHERE page_id IN (' . implode(',', array_keys($pages)) . ')');
        $list = [];
        foreach ($pages as $id => $p) {
            if (isset($titles[$id])) { // a page deleted since is left out
                $list[] = ['title' => (string) $titles[$id]] + $p;
            }
        }
        $news = Extensions::isEnabled($app->settings(), 'news');
        $where = 'FROM {news} WHERE visible = TRUE AND deleted_at IS NULL AND published_at >= ? AND published_at < ?';

        return [
            'pages' => array_slice($list, 0, self::LIST_LIMIT),
            'pages_count' => count($list),
            'news' => $news ? array_map(static fn (array $r): array => ['title' => (string) $r['title'], 'date' => (string) $r['published_at']],
                $db->all('SELECT title, published_at ' . $where . ' ORDER BY published_at LIMIT ' . self::LIST_LIMIT, [$from, $to])) : null,
            'news_count' => $news ? (int) $db->value('SELECT COUNT(*) ' . $where, [$from, $to]) : null,
        ];
    }

    /**
     * Claude sessions (the undo journal, Core\AgentJournal) and scheduled runs (Core\AgentSchedules): counts by result and by
     * task only – a run's name and summary are written by people and Claude and stay out.
     *
     * @return array{sessions: int, calls: int, undone: int, runs: int, run_statuses: array<string, int>, run_tasks: array<string, int>}
     */
    private static function claude(Db $db, string $from, string $to): array
    {
        $sessions = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(calls), 0) AS calls, COALESCE(SUM(CASE WHEN undone_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS undone FROM {agent_sessions} WHERE started_at >= ? AND started_at < ?', [$from, $to]) ?? [];
        $statuses = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {agent_runs} WHERE due_at >= ? AND due_at < ? GROUP BY status ORDER BY status', [$from, $to]));

        return [
            'sessions' => (int) ($sessions['n'] ?? 0), 'calls' => (int) ($sessions['calls'] ?? 0), 'undone' => (int) ($sessions['undone'] ?? 0),
            'runs' => array_sum($statuses), 'run_statuses' => $statuses,
            'run_tasks' => array_map('intval', $db->pairs('SELECT s.task, COUNT(*) FROM {agent_runs} r JOIN {agent_schedules} s ON s.id = r.schedule_id WHERE r.due_at >= ? AND r.due_at < ? GROUP BY s.task ORDER BY s.task', [$from, $to])),
        ];
    }

    /**
     * Sign-ins to the administration, the failed ones (the change log) and the security events – counts only.
     *
     * @return array{sign_ins: int, failed_sign_ins: int, events: array<string, int>}
     */
    private static function security(Db $db, string $from, string $to): array
    {
        $signIns = array_map('intval', $db->pairs("SELECT action, COUNT(*) FROM {change_log} WHERE module = 'signed_in' AND action IN ('login', 'failed_attempt') AND created_at >= ? AND created_at < ? GROUP BY action", [$from, $to]));
        $events = array_map('intval', $db->pairs('SELECT type, COUNT(*) FROM {events} WHERE type IN (' . implode(',', array_fill(0, count(self::SECURITY_EVENTS), '?')) . ') AND created_at >= ? AND created_at < ? GROUP BY type', [...self::SECURITY_EVENTS, $from, $to]));

        return ['sign_ins' => $signIns['login'] ?? 0, 'failed_sign_ins' => $signIns['failed_attempt'] ?? 0, 'events' => $events];
    }

    /**
     * The site audit when the report is made: findings by kind, and against the previous archived month how many fewer and how
     * many more per kind (the audit keeps no history of its own; null before there is a previous month to compare with).
     *
     * @return array{kinds: array<string, int>, total: int, fixed: ?int, new: ?int}
     */
    private static function audit(App $app, \DateTimeImmutable $monthStart): array
    {
        $kinds = [];
        foreach ((new Audit($app))->run() as $finding) {
            $kinds[$finding['kind']] = ($kinds[$finding['kind']] ?? 0) + 1;
        }
        $before = ReportArchive::get($app->db(), $monthStart->modify('first day of last month')->format('Y-m'))['data']['audit']['kinds'] ?? null;
        [$fixed, $new] = [null, null];
        if (is_array($before)) {
            [$fixed, $new] = [0, 0];
            foreach (array_unique([...array_keys($kinds), ...array_keys($before)]) as $kind) {
                $delta = ($kinds[$kind] ?? 0) - (int) ($before[$kind] ?? 0);
                if ($delta > 0) {
                    $new += $delta;
                } else {
                    $fixed -= $delta;
                }
            }
        }

        return ['kinds' => $kinds, 'total' => array_sum($kinds), 'fixed' => $fixed, 'new' => $new];
    }

    /**
     * Nothing personal leaves the report (#161): every string of the data – titles written by staff, search queries, form names,
     * the texts of System status – loses its e-mail addresses (and any other word with "@") and its phone numbers. The archive,
     * the printed report, the e-mail and get_monthly_report all read the scrubbed data. Numbers are not strings, so counts stay.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function scrub(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::redact($value);
            }
        });

        return $data;
    }

    /**
     * One text with e-mail addresses ("ann@studio.example", "ann @ studio.example", "ann [at] studio [dot] example") and phone numbers (a run of
     * 9 or more digits with spaces, dots, dashes, slashes or brackets, an optional "+") masked. A date or a moment in the run
     * ("2026-09-30 03:00:00") keeps it.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/[\w.+-]+\s*(?:\[\s*at\s*\]|\(\s*at\s*\))\s*[\w-]+(?:\s*(?:\[\s*dot\s*\]|\(\s*dot\s*\)|\.)\s*[\w-]+)+/iu', '[e-mail]', $text);
        $text = (string) preg_replace('/[^\s@<>()\[\]{},;:"\']+\s*@\s*[^\s@<>()\[\]{},;:"\']+|\S*[@＠﹫]\S*/u', '[e-mail]', $text);

        return (string) preg_replace_callback('/(?<![\w.\/-])\+?\(?\d[\d \t.\/()-]{6,}\d(?![\w\/])/u', static function (array $m): string {
            $digits = strlen((string) preg_replace('/\D/', '', $m[0]));
            $date = preg_match('/\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\. ?\d{1,2}\. ?\d{4}|\d{1,2}\/\d{1,2}\/\d{4}/', $m[0]) === 1;

            return $digits < 9 || $date ? $m[0] : '[phone]';
        }, $text);
    }

    /**
     * The e-mail: inline-styled tables (Outlook and Gmail ignore most CSS) at most 600 px wide, plus a plain-text twin. Written in
     * the site's default language with the admin dictionary – the owner reads it, not a visitor.
     *
     * @param array<string, mixed> $data from build()
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $data, Settings $s, string $siteUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($data, $s, $siteUrl): array {
            $siteUrl = rtrim($siteUrl, '/');
            $siteName = $s->get('site_name');
            $agency = $s->get('agency_name');
            $agencyOn = $agency !== '' && Agency::visible($s);
            $agency = $agencyOn ? $agency : '';
            $monthName = t(self::MONTHS[(int) substr((string) $data['month'], 5, 2)] ?? 'January') . ' ' . substr((string) $data['month'], 0, 4);
            $subject = t('Website report – %s – %s', $monthName, $siteName);
            // no e-mail address or phone number gets through from the data – a form name or a message could carry one (#161: the
            // same masking as the archive, so data built before it are covered too)
            $mask = self::redact(...);
            $safe = static fn (string $text): string => e($mask($text));
            $n = static fn (int $value): string => format_number($value, 0);
            $admin = $siteUrl . '/admin';

            $html = [];
            $text = [];
            $section = function (string $title, string $body, string $plain) use (&$html, &$text): void {
                $html[] = '<tr><td style="padding:20px 28px 4px;font:600 15px/1.3 Arial,Helvetica,sans-serif;color:#121212;">' . e($title) . '</td></tr><tr><td style="padding:0 28px 12px;font:14px/1.5 Arial,Helvetica,sans-serif;color:#333;">' . $body . '</td></tr>';
                $text[] = mb_strtoupper($title) . "\n" . $plain;
            };
            $row = static fn (string $label, string $value): string => '<tr><td style="padding:4px 0;border-bottom:1px solid #eee;">' . $label . '</td><td style="padding:4px 0 4px 12px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">' . $value . '</td></tr>';
            $table = static fn (array $rows): string => $rows === [] ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font:14px/1.5 Arial,Helvetica,sans-serif;color:#333;">' . implode('', $rows) . '</table>';
            $plainRows = static fn (array $pairs): string => implode("\n", array_map(static fn (array $p): string => '  ' . $mask($p[0]) . ': ' . $mask($p[1]), $pairs));
            $change = static function (int $now, int $before) use ($n): string {
                if ($before === 0) {
                    return $n($now);
                }
                $percent = (int) round(100 * ($now - $before) / $before);

                return $n($now) . ' (' . ($percent >= 0 ? '+' : '−') . abs($percent) . ' %)';
            };

            // needs your decision – first, so the owner sees it before the numbers
            $decisions = (array) ($data['decisions'] ?? []);
            $pending = [];
            if (($decisions['enquiries'] ?? 0) > 0) {
                $pending[] = [t('%d enquiry(ies) nobody has opened yet', (int) $decisions['enquiries']), $admin . '/enquiries'];
            }
            if (($decisions['errors'] ?? 0) > 0) {
                $pending[] = [t('%d problem(s) in System status need fixing', (int) $decisions['errors']), $admin . '/status'];
            }
            if ($pending !== []) {
                $items = array_map(static fn (array $p): string => '<li style="margin:0 0 4px;"><a href="' . e($p[1]) . '" style="color:#121212;">' . e($p[0]) . '</a></li>', $pending);
                $section(t('Needs your decision'), '<ul style="margin:0;padding:0 0 0 18px;">' . implode('', $items) . '</ul>', implode("\n", array_map(static fn (array $p): string => '  – ' . $p[0] . ' – ' . $p[1], $pending)));
            }

            // traffic
            $stats = $data['stats'] ?? null;
            if (is_array($stats)) {
                $rows = [$row(e(t('Visits')), e($change((int) $stats['visits'], (int) $stats['previous_visits']))), $row(e(t('Page views')), e($change((int) $stats['views'], (int) $stats['previous_views'])))];
                $pairs = [[t('Visits'), $change((int) $stats['visits'], (int) $stats['previous_visits'])], [t('Page views'), $change((int) $stats['views'], (int) $stats['previous_views'])]];
                $list = function (string $title, array $items, string $key) use (&$rows, &$pairs, $row, $safe, $n): void {
                    if ($items === []) {
                        return;
                    }
                    $rows[] = '<tr><td colspan="2" style="padding:12px 0 2px;font-weight:600;">' . e($title) . '</td></tr>';
                    $pairs[] = [$title, ''];
                    foreach ($items as $item) {
                        $rows[] = $row($safe((string) $item[$key]), $n((int) $item['n']));
                        $pairs[] = ['  ' . (string) $item[$key], $n((int) $item['n'])];
                    }
                };
                $list(t('Most read pages'), (array) $stats['pages'], 'path');
                $list(t('Visitors came from'), (array) $stats['sources'], 'site');
                $list(t('Campaigns'), (array) $stats['campaigns'], 'campaign');
                $section(t('Traffic'), $table($rows) . '<p style="margin:8px 0 0;font-size:12px;color:#777;">' . e(t('Compared with the month before.')) . '</p>', $plainRows($pairs));
            } else {
                $section(t('Traffic'), '<p style="margin:0;">' . e(t('The built-in statistics are off (Settings → Analytics), so there are no traffic figures.')) . '</p>', '  ' . t('The built-in statistics are off (Settings → Analytics), so there are no traffic figures.'));
            }

            // searches that brought visitors – only when an engine is connected and had data this month
            $search = (array) ($data['search'] ?? []);
            if ($search !== []) {
                $rows = [];
                $pairs = [];
                foreach ($search as $q) {
                    $label = t('%s on %s', (string) $q['query'], $q['engine'] === 'bing' ? 'Bing' : 'Google');
                    $rows[] = $row($safe($label), e(t('%s clicks', $n((int) $q['clicks']))));
                    $pairs[] = [$label, t('%s clicks', $n((int) $q['clicks']))];
                }
                $section(t('Searches that brought visitors'), $table($rows), $plainRows($pairs));
            }

            // leads – the contact clicks (calls, e-mails, WhatsApp) join them when there were any
            $enquiries = $data['enquiries'] ?? null;
            $signups = $data['signups'] ?? null;
            $clicks = (array) ($stats['contact_clicks'] ?? []);
            if (is_array($enquiries) || $signups !== null || Conversions::total($clicks) > 0) {
                $rows = [];
                $pairs = [];
                if (is_array($enquiries)) {
                    $rows[] = $row(e(t('Enquiries received')), $n((int) $enquiries['total']));
                    $pairs[] = [t('Enquiries received'), $n((int) $enquiries['total'])];
                    foreach ((array) $enquiries['forms'] as $f) {
                        $rows[] = $row('&nbsp;&nbsp;' . $safe((string) $f['form']), $n((int) $f['n']));
                        $pairs[] = ['  ' . (string) $f['form'], $n((int) $f['n'])];
                    }
                    foreach ((array) $enquiries['pages'] as $p) {
                        $rows[] = $row('&nbsp;&nbsp;' . t('from page %s', $safe((string) $p['path'])), $n((int) $p['n']));
                        $pairs[] = ['  ' . t('from page %s', (string) $p['path']), $n((int) $p['n'])];
                    }
                }
                if ($signups !== null) {
                    $rows[] = $row(e(t('Newsletter sign-ups')), $n((int) $signups));
                    $pairs[] = [t('Newsletter sign-ups'), $n((int) $signups)];
                }
                foreach (['calls' => 'Calls – clicks on a phone number', 'emails' => 'E-mails – clicks on an e-mail address', 'whatsapp' => 'WhatsApp – clicks on a WhatsApp link'] as $key => $label) {
                    if ((int) ($clicks[$key] ?? 0) > 0) {
                        $rows[] = $row(e(t($label)), $n((int) $clicks[$key]));
                        $pairs[] = [t($label), $n((int) $clicks[$key])];
                    }
                }
                $section(t('Enquiries and sign-ups'), $table($rows), $plainRows($pairs));
            }

            // the work on the site (#161): requests handled, what was published, Claude's sessions and scheduled runs – the whole
            // story is the printable report in Settings → Reports
            $requests = $data['requests'] ?? null;
            $published = $data['published'] ?? null;
            $claude = $data['claude'] ?? null;
            $rows = [];
            $pairs = [];
            $add = function (string $label, int $value) use (&$rows, &$pairs, $row, $n): void {
                $rows[] = $row(e($label), $n($value));
                $pairs[] = [$label, $n($value)];
            };
            if (is_array($requests)) {
                $add(t('Requests handled'), (int) $requests['done_count']);
                $add(t('Requests still open'), (int) $requests['open_count']);
            }
            if (is_array($published)) {
                $add(t('Pages published'), (int) ($published['pages_count'] ?? 0));
                if (($published['news_count'] ?? null) !== null) {
                    $add(t('News published'), (int) $published['news_count']);
                }
            }
            if (is_array($claude)) {
                $add(t('Claude sessions'), (int) $claude['sessions']);
                $add(t('Scheduled Claude runs'), (int) $claude['runs']);
            }
            if ($rows !== []) {
                $section(t('Work on the site'), $table($rows), $plainRows($pairs));
            }

            // multisite (AllSitesReport): the other sites of the owner, one section each
            foreach ((array) ($data['sites'] ?? []) as $site) {
                $rows = [$row(e(t('Visits')), e($change((int) $site['visits'], (int) $site['previous_visits']))), $row(e(t('Page views')), e($change((int) $site['views'], (int) $site['previous_views']))),
                    $row(e(t('Enquiries received')), $n((int) $site['enquiries'])), $row(e(t('Newsletter sign-ups')), $n((int) $site['signups'])), $row(e(t('Bookings')), $n((int) $site['bookings']))];
                $pairs = [[t('Visits'), $change((int) $site['visits'], (int) $site['previous_visits'])], [t('Page views'), $change((int) $site['views'], (int) $site['previous_views'])],
                    [t('Enquiries received'), $n((int) $site['enquiries'])], [t('Newsletter sign-ups'), $n((int) $site['signups'])], [t('Bookings'), $n((int) $site['bookings'])]];
                $section(t('Site: %s', $mask((string) $site['name'])), $table($rows), $plainRows($pairs));
            }

            // care of the site: backups, changes, security
            $rows = [];
            $pairs = [];
            $backups = (array) ($data['backups'] ?? []);
            $backupText = t('%d made, %d failed', (int) ($backups['created'] ?? 0), (int) ($backups['failed'] ?? 0))
                . (($backups['last'] ?? null) !== null ? ', ' . t('the last one %s', format_date((string) $backups['last'])) : '');
            $rows[] = $row(e(t('Backups')), e($backupText));
            $pairs[] = [t('Backups'), $backupText];
            $changes = (array) ($data['changes'] ?? []);
            $changesText = t('%d by people, %d by Claude', (int) ($changes['people'] ?? 0), (int) ($changes['claude'] ?? 0));
            $rows[] = $row(e(t('Changes in the administration')), e($changesText));
            $pairs[] = [t('Changes in the administration'), $changesText];
            $security = $data['security'] ?? null;
            if (is_array($security)) {
                $securityText = t('%d failed sign-in(s), %d address(es) blocked for a while', (int) $security['failed_sign_ins'], (int) ($security['events']['firewall.blocked'] ?? 0));
                $rows[] = $row(e(t('Security')), e($securityText));
                $pairs[] = [t('Security'), $securityText];
            }
            $section(t('Care of the site'), $table($rows), $plainRows($pairs));

            // problems right now
            $problems = (array) ($data['problems'] ?? []);
            if ($problems === []) {
                $section(t('Problems right now'), '<p style="margin:0;">' . e(t('None – every check in System status is fine.')) . '</p>', '  ' . t('None – every check in System status is fine.'));
            } else {
                $items = array_map(static fn (array $p): string => '<li style="margin:0 0 4px;">' . ($p['state'] === 'error' ? '<strong>' . $safe((string) $p['name']) . '</strong>' : $safe((string) $p['name'])) . ' – ' . $safe((string) $p['info']) . '</li>', $problems);
                $section(t('Problems right now'), '<ul style="margin:0;padding:0 0 0 18px;">' . implode('', $items) . '</ul><p style="margin:8px 0 0;font-size:12px;"><a href="' . e($admin . '/status') . '" style="color:#121212;">' . e(t('Details are in System status')) . '</a></p>',
                    implode("\n", array_map(static fn (array $p): string => '  – ' . $mask((string) $p['name']) . ' – ' . $mask((string) $p['info']), $problems)) . "\n  " . $admin . '/status');
            }

            // header and footer: the agency when it is set, otherwise the site
            $logo = $agencyOn ? $s->get('agency_logo') : '';
            $title = $agency !== '' ? $agency : $siteName;
            $header = '<tr><td style="padding:24px 28px 8px;">'
                . ($agency !== '' && $logo !== '' ? '<img src="' . e($siteUrl . '/' . ltrim($logo, '/')) . '" alt="" height="32" style="display:block;height:32px;margin:0 0 12px;">' : '')
                . '<div style="font:600 18px/1.3 Arial,Helvetica,sans-serif;color:#121212;">' . e($title) . '</div>'
                . '<div style="font:14px/1.5 Arial,Helvetica,sans-serif;color:#555;margin-top:4px;">' . e(t('Website report – %s', $monthName)) . ' · <a href="' . e($siteUrl) . '" style="color:#555;">' . e($siteName) . '</a></div></td></tr>';
            $contacts = array_filter([
                $s->get('agency_url') !== '' ? '<a href="' . e($s->get('agency_url')) . '" style="color:#555;">' . e(preg_replace('#^https?://#', '', $s->get('agency_url')) ?? '') . '</a>' : '',
                $s->get('agency_email') !== '' ? '<a href="mailto:' . e($s->get('agency_email')) . '" style="color:#555;">' . e($s->get('agency_email')) . '</a>' : '',
                $s->get('agency_phone') !== '' ? e($s->get('agency_phone')) : '',
            ]);
            $plainContacts = array_filter([$s->get('agency_url'), $s->get('agency_email'), $s->get('agency_phone')]);
            $footerNote = t('Sent once a month by the website %s. The full report to print or download, the recipients and the switch are in Settings → Reports.', $siteName);
            $footer = '<tr><td style="padding:20px 28px 24px;border-top:1px solid #eee;font:12px/1.6 Arial,Helvetica,sans-serif;color:#777;">'
                . ($agency !== '' && $contacts !== [] ? '<div style="margin:0 0 6px;color:#555;">' . e($agency) . ' · ' . implode(' · ', $contacts) . '</div>' : '')
                . e($footerNote) . '</td></tr>';

            $document = '<!DOCTYPE html><html lang="' . e(Language::code()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>' . e($subject) . '</title></head>'
                . '<body style="margin:0;padding:0;background:#f6f4ee;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f4ee;"><tr><td align="center" style="padding:24px 12px;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
                . $header . implode('', $html) . $footer
                . '</table></td></tr></table></body></html>';
            $plain = $title . "\n" . t('Website report – %s', $monthName) . ' · ' . $siteName . ' (' . $siteUrl . ")\n\n" . implode("\n\n", $text) . "\n\n"
                . ($agency !== '' && $plainContacts !== [] ? $agency . ' · ' . implode(' · ', $plainContacts) . "\n" : '') . $footerNote . "\n";

            return ['subject' => $subject, 'html' => $document, 'text' => $plain];
        }, 'admin-');
    }

    /**
     * The whole e-mail for a month: the archived month (#161, Core\ReportArchive) when it is stored, so the e-mail and the printed
     * report say the same; otherwise built on the spot, inside the e-mail's language too, so the health texts match.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function compose(App $app, \DateTimeImmutable $monthStart): array
    {
        $s = $app->settings();

        return Language::runWith(Language::defaults($s), fn (): array => self::render(ReportArchive::get($app->db(), $monthStart->format('Y-m'))['data'] ?? self::build($app, $monthStart), $s, self::siteUrl($app)), 'admin-');
    }

    /**
     * Where the report goes: report_recipients (comma or line separated), otherwise the site e-mail.
     *
     * @return list<string>
     */
    public static function recipients(Settings $s): array
    {
        $list = array_values(array_filter(array_unique(preg_split('/[\s,;]+/', $s->get('report_recipients')) ?: []),
            static fn (string $e): bool => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if ($list === [] && $s->get('site_email') !== '') {
            $list = [$s->get('site_email')];
        }

        return array_slice($list, 0, self::MAX_RECIPIENTS);
    }

    /** Sends the report for a month to every recipient (Mail::send, with its queue). Returns how many recipients. */
    public static function send(App $app, \DateTimeImmutable $monthStart): int
    {
        $s = $app->settings();
        $recipients = self::recipients($s);
        if ($recipients === []) {
            return 0;
        }
        $mail = self::compose($app, $monthStart);
        foreach ($recipients as $recipient) {
            Mail::send($s, $recipient, $mail['subject'], $mail['text'], $mail['html']);
        }

        return count($recipients);
    }

    /** Sends the report, remembers the month and records the event – the job and the "send now" button share this, so a month never goes twice. */
    public static function sendAndRecord(App $app, \DateTimeImmutable $monthStart): int
    {
        $sent = self::send($app, $monthStart);
        if ($sent > 0) {
            $month = $monthStart->format('Y-m');
            if ($month > $app->settings()->get('report_last_month')) {
                $app->settings()->set('report_last_month', $month); // an older month sent again from the archive never makes the job send the last one twice
            }
            Events::record($app->db(), 'report.sent', 'info', t('The monthly report for %s went to %d recipient(s).', $month, $sent), ['month' => $month, 'recipients' => $sent]);
        }

        return $sent;
    }

    /** One run of the job (Core\Scheduler): sends the previous month's report when it is on and not sent yet. */
    public static function runIfDue(App $app): string
    {
        $s = $app->settings();
        if (!$s->bool('report_monthly') || Demo::active()) {
            return 'off';
        }
        if (AllSitesReport::coveredBySiteOne($app)) {
            return 'in the report of site 1';
        }
        $month = self::previousMonth(new \DateTimeImmutable());
        if ($s->get('report_last_month') === $month->format('Y-m')) {
            return 'sent already';
        }
        $sent = self::sendAndRecord($app, $month);

        return $sent === 0 ? 'no address' : 'sent to ' . $sent;
    }

    /** The site address for links and the logo: the setting, not the Host header (cron may call from anywhere). */
    private static function siteUrl(App $app): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . rtrim($app->url(''), '/');
    }
}
