<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Whether Claude is connected to the site (#154). MCP is pull-only: Claude calls the site when a person uses it or a
 * scheduled run fires, so the site never knows whether Claude is "online". What it does know:
 *
 *  - state: off (the extension "claude" is switched off), none (no connection that can call now), idle (a connection that
 *    can call, but no call in ACTIVE_DAYS, or never), active (a call within ACTIVE_DAYS);
 *  - pickup: how requests (Core\Requests) reach Claude – automatic (an active schedule of the task "requests" that runs: its
 *    last run was not missed or failed and it is not overdue by more than AgentSchedules::MISSED_HOURS), stalled (such a
 *    schedule exists but does not run), manual (no such schedule: requests are read when someone asks Claude); null while
 *    the state is off or none;
 *  - expires: the earliest end of a connection that can call; expiring when it is within EXPIRY_DAYS.
 *
 * A connection can call when its personal token or OAuth refresh token is valid and its account is not blocked and meets
 * the two-factor sign-in the site requires (Mcp\Server refuses every call of the others). The last call is the newest
 * used_at of those connections: Mcp\Server stamps it only for a call it serves, also on the connection's refresh row
 * (stamp()), and a refresh carries it over to the new pair (Front\OAuth) – the access row of an OAuth connection goes an
 * hour after it expires, so without that the last call of a connection idle for an hour was lost.
 */
final class ClaudeConnection
{
    /** A call within this many days makes the connection active, an older one (or none) idle. */
    public const int ACTIVE_DAYS = 7;

    /** A connection that ends within this many days is expiring – connect Claude again before then. */
    public const int EXPIRY_DAYS = 14;

    /** A connection's refresh row is stamped at most this often (one write a minute, not one per call). */
    public const int STAMP_SECONDS = 60;

    /** A new request that waits longer than this while nothing picks it up is highlighted (Requests). */
    public const int WAIT_HOURS = 48;

    /** @var array<int, array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string}> per Db object: the header asks on every screen */
    private static array $cache = [];

    /**
     * The status of the site's Claude connection, read once per request ($fresh = read again).
     *
     * @return array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string}
     */
    public static function status(Db $db, Settings $settings, bool $fresh = false): array
    {
        $key = spl_object_id($db);
        if ($fresh || !isset(self::$cache[$key])) {
            self::$cache[$key] = self::evaluate(self::facts($db, $settings), time());
        }

        return self::$cache[$key];
    }

    /**
     * What the status is made of, read from the database (moments as DATETIME values in the site's time).
     *
     * @return array{enabled: bool, connections: int, last_call: ?string, expires: ?string, schedules: list<array{next_due: ?string, last_status: ?string, last_at: ?string}>, waiting: int, oldest_waiting: ?string}
     */
    public static function facts(Db $db, Settings $settings): array
    {
        // live rows of accounts that can call: personal tokens and refresh tokens are the connections, access tokens count
        // for the last call only
        $live = $db->one("SELECT SUM(CASE WHEN t.kind <> 'access' THEN 1 ELSE 0 END) AS n, MIN(CASE WHEN t.kind <> 'access' THEN t.expires_at END) AS expires, MAX(t.used_at) AS last
            FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id
            WHERE " . self::canCall($settings) . " AND ((t.kind = 'token' AND t.expires_at IS NULL) OR t.expires_at > ?)", [date('Y-m-d H:i:s')]) ?? [];
        // each active schedule of the task "requests" with its last finished run
        $schedules = array_map(static fn (array $s): array => ['next_due' => $s['next_due'] !== null ? (string) $s['next_due'] : null,
            'last_status' => $s['status'] !== null ? (string) $s['status'] : null, 'last_at' => $s['at'] !== null ? (string) $s['at'] : null],
            $db->all("SELECT s.next_due, r.status, COALESCE(r.finished_at, r.started_at, r.due_at) AS at FROM {agent_schedules} s
                LEFT JOIN {agent_runs} r ON r.id = (SELECT MAX(x.id) FROM {agent_runs} x WHERE x.schedule_id = s.id AND x.status <> 'running')
                WHERE s.active = TRUE AND s.task = 'requests' ORDER BY s.id LIMIT 20"));
        $waiting = $db->one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM {requests} WHERE status = 'new'") ?? [];

        return [
            'enabled' => Extensions::isEnabled($settings, 'claude'),
            'connections' => (int) ($live['n'] ?? 0),
            'last_call' => isset($live['last']) ? (string) $live['last'] : null,
            'expires' => isset($live['expires']) ? (string) $live['expires'] : null,
            'schedules' => $schedules,
            'waiting' => (int) ($waiting['n'] ?? 0),
            'oldest_waiting' => isset($waiting['oldest']) ? (string) $waiting['oldest'] : null,
        ];
    }

    /**
     * The SQL condition on the account (alias u) whose connections can call the site: not blocked, and with two-factor
     * sign-in (app or passkey) when the site requires it of everyone or of administrators (Auth::isMissingRequired2fa).
     */
    public static function canCall(Settings $settings): string
    {
        $twoStep = "(u.totp_secret <> '' OR EXISTS (SELECT 1 FROM {user_passkeys} k WHERE k.user_id = u.user_id))";

        return 'u.blocked = FALSE' . match ($settings->get('require_2fa')) {
            'everyone' => ' AND ' . $twoStep,
            'admins' => ' AND (u.admin <> ' . Auth::ADMIN . ' OR ' . $twoStep . ')',
            default => '',
        };
    }

    /**
     * The state machine (pure): facts() and the current Unix time give the status.
     *
     * @param array{enabled: bool, connections: int, last_call: ?string, expires: ?string, schedules: list<array{next_due: ?string, last_status: ?string, last_at: ?string}>, waiting: int, oldest_waiting: ?string} $facts
     * @return array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string}
     */
    public static function evaluate(array $facts, int $now): array
    {
        $time = static fn (?string $moment): ?int => $moment !== null && ($t = strtotime($moment)) !== false ? $t : null;
        $lastCall = $time($facts['last_call']);
        $state = match (true) {
            !$facts['enabled'] => 'off',
            $facts['connections'] < 1 => 'none',
            $lastCall !== null && $lastCall >= $now - self::ACTIVE_DAYS * 86400 => 'active',
            default => 'idle',
        };
        $reachable = $state === 'idle' || $state === 'active';
        $pickup = null;
        $stalledSince = null;
        if ($reachable) {
            $pickup = $facts['schedules'] === [] ? 'manual' : 'stalled';
            foreach ($facts['schedules'] as $s) {
                $due = $time($s['next_due']);
                $since = match (true) {
                    in_array($s['last_status'], ['missed', 'failed'], true) => $s['last_at'] ?? $s['next_due'],
                    $due !== null && $due < $now - AgentSchedules::MISSED_HOURS * 3600 => $s['next_due'],
                    default => null,
                };
                if ($since === null) {
                    $pickup = 'automatic'; // one schedule that runs is enough
                    $stalledSince = null;
                    break;
                }
                $stalledSince = $stalledSince === null ? $since : max($stalledSince, $since);
            }
        }
        $expires = $reachable ? $facts['expires'] : null;
        $expiresAt = $time($expires);

        return [
            'state' => $state,
            'last_call' => $facts['last_call'],
            'pickup' => $pickup,
            'stalled_since' => $stalledSince,
            'expires' => $expires,
            'expiring' => $expiresAt !== null && $expiresAt < $now + self::EXPIRY_DAYS * 86400,
            'waiting' => max(0, $facts['waiting']),
            'oldest_waiting' => $facts['waiting'] > 0 ? $facts['oldest_waiting'] : null,
        ];
    }

    /**
     * Whether a connection that can call exists now (state idle or active).
     *
     * @param array{state: string} $status
     */
    public static function reachable(array $status): bool
    {
        return $status['state'] === 'idle' || $status['state'] === 'active';
    }

    /**
     * Whether nothing picks new requests up: no connection, or the scheduled run that should does not run.
     *
     * @param array{state: string, pickup: ?string} $status
     */
    public static function blocked(array $status): bool
    {
        return !self::reachable($status) || $status['pickup'] === 'stalled';
    }

    /**
     * The status as get_health returns it: the state, the last call and the earliest end as ISO 8601 moments, the pickup.
     *
     * @param array{state: string, last_call: ?string, pickup: ?string, expires: ?string} $status
     * @return array{state: string, last_call: ?string, pickup: ?string, expires: ?string}
     */
    public static function summary(array $status): array
    {
        $iso = static fn (?string $moment): ?string => $moment !== null && ($t = strtotime($moment)) !== false ? date(DATE_ATOM, $t) : null;

        return ['state' => $status['state'], 'last_call' => $iso($status['last_call']), 'pickup' => $status['pickup'], 'expires' => $iso($status['expires'])];
    }

    /**
     * Stamps the refresh row of the OAuth connection a served call came through (Mcp\Server): the access row goes an hour
     * after it expires, the refresh row lives a year. At most once a STAMP_SECONDS. A personal token (no client) is its
     * own long-lived row, stamped by the caller.
     */
    public static function stamp(Db $db, int $userId, ?string $client): void
    {
        if ($client === null || $client === '') {
            return;
        }
        $db->run("UPDATE {api_tokens} SET used_at = ? WHERE user_id = ? AND client_id = ? AND kind = 'refresh' AND (used_at IS NULL OR used_at < ?)",
            [date('Y-m-d H:i:s'), $userId, $client, date('Y-m-d H:i:s', time() - self::STAMP_SECONDS)]);
    }

    /** The last served call of a user's OAuth connection (every row of the client), which a refresh carries over; null = never. */
    public static function lastUse(Db $db, int $userId, string $client): ?string
    {
        $last = $db->value('SELECT MAX(used_at) FROM {api_tokens} WHERE user_id = ? AND client_id = ?', [$userId, $client]);

        return $last === null ? null : (string) $last;
    }
}
