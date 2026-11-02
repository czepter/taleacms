<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Bringing an existing site in (docs/specs/multisite.md, "Bringing existing sites in"): an export archive of an older (single-site) Talea
 * becomes a NEW site of an owner while the other sites keep running. A request only stores the archive under storage/import/sites/<site public id>/
 * and inserts the site row (not ready, `sites.import_file`); cron migrates the new prefix and runs the import for that site in batches
 * (work(), called by Sites::provisionPending) with a state and a lock of its own – never the install-wide RUNNING file of SiteImport, so no
 * other site shows a maintenance notice. The site becomes ready when the import is done; a failure leaves it not ready with the error in
 * `sites.import_error`, the owner's shared rows the import added taken out again.
 */
final class SiteImports
{
    /** The seconds one cron run spends on imports (the next run goes on from the saved state). */
    public const float SECONDS = 200.0;

    /** After a batch the import rests this many times as long as the batch took: it never takes a whole core from the sites that are live (1.0 = at most half). */
    public const float REST = 1.0;

    /**
     * The folder of a site's archive, state, lock and working files, named by the site's public id. Never by the table prefix: a prefix is
     * handed out again after a site is dropped (and every test database uses the same ones), and two sites must not share or remove files.
     */
    public static function folder(string $publicId): string
    {
        if (!Uuid::valid($publicId)) {
            throw new \InvalidArgumentException('Not a site public id: ' . $publicId);
        }

        return WpFile::FOLDER . '/sites/' . $publicId;
    }

    /** Is this file (as uploaded) a Talea export, or a Kaleta one that SiteImport converts? A quick look at the header, not the import. */
    public static function looksLikeExport(string $path, string $extension): bool
    {
        $extension = strtolower($extension);
        if ($extension === 'zip') {
            if (!class_exists(\ZipArchive::class)) {
                return false;
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            $in = $zip->getStream('content.json');
            $head = $in === false ? '' : (string) fread($in, 300);
            $zip->close();

            return str_contains($head, '"format":"talea-export"') || KaletaExport::is($path);
        }

        return $extension === 'json' && (str_contains((string) file_get_contents($path, false, null, 0, 300), '"format":"talea-export"') || KaletaExport::is($path));
    }

    /**
     * Makes the site row for an archive and stores the archive for cron: the file is MOVED from $source (an upload or a file in storage/import).
     * Returns the prefix of the new site.
     *
     * @param Db $owner the owner the site belongs to
     * @param int $admin the user of that owner who imports (authors of news go to this person); 0 = not a user of that owner (an operator)
     * @param callable(string, string): bool $move moves $source to the target (move_uploaded_file for an upload, rename for a file already here)
     * @throws \RuntimeException the archive is not usable
     */
    public static function create(Db $owner, string $name, string $source, string $extension, int $admin, callable $move): string
    {
        $extension = strtolower($extension) === 'json' ? 'json' : 'zip';
        if (!self::looksLikeExport($source, $extension)) {
            throw new \RuntimeException('The file is not a Talea export.');
        }
        $prefix = Sites::add($owner, $name, Sites::freeSlug($owner, $name), Sites::currentId($owner), false);
        $folder = self::folder((string) $owner->value('SELECT public_id FROM {sites} WHERE prefix = ?', [$prefix]));
        $file = 'archive.' . $extension;
        if ((!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) || !$move($source, $folder . '/' . $file)) {
            $owner->delete('sites', ['prefix' => $prefix]);
            throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
        }
        $owner->update('sites', ['import_file' => $file], ['prefix' => $prefix]);
        SiteImport::saveState(['phase' => 'queued', 'admin' => $admin, 'site_prefix' => $prefix, 'media_dir' => MediaFolder::dirOf(Owners::publicIdOf($owner, $owner->ownerPrefix))]
            + SiteImport::newState($file, $folder));

        return $prefix;
    }

    /** Removes the files of a site's import (the site is dropped, or its import is given up). */
    public static function forget(string $publicId): void
    {
        $folder = self::folder($publicId);
        foreach (glob($folder . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $part) {
                    @unlink($part);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($folder);
    }

    /**
     * Where the import of a pending site is: the phase and a share of the work done, for the Sites list.
     *
     * @param array<string, mixed> $site the row of {sites}
     * @return array{phase: string, percent: int, rows: int, rows_done: int, media: int, media_done: int}|null null = not an import (or no state)
     */
    public static function progress(array $site): ?array
    {
        if (($site['import_file'] ?? '') === '') {
            return null;
        }
        $state = SiteImport::loadState((string) $site['import_file'], self::folder((string) $site['public_id']));
        if ($state === null) {
            return null;
        }
        $rows = array_sum(array_map('intval', (array) $state['counts']));
        $phase = (string) $state['phase'];
        $before = array_slice(SiteImport::order(true), 0, (int) $state['table']);
        $rowsDone = array_sum(array_map(fn (string $t): int => (int) ($state['counts'][$t] ?? 0), $before)) + (int) $state['position'];
        $data = $rows > 0 ? min(1.0, $rowsDone / $rows) : 1.0;
        $media = (int) $state['media_total'] > 0 ? min(1.0, (int) $state['media_position'] / max(1, (int) $state['media_total'])) : 1.0;
        $percent = match ($phase) {
            'queued', 'preparing', 'preview' => 0,
            'data' => (int) round($data * 70),
            'media' => 70 + (int) round($media * 30),
            default => 100,
        };

        $past = in_array($phase, ['media', 'done'], true);

        return ['phase' => $phase, 'percent' => $percent, 'rows' => $rows, 'rows_done' => $past ? $rows : min($rows, $rowsDone),
            'media' => (int) $state['media_total'], 'media_done' => $phase === 'done' ? (int) $state['media_total'] : ($phase === 'media' ? (int) $state['media_position'] : 0)];
    }

    /**
     * Cron: goes on with the import of a pending site until it is done, fails or the budget is used up (the state is saved after every batch).
     * Returns a line for the cron output: "ready", "importing 43 %" or "error: …". The site's tables exist (the caller migrated).
     *
     * @param array<string, mixed> $site the row of {sites}
     */
    public static function work(Db $owner, array $site, float $seconds = self::SECONDS, float $rest = self::REST): string
    {
        $prefix = (string) $site['prefix'];
        $folder = self::folder((string) $site['public_id']);
        $file = (string) $site['import_file'];
        $state = SiteImport::loadState($file, $folder);
        if ($state === null || SiteImport::path($file, $folder) === null) {
            return self::fail($owner, $site, null, 'The archive is gone – create the site again from the export.');
        }
        $lock = fopen($folder . '/import.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'importing (busy)';
        }
        $new = $owner->forSite($prefix);
        $import = null;
        try {
            Sites::forgetCurrent();
            if (!$new->tableExists('settings')) {
                throw new \RuntimeException('The tables of site ' . $prefix . ' do not exist yet: migrate first.');
            }
            $settings = new Settings($new);
            $import = new SiteImport($new, $settings, (int) ($state['admin'] ?? 0), (string) $state['media_dir']);
            $end = microtime(true) + $seconds;
            if ($state['phase'] === 'queued') {
                @set_time_limit(0);
                SiteImport::prepare($state, $owner);
                $state['phase'] = 'data';
                $state['error'] = '';
                self::accountSettings($owner, $new, (int) ($site['source_site_id'] ?? 1));
                SiteImport::saveState($state);
            }
            while (in_array($state['phase'], ['data', 'media'], true) && microtime(true) < $end) {
                $began = microtime(true);
                $state['phase'] === 'data' ? $import->importData($state) : $import->importMedia($state);
                SiteImport::saveState($state);
                $rest > 0 && usleep((int) ((microtime(true) - $began) * $rest * 1_000_000)); // the database and the disk get their breath too
            }
            if ($state['phase'] !== 'done') {
                $p = self::progress($site);

                return 'importing ' . ($p['percent'] ?? 0) . ' %';
            }
            self::finish($owner, $site, $state);

            return 'ready';
        } catch (\Throwable $e) {
            ErrorReport::capture($e, 'error', ['tags' => ['job' => 'sites_import']]);

            return self::fail($owner, $site, $import !== null ? [$import, $state] : null, $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
        }
    }

    /** The mail, AI, time zone and language settings of the site the new one is made beside, as for an empty site (never the archive's secrets: it has none). */
    private static function accountSettings(Db $owner, Db $new, int $sourceId): void
    {
        $sourcePrefix = (string) ($owner->value('SELECT prefix FROM {sites} WHERE site_id = ?', [$sourceId]) ?? $owner->ownerPrefix);
        $marks = implode(', ', array_fill(0, count(Settings::ACCOUNT), '?'));
        foreach ($owner->forSite($sourcePrefix)->all('SELECT name, value FROM {settings} WHERE name IN (' . $marks . ')', Settings::ACCOUNT) as $r) {
            $new->upsert('settings', ['name' => $r['name'], 'value' => $r['value']], ['name']);
        }
    }

    /** @param array<string, mixed> $state */
    private static function finish(Db $owner, array $site, array $state): void
    {
        $prefix = (string) $site['prefix'];
        $people = array_values(array_filter((array) ($state['people'] ?? []), 'is_array'));
        $owner->update('sites', ['ready_at' => date('Y-m-d H:i:s'), 'import_file' => '', 'import_error' => '', 'import_people' => $people === [] ? null : (string) json_encode($people, JSON_UNESCAPED_UNICODE)], ['site_id' => (int) $site['site_id']]);
        Domains::addPreview($owner, (int) $site['site_id'], (string) $site['slug']);
        try {
            $owner->forSite($prefix)->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => null, 'user_name' => 'Import', 'via' => '', 'module' => 'sites', 'action' => 'import', 'description' => 'Imported from a Talea archive']);
        } catch (\Throwable) {
            // the log must not stop the import
        }
        self::forget((string) $site['public_id']);
    }

    /**
     * The import stopped: the site stays not ready with the reason, the rows it added to the owner's shared tables are taken out, its files go.
     *
     * @param array{0: SiteImport, 1: array<string, mixed>}|null $running
     * @param array<string, mixed> $site
     */
    private static function fail(Db $owner, array $site, ?array $running, string $message): string
    {
        try {
            $running !== null && $running[0]->undoOwnerRows($running[1]);
        } catch (\Throwable) {
            // the original error is the one to report
        }
        $message = mb_substr($message, 0, 500);
        $owner->update('sites', ['import_error' => $message], ['site_id' => (int) $site['site_id']]);
        self::forget((string) $site['public_id']);

        return 'error: ' . $message;
    }
}
