<?php

declare(strict_types=1);

namespace Talea\Fleet;

/**
 * The console's Today screen (#341): what needs doing on the fleet, as ITEMS – one check of one site (a site that does not answer,
 * a Health problem the site reports, certificates ending soon…) – sorted into six GROUPS: uptime, errors and jobs, security, backups,
 * certificates and domains, enquiries. A group with an incident (an open item of severity error) comes first and stays open; the others
 * fold when they are long.
 *
 * A person can ACCEPT an item: the state is known and fine for now. The acceptance is kept on the console only
 * (fleet_sites.accepted, item key => {state, note, until, by, at}); it holds until its last day and as long as the item's state
 * is the stored one – the state carries the severity (and the date of a domain registration), so a worse or otherwise changed state brings the
 * item back at once. What an accepted item no longer does: it is not an open reason, not in the score, its expiry reminders are muted,
 * and the bell leaves it out. Pure.
 */
final class Today
{
    /** group => label (translated where shown) */
    public const array GROUPS = ['uptime' => 'Uptime', 'errors' => 'Errors and jobs', 'security' => 'Security', 'backups' => 'Backups', 'certificates' => 'Certificates and domains', 'enquiries' => 'Enquiries'];

    public const int FOLD_AFTER = 5;
    public const int ACCEPT_DAYS = 30;
    public const int ACCEPT_MAX_DAYS = 365;
    public const int NOTE_MAX = 300;

    /** The groups and checks of the site's own Health (Core\Health), as the heartbeat writes them – in English, whatever the site's language. */
    private const array SECURITY_GROUPS = ['Security', 'Accounts and access'];
    private const array BACKUP_CHECKS = ['Database backup', 'Off-site backups'];

    /**
     * @param array<string, mixed> $site a row of tl_fleet_sites
     * @param list<string> $reasons the reasons for attention (Console::attention), before anything is accepted
     * @param array<string, mixed> $beat the decoded heartbeat
     * @return list<array{key: string, reason: string, group: string, severity: string, title: string, detail: string, state: string}>
     */
    public static function items(array $site, array $reasons, array $beat, int $now): array
    {
        $items = [];
        $add = function (string $key, string $reason, string $group, string $severity, string $title, string $detail, string $state) use (&$items): void {
            $items[$key] ??= ['key' => $key, 'reason' => $reason, 'group' => $group, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'state' => $state];
        };
        foreach ($reasons as $reason) {
            switch ($reason) {
                case 'down':
                    $add('down', 'down', 'uptime', 'error', 'does not answer', (int) ($site['up_status'] ?? 0) > 0 ? 'HTTP ' . (int) $site['up_status'] : 'no answer', 'down');
                    break;
                case 'not_reporting':
                    $add('not_reporting', 'not_reporting', 'uptime', 'error', 'stopped reporting', (string) ($site['last_seen'] ?? ''), 'not_reporting');
                    break;
                case 'no_heartbeat':
                    $add('no_heartbeat', 'no_heartbeat', 'uptime', 'warning', 'no report yet', '', 'no_heartbeat');
                    break;
                case 'errors':
                case 'warnings':
                    $status = $reason === 'errors' ? 'error' : 'warning';
                    $found = false;
                    foreach ((array) ($beat['problems'] ?? []) as $problem) {
                        if (!is_array($problem) || (string) ($problem['check'] ?? '') === '') {
                            continue;
                        }
                        $problemStatus = ($problem['status'] ?? '') === 'error' ? 'error' : 'warning';
                        $found = $found || $problemStatus === $status;
                    }
                    if (!$found) {
                        $add('health', $reason, 'errors', $status, $reason, '', $status); // the site's list of problems is cut off or empty
                    }
                    break;
                case 'jobs_failing':
                    $names = array_values(array_filter((array) ($beat['jobs_failing'] ?? []), 'is_string'));
                    sort($names);
                    $add('jobs_failing', 'jobs_failing', 'errors', 'error', 'background jobs fail', implode(', ', $names), implode(',', $names));
                    break;
                case 'no_backup':
                    $add('no_backup', 'no_backup', 'backups', 'warning', 'no recent backup', !empty($beat['last_backup']) ? date('Y-m-d', (int) $beat['last_backup']) : '', 'no_backup');
                    break;
                case 'no_cron':
                    $add('no_cron', 'no_cron', 'errors', 'warning', 'cron not set up', '', 'no_cron');
                    break;
                case 'enquiries':
                    $add('enquiries', 'enquiries', 'enquiries', 'warning', 'enquiries waiting', (string) (int) ($beat['enquiries_unanswered'] ?? 0), 'enquiries');
                    break;
                case 'certificate':
                case 'domain':
                    $date = substr((string) ($site[Expiry::KINDS[$reason]] ?? ''), 0, 10);
                    $severity = Expiry::severity($reason, $date, $now) ?? 'warning';
                    $add($reason, $reason, 'certificates', $severity, $reason === 'certificate' ? 'certificate expires soon' : 'domain registration expires soon', $date, $reason === 'certificate' ? $severity : $date . '|' . $severity); // a certificate's date may alternate between servers of one site: its state is the severity
                    break;
                // the audit has no group among the six: it stays a reason on the list of all sites and on the site's page
            }
        }
        if (array_intersect(['errors', 'warnings'], $reasons) !== []) {
            foreach ((array) ($beat['problems'] ?? []) as $problem) {
                if (!is_array($problem) || (string) ($problem['check'] ?? '') === '') {
                    continue;
                }
                $status = ($problem['status'] ?? '') === 'error' ? 'error' : 'warning';
                $check = (string) $problem['check'];
                $group = in_array((string) ($problem['group'] ?? ''), self::SECURITY_GROUPS, true) ? 'security' : (in_array($check, self::BACKUP_CHECKS, true) ? 'backups' : 'errors');
                $add('check:' . mb_substr($check, 0, 60), $status === 'error' ? 'errors' : 'warnings', $group, $status, $check, (string) ($problem['detail'] ?? ''), $status);
            }
        }

        return array_values($items);
    }

    /** @param array<string, mixed> $site @return array<string, array<string, mixed>> the acceptances stored on the row */
    public static function decode(array $site): array
    {
        $accepted = json_decode((string) ($site['accepted'] ?? ''), true);

        return is_array($accepted) ? array_filter($accepted, 'is_array') : [];
    }

    /** Is the acceptance still in force for this item: not past its last day, and the item is in the state that was accepted? */
    public static function holds(?array $acceptance, array $item, int $now): bool
    {
        return is_array($acceptance) && (string) ($acceptance['until'] ?? '') >= date('Y-m-d', $now) && (string) ($acceptance['state'] ?? '') === (string) $item['state'];
    }

    /**
     * The acceptances with this item accepted.
     *
     * @param array<string, array<string, mixed>> $accepted
     * @param array{key: string, state: string} $item
     * @return array<string, array<string, mixed>>
     * @throws \DomainException with an English sentence (translate it where it is shown)
     */
    public static function accept(array $accepted, array $item, string $note, string $until, string $by, int $now): array
    {
        $note = mb_substr(trim($note), 0, self::NOTE_MAX);
        if ($note === '') {
            throw new \DomainException('Write a short note why this state is accepted.');
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $until) : false;
        $today = new \DateTimeImmutable(date('Y-m-d', $now));
        if ($date === false || $date->format('Y-m-d') !== $until || $date <= $today || $date > $today->modify('+' . self::ACCEPT_MAX_DAYS . ' days')) {
            throw new \DomainException('Choose a last day between tomorrow and one year from today.');
        }
        $accepted[$item['key']] = ['state' => $item['state'], 'note' => $note, 'until' => $until, 'by' => mb_substr($by, 0, 100), 'at' => date('Y-m-d H:i:s', $now)];

        return $accepted;
    }

    /**
     * Only the acceptances that still hold: a state that changed or whose last day passed is dropped, so it cannot come back
     * when the old state returns.
     *
     * @param array<string, array<string, mixed>> $accepted
     * @param list<array<string, mixed>> $items the site's current items (Console::attention)
     * @return array<string, array<string, mixed>>
     */
    public static function prune(array $accepted, array $items, int $now): array
    {
        $byKey = array_column($items, null, 'key');

        return array_filter($accepted, fn (array $a, string|int $key): bool => isset($byKey[$key]) && self::holds($a, $byKey[$key], $now), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * How many sites have at least one open (not accepted) item: the number on the Today tab, so it agrees with what Today lists.
     *
     * @param list<array<string, mixed>> $overview Console::overview() rows (with items)
     */
    public static function needing(array $overview): int
    {
        return count(array_filter($overview, fn (array $site): bool => array_filter((array) ($site['items'] ?? []), fn (array $item): bool => $item['accepted'] === null) !== []));
    }

    /**
     * The Today screen: groups with an incident first, then the rest, then what a person accepted.
     *
     * @param list<array<string, mixed>> $overview Console::overview() rows (with items)
     * @return list<array{key: string, label: string, incident: bool, open: bool, rows: list<array{site: array<string, mixed>, item: array<string, mixed>}>}>
     */
    public static function groups(array $overview): array
    {
        $rows = array_fill_keys(array_keys(self::GROUPS), []);
        $accepted = [];
        foreach ($overview as $site) {
            foreach ((array) ($site['items'] ?? []) as $item) {
                if ($item['accepted'] !== null) {
                    $accepted[] = ['site' => $site, 'item' => $item];
                } else {
                    $rows[$item['group']][] = ['site' => $site, 'item' => $item];
                }
            }
        }
        $order = fn (array $a, array $b): int => [$a['item']['severity'] === 'error' ? 0 : 1, -(int) $a['site']['score'], (string) $a['site']['name']] <=> [$b['item']['severity'] === 'error' ? 0 : 1, -(int) $b['site']['score'], (string) $b['site']['name']];
        $groups = [];
        foreach ($rows as $key => $list) {
            if ($list === []) {
                continue;
            }
            usort($list, $order);
            $incident = array_filter($list, fn (array $r): bool => $r['item']['severity'] === 'error') !== [];
            $groups[] = ['key' => $key, 'label' => self::GROUPS[$key], 'incident' => $incident, 'open' => $incident || count($list) <= self::FOLD_AFTER, 'rows' => $list];
        }
        usort($groups, fn (array $a, array $b): int => (int) $b['incident'] <=> (int) $a['incident']); // stable: the order of GROUPS inside each half
        if ($accepted !== []) {
            usort($accepted, $order);
            $groups[] = ['key' => 'accepted', 'label' => 'Accepted', 'incident' => false, 'open' => false, 'rows' => $accepted];
        }

        return $groups;
    }
}
