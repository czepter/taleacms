<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Security hygiene that runs itself (2.8): a daily look at accounts and access.
 *
 *  - administrators who sign in without two-step sign-in or a passkey;
 *  - accounts nobody has used for ACCOUNT_DAYS – the last completed sign-in, the last use of a Claude connection of the
 *    account, or the moment an administrator created or confirmed the account (ka_uzivatele.potvrzeno), whichever is latest;
 *  - Claude connections (personal tokens from My account and applications connected via OAuth) nobody has used for
 *    CONNECTION_DAYS, and personal tokens that never expire.
 *
 * findings() only reads and feeds System status (Core\Health) and the site audit (Core\Audit). run() is called once a day
 * by the scheduler: when the administrator switched the automatic suspension on (setting auto_suspend), it blocks the
 * unused accounts (never the last active administrator, never the signed-in user) and revokes the unused connections,
 * and writes every action to the change log. A blocked account is reactivated in Users; a revoked connection has to be
 * connected again. Nothing happens while the setting is off, and nothing ever happens in the public demo.
 */
final class SecurityHygiene
{
    /** An account with no sign-in, no Claude activity and no confirmation by an administrator for this many days is unused. */
    public const int ACCOUNT_DAYS = 90;

    /** A Claude connection not used for this many days is unused (one never used counts from its creation). */
    public const int CONNECTION_DAYS = 60;

    /** Values of the setting auto_suspend (a comma-separated list). */
    public const string SUSPEND_ACCOUNTS = 'ucty';
    public const string SUSPEND_CONNECTIONS = 'napojeni';

    /** @return list<string> the choices of the automatic suspension that are switched on */
    public static function autoSuspend(Settings $settings): array
    {
        return array_values(array_intersect(explode(',', $settings->get('auto_suspend')), [self::SUSPEND_ACCOUNTS, self::SUSPEND_CONNECTIONS]));
    }

    /**
     * What deserves attention, read only.
     *
     * @return array{two_step: list<array<string, mixed>>, unused_accounts: list<array<string, mixed>>, unused_connections: list<array<string, mixed>>, no_expiry: list<array<string, mixed>>}
     */
    public static function findings(App $app): array
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $accounts = self::accounts($db);
        $connections = self::connections($db);

        return [
            'two_step' => array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['admin'] === Auth::ADMIN && !$a['blocked'] && (string) $a['totp_secret'] === '' && (int) $a['klice'] === 0)),
            'unused_accounts' => self::unusedAccounts($accounts, $now),
            'unused_connections' => self::unusedConnections($connections, $now),
            'no_expiry' => array_values(array_filter($connections, static fn (array $c): bool => $c['kind'] === 'token' && $c['expiry'] === null)),
        ];
    }

    /**
     * The daily run: applies the automatic suspension when it is switched on. Returns what it did (display names).
     *
     * @return array{blocked: list<string>, revoked: list<string>}
     */
    public static function run(App $app): array
    {
        $done = ['blocked' => [], 'revoked' => []];
        $choices = self::autoSuspend($app->settings());
        if ($choices === [] || Demo::active()) {
            return $done;
        }
        $db = $app->db();
        $now = new \DateTimeImmutable();
        if (in_array(self::SUSPEND_ACCOUNTS, $choices, true)) {
            $accounts = self::accounts($db);
            foreach (self::blockable(self::unusedAccounts($accounts, $now), $accounts, $app->auth()->id()) as $a) {
                $db->update('users', ['blocked' => 1, 'auto_blocked_at' => $now->format('Y-m-d H:i:s')], ['user_id' => (int) $a['user_id']]);
                ChangeLog::write($app, 'users', 'auto_block', sprintf('%s – not used for %d days (last activity %s)', self::displayName($a), self::ACCOUNT_DAYS, (string) $a['last']));
                Events::record($db, 'security.account_suspended', 'warning', t('An account not used for %d days was suspended (user #%d).', self::ACCOUNT_DAYS, (int) $a['user_id']), ['username' => (int) $a['user_id']]);
                $done['blocked'][] = self::displayName($a);
            }
        }
        if (in_array(self::SUSPEND_CONNECTIONS, $choices, true)) {
            foreach (self::unusedConnections(self::connections($db), $now) as $c) {
                if ($c['kind'] === 'token') {
                    $db->delete('api_tokens', ['token_id' => (int) $c['id']]);
                } else {
                    $db->delete('api_tokens', ['user_id' => (int) $c['user_id'], 'client_id' => (string) $c['id']]);
                }
                ChangeLog::write($app, 'claude', 'auto_revoke', sprintf('%s (%s) – not used for %d days (last activity %s)', (string) $c['name'], (string) $c['username'], self::CONNECTION_DAYS, (string) $c['last']));
                Events::record($db, 'security.connection_revoked', 'warning', t('A Claude connection not used for %d days was revoked (user #%d).', self::CONNECTION_DAYS, (int) $c['user_id']), ['username' => (int) $c['user_id'], 'kind' => (string) $c['kind']]);
                $done['revoked'][] = $c['name'] . ' (' . $c['username'] . ')';
            }
        }

        return $done;
    }

    /* ---------- selection (pure, unit-tested) ---------- */

    /**
     * The moment the account was last known to be wanted: the last completed sign-in, the last use of one of its Claude
     * connections, or the creation / confirmation by an administrator. Null = nothing is known (an account from before
     * the record existed) – such an account is never treated as unused.
     *
     * @param array<string, mixed> $account row of ka_uzivatele with pouzit = MAX(ka_api_tokeny.pouzit) of the account
     */
    public static function lastActivity(array $account): ?string
    {
        $moments = array_filter([$account['last_login_at'] ?? null, $account['confirmed_at'] ?? null, $account['used_at'] ?? null], static fn (mixed $m): bool => is_string($m) && $m !== '');

        return $moments === [] ? null : max($moments);
    }

    /**
     * Accounts that are not blocked and have not been used for ACCOUNT_DAYS, each with 'last' = the last activity.
     *
     * @param list<array<string, mixed>> $accounts see lastActivity()
     * @return list<array<string, mixed>>
     */
    public static function unusedAccounts(array $accounts, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('-' . self::ACCOUNT_DAYS . ' days')->format('Y-m-d H:i:s');
        $out = [];
        foreach ($accounts as $a) {
            $last = self::lastActivity($a);
            if (!$a['blocked'] && $last !== null && $last < $limit) {
                $out[] = ['last' => $last] + $a;
            }
        }

        return $out;
    }

    /**
     * Which of the unused accounts the automatic suspension may block: never the signed-in user, and never the last active
     * administrator – when blocking them all would leave the site without one, the most recently active administrator stays.
     *
     * @param list<array<string, mixed>> $unused result of unusedAccounts()
     * @param list<array<string, mixed>> $accounts all accounts (admin, blokovat)
     * @param int $currentUser id of the signed-in user, 0 = nobody (the scheduler)
     * @return list<array<string, mixed>>
     */
    public static function blockable(array $unused, array $accounts, int $currentUser): array
    {
        $unused = array_values(array_filter($unused, static fn (array $a): bool => (int) $a['user_id'] !== $currentUser));
        $candidates = array_map(intval(...), array_column($unused, 'user_id'));
        $remainingAdmins = array_filter($accounts, static fn (array $a): bool => (int) $a['admin'] === Auth::ADMIN && !$a['blocked'] && !in_array((int) $a['user_id'], $candidates, true));
        if ($remainingAdmins === []) {
            $keep = null;
            foreach ($unused as $a) {
                if ((int) $a['admin'] === Auth::ADMIN && ($keep === null || (string) $a['last'] > (string) $keep['last'])) {
                    $keep = $a;
                }
            }
            if ($keep !== null) {
                $unused = array_values(array_filter($unused, static fn (array $a): bool => (int) $a['user_id'] !== (int) $keep['user_id']));
            }
        }

        return $unused;
    }

    /**
     * Claude connections not used for CONNECTION_DAYS (a connection never used counts from its creation).
     *
     * @param list<array<string, mixed>> $connections see connections(): kind token | app, id, idu, user, name, last, expiry
     * @return list<array<string, mixed>>
     */
    public static function unusedConnections(array $connections, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('-' . self::CONNECTION_DAYS . ' days')->format('Y-m-d H:i:s');

        return array_values(array_filter($connections, static fn (array $c): bool => is_string($c['last']) && $c['last'] < $limit));
    }

    /* ---------- reading ---------- */

    /**
     * All accounts with what the checks need: the last use of their Claude connections and the number of passkeys.
     *
     * @return list<array<string, mixed>>
     */
    public static function accounts(Db $db): array
    {
        return $db->all('SELECT u.user_id, u.username, u.name, u.admin, u.blocked, u.auto_blocked_at, u.last_login_at, u.confirmed_at, u.totp_secret,
            (SELECT MAX(t.used_at) FROM {api_tokens} t WHERE t.user_id = u.user_id) AS used_at, (SELECT COUNT(*) FROM {user_passkeys} k WHERE k.user_id = u.user_id) AS klice
            FROM {users} u ORDER BY u.username');
    }

    /**
     * Live Claude connections: every personal token (kind token, id = idt) and every application connected via OAuth that
     * still has a valid token (kind app, id = client_id), one item per application and account. 'last' = the last use, or
     * the creation when it was never used; 'expiry' = when the connection ends by itself (null = never).
     *
     * @return list<array<string, mixed>>
     */
    public static function connections(Db $db): array
    {
        $out = [];
        $apps = [];
        $now = date('Y-m-d H:i:s');
        $rows = $db->all('SELECT t.token_id, t.user_id, t.name, t.client_id, t.kind, t.access, t.expires_at, t.created_at, t.used_at, u.username, u.name AS user_name FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id ORDER BY t.token_id');
        foreach ($rows as $r) {
            $user = self::displayName(['name' => $r['user_name'], 'username' => $r['username']]);
            if ($r['client_id'] === null) {
                if ($r['kind'] !== 'token') {
                    continue;
                }
                $out[] = ['kind' => 'token', 'id' => (int) $r['token_id'], 'user_id' => (int) $r['user_id'], 'username' => $user, 'name' => (string) $r['name'], 'access' => (string) $r['access'],
                    'last' => (string) ($r['used_at'] ?? $r['created_at']), 'used' => $r['used_at'] !== null, 'expiry' => $r['expires_at'], 'created' => (string) $r['created_at']];
                continue;
            }
            $key = $r['user_id'] . '|' . $r['client_id'];
            $app = $apps[$key] ?? ['kind' => 'app', 'id' => (string) $r['client_id'], 'user_id' => (int) $r['user_id'], 'username' => $user, 'name' => (string) $r['name'], 'access' => (string) $r['access'],
                'last' => null, 'used' => false, 'expiry' => null, 'created' => (string) $r['created_at'], 'alive' => false];
            $app['alive'] = $app['alive'] || $r['expires_at'] === null || (string) $r['expires_at'] > $now;
            $app['created'] = min($app['created'], (string) $r['created_at']);
            if ($r['used_at'] !== null) {
                $app['used'] = true;
                $app['last'] = $app['last'] === null ? (string) $r['used_at'] : max((string) $app['last'], (string) $r['used_at']);
            }
            if ($r['kind'] === 'obnova' && $r['expires_at'] !== null) {
                $app['expiry'] = $app['expiry'] === null ? (string) $r['expires_at'] : max((string) $app['expiry'], (string) $r['expires_at']);
            }
            $apps[$key] = $app;
        }
        foreach ($apps as $app) {
            if (!$app['alive']) {
                continue; // every token of the application has expired – the connection is over, the rows only wait for the cleanup
            }
            $app['last'] ??= $app['created'];
            unset($app['alive']);
            $out[] = $app;
        }

        return $out;
    }

    /** @param array<string, mixed> $account */
    public static function displayName(array $account): string
    {
        return (string) ($account['name'] ?? '') !== '' ? (string) $account['name'] : (string) ($account['username'] ?? '');
    }

    /** How many whole days ago a moment was (for messages). */
    public static function daysAgo(string $moment, ?\DateTimeImmutable $now = null): int
    {
        return max(0, (int) (new \DateTimeImmutable($moment))->diff($now ?? new \DateTimeImmutable())->days);
    }
}
