<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What happened on the site (2.8): one table of events that the alert e-mails, the monthly report, the heartbeat and Claude
 * (list_events, get_health) read. Recording an event never breaks what caused it.
 *
 * Events carry ids, counts and short messages – never personal data (an enquiry event names the form and the page, not
 * the sender). They are kept for KEEP_DAYS.
 */
final class Events
{
    /** Known types => what they mean (for Claude and the documentation). Other code may add types; these are the stable ones. */
    public const array TYPES = [
        'enquiry.received' => 'A form on the site was sent.',
        'connector.connected' => 'An outside service was connected.',
        'connector.failed' => 'A delivery to an outside service failed after all retries.',
        'testimonial.received' => 'A customer sent a testimonial (a hidden draft reference).',
        'build.published' => 'A page, a site part, a collection template or a pop-up was published.',
        'look.published' => 'The draft look (design system, classes, menus) was published.',
        'backup.created' => 'An automatic database backup was made.',
        'backup.failed' => 'A database backup or its off-site copy failed.',
        'update.applied' => 'A new version of Kaleta was installed.',
        'update.failed' => 'Installing an update failed; the site stayed on its version.',
        'update.rolled_back' => 'An update was installed but the site did not work afterwards, so it went back to the previous version.',
        'mail.failed' => 'An e-mail could not be sent after all attempts.',
        'webhook.failed' => 'A webhook call could not be delivered after all attempts.',
        'notfound.spike' => 'An address without a page is being requested often.',
        'task.failed' => 'A background job failed several times in a row.',
        'task.recovered' => 'A background job works again.',
        'security.account_suspended' => 'An unused account was suspended.',
        'security.connection_revoked' => 'An unused Claude connection was revoked.',
        'firewall.blocked' => 'An address was blocked for a while (it probed for other systems).',
        'fact.changed' => 'The value of a business fact changed (the old sentences that still state it: Facts → the fact).',
        'fleet.paired' => 'This site was paired with a fleet console.',
        'fleet.site_paired' => 'Console: a site was paired.',
        'fleet.site_removed' => 'Console: a site was removed or ended the pairing.',
        'fleet.site_down' => 'Console: a site does not answer.',
        'fleet.site_up' => 'Console: a site answers again.',
        'fleet.site_silent' => 'Console: a site stopped sending its heartbeat.',
        'fleet.site_updated' => 'Console: a site runs a new version.',
        'report.sent' => 'The monthly report by e-mail went out (the month and how many recipients).',
        'content.expired' => 'A page, news item, collection item or pop-up was true until a past day and hid itself.',
        'content.review' => 'A page, news item, collection item or pop-up asks for a review (its review-by day has come).',
        'applications.purged' => 'Job applications past their retention period were deleted, including the CVs (the count only).',
        'links.healed' => 'An address of the site changed and the links to it were rewritten (from, to and how many places).',
        'personal_data.erased' => 'Everything about one e-mail address was erased on request (the counts only, never the address).',
    ];

    public const array SEVERITIES = ['info', 'warning', 'error'];

    public const int KEEP_DAYS = 180;

    /**
     * Records an event. Returns its id, 0 when it could not be written (the cause of the event must not fail because of it).
     *
     * @param array<string, scalar|null|array<mixed>> $data ids and counts, never personal data
     */
    public static function record(Db $db, string $type, string $severity, string $message, array $data = []): int
    {
        try {
            return $db->insert('events', [
                'created_at' => date('Y-m-d H:i:s'),
                'type' => mb_substr($type, 0, 40),
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
                'message' => mb_substr($message, 0, 255),
                'data' => $data === [] ? null : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Events after an id (a cursor), oldest first.
     *
     * @param list<string> $types type names or prefixes ending with a dot ("backup.")
     * @return list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}>
     */
    public static function since(Db $db, int $afterId, array $types = [], int $limit = 100, string $minSeverity = 'info'): array
    {
        $where = ['id > ?'];
        $params = [$afterId];
        $or = [];
        foreach ($types as $type) {
            if (str_ends_with($type, '.')) {
                $or[] = 'type LIKE ?';
                $params[] = addcslashes($type, '%_\\') . '%';
            } else {
                $or[] = 'type = ?';
                $params[] = $type;
            }
        }
        if ($or !== []) {
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $levels = array_slice(self::SEVERITIES, (int) array_search($minSeverity, self::SEVERITIES, true));
        $where[] = 'severity IN (' . implode(', ', array_fill(0, count($levels), '?')) . ')';
        array_push($params, ...$levels);

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'created_at' => (string) $r['created_at'], 'type' => (string) $r['type'],
            'severity' => (string) $r['severity'], 'message' => (string) $r['message'], 'data' => json_decode((string) $r['data'], true) ?: []],
            $db->all('SELECT id, created_at, type, severity, message, data FROM {events} WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . max(1, min(500, $limit)), $params));
    }

    /** The id of the newest event (a starting cursor). */
    public static function lastId(Db $db): int
    {
        return (int) $db->value('SELECT MAX(id) FROM {events}');
    }

    /**
     * Counts of warnings and errors of the last hours, by type – for the health overview.
     *
     * @return array<string, int>
     */
    public static function problems(Db $db, int $hours = 168): array
    {
        $out = [];
        foreach ($db->all("SELECT type, COUNT(*) AS n FROM {events} WHERE severity IN ('warning', 'error') AND created_at > NOW() - INTERVAL ? HOUR GROUP BY type ORDER BY n DESC", [$hours]) as $r) {
            $out[(string) $r['type']] = (int) $r['n'];
        }

        return $out;
    }

    public static function prune(Db $db): void
    {
        $db->run('DELETE FROM {events} WHERE created_at < NOW() - INTERVAL ? DAY', [self::KEEP_DAYS]);
    }
}
