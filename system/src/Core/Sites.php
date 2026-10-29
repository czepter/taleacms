<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The owner's sites (multisite, docs/specs/multisite.md): one row per site in the owner table {sites}, each with its table prefix.
 * Site 1 uses the owner prefix; a further site gets its own (prefixFor()). With TALEA_MULTISITE off there is only site 1.
 */
final class Sites
{
    public const array STATUSES = ['live', 'paused', 'archived', 'trashed'];

    /**
     * A site's own tables a clone does not take along: content and structure are copied, never visitors' personal data or logs
     * (enquiries, requests, bookings, subscribers, members, consents), histories, counters, queues, sessions or the job state (stats_* too).
     */
    private const array NOT_CLONED = [
        'migrations', 'jobs', 'web_vitals', 'ip_checks', 'mail', 'webhook_deliveries', 'connector_queue', 'newsletter_queue', 'subscription_queue',
        'member_sessions', 'member_tokens', 'enquiries', 'requests', 'request_messages', 'bookings', 'booking_proposals', 'subscribers', 'members',
        'member_group_links', 'consents', 'change_log', 'events', 'not_found', 'search_stats', 'monthly_reports', 'testimonial_requests',
        'document_downloads', 'draft_comments', 'notice_log', 'connector_log', 'google_reviews', 'broken_links', 'import_map', 'agent_runs',
        'form_steps', 'fleet_pairing',
    ];

    /** A trashed site is dropped for good after this many days. */
    public const int TRASH_DAYS = 30;

    /** The seconds a handoff link lives. */
    public const int HANDOFF_SECONDS = 60;

    /** Multisite is switched on by the environment (TALEA_MULTISITE); off, the install is one site and nothing here is consulted. */
    public static function enabled(): bool
    {
        return Config::flag('MULTISITE', false);
    }

    /**
     * The site that answers to this host (lower-case, no port), with the matched domain's flags and the host the site prefers.
     * null = no such domain.
     *
     * @return array{site_id: int, public_id: string, prefix: string, status: string, redirect_to_site_id: ?int, is_primary: bool, redirect_to_primary: bool, primary_host: ?string, ready: bool}|null
     */
    public static function resolve(Db $db, string $host): ?array
    {
        $r = $db->all(
            'SELECT s.site_id, s.public_id, s.prefix, s.status, s.redirect_to_site_id, s.ready_at, d.is_primary, d.redirect_to_primary,
                (SELECT p.host FROM {site_domains} p WHERE p.site_id = s.site_id AND p.is_primary = TRUE ORDER BY p.domain_id LIMIT 1) AS primary_host
             FROM {site_domains} d JOIN {sites} s ON s.site_id = d.site_id WHERE d.host = ?',
            [$host],
        )[0] ?? null;

        return $r === null ? null : [
            'site_id' => (int) $r['site_id'], 'public_id' => (string) $r['public_id'], 'prefix' => (string) $r['prefix'], 'status' => (string) $r['status'],
            'redirect_to_site_id' => $r['redirect_to_site_id'] === null ? null : (int) $r['redirect_to_site_id'],
            'is_primary' => (bool) $r['is_primary'], 'redirect_to_primary' => (bool) $r['redirect_to_primary'], 'primary_host' => $r['primary_host'] === null ? null : (string) $r['primary_host'], 'ready' => $r['ready_at'] !== null,
        ];
    }

    /** Gives site 1 the host of a site address as its primary domain (multisite switched on for the first time); returns the host, null when the address has none. */
    public static function addFirstDomain(Db $db, string $siteUrl): ?string
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        $db->insert('site_domains', ['site_id' => 1, 'host' => $host, 'is_primary' => true, 'redirect_to_primary' => false]);

        return $host;
    }

    /** The primary host of a site, or null while it has no domain. */
    public static function primaryHost(Db $db, int $siteId): ?string
    {
        $host = $db->value('SELECT host FROM {site_domains} WHERE site_id = ? AND is_primary = TRUE ORDER BY domain_id LIMIT 1', [$siteId]);

        return $host === null || $host === false ? null : (string) $host;
    }

    /** The host of the request without its port, lower-case. */
    public static function requestHost(Request $request): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', (string) ($request->serverValues()['HTTP_HOST'] ?? '')));
    }

    /** Scheme and host of the request's address with another host in it (the port of the request is kept): "https://www.example.com". */
    public static function originOn(Request $request, string $host): string
    {
        preg_match('/:\d+$/', (string) ($request->serverValues()['HTTP_HOST'] ?? ''), $port);

        return ($request->isHttps() ? 'https://' : 'http://') . $host . ($port[0] ?? '');
    }

    /**
     * Table prefix of the site numbered $n: "tl_" for site 1, "tl2_", "tl3_" … after it. It never starts with the owner prefix, so a
     * LIKE 'tl\_%' (backups, Db::tablesSize()) does not catch another site's tables.
     */
    public static function prefixFor(string $ownerPrefix, int $n): string
    {
        if ($n <= 1) {
            return $ownerPrefix;
        }
        if (!preg_match('/^[a-z][a-z0-9]*_$/', $ownerPrefix)) {
            throw new \RuntimeException('Further sites need a table prefix of letters and digits ending in "_" (now "' . $ownerPrefix . '").');
        }

        return substr($ownerPrefix, 0, -1) . $n . '_';
    }

    /**
     * Every site, site 1 first. Before the registry exists (an install that has not run the migration yet) only site 1.
     *
     * @return list<array{site_id: int, prefix: string, status: string}>
     */
    public static function all(Db $db): array
    {
        if (!$db->tableExists('sites')) {
            return [['site_id' => 1, 'prefix' => $db->ownerPrefix, 'status' => 'live']];
        }

        return array_map(
            fn (array $r): array => ['site_id' => (int) $r['site_id'], 'prefix' => (string) $r['prefix'], 'status' => (string) $r['status']],
            $db->all('SELECT site_id, prefix, status FROM {sites} ORDER BY site_id'),
        );
    }

    /** @return list<string> table prefixes, the owner's (site 1) first */
    public static function prefixes(Db $db): array
    {
        return array_column(self::all($db), 'prefix');
    }

    /**
     * Registers a further site (paused: not public yet) and returns its prefix. Its tables come from the next Migrator::migrate(), its
     * settings (and with $clone its content) from provision(); until then ready_at is NULL.
     */
    public static function add(Db $db, string $name, string $slug, int $source = 1, bool $clone = false): string
    {
        $prefix = self::prefixFor($db->ownerPrefix, (int) $db->value('SELECT MAX(site_id) FROM {sites}') + 1);
        $db->insert('sites', ['name' => $name, 'slug' => $slug, 'prefix' => $prefix, 'status' => 'paused', 'source_site_id' => $source, 'clone' => $clone, 'created_at' => date('Y-m-d H:i:s')]);

        return $prefix;
    }

    /** A free address slug for a site name ("main", "main-2" …). */
    public static function freeSlug(Db $db, string $name): string
    {
        $base = substr(slugify($name) ?: 'site', 0, 50);
        for ($slug = $base, $n = 2; $db->value('SELECT 1 FROM {sites} WHERE slug = ?', [$slug]) !== null; $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /** The host as the domains table keeps it (lower-case, no port, scheme or path), or null when it is not a host name. */
    public static function normalizeHost(string $input): ?string
    {
        $host = strtolower(trim($input));

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1 ? $host : null;
    }

    /**
     * The sites for the Sites area, site 1 first: the row, its domains (primary first) and, with $sizes, the bytes its tables take.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(Db $db, bool $sizes = true): array
    {
        $sites = $db->all('SELECT * FROM {sites} ORDER BY site_id');
        $domains = [];
        foreach ($db->all('SELECT * FROM {site_domains} ORDER BY is_primary DESC, domain_id') as $d) {
            $domains[(int) $d['site_id']][] = $d;
        }
        foreach ($sites as &$site) {
            $site['domains'] = $domains[(int) $site['site_id']] ?? [];
            $site['ready'] = $site['ready_at'] !== null;
            $site['bytes'] = $sizes && $site['ready'] ? $db->forSite((string) $site['prefix'])->tablesSize() : 0;
        }

        return $sites;
    }

    /** Sites that cron has to set up: registered, not ready, not in the trash. @return list<array<string, mixed>> */
    public static function pending(Db $db): array
    {
        return $db->all("SELECT * FROM {sites} WHERE ready_at IS NULL AND status <> 'trashed' ORDER BY site_id");
    }

    /** Prefixes of the sites that are set up (the owner's first); what the cron loop runs. @return list<string> */
    public static function readyPrefixes(Db $db): array
    {
        return array_map('strval', array_column($db->all('SELECT prefix FROM {sites} WHERE ready_at IS NOT NULL ORDER BY site_id'), 'prefix'));
    }

    /**
     * Cron: migrates the database (the new sites get their tables) and sets each pending site up. One failing site is reported and
     * retried on the next run; the others go on.
     *
     * @param array<string, mixed> $config the db part of config.php
     * @return list<string> one line per site
     */
    public static function provisionPending(Db $db, array $config): array
    {
        $pending = self::pending($db);
        if ($pending === []) {
            return [];
        }
        Migrator::migrate($config);
        $lines = [];
        foreach ($pending as $site) {
            try {
                self::provision($db, $site);
                $lines[] = 'site ' . $site['prefix'] . ': ready';
            } catch (\Throwable $e) {
                ErrorReport::capture($e, 'error', ['tags' => ['job' => 'sites_provision']]);
                $lines[] = 'site ' . $site['prefix'] . ': error: ' . $e->getMessage();
            }
        }

        return $lines;
    }

    /**
     * Sets a migrated site up: the account settings of its source site (mail, AI, time zone, languages) and, for a clone, every table
     * of the source except logs, queues and counters, with the auto-numbers moved past the copied keys. Plain DML, so a run that fails
     * half-way is simply redone (it empties what it copies first); ready_at is set last. Components, facts and the other owner
     * tables are shared rows and are not copied.
     *
     * @param array<string, mixed> $site the row of {sites}
     */
    public static function provision(Db $db, array $site): void
    {
        $new = $db->forSite((string) $site['prefix']);
        if ($new->prefix === $db->ownerPrefix || !$new->tableExists('settings')) {
            throw new \RuntimeException('The tables of site ' . $site['prefix'] . ' do not exist yet: migrate first.');
        }
        $sourcePrefix = (string) ($db->value('SELECT prefix FROM {sites} WHERE site_id = ?', [(int) ($site['source_site_id'] ?? 1)]) ?? $db->ownerPrefix);
        $source = $db->forSite($sourcePrefix);
        if ($source->prefix === $new->prefix) {
            throw new \RuntimeException('A site cannot be made from itself.');
        }
        if (!empty($site['clone'])) {
            self::copyTables($source, $new);
            // an allow-list: only the settings that may move to another site (SiteExport::SETTINGS, with their language variants) and the
            // account settings stay; the rest (secrets, tokens, keys) is made again on first use
            foreach ($new->all('SELECT name FROM {settings}') as $r) {
                $name = (string) $r['name'];
                $base = (string) preg_replace('/_' . Language::TAG . '$/D', '', $name);
                $keep = in_array($name, SiteExport::SETTINGS, true) || in_array($name, Settings::ACCOUNT, true) || ($base !== $name && in_array($base, Settings::PER_LANGUAGE, true));
                if ($name === 'site_url' || !$keep) {
                    $new->delete('settings', ['name' => $name]);
                }
            }
        } else {
            $marks = implode(', ', array_fill(0, count(Settings::ACCOUNT), '?'));
            foreach ($source->all('SELECT name, value FROM {settings} WHERE name IN (' . $marks . ')', Settings::ACCOUNT) as $r) {
                $new->upsert('settings', ['name' => $r['name'], 'value' => $r['value']], ['name']);
            }
        }
        $db->update('sites', ['ready_at' => date('Y-m-d H:i:s')], ['site_id' => (int) $site['site_id']]);
    }

    /** INSERT … SELECT of every copied table of $source into $new (parents first on PostgreSQL, checks off on MySQL and SQLite). */
    private static function copyTables(Db $source, Db $new): void
    {
        $dialect = $new->dialect();
        $tables = [];
        foreach ($dialect->dumpTables($new->pdo(), $source->prefix) as $real) {
            $table = substr($real, strlen($source->prefix));
            if (!in_array($table, Db::OWNER_TABLES, true) && !in_array($table, self::NOT_CLONED, true) && !str_starts_with($table, 'stats_') && $new->tableExists($table)) {
                $tables[] = $table;
            }
        }
        $new->emptyTables(array_reverse($tables)); // what the migrations seeded; brings its own checks off and on
        [$before, $after] = $dialect->foreignKeysOff();
        array_map($new->run(...), $before);
        try {
            foreach ($tables as $table) {
                $columns = array_map($dialect->quote(...), array_values(array_intersect($new->columns($table), $source->columns($table))));
                $list = implode(', ', $columns);
                $new->run('INSERT INTO ' . $dialect->quote($new->table($table)) . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $dialect->quote($source->table($table)));
            }
        } finally {
            array_map($new->run(...), $after);
        }
        $new->syncSequences(...$tables);
    }

    /** Drops every table of a site's prefix (never the owner's) and returns how many there were. */
    public static function dropTables(Db $db, string $prefix): int
    {
        if ($prefix === $db->ownerPrefix || preg_match('/^[a-z][a-z0-9]*_$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Not the table prefix of a further site: ' . $prefix);
        }
        $tables = array_values(array_filter($db->dialect()->dumpTables($db->pdo(), $prefix), fn (string $t): bool => str_starts_with($t, $prefix)));
        foreach ($db->dialect()->dropTablesSql($tables) as $sql) {
            $db->run($sql);
        }

        return count($tables);
    }

    /** The owner job sites_cleanup: a site that has been in the trash for TRASH_DAYS loses its tables and its row (the domains go with it). */
    public static function cleanup(Db $db): string
    {
        $gone = 0;
        foreach ($db->all("SELECT site_id, prefix FROM {sites} WHERE status = 'trashed' AND site_id <> 1 AND trashed_at < NOW() - INTERVAL " . self::TRASH_DAYS . ' DAY') as $site) {
            self::dropTables($db, (string) $site['prefix']);
            $db->delete('sites', ['site_id' => (int) $site['site_id']]);
            $gone++;
        }

        return 'dropped ' . $gone;
    }

    /** Renames a site and sets its state; an archived site may point at another site (301 to its primary host). Returns an error text, null = saved. */
    public static function save(Db $db, int $siteId, string $name, string $status, ?int $redirectTo): ?string
    {
        $name = trim(mb_substr($name, 0, 120));
        if ($name === '') {
            return 'Fill in the site name.';
        }
        if (!in_array($status, ['live', 'paused', 'archived'], true)) {
            return 'Choose a state for the site.';
        }
        $site = $db->one('SELECT * FROM {sites} WHERE site_id = ?', [$siteId]);
        if ($site === null || $site['status'] === 'trashed') {
            return 'The site does not exist.';
        }
        if ($status === 'archived' && $redirectTo !== null && ($redirectTo === $siteId || $db->value("SELECT 1 FROM {sites} WHERE site_id = ? AND status <> 'trashed' AND ready_at IS NOT NULL", [$redirectTo]) === null)) {
            return 'Choose another site that is set up as the target.';
        }
        $db->update('sites', ['name' => $name, 'status' => $status, 'redirect_to_site_id' => $status === 'archived' ? $redirectTo : null], ['site_id' => $siteId]);

        return null;
    }

    /** Puts a site into the trash (kept 30 days, then dropped); site 1 never. Returns an error text, null = done. */
    public static function trash(Db $db, int $siteId): ?string
    {
        if ($siteId === 1) {
            return 'The first site cannot be deleted.';
        }
        $db->run("UPDATE {sites} SET status = 'trashed', trashed_at = ?, redirect_to_site_id = NULL WHERE site_id = ? AND status <> 'trashed'", [date('Y-m-d H:i:s'), $siteId]);
        $db->run("UPDATE {sites} SET redirect_to_site_id = NULL WHERE redirect_to_site_id = ?", [$siteId]);

        return null;
    }

    /** Takes a site out of the trash; it comes back paused. */
    public static function restore(Db $db, int $siteId): void
    {
        $db->run("UPDATE {sites} SET status = 'paused', trashed_at = NULL WHERE site_id = ? AND status = 'trashed'", [$siteId]);
    }

    /** Adds a host to a site (the first one becomes primary). Returns an error text, null = added. */
    public static function addDomain(Db $db, int $siteId, string $input): ?string
    {
        $host = self::normalizeHost($input);
        if ($host === null) {
            return 'Enter a host name such as www.example.com – no http://, no port, no path.';
        }
        if ($db->value('SELECT 1 FROM {site_domains} WHERE host = ?', [$host]) !== null) {
            return 'This host name is already used by a site.';
        }
        $first = $db->value('SELECT 1 FROM {site_domains} WHERE site_id = ?', [$siteId]) === null;
        $db->insert('site_domains', ['site_id' => $siteId, 'host' => $host, 'is_primary' => $first, 'redirect_to_primary' => false]);

        return null;
    }

    /** Removes a host; when it was the primary one, the oldest remaining host takes over. Returns an error text, null = removed. */
    public static function removeDomain(Db $db, int $siteId, int $domainId, string $currentHost): ?string
    {
        $domain = $db->one('SELECT * FROM {site_domains} WHERE domain_id = ? AND site_id = ?', [$domainId, $siteId]);
        if ($domain === null) {
            return 'The host name does not exist.';
        }
        if ($domain['host'] === $currentHost) {
            return 'This is the host name you are using right now – remove it from another one.';
        }
        $db->delete('site_domains', ['domain_id' => $domainId]);
        if ($domain['is_primary']) {
            $next = $db->value('SELECT domain_id FROM {site_domains} WHERE site_id = ? ORDER BY domain_id LIMIT 1', [$siteId]);
            if ($next !== null) {
                $db->update('site_domains', ['is_primary' => true, 'redirect_to_primary' => false], ['domain_id' => (int) $next]);
            }
        }

        return null;
    }

    /** Makes a host the primary one of its site. */
    public static function makePrimary(Db $db, int $siteId, int $domainId): void
    {
        if ($db->value('SELECT 1 FROM {site_domains} WHERE domain_id = ? AND site_id = ?', [$domainId, $siteId]) === null) {
            return;
        }
        $db->run('UPDATE {site_domains} SET is_primary = CASE WHEN domain_id = ? THEN TRUE ELSE FALSE END, redirect_to_primary = CASE WHEN domain_id = ? THEN FALSE ELSE redirect_to_primary END WHERE site_id = ?', [$domainId, $domainId, $siteId]);
    }

    /** Whether a secondary host answers with a 301 to the primary one (the primary host never does). */
    public static function setRedirect(Db $db, int $siteId, int $domainId, bool $redirect): void
    {
        $db->update('site_domains', ['redirect_to_primary' => $redirect], ['domain_id' => $domainId, 'site_id' => $siteId, 'is_primary' => false]);
    }

    /** A one-time token for the sign-in on another site: only its sha256 is kept. */
    public static function handoffToken(Db $db, int $userId, int $siteId): string
    {
        $db->run('DELETE FROM {handoff_tokens} WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]);
        $token = bin2hex(random_bytes(32));
        // UTC: the token is written on one site and claimed on another, and the two may run in different time zones (date() or NOW() would stretch the 60 s)
        $db->insert('handoff_tokens', ['token_hash' => hash('sha256', $token), 'user_id' => $userId, 'site_id' => $siteId, 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::HANDOFF_SECONDS)]);

        return $token;
    }

    /** Uses a token up on the site it was made for; the user id it vouches for, or null (unknown, used, expired, another site). */
    public static function claimHandoff(Db $db, string $token, int $siteId): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $claimed = $db->run('UPDATE {handoff_tokens} SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? AND site_id = ?', [gmdate('Y-m-d H:i:s'), $hash, gmdate('Y-m-d H:i:s'), $siteId])->rowCount();

        return $claimed === 1 ? (int) $db->value('SELECT user_id FROM {handoff_tokens} WHERE token_hash = ?', [$hash]) : null;
    }
}
