<?php

declare(strict_types=1);

namespace Talea\Fleet;

use Talea\Core\Alerts;
use Talea\Core\App;
use Talea\Core\Events;
use Talea\Core\Language;
use Talea\Core\Mail;

/**
 * When a site's TLS certificate (seen by the uptime check, Fleet\Http::probe) and its domain registration (typed on the site's
 * page) end (#341). The calendar lists the dates; the background job `fleet_expiry` sends ONE e-mail with every reminder that
 * is due, once per kind, date and threshold (certificates 14 and 7 days before, domains 30 and 7) – what was sent is kept in
 * fleet_sites.reminders, so a renewed date starts afresh. The bell shows the dates inside their window (Core\PendingReview).
 * Pure apart from remind().
 */
final class Expiry
{
    public const array CERTIFICATE_DAYS = [14, 7];
    public const array DOMAIN_DAYS = [30, 7];

    /** kind => the column of tl_fleet_sites */
    public const array KINDS = ['certificate' => 'cert_expires', 'domain' => 'domain_expires'];

    /** How long a sent reminder is remembered after its date: a site behind several servers may show the dates of two certificates in turn, and each is reminded once. */
    public const int REMEMBER_DAYS = 30;

    /** Whole days from the day of $now to the day of $date (negative = past). DST-safe: calendar days, not seconds. */
    public static function daysLeft(string $date, int $now): int
    {
        $from = new \DateTimeImmutable(date('Y-m-d', $now));
        $to = new \DateTimeImmutable(substr($date, 0, 10));
        $diff = $from->diff($to);

        return $diff->invert === 1 ? -$diff->days : $diff->days;
    }

    /** @return list<int> the thresholds of a kind, widest first */
    public static function days(string $kind): array
    {
        return $kind === 'certificate' ? self::CERTIFICATE_DAYS : self::DOMAIN_DAYS;
    }

    /** 'warning' inside the widest threshold, 'error' inside the narrowest (a past date too); null when the date is further away. */
    public static function severity(string $kind, string $date, int $now): ?string
    {
        $days = self::days($kind);
        $left = self::daysLeft($date, $now);
        if ($left > max($days)) {
            return null;
        }

        return $left <= min($days) ? 'error' : 'warning';
    }

    /**
     * Every date of the fleet, nearest first.
     *
     * @param list<array<string, mixed>> $sites rows of tl_fleet_sites
     * @return list<array{site: array<string, mixed>, kind: string, date: string, days: int}>
     */
    public static function dates(array $sites, int $now): array
    {
        $out = [];
        foreach ($sites as $site) {
            foreach (self::KINDS as $kind => $column) {
                if (($site[$column] ?? null) !== null && $site[$column] !== '') {
                    $date = substr((string) $site[$column], 0, 10);
                    $out[] = ['site' => $site, 'kind' => $kind, 'date' => $date, 'days' => self::daysLeft($date, $now)];
                }
            }
        }
        usort($out, fn (array $a, array $b): int => [$a['date'], (string) $a['site']['name']] <=> [$b['date'], (string) $b['site']['name']]);

        return $out;
    }

    /**
     * The reminders of one site that are due now, and the keys of the reminders sent so far (forgotten 30 days after their date).
     * A threshold crossed counts as sent: a date first seen at 5 days gets one mail, not one per threshold.
     *
     * @param array<string, mixed> $site a row of tl_fleet_sites
     * @param list<string> $muted kinds whose state a person accepted (Fleet\Today): no reminder, nothing marked as sent
     * @return array{0: list<array{kind: string, date: string, days: int}>, 1: list<string>}
     */
    public static function due(array $site, int $now, array $muted = []): array
    {
        $sent = json_decode((string) ($site['reminders'] ?? ''), true);
        $sent = is_array($sent) ? array_values(array_filter($sent, 'is_string')) : [];
        $current = [];
        $reminders = [];
        foreach (self::KINDS as $kind => $column) {
            if (($site[$column] ?? null) === null || $site[$column] === '') {
                continue;
            }
            $date = substr((string) $site[$column], 0, 10);
            $current[$kind] = $date;
            if (in_array($kind, $muted, true)) {
                continue;
            }
            $days = self::daysLeft($date, $now);
            $crossed = array_filter(self::days($kind), fn (int $t): bool => $days <= $t && !in_array("$kind|$date|$t", $sent, true));
            if ($crossed !== []) {
                $reminders[] = ['kind' => $kind, 'date' => $date, 'days' => $days];
                foreach ($crossed as $t) {
                    $sent[] = "$kind|$date|$t";
                }
            }
        }
        $oldest = date('Y-m-d', $now - self::REMEMBER_DAYS * 86400);
        $keys = array_values(array_unique(array_filter($sent, function (string $key) use ($current, $oldest): bool {
            [$kind, $date] = explode('|', $key) + ['', ''];

            return ($current[$kind] ?? null) === $date || $date >= $oldest;
        })));

        return [$reminders, $keys];
    }

    /**
     * A month for the calendar, Monday first: weeks of seven cells, a cell outside the month has no day. An invalid month is the current one.
     *
     * @param list<array{site: array<string, mixed>, kind: string, date: string, days: int}> $dates
     * @return array{year: int, month: int, previous: string, next: string, weeks: list<list<array{day: ?string, entries: list<array<string, mixed>>}>>}
     */
    public static function month(string $yearMonth, array $dates): array
    {
        $first = preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 1970 && (int) $m[1] <= 2200
            ? new \DateTimeImmutable($yearMonth . '-01') : new \DateTimeImmutable(date('Y-m-01'));
        $byDay = [];
        foreach ($dates as $d) {
            $byDay[$d['date']][] = $d;
        }
        $lead = (int) $first->format('N') - 1;
        $length = (int) $first->format('t');
        $cells = array_fill(0, $lead, ['day' => null, 'entries' => []]);
        for ($day = 1; $day <= $length; $day++) {
            $iso = $first->format('Y-m-') . sprintf('%02d', $day);
            $cells[] = ['day' => $iso, 'entries' => $byDay[$iso] ?? []];
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = ['day' => null, 'entries' => []];
        }

        return ['year' => (int) $first->format('Y'), 'month' => (int) $first->format('n'), 'previous' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'),
            'weeks' => array_chunk($cells, 7)];
    }

    /**
     * The background job: one e-mail with every reminder that is due on the whole fleet. The reminders count as sent whether or not
     * the alert e-mail is on (switching alerts on later must not flood); the e-mail goes only when alerts are on and the fleet kind is chosen.
     */
    public static function remind(App $app): string
    {
        $s = $app->settings();
        $db = $app->db();
        $now = time();
        $lines = [];
        foreach (Console::overview($db, $now) as $site) {
            [$reminders, $keys] = self::due($site, $now, self::muted($site));
            if ($keys !== self::sent($site)) {
                $db->update('fleet_sites', ['reminders' => $keys === [] ? null : json_encode($keys)], ['id' => (int) $site['id']]);
            }
            foreach ($reminders as $r) {
                $name = (string) $site['name'] !== '' ? (string) $site['name'] : (string) $site['url'];
                Events::record($db, 'fleet.expiry_reminder', 'info', t('%s: the %s ends on %s.', (string) $site['url'], $r['kind'] === 'certificate' ? t('certificate') : t('domain registration'), $r['date']),
                    ['site' => (int) $site['id'], 'kind' => $r['kind'], 'date' => $r['date']]);
                $lines[] = [$name, (string) $site['url'], $r];
            }
        }
        if ($lines === []) {
            return 'nothing due';
        }
        $recipient = Alerts::recipient($s);
        if (!$s->bool('alerts_enabled') || !in_array('fleet', explode(',', $s->get('alerts_kinds')), true)) {
            return 'reminded ' . count($lines) . ' (alerts off)';
        }
        if ($recipient === '') {
            return 'no address';
        }
        [$subject, $text] = Language::runWith(Language::defaults($s), function () use ($s, $lines): array {
            $rows = array_map(fn (array $l): string => '– ' . $l[0] . ' (' . $l[1] . '): ' . ($l[2]['kind'] === 'certificate'
                ? ($l[2]['days'] >= 0 ? t('the certificate ends on %s – in %d days', $l[2]['date'], $l[2]['days']) : t('the certificate ended on %s', $l[2]['date']))
                : ($l[2]['days'] >= 0 ? t('the domain registration ends on %s – in %d days', $l[2]['date'], $l[2]['days']) : t('the domain registration ended on %s', $l[2]['date']))), $lines);

            return [t('%s: certificates and domains that expire soon', $s->get('site_name')),
                implode("\n", $rows) . "\n\n" . t('The calendar of the fleet console:') . ' ' . rtrim($s->get('site_url')) . '/admin.php?module=fleet&action=calendar'];
        }, 'admin-');
        Mail::send($s, $recipient, $subject, $text);

        return 'reminded ' . count($lines);
    }

    /**
     * What the bell shows: one "name: date" line per site and kind whose date is inside its window.
     *
     * @param list<array<string, mixed>> $overview Console::overview() rows
     * @return list<string>
     */
    public static function soon(array $overview, int $now): array
    {
        $out = [];
        foreach ($overview as $site) {
            foreach (self::KINDS as $kind => $column) {
                if (($site[$column] ?? null) !== null && $site[$column] !== '' && self::severity($kind, (string) $site[$column], $now) !== null && !in_array($kind, self::muted($site), true)) {
                    $out[] = ((string) $site['name'] !== '' ? (string) $site['name'] : (string) $site['url']) . ': ' . substr((string) $site[$column], 0, 10);
                }
            }
        }

        return $out;
    }

    /** @return list<string> the keys of reminders already sent */
    private static function sent(array $site): array
    {
        $sent = json_decode((string) ($site['reminders'] ?? ''), true);

        return is_array($sent) ? array_values(array_filter($sent, 'is_string')) : [];
    }

    /** @return list<string> the kinds whose state a person accepted on the console (the items of Console::attention): an accepted date mutes its reminders until the state changes */
    private static function muted(array $site): array
    {
        $muted = [];
        foreach ((array) ($site['items'] ?? []) as $item) {
            if (isset(self::KINDS[$item['key'] ?? '']) && ($item['accepted'] ?? null) !== null) {
                $muted[] = (string) $item['key'];
            }
        }

        return $muted;
    }
}
