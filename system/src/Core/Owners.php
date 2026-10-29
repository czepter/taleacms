<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The owners of the install (several owners, docs/specs/multisite.md "Several owners"): one row per owner in the install table {owners}.
 * An owner is a group of table prefixes: owner 1 is the install's own prefix ("tl_", which is also its site 1), a further owner gets
 * the install stem plus letters ("tlb_", "tlc_" … "tlba_"), and its further sites that prefix with a number ("tlb2_"). Letters only in
 * an owner stem and digits only in a site number keep every `LIKE 'tlb\_%'` unambiguous: no prefix is the start of another one.
 * With TALEA_OWNERS off there is only owner 1 and nothing here is consulted.
 */
final class Owners
{
    public const array KINDS = ['person', 'organisation'];
    public const array STATUSES = ['live', 'paused', 'archived'];

    /** Several owners are switched on by the environment (TALEA_OWNERS) and need multisite. */
    public static function enabled(): bool
    {
        return Sites::enabled() && Config::flag('OWNERS', false);
    }

    /**
     * Table prefix of the owner numbered $n: the install prefix for owner 1, then its stem plus b, c … z, ba, bb … and an underscore
     * (n = 2 gives "tlb_"). The install prefix of an install with several owners is letters only ("tl_", "wp_").
     */
    public static function prefixFor(string $installPrefix, int $n): string
    {
        if ($n <= 1) {
            return $installPrefix;
        }
        if (preg_match('/^[a-z]+_$/', $installPrefix) !== 1) {
            throw new \RuntimeException('Further owners need a table prefix of letters ending in "_" (now "' . $installPrefix . '").');
        }
        $letters = '';
        for ($i = $n - 1; $i > 0; $i = intdiv($i, 26)) {
            $letters = chr(97 + $i % 26) . $letters;
        }

        return substr($installPrefix, 0, -1) . $letters . '_';
    }

    /**
     * The owner prefix a table prefix belongs to ("tlb2_" and "tlb_" belong to "tlb_", "tl3_" to "tl_"), or null when the prefix has not
     * the shape of this install's prefixes (stem, letters, digits, underscore).
     */
    public static function ownerOf(string $installPrefix, string $prefix): ?string
    {
        $stem = substr($installPrefix, 0, -1);
        if (preg_match('/^' . preg_quote($stem, '/') . '([a-z]*)[0-9]*_$/D', $prefix, $m) !== 1) {
            return null;
        }

        return $m[1] === '' ? $installPrefix : $stem . $m[1] . '_';
    }

    /**
     * Every owner, owner 1 first. Before the table exists (an install that has not run the migration yet) only owner 1.
     *
     * @return list<array<string, mixed>> the rows of {owners}
     */
    public static function all(Db $db): array
    {
        if (!$db->tableExists('owners')) {
            return [['owner_id' => 1, 'public_id' => '', 'name' => '', 'kind' => 'organisation', 'prefix' => $db->installPrefix, 'status' => 'live', 'ready_at' => '', 'created_at' => '']];
        }

        return $db->all('SELECT * FROM {owners} ORDER BY owner_id');
    }

    /** @return list<string> owner prefixes, owner 1's (the install's) first */
    public static function prefixes(Db $db): array
    {
        return array_map('strval', array_column(self::all($db), 'prefix'));
    }

    /** Owners that are set up (owner 1 always); what routing reads. @return list<string> */
    public static function readyPrefixes(Db $db): array
    {
        return array_map('strval', array_column(array_filter(self::all($db), fn (array $o): bool => $o['ready_at'] !== null), 'prefix'));
    }

    /** Sets an owner's state (live, paused, archived); owner 1 is the install itself and always live. Returns an error text, null = saved. */
    public static function setStatus(Db $db, string $prefix, string $status): ?string
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 'Choose a state for the owner.';
        }
        if ($prefix === $db->installPrefix) {
            return 'The first owner cannot be paused or archived.';
        }
        if ($db->update('owners', ['status' => $status], ['prefix' => $prefix]) === 0 && $db->value('SELECT 1 FROM {owners} WHERE prefix = ?', [$prefix]) === null) {
            return 'The owner does not exist.';
        }

        return null;
    }

    /** Is this connection owner 1's, the one whose files (media/, storage/backups/, the logs) are the install's own? */
    public static function isFirst(Db $db): bool
    {
        return $db->ownerPrefix === $db->installPrefix;
    }

    /**
     * Why a feature that works on the files shared by the whole install (backups, exports, the log, the media archive) is not
     * available to this owner yet, or null. A further owner's files are not separated from the first one's until owners get their own folders.
     */
    public static function sharedFilesRefusal(Db $db): ?string
    {
        return self::isFirst($db) ? null : 'This is not available to this owner yet: backups, exports and the log are kept for the whole install.';
    }

    /**
     * Every owner's prefix and its further sites' prefixes in order (owner, its sites, next owner …), each with a connection on its
     * tables: what the migrations and the backup walk.
     *
     * @return list<Db>
     */
    public static function connections(Db $db): array
    {
        $out = [];
        foreach (self::prefixes($db) as $owner) {
            $ownerDb = $db->forOwner($owner);
            foreach (Sites::prefixes($ownerDb) as $prefix) {
                $out[] = $ownerDb->forSite($prefix);
            }
        }

        return $out;
    }

    /** @return list<string> every table prefix of the install: each owner's, then its sites' */
    public static function allPrefixes(Db $db): array
    {
        return array_map(fn (Db $c): string => $c->prefix, self::connections($db));
    }

    /**
     * Registers a further owner (not set up yet) and returns its prefix. Its tables come from the next Migrator::migrate(), its first
     * site from the migration; provisionPending() (cron) sets ready_at. A number whose prefix already has tables is skipped.
     */
    public static function add(Db $db, string $name, string $kind = 'organisation'): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('The kind of an owner is person or organisation.');
        }
        $n = (int) $db->value('SELECT MAX(owner_id) FROM {owners}') + 1;
        while ($db->dialect()->dumpTables($db->pdo(), $prefix = self::prefixFor($db->installPrefix, $n)) !== []) {
            $n++;
        }
        $db->insert('owners', ['name' => trim(mb_substr($name, 0, 120)), 'kind' => $kind, 'prefix' => $prefix, 'status' => 'live', 'created_at' => date('Y-m-d H:i:s')]);

        return $prefix;
    }

    /**
     * Cron (owner 1's run): migrates the database so the new owners get their tables, makes sure each has the row of its site 1 and marks
     * it set up. Nothing else is copied: a new owner starts empty. One failing owner is reported and retried on the next run.
     *
     * @param array<string, mixed> $config the db part of config.php
     * @return list<string> one line per owner
     */
    public static function provisionPending(Db $db, array $config): array
    {
        $pending = $db->all('SELECT * FROM {owners} WHERE ready_at IS NULL ORDER BY owner_id');
        if ($pending === []) {
            return [];
        }
        Migrator::migrate($config);
        $lines = [];
        foreach ($pending as $owner) {
            try {
                $ownerDb = $db->forOwner((string) $owner['prefix']);
                if (!$ownerDb->tableExists('sites')) {
                    throw new \RuntimeException('The tables of owner ' . $owner['prefix'] . ' do not exist yet: migrate first.');
                }
                if ($ownerDb->value('SELECT 1 FROM {sites} WHERE site_id = 1') === null) {
                    $ownerDb->insert('sites', ['site_id' => 1, 'slug' => 'main', 'prefix' => $ownerDb->prefix, 'status' => 'live', 'ready_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
                }
                $db->update('owners', ['ready_at' => date('Y-m-d H:i:s')], ['owner_id' => (int) $owner['owner_id']]);
                $lines[] = 'owner ' . $owner['prefix'] . ': ready';
            } catch (\Throwable $e) {
                ErrorReport::capture($e, 'error', ['tags' => ['job' => 'owners_provision']]);
                $lines[] = 'owner ' . $owner['prefix'] . ': error: ' . $e->getMessage();
            }
        }

        return $lines;
    }

    /**
     * Does any owner of the install use this host? A host name is unique in the whole install, not per owner: routing reads every
     * owner's domains (and so does adding one). Without several owners only this owner's domains exist.
     */
    public static function hostTaken(Db $db, string $host): bool
    {
        $owners = self::enabled() ? self::readyPrefixes($db) : [$db->ownerPrefix];
        $sql = implode(' UNION ALL ', array_map(fn (string $p): string => 'SELECT 1 FROM ' . $db->dialect()->quote($db->forOwner($p)->table('site_domains')) . ' WHERE host = ?', $owners));
        $stmt = $db->pdo()->prepare($sql);
        $stmt->execute(array_fill(0, count($owners), $host));

        return $stmt->fetchColumn() !== false;
    }
}
