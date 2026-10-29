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

    /** Multisite is switched on by the environment (TALEA_MULTISITE); off, the install is one site and nothing here is consulted. */
    public static function enabled(): bool
    {
        return Config::flag('MULTISITE', false);
    }

    /**
     * The site that answers to this host (lower-case, no port), with the matched domain's flags and the host the site prefers.
     * null = no such domain.
     *
     * @return array{site_id: int, public_id: string, prefix: string, status: string, redirect_to_site_id: ?int, is_primary: bool, redirect_to_primary: bool, primary_host: ?string}|null
     */
    public static function resolve(Db $db, string $host): ?array
    {
        $r = $db->all(
            'SELECT s.site_id, s.public_id, s.prefix, s.status, s.redirect_to_site_id, d.is_primary, d.redirect_to_primary,
                (SELECT p.host FROM {site_domains} p WHERE p.site_id = s.site_id AND p.is_primary = TRUE ORDER BY p.domain_id LIMIT 1) AS primary_host
             FROM {site_domains} d JOIN {sites} s ON s.site_id = d.site_id WHERE d.host = ?',
            [$host],
        )[0] ?? null;

        return $r === null ? null : [
            'site_id' => (int) $r['site_id'], 'public_id' => (string) $r['public_id'], 'prefix' => (string) $r['prefix'], 'status' => (string) $r['status'],
            'redirect_to_site_id' => $r['redirect_to_site_id'] === null ? null : (int) $r['redirect_to_site_id'],
            'is_primary' => (bool) $r['is_primary'], 'redirect_to_primary' => (bool) $r['redirect_to_primary'], 'primary_host' => $r['primary_host'] === null ? null : (string) $r['primary_host'],
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
     * Registers a further site (paused: not public yet) and returns its prefix. Its tables come from the next Migrator::migrate().
     */
    public static function add(Db $db, string $name, string $slug): string
    {
        $prefix = self::prefixFor($db->ownerPrefix, (int) $db->value('SELECT MAX(site_id) FROM {sites}') + 1);
        $db->insert('sites', ['name' => $name, 'slug' => $slug, 'prefix' => $prefix, 'status' => 'paused', 'created_at' => date('Y-m-d H:i:s')]);

        return $prefix;
    }
}
