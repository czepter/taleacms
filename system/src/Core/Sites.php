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
