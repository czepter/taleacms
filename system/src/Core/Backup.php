<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Database backups to storage/backups/ (not accessible from the web). Without mysqldump - works on shared hosting too.
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
        if (!is_dir(self::FOLDER) && !mkdir(self::FOLDER, 0775, true)) {
            throw new \RuntimeException('The folder storage/backups cannot be created - check the write permissions.');
        }
        $gz = function_exists('gzopen');
        $file = 'talea-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $reason) . '-' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $path = self::FOLDER . '/' . $file;
        $f = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        $write = fn (string $s) => $gz ? gzwrite($f, $s) : fwrite($f, $s);

        $pdo = $db->pdo();
        $dialect = $db->dialect();
        $tables = $dialect->dumpTables($pdo, $db->prefix);
        $write('-- Talea ' . TALEA_VERSION . ' - database backup ' . date('c') . "\n-- engine: " . $dialect->name() . "\n" . $dialect->dumpPrologue($tables));
        foreach ($tables as $table) {
            // temporary data is not backed up
            $structureOnly = in_array(substr($table, strlen($db->prefix)), ['stats_visitors', 'ip_checks'], true);
            $write($dialect->dumpStructure($pdo, $table));
            if ($structureOnly) {
                continue;
            }
            $columns = $db->columns(substr($table, strlen($db->prefix)));
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

        foreach (array_slice(self::listAll(), self::KEEP) as $old) {
            @unlink(self::FOLDER . '/' . $old['file']);
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
        $path = self::path($file);
        if ($path === null) {
            throw new \RuntimeException('Backup does not exist.');
        }
        $gz = str_ends_with($path, '.gz');
        if ($gz && !function_exists('gzopen')) {
            throw new \RuntimeException('The server cannot read compressed backups (zlib is missing).');
        }
        // the first pass only checks (header, only tables of this installation, a complete last statement) – the database is
        // touched only when the whole file is OK; a damaged or foreign backup thus does not leave the database half restored
        $dialect = $db->dialect();
        $count = self::walk($path, $gz, $db->prefix, $dialect->name(), null);
        @set_time_limit(300);
        $pdo = $db->pdo();
        $tables = [];
        $dialect->restoreBegin($pdo);
        try {
            self::walk($path, $gz, $db->prefix, $dialect->name(), function (string $statement) use ($pdo, &$tables, $db): mixed {
                if (preg_match('/^INSERT INTO [`"]([^`"]+)[`"]/', $statement, $m) === 1) {
                    $tables[substr($m[1], strlen($db->prefix))] = true;
                }

                return $pdo->exec($statement);
            });
        } catch (\Throwable $e) {
            $dialect->restoreEnd($pdo, true);
            throw $e;
        }
        $dialect->restoreEnd($pdo, false);
        $db->syncSequences(...array_keys($tables)); // rows came back with their own numbers

        return $count;
    }

    /** @param (callable(string): mixed)|null $apply null = check only */
    private static function walk(string $path, bool $gz, string $prefix, string $engine, ?callable $apply): int
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
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|TRUNCATE) ([^(;]+)/', $statement, $m) && preg_match_all('/[`"]([^`"]+)[`"]/', $m[2], $names) > 0) {
                    foreach ($names[1] as $name) {
                        if (!str_starts_with($name, $prefix)) {
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

    /** @return list<array{file:string, size:int, time:int}> newest first */
    public static function listAll(): array
    {
        $backups = [];
        foreach (glob(self::FOLDER . '/talea-*.sql*') ?: [] as $path) {
            $backups[] = ['file' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($backups, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $backups;
    }

    /** Path to an existing backup by the name from the URL; null = invalid name. */
    public static function path(string $file): ?string
    {
        return preg_match('/^talea-[0-9a-z-]+\.sql(\.gz)?$/', $file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /**
     * Automatic backup – called when an administrator works in the administration and from cron. Since 1.8 every day
     * something changed on the site (the change log, a new enquiry), otherwise once a week.
     */
    public static function createAutomatic(Db $db, Settings $settings): void
    {
        if (!$settings->bool('auto_backups')) {
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
