<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The printable monthly report (#161, Settings → Reports): one archived month (Core\ReportArchive) as a standalone A4 page the
 * browser prints or saves as PDF, and the same HTML as a file to download. With the agency's branding (agency_* settings) when
 * it is set, otherwise the site's name. A summary of what happened to the site – no hours, no work log, nothing about money.
 *
 * sections() turns the stored data into titled blocks (facts, pairs, tables, lists, notes) in the language set when called –
 * no database, so it is tested on its own; views/admin/settings/report_print.php draws them with print CSS (A4, page numbers,
 * no table row cut across a page). Written in the site's default language with the admin dictionary, like the e-mail.
 */
final class ReportPage
{
    /** What waits at the end of the month (MonthlyReport 'waiting', the kinds of Core\PendingReview) => label. */
    private const array WAITING = ['requests_done' => 'Requests Claude finished – review the drafts', 'page_drafts' => 'Pages with unpublished changes', 'draft_comments' => 'Unresolved comments on drafts',
        'news_drafts' => 'News drafts to publish', 'hidden_items' => 'Hidden collection items changed in the last 30 days', 'proposed_hours' => 'Proposed exceptions to the opening hours',
        'part_drafts' => 'Site parts with unpublished changes', 'look_draft' => 'Unpublished changes of the look'];

    /** Security events (MonthlyReport::SECURITY_EVENTS) => label. */
    private const array SECURITY = ['firewall.blocked' => 'Addresses blocked for a while by the firewall', 'security.account_suspended' => 'Unused accounts suspended',
        'security.connection_revoked' => 'Unused Claude connections revoked'];

    /**
     * The whole page as an HTML document.
     *
     * @param array{month: string, created_at: string, data: array<string, mixed>} $stored from ReportArchive::get
     * @param string $script the Print button's script (image/print.js) on screen; '' in the downloaded file
     */
    public static function render(App $app, array $stored, string $script = ''): string
    {
        $s = $app->settings();
        $siteUrl = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . rtrim($app->url(''), '/');

        return Language::runWith(Language::defaults($s), fn (): string => $app->view->render('admin/settings/report_print', self::viewData($stored, $s, $siteUrl) + ['script' => $script]), 'admin-');
    }

    /**
     * What the template prints, in the language set when called.
     *
     * @param array{month: string, created_at: string, data: array<string, mixed>} $stored
     * @return array<string, mixed>
     */
    public static function viewData(array $stored, Settings $s, string $siteUrl): array
    {
        $siteUrl = rtrim($siteUrl, '/');
        $agency = $s->get('agency_name');
        $logo = $s->get('agency_logo');
        $month = self::monthName($stored['month']);

        return [
            'language' => Language::code(),
            'title' => t('Website report – %s – %s', $month, $s->get('site_name')),
            'monthName' => $month,
            'siteName' => $s->get('site_name'),
            'siteAddress' => (string) preg_replace('#^https?://#', '', $siteUrl),
            'agency' => $agency,
            'agencyLogo' => $agency !== '' && $logo !== '' ? $siteUrl . '/' . ltrim($logo, '/') : '',
            'agencyContacts' => $agency === '' ? [] : array_values(array_filter([(string) preg_replace('#^https?://#', '', $s->get('agency_url')), $s->get('agency_email'), $s->get('agency_phone')])),
            'generated' => format_date($stored['created_at'], true),
            'sections' => self::sections($stored['data']),
        ];
    }

    /** "September 2026" in the current language. */
    public static function monthName(string $month): string
    {
        return t(MonthlyReport::MONTHS[(int) substr($month, 5, 2)] ?? 'January') . ' ' . substr($month, 0, 4);
    }

    /**
     * The report as sections of blocks; texts in the current language, every string from the data scrubbed again.
     *
     * @param array<string, mixed> $data from MonthlyReport::build (archived)
     * @return list<array{key: string, title: string, blocks: list<array<string, mixed>>}>
     */
    public static function sections(array $data): array
    {
        $data = MonthlyReport::scrub($data);
        $n = static fn (mixed $value): string => format_number((int) $value, 0);
        $date = static fn (mixed $value, bool $time = false): string => is_string($value) && $value !== '' ? format_date($value, $time) : '';
        $pairs = static fn (array $rows): ?array => $rows === [] ? null : ['type' => 'pairs', 'rows' => $rows];
        // the columns listed in $numeric align right; the rest wraps at word boundaries
        $table = static fn (string $caption, array $head, array $rows, array $numeric = []): ?array => $rows === [] ? null
            : ['type' => 'table', 'caption' => $caption, 'head' => $head, 'rows' => $rows, 'numeric' => $numeric];
        $note = static fn (string $text): array => ['type' => 'note', 'text' => $text];
        $more = static fn (int $total, int $shown): ?array => $total > $shown ? ['type' => 'note', 'text' => t('… and %d more.', $total - $shown)] : null;
        $change = static function (int $now, int $before) use ($n): string {
            if ($before === 0) {
                return $n($now);
            }
            $percent = (int) round(100 * ($now - $before) / $before);

            return $n($now) . ' (' . ($percent >= 0 ? '+' : '−') . abs($percent) . ' %)';
        };
        $array = static fn (string $key): ?array => is_array($data[$key] ?? null) ? $data[$key] : null;
        $out = [];
        $add = static function (string $key, string $title, array $blocks) use (&$out): void {
            $blocks = array_values(array_filter($blocks));
            if ($blocks !== []) {
                $out[] = ['key' => $key, 'title' => $title, 'blocks' => $blocks];
            }
        };
        [$stats, $enquiries, $requests, $published, $claude] = [$array('stats'), $array('enquiries'), $array('requests'), $array('published'), $array('claude')];
        $changes = (array) ($data['changes'] ?? []);
        $backups = (array) ($data['backups'] ?? []);
        $problems = (array) ($data['problems'] ?? []);

        // at a glance: the few numbers a client reads first
        $facts = [];
        if ($stats !== null) {
            $facts[] = [t('Visits'), $n($stats['visits'] ?? 0)];
        }
        if ($enquiries !== null) {
            $facts[] = [t('Enquiries received'), $n($enquiries['total'] ?? 0)];
        }
        if ($requests !== null) {
            $facts[] = [t('Requests handled'), $n($requests['done_count'] ?? 0)];
        }
        if ($published !== null) {
            $facts[] = [t('Pages published'), $n($published['pages_count'] ?? 0)];
        }
        $facts[] = [t('Changes to the site'), $n((int) ($changes['people'] ?? 0) + (int) ($changes['claude'] ?? 0))];
        $facts[] = [t('Backups made'), $n($backups['created'] ?? 0)];
        $facts[] = [t('Problems right now'), $n(count($problems))];
        $add('summary', t('At a glance'), [['type' => 'facts', 'items' => $facts]]);

        // what waits at the end of the month
        $rows = [];
        foreach (self::WAITING as $kind => $label) {
            if ((int) ($data['waiting'][$kind] ?? 0) > 0) {
                $rows[] = [t($label), $kind === 'look_draft' ? t('Yes') : $n($data['waiting'][$kind])];
            }
        }
        if ((int) ($data['decisions']['enquiries'] ?? 0) > 0) {
            $rows[] = [t('Enquiries nobody has opened yet'), $n($data['decisions']['enquiries'])];
        }
        if ((int) ($data['decisions']['errors'] ?? 0) > 0) {
            $rows[] = [t('Problems in System status that need fixing'), $n($data['decisions']['errors'])];
        }
        $add('waiting', t('Waiting at the end of the month'), [$pairs($rows) ?? $note(t('Nothing was waiting.'))]);

        // requests to Claude
        if ($requests !== null) {
            $status = static fn (string $s): string => t(Requests::STATUSES[$s] ?? $s);
            $add('requests', t('Requests to Claude'), [
                $pairs([[t('Written this month'), $n($requests['created'] ?? 0)], [t('Handled this month'), $n($requests['done_count'] ?? 0)], [t('Still open'), $n($requests['open_count'] ?? 0)]]),
                $table(t('Handled this month'), [t('Request'), t('Status'), t('Written'), t('Done')], array_map(static fn (array $r): array => [(string) $r['title'], $status((string) $r['status']), $date($r['created']), $date($r['done'])], (array) ($requests['done'] ?? []))),
                $more((int) ($requests['done_count'] ?? 0), count((array) ($requests['done'] ?? []))),
                $table(t('Still open'), [t('Request'), t('Status'), t('Written')], array_map(static fn (array $r): array => [(string) $r['title'], $status((string) $r['status']), $date($r['created'])], (array) ($requests['open'] ?? []))),
                $more((int) ($requests['open_count'] ?? 0), count((array) ($requests['open'] ?? []))),
            ]);
        }

        // changes by people and by Claude, and what was published
        $pages = (array) ($published['pages'] ?? []);
        $news = (array) ($published['news'] ?? []);
        $add('changes', t('Changes to the site'), [
            $pairs([[t('Changes by people'), $n($changes['people'] ?? 0)], [t('Changes by Claude'), $n($changes['claude'] ?? 0)]]),
            $table(t('Pages published'), [t('Page'), t('Date'), t('Published by')], array_map(static fn (array $p): array => [(string) $p['title'], $date($p['date']), $p['by'] === 'claude' ? 'Claude' : t('a person')], $pages)),
            $more((int) ($published['pages_count'] ?? 0), count($pages)),
            $table(t('News published'), [t('Title'), t('Date')], array_map(static fn (array $p): array => [(string) $p['title'], $date($p['date'])], $news)),
            $more((int) ($published['news_count'] ?? 0), count($news)),
        ]);

        // Claude: sessions and scheduled runs, counts only
        if ($claude !== null && ((int) ($claude['sessions'] ?? 0) > 0 || (int) ($claude['runs'] ?? 0) > 0)) {
            $rows = [[t('Claude sessions'), $n($claude['sessions'] ?? 0)], [t('Changes made in them'), $n($claude['calls'] ?? 0)]];
            if ((int) ($claude['undone'] ?? 0) > 0) {
                $rows[] = [t('Sessions undone'), $n($claude['undone'])];
            }
            $rows[] = [t('Scheduled Claude runs'), $n($claude['runs'] ?? 0)];
            foreach ((array) ($claude['run_statuses'] ?? []) as $runStatus => $count) {
                $rows[] = [t(AgentSchedules::STATUSES[$runStatus] ?? (string) $runStatus), $n($count), true]; // indented under the runs
            }
            $add('claude', t('Claude sessions and scheduled runs'), [
                $pairs($rows),
                $table(t('Scheduled runs by task'), [t('Task'), t('Scheduled runs')], array_map(static fn (string $task, mixed $count): array => [t(AgentSchedules::TASKS[$task][0] ?? 'Custom instructions'), $n($count)],
                    array_keys((array) ($claude['run_tasks'] ?? [])), (array) ($claude['run_tasks'] ?? [])), [1]),
            ]);
        }

        // traffic and conversions
        if ($stats !== null) {
            $rows = [[t('Visits'), $change((int) ($stats['visits'] ?? 0), (int) ($stats['previous_visits'] ?? 0))], [t('Page views'), $change((int) ($stats['views'] ?? 0), (int) ($stats['previous_views'] ?? 0))]];
            foreach (['calls' => 'Calls – clicks on a phone number', 'emails' => 'E-mails – clicks on an e-mail address', 'whatsapp' => 'WhatsApp – clicks on a WhatsApp link'] as $key => $label) {
                if ((int) ($stats['contact_clicks'][$key] ?? 0) > 0) {
                    $rows[] = [t($label), $n($stats['contact_clicks'][$key])];
                }
            }
            $add('traffic', t('Traffic and conversions'), [
                $pairs($rows),
                $note(t('Compared with the month before.')),
                $table(t('Most read pages'), [t('Page'), t('Views')], array_map(static fn (array $p): array => [(string) $p['path'], $n($p['n'])], (array) ($stats['pages'] ?? [])), [1]),
                $table(t('Visitors came from'), [t('Site'), t('Visits')], array_map(static fn (array $p): array => [(string) $p['site'], $n($p['n'])], (array) ($stats['sources'] ?? [])), [1]),
                $table(t('Campaigns'), [t('Campaign'), t('Visits')], array_map(static fn (array $p): array => [(string) $p['campaign'], $n($p['n'])], (array) ($stats['campaigns'] ?? [])), [1]),
                $table(t('Searches that brought visitors'), [t('Search'), t('Engine'), t('Clicks')],
                    array_map(static fn (array $q): array => [(string) $q['query'], ($q['engine'] ?? '') === 'bing' ? 'Bing' : 'Google', $n($q['clicks'] ?? 0)], (array) ($data['search'] ?? [])), [2]),
            ]);
        }

        // enquiries: counts by form, category and page – never their contents or senders
        if ($enquiries !== null) {
            $add('enquiries', t('Enquiries'), [
                $pairs([[t('Enquiries received'), $n($enquiries['total'] ?? 0)], [t('Nobody has opened yet (now)'), $n($enquiries['unanswered'] ?? 0)]]),
                $table(t('By form'), [t('Form'), t('Enquiries')], array_map(static fn (array $f): array => [(string) $f['form'] !== '' ? (string) $f['form'] : t('Form'), $n($f['n'])], (array) ($enquiries['forms'] ?? [])), [1]),
                $table(t('By category'), [t('Category'), t('Enquiries')], array_map(static fn (array $c): array => [t(Triage::CATEGORIES[$c['category']] ?? (string) $c['category']), $n($c['n'])], (array) ($enquiries['categories'] ?? [])), [1]),
                $table(t('By page'), [t('Page'), t('Enquiries')], array_map(static fn (array $p): array => [(string) $p['path'], $n($p['n'])], (array) ($enquiries['pages'] ?? [])), [1]),
            ]);
        }

        // bookings
        $bookings = $array('bookings');
        if ($bookings !== null) {
            $rows = [[t('Booked this month'), $n($bookings['total'] ?? 0)], [t('Appointments this month'), $n($bookings['appointments'] ?? 0)]];
            foreach ((array) ($bookings['statuses'] ?? []) as $bookingStatus => $count) {
                $rows[] = [t(Booking::STATUSES[$bookingStatus] ?? (string) $bookingStatus), $n($count), true];
            }
            $add('bookings', t('Bookings'), [$pairs($rows)]);
        }

        // newsletter
        $newsletters = $array('newsletters');
        if ($newsletters !== null || ($data['signups'] ?? null) !== null) {
            $add('newsletter', t('Newsletter'), [
                $pairs(array_values(array_filter([($data['signups'] ?? null) !== null ? [t('Newsletter sign-ups'), $n($data['signups'])] : null,
                    $newsletters !== null ? [t('Newsletters sent'), $n(count($newsletters))] : null]))),
                $table(t('Newsletters sent'), [t('Subject'), t('Sent'), t('Recipients')], array_map(static fn (array $c): array => [(string) $c['subject'], $date($c['date']), $n($c['sent'])], $newsletters ?? []), [2]),
            ]);
        }

        // backups
        $add('backups', t('Backups'), [$pairs(array_values(array_filter([
            [t('Backups made'), $n($backups['created'] ?? 0)],
            (int) ($backups['failed'] ?? 0) > 0 ? [t('Backups failed'), $n($backups['failed'])] : null,
            ($backups['last'] ?? null) !== null ? [t('Last backup'), $date($backups['last'], true)] : null,
        ])))]);

        // security: counts only, never an address or an account
        $security = $array('security');
        if ($security !== null) {
            $rows = [[t('Sign-ins to the administration'), $n($security['sign_ins'] ?? 0)], [t('Failed sign-ins'), $n($security['failed_sign_ins'] ?? 0)]];
            foreach (self::SECURITY as $type => $label) {
                if ((int) ($security['events'][$type] ?? 0) > 0) {
                    $rows[] = [t($label), $n($security['events'][$type])];
                }
            }
            $add('security', t('Security'), [$pairs($rows), $note(t('Counts only – no addresses, accounts or e-mail addresses.'))]);
        }

        // site audit
        $audit = $array('audit');
        if ($audit !== null) {
            $kinds = array_map('intval', (array) ($audit['kinds'] ?? []));
            $add('audit', t('Site audit'), [
                $pairs(array_values(array_filter([[t('Findings when the report was made'), $n($audit['total'] ?? 0)],
                    ($audit['fixed'] ?? null) !== null ? [t('Fewer than a month before'), $n($audit['fixed'])] : null,
                    ($audit['new'] ?? null) !== null ? [t('New since a month before'), $n($audit['new'])] : null]))),
                $kinds === [] ? $note(t('The audit found nothing to fix.')) : $table(t('Findings by kind'), [t('Kind'), t('Findings')],
                    array_map(static fn (string $kind, int $count): array => [t(Audit::KINDS[$kind] ?? $kind), $n($count)], array_keys($kinds), $kinds), [1]),
                ($audit['fixed'] ?? null) === null ? $note(t('What was fixed and what is new is compared with the previous month from the next report on.')) : null,
            ]);
        }

        // problems (System status when the report was made)
        $add('problems', t('Problems when the report was made'), [$problems === [] ? $note(t('None – every check in System status is fine.'))
            : ['type' => 'list', 'items' => array_map(static fn (array $p): string => strip_tags((string) $p['name'] . ' – ' . (string) $p['info']), $problems)]]);

        return $out;
    }
}
