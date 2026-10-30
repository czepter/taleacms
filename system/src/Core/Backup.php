<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Database backups to storage/backups/ (not accessible from the web). Without mysqldump or pg_dump - works on shared hosting too; an SQLite site gets the same kind of file.
 * One file holds every site of the database (multisite: the owner prefix and each further site's, and with several owners every owner's
 * prefixes, docs/specs/multisite.md). It is the install's file: only owner 1 makes, restores and sees those backups (Owners::sharedFilesRefusal()).
 * A further owner (a client) has backups of its OWN: the owner prefix and its sites' only, in a folder of its own (OwnerFiles::folder), made and
 * restored by createForOwner() / restoreForOwner() and never able to name another owner's file or table.
 */
final class Backup
{
    public const string FOLDER = TALEA_ROOT . '/storage/backups';
    private const int KEEP = 10;

    /**
     * The public demo (3.3.2, N24): a web request never makes, restores or hands out a backup – a dump holds the site's
     * secret key and password hashes. The demo's own snapshot and reset run from the command line and may.
     */
    public static function refusedInDemo(): bool
    {
        return Demo::active() && PHP_SAPI !== 'cli';
    }

    /** @return string name of the created file */
    public static function create(Db $db, string $reason = 'manual'): string
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        if (($refusal = Owners::sharedFilesRefusal($db)) !== null) {
            throw new \RuntimeException($refusal); // the file holds every owner's data
        }

        return self::write(Owners::connections($db), self::FOLDER, $reason);
    }

    /**
     * A backup of ONE further owner: its owner prefix and its sites' tables, nothing of the install or of another owner. The folder is the owner's own.
     *
     * @return string name of the created file
     */
    public static function createForOwner(Db $db, string $reason = 'manual'): string
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        $folder = OwnerFiles::folder($db);
        $owner = $db->forOwner($db->ownerPrefix);

        return self::write(array_map(fn (string $prefix): Db => $owner->forSite($prefix), Sites::prefixes($owner)), $folder, $reason);
    }

    /**
     * @param list<Db> $connections the sites to dump, each on its own tables
     * @return string name of the created file
     */
    private static function write(array $connections, string $folder, string $reason): string
    {
        if (!is_dir($folder) && !mkdir($folder, 0775, true)) {
            throw new \RuntimeException('The folder storage/backups cannot be created - check the write permissions.');
        }
        $gz = function_exists('gzopen');
        $file = 'talea-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $reason) . '-' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $path = $folder . '/' . $file;
        $f = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        $write = fn (string $s) => $gz ? gzwrite($f, $s) : fwrite($f, $s);

        $pdo = $connections[0]->pdo();
        $dialect = $connections[0]->dialect();
        $sites = []; // real table name => the Db of its site
        $prefixes = [];
        foreach ($connections as $site) {
            $prefixes[] = $site->prefix;
            foreach ($dialect->dumpTables($pdo, $site->prefix) as $table) {
                $sites[$table] = $site;
            }
        }
        $write('-- Talea ' . TALEA_VERSION . ' - database backup ' . date('c') . "\n-- engine: " . $dialect->name() . "\n-- prefixes: " . implode(',', $prefixes) . "\n" . $dialect->dumpPrologue(array_keys($sites)));
        foreach ($sites as $table => $site) {
            $name = substr($table, strlen($site->prefix));
            // temporary data is not backed up
            $structureOnly = in_array($name, ['stats_visitors', 'ip_checks'], true);
            $write($dialect->dumpStructure($pdo, $table));
            if ($structureOnly) {
                continue;
            }
            $columns = $site->columns($name);
            $booleans = array_keys(array_intersect($columns, $dialect->dumpBooleanColumns($pdo, $table)));
            $head = $dialect->dumpInsert($table, $columns);
            $rows = $pdo->query('SELECT ' . implode(', ', array_map($dialect->quote(...), $columns)) . ' FROM ' . $dialect->quote($table), \PDO::FETCH_NUM);
            $batch = [];
            foreach ($rows as $row) {
                foreach ($booleans as $i) {
                    $row[$i] = $row[$i] === null ? null : (bool) $row[$i];
                }
                $batch[] = '(' . implode(',', array_map(fn ($h): string => $h === null ? 'NULL' : (is_bool($h) ? ($h ? 'TRUE' : 'FALSE') : (is_int($h) || is_float($h) ? (string) $h : $dialect->dumpLiteral($pdo, (string) $h))), $row)) . ')';
                if (count($batch) >= 200) {
                    $write($head . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $write($head . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
        }
        $write($dialect->dumpEpilogue());
        $gz ? gzclose($f) : fclose($f);

        foreach (array_slice(self::listIn($folder), self::KEEP) as $old) {
            @unlink($folder . '/' . $old['file']);
        }

        return $file;
    }

    /**
     * Restores the database from a backup created by this class. A statement in the backup always ends with a semicolon at
     * the end of a line (values are written by Dialect::dumpLiteral, line breaks in texts are encoded in them), so it can be read line
     * by line without loading the whole file into memory.
     *
     * @return int number of executed statements
     * @throws \RuntimeException
     */
    public static function restore(Db $db, string $file): int
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        if (($refusal = Owners::sharedFilesRefusal($db)) !== null) {
            throw new \RuntimeException($refusal);
        }
        $path = self::path($file);
        if ($path === null) {
            throw new \RuntimeException('Backup does not exist.');
        }

        return self::apply($db, $path, Owners::allPrefixes($db), null);
    }

    /**
     * Restores a further owner's backup: only that owner's tables and sites (the file's header must name prefixes of this owner and nothing
     * else, and a table of any other prefix stops the restore). Sites of the owner made after the backup are dropped, as in the install's restore.
     *
     * @return int number of executed statements
     * @throws \RuntimeException
     */
    public static function restoreForOwner(Db $db, string $file): int
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        $path = self::pathForOwner($db, $file) ?? throw new \RuntimeException('Backup does not exist.');

        return self::apply($db, $path, Sites::prefixes($db->forOwner($db->ownerPrefix)), $db->ownerPrefix);
    }

    /**
     * @param list<string> $registered the sites there are now (those of the install, or of the one owner)
     * @param string|null $owner the owner prefix a restore is limited to; null = the whole install
     */
    private static function apply(Db $db, string $path, array $registered, ?string $owner): int
    {
        $gz = str_ends_with($path, '.gz');
        if ($gz && !function_exists('gzopen')) {
            throw new \RuntimeException('The server cannot read compressed backups (zlib is missing).');
        }
        // the first pass only checks (header, only tables of this installation, a complete last statement) – the database is
        // touched only when the whole file is OK; a damaged or foreign backup thus does not leave the database half restored
        $dialect = $db->dialect();
        // $registered was read before the restore replaces the registries
        // the sites the file holds (its header line); an older file has none and is taken for the sites there are now
        $prefixes = self::headerPrefixes($path, $gz, $db->installPrefix, $owner) ?? ($owner === null ? $registered : throw new \RuntimeException('The file is not a backup of this owner.'));
        $count = self::walk($path, $gz, $prefixes, $dialect->name(), null);
        @set_time_limit(300);
        $pdo = $db->pdo();
        foreach ($prefixes as $prefix) {
            if ($prefix !== $db->installPrefix && $dialect->dumpTables($pdo, $prefix) === []) {
                Migrator::migratePrefix($db, $prefix); // a site dropped since the backup: its tables first (a PostgreSQL or SQLite backup has no structure)
            }
        }
        $tables = [];
        $dialect->restoreBegin($pdo);
        try {
            // sites made after the backup are not in the restored registry: their tables go, or they would be orphans (and on PostgreSQL
            // a TRUNCATE of the owner tables fails while they still refer to them)
            foreach (array_reverse(array_diff($registered, $prefixes)) as $gone) { // a site before its owner's tables
                Sites::dropTables($db, $gone);
            }
            self::walk($path, $gz, $prefixes, $dialect->name(), function (string $statement) use ($pdo, &$tables, $prefixes): mixed {
                if (preg_match('/^INSERT INTO [`"]([^`"]+)[`"]/', $statement, $m) === 1) {
                    $prefix = (string) self::prefixOf($prefixes, $m[1]);
                    $tables[$prefix][substr($m[1], strlen($prefix))] = true;
                }

                return $pdo->exec($statement);
            });
        } catch (\Throwable $e) {
            $dialect->restoreEnd($pdo, true);
            throw $e;
        }
        $dialect->restoreEnd($pdo, false);
        Sites::forgetCurrent();
        $connections = [];
        foreach (Owners::connections($db) as $site) { // read again: the restored registries decide which owner a prefix belongs to
            $connections[$site->prefix] = $site;
        }
        foreach ($tables as $prefix => $names) {
            ($connections[$prefix] ?? $db->forSite($prefix))->syncSequences(...array_keys($names)); // rows came back with their own numbers
        }

        return $count;
    }

    /**
     * The table prefixes a backup was made of (its "-- prefixes:" line), each owner's followed by its sites'; null for a file without the line.
     *
     * @return list<string>|null
     * @throws \RuntimeException a prefix that cannot be one of this installation's sites
     */
    private static function headerPrefixes(string $path, bool $gz, string $install, ?string $owner = null): ?array
    {
        $f = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $found = null;
        for ($i = 0; $i < 6 && ($row = $gz ? gzgets($f) : fgets($f)) !== false; $i++) {
            if (preg_match('/^-- prefixes: (\S+)/', $row, $m) === 1) {
                $found = explode(',', $m[1]);
                break;
            }
        }
        $gz ? gzclose($f) : fclose($f);
        if ($found === null) {
            return null;
        }
        foreach ($found as $prefix) {
            if (($prefix !== $install && Owners::ownerOf($install, $prefix) === null) || ($owner !== null && Owners::ownerOf($install, $prefix) !== $owner)) { // the shape of an owner's or a site's prefix of this install, and of the one owner
                throw new \RuntimeException('The backup was made with other table prefixes (' . $prefix . ') – the restore was stopped.');
            }
        }
        if (!in_array($owner ?? $install, $found, true)) {
            throw new \RuntimeException('The backup was made with other table prefixes (' . implode(', ', $found) . ') – the restore was stopped.');
        }

        return $found;
    }

    /**
     * @param list<string> $prefixes table prefixes of this installation's sites
     * @param (callable(string): mixed)|null $apply null = check only
     */
    private static function walk(string $path, bool $gz, array $prefixes, string $engine, ?callable $apply): int
    {
        $f = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $first = (string) ($gz ? gzgets($f) : fgets($f));
        if (!str_starts_with($first, '-- Talea ')) {
            throw new \RuntimeException('The file is not a backup created by Talea.');
        }
        $fileEngine = 'mysql'; // a file without an engine line is an old MySQL backup
        $checked = false;
        $statement = '';
        $count = 0;
        while (($row = $gz ? gzgets($f) : fgets($f)) !== false) {
            if ($statement === '' && (trim($row) === '' || str_starts_with($row, '--'))) {
                $fileEngine = preg_match('/^-- engine: (\w+)/', $row, $m) === 1 ? $m[1] : $fileEngine;
                continue;
            }
            if (!$checked && $fileEngine !== $engine) {
                throw new \RuntimeException('The backup was made on another database engine (' . $fileEngine . ', this site uses ' . $engine . ') - move the content with an export instead.');
            }
            $checked = true;
            $statement .= $row;
            if (str_ends_with(rtrim($row), ';')) {
                // a backup can contain only tables of this installation
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|TRUNCATE|DELETE FROM) ([^(;]+)/', $statement, $m) && preg_match_all('/[`"]([^`"]+)[`"]/', $m[2], $names) > 0) {
                    foreach ($names[1] as $name) {
                        if (self::prefixOf($prefixes, $name) === null) {
                            throw new \RuntimeException('The backup contains a foreign table ' . $name . ' – the restore was stopped.');
                        }
                    }
                }
                if ($apply !== null) {
                    $apply($statement);
                }
                $statement = '';
                $count++;
            }
        }
        $gz ? gzclose($f) : fclose($f);
        if (trim($statement) !== '' || $count === 0) {
            throw new \RuntimeException('The backup is incomplete or damaged (the end of the file is missing) – the restore was stopped.');
        }

        return $count;
    }

    /**
     * The prefix of a real table name among the sites in the registry, null for a foreign table – also for a tl7_ table of a site
     * that is not registered: it may belong to another installation in the same database.
     *
     * @param list<string> $prefixes
     */
    private static function prefixOf(array $prefixes, string $table): ?string
    {
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a)); // the longest first
        foreach ($prefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /** @return list<array{file:string, size:int, time:int}> newest first */
    public static function listAll(): array
    {
        return self::listIn(self::FOLDER);
    }

    /** The backups of a further owner, newest first (its own folder only). @return list<array{file:string, size:int, time:int}> */
    public static function listForOwner(Db $db): array
    {
        return Owners::isFirst($db) ? [] : self::listIn(OwnerFiles::folder($db));
    }

    /** Path to an existing backup of this further owner by the name from the URL; null = invalid name (a name never leaves the owner's folder). */
    public static function pathForOwner(Db $db, string $file): ?string
    {
        if (Owners::isFirst($db) || preg_match('/^talea-[0-9a-z-]+\.sql(\.gz)?$/', $file) !== 1) {
            return null;
        }
        $path = OwnerFiles::folder($db) . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** @return list<array{file:string, size:int, time:int}> newest first */
    private static function listIn(string $folder): array
    {
        $backups = [];
        foreach (glob($folder . '/talea-*.sql*') ?: [] as $path) {
            $backups[] = ['file' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($backups, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $backups;
    }

    /** Path to an existing backup by the name from the URL; null = invalid name. */
    public static function path(string $file, ?Db $for = null): ?string
    {
        return ($for === null || Owners::sharedFilesRefusal($for) === null) && preg_match('/^talea-[0-9a-z-]+\.sql(\.gz)?$/', $file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /**
     * Automatic backup – called when an administrator works in the administration and from cron. Since 1.8 every day
     * something changed on the site (the change log, a new enquiry), otherwise once a week.
     */
    public static function createAutomatic(Db $db, Settings $settings): void
    {
        if (!$settings->bool('auto_backups') || Owners::sharedFilesRefusal($db) !== null) {
            return;
        }
        $last = self::listAll()[0]['time'] ?? 0;
        $age = time() - $last;
        $changed = fn (): bool => (int) $db->value('SELECT COUNT(*) FROM {change_log} WHERE created_at > ?', [date('Y-m-d H:i:s', $last)]) > 0
            || (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE created_at > ?', [date('Y-m-d H:i:s', $last)]) > 0;
        if ($age > 7 * 86400 || ($age > 86400 && $changed())) {
            try {
                $file = self::create($db, 'auto');
                Events::record($db, 'backup.created', 'info', t('Automatic backup %s', $file), ['file' => $file]);
            } catch (\Throwable $e) {
                // a backup must not bring down the administration; the system health page warns about a missing backup
                Events::record($db, 'backup.failed', 'error', mb_substr(t('The automatic backup failed: %s', $e->getMessage()), 0, 255));

                return;
            }
            try {
                RemoteBackup::upload($settings, (string) self::path($file)); // the result is shown by the system health page and the Backups tab
            } catch (\Throwable $e) {
                Events::record($db, 'backup.failed', 'error', mb_substr(t('The off-site copy of the backup failed: %s', $e->getMessage()), 0, 255), ['file' => $file]);
            }
        }
    }
}
