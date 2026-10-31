<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Collections;
use Talea\Builder\DesignSystem;

/**
 * Import of a Talea export (1.8): moves a whole site into a new, empty installation – the counterpart of SiteExport.
 *
 * The site is empty, so the rows get their integer keys from their order in the export (the first row of a table is 1) and keep
 * the public_id (UUID v4) the export gave them: the file has no integer keys. Every reference between rows is a public id
 * (see SiteExport); prepare() reads all public ids once into ids.json (public id => the new integer key), and every row, build,
 * menu and rule is pointed at the new keys before it is validated and stored. What comes in goes through the same validators as when saving
 * in the administration: builds (Build::sanitize), classes (Style), menus, collection data, pop-up rules, design system.
 * Users, passwords, keys and tokens are never in an export, so they are never imported; the imported news belong to the
 * administrator who runs the import.
 *
 * Steps: prepare – content.json is split into one file per table (one row per
 * line, so even a large export does not need to fit in memory); preview; data – a database backup, emptying the content
 * and the rows in batches; media – files from the archive in batches; done. The state is a file in storage/import. Data and media
 * run as a background job (work()); while it runs the site shows the maintenance notice and the administration only the progress.
 */
final class SiteImport
{
    use SiteImportRows;

    /** Tables in the order of import (a folder before media, a collection before its items). */
    public const array TABLES = ['categories', 'tags', 'popups', 'pages', 'news', 'redirects', 'classes', 'site_parts', 'components', 'forms', 'sections', 'menus',
        'collections', 'collection_templates', 'collection_items', 'document_versions', 'media_folders', 'media', 'facts', 'hours_exceptions', 'notice_log', 'blueprints', 'notebook',
        'booking_services', 'booking_staff', 'booking_staff_services', 'booking_hours', 'booking_off'];

    /** Content emptied before the import (including what depends on it: versions, drafts, usage and link checks). */
    private const array EMPTIED = ['news_tags', 'news_revisions', 'news_drafts', 'page_revisions', 'build_revisions', 'media_usage', 'broken_links',
        'collection_items', 'collection_templates', 'collections', 'news', 'categories', 'tags', 'pages', 'redirects', 'classes', 'site_parts', 'components', 'forms', 'sections',
        'menus', 'popups', 'media', 'media_folders', 'import_map', 'facts', 'fact_history', 'hours_exceptions', 'document_versions', 'document_downloads', 'notice_log', 'blueprints', 'notebook', 'draft_comments',
        'booking_staff_services', 'booking_hours', 'booking_off', 'booking_staff', 'booking_services']; // the bookings themselves stay: personal data of this site's customers

    /** The rows a site import ADDS to the owner's shared tables (never empties them): ids are new, public ids that are taken get a new one. */
    private const array OWNER_ROWS = ['media_folders', 'media', 'components', 'facts'];

    private const int BATCH = 300;
    private const int MEDIA_BATCH = 100;
    private const int SECONDS = 8;

    /** @var array<string, list<string>> columns of the tables on this site */
    private array $columns = [];

    /** @var array<string, array<string, int>>|null table => public id => the integer key the row gets (ids.json of the working folder) */
    private ?array $ids = null;

    /** The export brings the notice log itself (2.11) – otherwise every imported notice gets a 'created' row. */
    private bool $exportHasNoticeLog = false;

    /** @var array<int, array<string, mixed>|null> idk => the collection when it is an official notice board (for the 'created' rows of imported notices) */
    private array $noticeBoards = [];

    /** @var string the folder of the state and working files: storage/import, or that of a site import */
    private string $folder = WpFile::FOLDER;

    /** @var array<string, array<string, int>> table => public id in the archive => the key the row got (the owner rows of a site import) */
    private array $map = [];

    /** @var array<string, array{name: string, email: string}> the people the archive names (authors of news, booking staff), by lower-case name */
    private array $people = [];

    /** @var string the archive's file name: names the working folder */
    private string $stateFileName = '';

    /** @var list<string> media paths of the archive that were refused (not a file of the archive), reported in the state */
    private array $dropped = [];

    /** @var array<string, string>|null archive path => path in the owner's media folder (a site import) */
    private ?array $mediaMap = null;

    /**
     * $mediaDir non-empty = a SITE import (Sites → Import a Talea archive): $db is the new, empty site; media, folders, components and facts
     * are ADDED to the owner's shared tables (the media files into $mediaDir) and the other sites keep working. $admin 0 = the importer is not
     * a user of this owner (an operator): authors stay empty.
     */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $admin, private readonly string $mediaDir = '')
    {
    }

    private function siteMode(): bool
    {
        return $this->mediaDir !== '';
    }

    /** @param array<string, mixed> $state the folder of an import's files */
    private static function dir(array $state): string
    {
        return (string) ($state['folder'] ?? WpFile::FOLDER);
    }

    /** The integer key the exported row with this public id gets (0: not a public id of the export). */
    private function ref(string $table, mixed $publicId): int
    {
        if ($this->siteMode() && in_array($table, self::OWNER_ROWS, true)) {
            return Uuid::valid($publicId) ? (int) ($this->map[$table][(string) $publicId] ?? 0) : 0;
        }

        return Uuid::valid($publicId) ? (int) ($this->ids[$table][(string) $publicId] ?? 0) : 0;
    }

    /** The row's public id when it is a valid one (the import keeps it), otherwise a new one. */
    private static function publicId(array $r): string
    {
        return Uuid::valid($r['public_id'] ?? null) ? (string) $r['public_id'] : Uuid::v4();
    }

    /** publicId() for a row of the owner's shared tables: in a site import an id another site's row already has is not taken over. */
    private function ownerPublicId(string $table, array $r): string
    {
        $id = self::publicId($r);

        return $this->siteMode() && $this->db->value('SELECT 1 FROM {' . $table . '} WHERE public_id = ?', [$id]) !== null ? Uuid::v4() : $id;
    }

    /** The key a row of the owner's shared tables is stored under: its own number in a normal import (the site was empty), the new one in a site import. */
    private function ownerKey(string $table, string $key, int $ordinal): array
    {
        return $this->siteMode() ? [] : [$key => $ordinal];
    }

    /* ---------- files in storage/import ---------- */

    /** @return list<array{file:string, size:int, time:int}> Talea exports in storage/import, newest on top */
    public static function listAll(): array
    {
        $files = [];
        foreach (glob(WpFile::FOLDER . '/*.{zip,json}', GLOB_BRACE) ?: [] as $path) {
            if (self::isValidName(basename($path))) {
                $files[] = ['file' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $files;
    }

    public static function isValidName(string $file): bool
    {
        return $file !== '' && strlen($file) <= 150 && basename($file) === $file && !str_starts_with($file, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['zip', 'json'], true)
            && !preg_match('/^(talea-)?state-[0-9a-f]{16}\.json$/', $file); // state files of the imports live in the same folder
    }

    public static function path(string $file, string $folder = WpFile::FOLDER): ?string
    {
        return self::isValidName($file) && is_file($folder . '/' . $file) ? $folder . '/' . $file : null;
    }

    public static function uploadName(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) === 'json' ? 'json' : 'zip';

        return slugify(pathinfo($original, PATHINFO_FILENAME), 80) . '.' . $extension;
    }

    /* ---------- state ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file, string $folder = WpFile::FOLDER): array
    {
        return ($folder !== WpFile::FOLDER ? ['folder' => $folder] : []) + ['file' => $file, 'phase' => 'preparing', 'header' => [], 'counts' => [], 'media_total' => 0, 'table' => 0, 'position' => 0,
            'emptied' => false, 'backup' => '', 'media_position' => 0, 'result' => [], 'media' => ['saved' => 0, 'skipped' => 0], 'errors' => []];
    }

    private static function stateFile(string $file, string $folder = WpFile::FOLDER): string
    {
        return $folder . '/talea-state-' . substr(sha1($file), 0, 16) . '.json';
    }

    /** Working folder of the import: content.json and one file per table. */
    private static function workFolder(string $file, string $folder = WpFile::FOLDER): string
    {
        return $folder . '/talea-' . substr(sha1($file), 0, 16);
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file, string $folder = WpFile::FOLDER): ?array
    {
        $data = is_file(self::stateFile($file, $folder)) ? json_decode((string) file_get_contents(self::stateFile($file, $folder)), true) : null;

        return is_array($data) ? array_replace(self::newState($file, $folder), $data) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['file'], self::dir($state));
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    /** Deletes the state and the working folder (the file itself stays until the administrator deletes it). */
    public static function deleteState(string $file, string $folder = WpFile::FOLDER): void
    {
        @unlink(self::stateFile($file, $folder));
        self::cleanUp($file, $folder);
    }

    /**
     * What the site already contains; the import is allowed only on an empty site (a fresh installation, possibly with
     * a starter site: at most its five pages and the welcome news item).
     *
     * @return array{empty: bool, pages: int, news: int, items: int, media: int}
     */
    public static function siteContent(Db $db): array
    {
        $c = ['pages' => (int) $db->value('SELECT COUNT(*) FROM {pages}'), 'news' => (int) $db->value('SELECT COUNT(*) FROM {news}'),
            'items' => (int) $db->value('SELECT COUNT(*) FROM {collection_items}'), 'media' => (int) $db->value('SELECT COUNT(*) FROM {media}')];

        return ['empty' => $c['pages'] <= 5 && $c['news'] <= 1 && $c['items'] === 0 && $c['media'] === 0] + $c;
    }

    /* ---------- 1. preparation ---------- */

    /**
     * Reads the header and splits content.json into files per table (one JSON row per line); counts rows and media files.
     * A Kaleta export (obsah.json) is converted into a Talea export first (KaletaExport), replacing the uploaded file.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException the file is not a Talea export or comes from a newer Talea
     */
    public static function prepare(array &$state, ?Db $owner = null): void
    {
        $path = self::path((string) $state['file'], self::dir($state)) ?? throw new \RuntimeException('The file does not exist.');
        $work = self::workFolder((string) $state['file'], self::dir($state));
        if (!is_dir($work) && !@mkdir($work, 0775, true)) {
            throw new \RuntimeException('Cannot create the storage/import folder – check write permissions.');
        }
        $json = $work . '/content.json';
        $mediaCount = 0;
        $entries = [];
        $renamed = [];
        $warnings = [];
        if (KaletaExport::is($path)) {
            // a Kaleta export becomes a Talea export in place; everything below reads only the converted file
            $converted = $work . '/converted.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $warnings = KaletaExport::convert($path, $converted);
            if (!rename($converted, $path)) {
                throw new \RuntimeException('Cannot write the converted export.');
            }
        }
        if (str_ends_with(strtolower($path), '.zip')) {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('The PHP zip extension is missing on the server – upload content.json from the archive instead.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true || ($in = $zip->getStream('content.json')) === false) {
                throw new \RuntimeException('The file is not a Talea export (content.json is missing in the archive).');
            }
            $out = fopen($json, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $target = ArchiveMedia::target($zip, $name);
                if ($target !== null) {
                    $mediaCount++;
                    $entries[$name] = $target;
                    if ($target !== $name) {
                        $renamed[$name] = $target; // photo.JPG is saved as photo.jpg – the content follows (split())
                    }
                }
            }
            $zip->close();
            if ($owner !== null) {
                // a site import: the files go into the owner's media folder, next to what the owner's other sites keep there
                $mediaMap = ArchiveMedia::intoFolder(array_values(array_unique($entries)), (string) $state['media_dir'], static fn (string $p): bool => is_file(TALEA_ROOT . '/' . $p)
                    || $owner->value('SELECT 1 FROM {media} WHERE image_path = ? OR thumb_path = ?', [$p, $p]) !== null, substr(sha1((string) ($state['site_prefix'] ?? '') . '|' . $state['file']), 0, 6));
                $renamed = []; // the content follows by name, also the archive's own spelling of an upper-case extension
                foreach ($entries as $name => $target) {
                    if ($mediaMap[$target] !== $name) {
                        $renamed[$name] = $mediaMap[$target];
                    }
                }
                file_put_contents($work . '/media-map.json', (string) json_encode($mediaMap === [] ? new \stdClass() : $mediaMap, JSON_UNESCAPED_SLASHES));
            }
        } else {
            copy($path, $json);
        }
        [$header, $counts] = self::split($json, $work, $renamed);
        @unlink($json);
        if (($header['format'] ?? '') !== 'talea-export') {
            throw new \RuntimeException('The file is not a Talea export.');
        }
        if ((int) ($header['format_version'] ?? 0) < 3) {
            throw new \RuntimeException('This export is from before public ids (format version 2 or older) – export the site again with a current Talea.');
        }
        if ((int) ($header['format_version'] ?? 0) > SiteExport::FORMAT_VERSION || version_compare((string) ($header['talea'] ?? '0'), TALEA_VERSION, '>')) {
            throw new \RuntimeException('The export comes from a newer version of Talea – update this site first (pull the newer image).');
        }
        $state['header'] = ['talea' => (string) ($header['talea'] ?? ''), 'created_at' => (string) ($header['created_at'] ?? ''),
            'name' => (string) ($header['settings']['site_name'] ?? ''), 'format_version' => (int) ($header['format_version'] ?? 1),
            'converted_from' => (string) ($header['converted_from'] ?? ''), 'conversion_warnings' => $warnings];
        $state['counts'] = $counts;
        $state['media_total'] = $mediaCount;
        $state['phase'] = 'preview';
    }

    /**
     * content.json as written by SiteExport has one row per line – read as a stream. Any other layout (e.g. formatted by hand)
     * is read whole, when it is not too large.
     *
     * @param array<string, string> $renamed media saved under another name (archive name => saved name, ArchiveMedia::target())
     * @return array{0: array<string, mixed>, 1: array<string, int>} header with settings, rows per table
     */
    private static function split(string $json, string $work, array $renamed = []): array
    {
        $parts = [];
        $counts = [];
        $ids = [];
        $write = function (string $table, array $row) use (&$parts, &$counts, &$ids, $work, $renamed): void {
            if (!in_array($table, self::TABLES, true)) {
                return;
            }
            $row = ArchiveMedia::rename($row, $renamed);
            $parts[$table] ??= fopen($work . '/' . $table . '.ndjson', 'wb');
            fwrite($parts[$table], json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            $counts[$table] = ($counts[$table] ?? 0) + 1;
            if (in_array($table, Db::PUBLIC_ID_TABLES, true) && Uuid::valid($row['public_id'] ?? null)) {
                $ids[$table][(string) $row['public_id']] = $counts[$table]; // the row's number in its table is its key on this site
            }
        };
        $f = fopen($json, 'rb');
        $first = (string) fgets($f);
        $header = json_decode(rtrim(rtrim($first), ',') . '}', true);
        $table = null;
        $streamed = is_array($header);
        while ($streamed && ($line = fgets($f)) !== false) {
            $line = rtrim($line);
            if (preg_match('/^"([a-z_]+)":\[$/', $line, $m)) {
                $table = $m[1];
            } elseif (str_starts_with($line, '{') && $table !== null) {
                $row = json_decode(rtrim($line, ','), true);
                if (!is_array($row)) {
                    $streamed = false;
                    break;
                }
                $write($table, $row);
            } elseif (in_array($line, [']', '],', ']}', ''], true)) {
                $table = null;
            } else {
                $streamed = false;
            }
        }
        fclose($f);
        if (!$streamed) {
            foreach ($parts as $h) {
                fclose($h);
            }
            [$parts, $counts] = [[], []];
            if (filesize($json) > 64 * 1024 * 1024) {
                throw new \RuntimeException('The file is not a Talea export.');
            }
            $data = json_decode((string) file_get_contents($json), true);
            if (!is_array($data)) {
                throw new \RuntimeException('The file is not a Talea export.');
            }
            $header = array_diff_key($data, array_flip(self::TABLES));
            foreach (self::TABLES as $t) {
                foreach (is_array($data[$t] ?? null) ? $data[$t] : [] as $row) {
                    if (is_array($row)) {
                        $write($t, $row);
                    }
                }
            }
        }
        foreach ($parts as $h) {
            fclose($h);
        }
        file_put_contents($work . '/ids.json', (string) json_encode($ids === [] ? new \stdClass() : $ids));
        if (is_array($header['settings'] ?? null)) {
            file_put_contents($work . '/settings.json', (string) json_encode(ArchiveMedia::rename($header['settings'], $renamed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [is_array($header) ? $header : [], $counts];
    }

    /* ---------- 2. data ---------- */

    /**
     * One batch: the first one backs up the database and empties the content, then rows table by table. After the last
     * table the settings are applied and the media step follows.
     *
     * @param array<string, mixed> $state
     */
    public function importData(array &$state): void
    {
        $this->bind($state);
        if (!$state['emptied']) {
            if ($this->siteMode()) {
                $this->startSite($state);

                return;
            }
            if (!self::siteContent($this->db)['empty'] || Sites::importRefusal($this->db) !== null) {
                throw new \RuntimeException(Sites::importRefusal($this->db) ?? 'The site already has its own content. A Talea export can be imported only into a new, empty site.');
            }
            $state['backup'] = Backup::create($this->db, 'before_import');
            $this->db->emptyTables(self::EMPTIED);
            $state['emptied'] = true;

            return;
        }
        $start = microtime(true);
        $done = 0;
        $this->exportHasNoticeLog = (int) ($state['counts']['notice_log'] ?? 0) > 0;
        $this->loadIds((string) $state['file']);
        $tables = self::order($this->siteMode());
        $this->people = (array) ($state['people'] ?? []);
        while ($state['table'] < count($tables) && $done < self::BATCH && microtime(true) - $start < self::SECONDS) {
            $table = $tables[$state['table']];
            $file = self::workFolder((string) $state['file'], $this->folder) . '/' . $table . '.ndjson';
            if (!is_file($file) || $state['position'] >= (int) ($state['counts'][$table] ?? 0)) {
                if ($table === 'media_folders' && is_file($file)) {
                    $this->linkFolders($file); // every folder exists now: a parent may come after its child in the file
                }
                $state['table']++;
                $state['position'] = 0;
                continue;
            }
            $f = new \SplFileObject($file, 'rb');
            $f->seek((int) $state['position']);
            $this->db->transaction(function () use ($f, $table, &$state, &$done, $start): void {
                while (!$f->eof() && $done < self::BATCH && microtime(true) - $start < self::SECONDS) {
                    $line = trim((string) $f->current());
                    $f->next();
                    $state['position']++;
                    if ($line === '') {
                        continue; // the end of the file is not a skipped row
                    }
                    $done++;
                    $row = json_decode($line, true);
                    $ok = is_array($row) && $this->insert($table, $row, (int) $state['position']);
                    $state['result'][$table][$ok ? 'ok' : 'skipped'] = ($state['result'][$table][$ok ? 'ok' : 'skipped'] ?? 0) + 1;
                }
            });
            $this->saveMap();
        }
        $state['people'] = $this->people;
        $state['refused_media'] = array_slice(array_merge((array) ($state['refused_media'] ?? []), $this->dropped), 0, 50);
        $this->dropped = [];
        if ($state['table'] >= count($tables)) {
            $this->db->syncSequences(...array_values(array_diff(self::TABLES, $this->siteMode() ? self::OWNER_ROWS : [])));
            $this->loadIds((string) $state['file']);
            $this->applySettings((string) $state['file'], (string) ($state['header']['talea'] ?? ''));
            $state['phase'] = $state['media_total'] > 0 ? 'media' : 'done';
            if ($state['phase'] === 'done') {
                $this->finish();
            }
        }
    }

    /** @param array<string, mixed> $state the import this object works on: its files, and what an earlier batch added to the owner's shared tables */
    private function bind(array $state): void
    {
        $this->folder = self::dir($state);
        $this->stateFileName = (string) $state['file'];
        $map = $this->siteMode() ? json_decode((string) @file_get_contents(self::workFolder($this->stateFileName, $this->folder) . '/owner-map.json'), true) : null;
        $this->map = is_array($map) ? $map : [];
        $this->mediaMap ??= $this->siteMode() ? (array) json_decode((string) @file_get_contents(self::workFolder($this->stateFileName, $this->folder) . '/media-map.json'), true) : [];
    }

    /**
     * The tables in the order of import: a site import adds the owner's shared rows first, so that every reference to them is known.
     *
     * @return list<string>
     */
    public static function order(bool $site): array
    {
        return $site ? [...self::OWNER_ROWS, ...array_diff(self::TABLES, self::OWNER_ROWS)] : self::TABLES;
    }

    /**
     * The first batch of a site import: the new site's tables are emptied again (a run that stopped half-way starts over), the components are
     * given their numbers before anything refers to them, and the per-language address keys are switched on if the archive needs them.
     * Nothing of the owner's other sites is touched: the owner's shared tables are only added to (and the rows of an earlier try removed).
     *
     * @param array<string, mixed> $state
     */
    private function startSite(array &$state): void
    {
        $this->undoOwnerRows($state);
        $this->db->emptyTables(array_values(array_diff(self::EMPTIED, self::OWNER_ROWS, ['fact_history'])));
        // the components first: a component may place another one, which comes later in the file
        $file = self::workFolder((string) $state['file'], $this->folder) . '/components.ndjson';
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $n => $line) {
            $r = json_decode($line, true);
            if (is_array($r) && self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100) !== '') {
                $r['_id'] = $n + 1;
                $this->map['components'][self::componentKey($r)] = $this->addOwnerRow('components', ['name' => self::text(trim(strip_tags((string) $r['name'])), 100), 'properties' => '{}',
                    'public_id' => $this->ownerPublicId('components', $r), 'site_id' => Sites::currentId($this->db), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $this->saveMap();
        $state['emptied'] = true;
    }

    /** Adds a row to a shared table of the owner and remembers it, so that a failed import can take it out again. */
    private function addOwnerRow(string $table, array $row): int
    {
        $id = $this->db->insert($table, $row);
        file_put_contents(self::workFolder($this->stateFileName, $this->folder) . '/owner-rows.txt', $table . ' ' . $id . "\n", FILE_APPEND | LOCK_EX);

        return $id;
    }

    private function saveMap(): void
    {
        if ($this->siteMode()) {
            file_put_contents(self::workFolder($this->stateFileName, $this->folder) . '/owner-map.json', (string) json_encode($this->map === [] ? new \stdClass() : $this->map));
        }
    }

    /** Takes the rows an import of this site added to the owner's shared tables out again (a failed or restarted import). */
    public function undoOwnerRows(array $state): void
    {
        $this->bind($state);
        $list = self::workFolder($this->stateFileName, $this->folder) . '/owner-rows.txt';
        foreach (is_file($list) ? (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            [$table, $id] = explode(' ', $line) + [1 => '0'];
            if (in_array($table, self::OWNER_ROWS, true)) {
                $this->db->delete($table, [Db::PRIMARY_KEYS[$table] ?? 'id' => (int) $id]);
            }
        }
        @unlink($list);
        @unlink(self::workFolder($this->stateFileName, $this->folder) . '/owner-map.json');
        $this->map = [];
    }

    private function loadIds(string $file): void
    {
        $this->ids ??= (array) json_decode((string) @file_get_contents(self::workFolder($file, $this->folder) . '/ids.json'), true);
    }

    /** One row: cleaned by the table's rules, only columns this site has; false = skipped. $ordinal = its number in the table = its key. */
    private function insert(string $table, array $r, int $ordinal): bool
    {
        $r['_id'] = $ordinal;
        $clean = match ($table) {
            'categories' => $this->category($r),
            'tags' => $this->tag($r),
            'pages' => $this->page($r),
            'news' => $this->newsItem($r),
            'redirects' => $this->redirect($r),
            'classes' => $this->sharedClass($r),
            'site_parts' => $this->sitePart($r),
            'components' => $this->component($r),
            'forms' => $this->formRow($r),
            'sections' => $this->section($r),
            'menus' => $this->menu($r),
            'collections' => $this->collection($r),
            'collection_templates' => $this->collectionTemplate($r),
            'collection_items' => $this->collectionItem($r),
            'document_versions' => $this->documentVersion($r),
            'popups' => $this->popup($r),
            'media_folders' => $this->ownerKey('media_folders', 'folder_id', $ordinal) + ['public_id' => $this->ownerPublicId('media_folders', $r), 'name' => mb_substr(trim(strip_tags((string) ($r['name'] ?? ''))), 0, 100)],
            'media' => $this->mediaRow($r),
            'facts' => self::fact($r),
            'hours_exceptions' => self::hoursException($r),
            'booking_services' => self::bookingService($r),
            'booking_staff' => self::bookingStaff($r),
            'booking_staff_services' => ($staff = $this->ref('booking_staff', $r['staff_id'] ?? null)) > 0 && ($service = $this->ref('booking_services', $r['service_id'] ?? null)) > 0 ? ['staff_id' => $staff, 'service_id' => $service] : null,
            'booking_hours' => $this->bookingHours($r),
            'booking_off' => $this->bookingOff($r),
            'blueprints' => self::blueprint($r),
            'notice_log' => $this->noticeLogRow($r),
            'notebook' => self::note($r),
        };
        if ($clean === null) {
            return false;
        }
        $tags = $clean['_tags'] ?? [];
        unset($clean['_tags']);
        $clean = array_intersect_key($clean + (in_array($table, ['components', 'facts'], true) ? ['site_id' => Sites::currentId($this->db)] : []), array_flip($this->columns($table))); // an imported component or fact belongs to this site
        try {
            if ($this->siteMode() && $table === 'components') {
                $this->db->update('components', array_diff_key($clean, ['component_id' => 1, 'public_id' => 1]), ['component_id' => (int) $clean['component_id']]); // the row reserved for it
            } elseif ($this->siteMode() && in_array($table, self::OWNER_ROWS, true)) {
                $id = $this->addOwnerRow($table, $clean);
                if (Uuid::valid($r['public_id'] ?? null) && $table !== 'facts') {
                    $this->map[$table][(string) $r['public_id']] = $id;
                }
            } else {
                $this->db->insert($table, $clean);
            }
        } catch (\PDOException) {
            return false; // a duplicate number or address in the export
        }
        if ($table === 'news') {
            $this->person((string) ($r['author'] ?? ''), '');
        } elseif ($table === 'booking_staff') {
            $this->person((string) $clean['name'], (string) $clean['email']);
        }
        foreach ($tags as $tagId) {
            $this->db->insertIgnore('news_tags', ['news_id' => $clean['news_id'], 'tag_id' => $tagId]);
        }
        if ($table === 'collection_items' && !$this->exportHasNoticeLog) {
            $this->logImportedNotice($clean);
        }

        return true;
    }

    /** The second pass over the imported folders: parent_id by the parent's public id; an unknown parent, a cycle or a skipped row leaves the folder at the top level. */
    private function linkFolders(string $file): void
    {
        // streamed and resolved in memory: MediaFolders::move() aggregates every folder per call, far too slow for thousands
        $parents = [];
        $exists = array_flip(array_map('intval', array_column($this->db->all('SELECT folder_id FROM {media_folders}'), 'folder_id'))); // a malformed archive may name a row that was skipped: the foreign key would refuse it
        foreach (new \SplFileObject($file) as $line) {
            $r = json_decode((string) $line, true);
            $id = is_array($r) ? $this->ref('media_folders', $r['public_id'] ?? null) : 0;
            $parent = is_array($r) ? $this->ref('media_folders', $r['parent_id'] ?? null) : 0;
            if (isset($exists[$id], $exists[$parent]) && $id !== $parent) {
                $parents[$id] = $parent;
            }
        }
        foreach (array_keys($parents) as $id) {
            $seen = [];
            for ($at = $parents[$id] ?? 0; $at > 0 && !isset($seen[$at]); $at = $parents[$at] ?? 0) {
                if ($at === $id) {
                    unset($parents[$id]); // closes a cycle: this folder stays on the top level
                    break;
                }
                $seen[$at] = true;
            }
        }
        foreach ($parents as $id => $parent) {
            $this->db->update('media_folders', ['parent_id' => $parent], ['folder_id' => $id]);
        }
    }

    /** Remembers a person the archive names (the author of a news item, a person who takes bookings), once. */
    private function person(string $name, string $email): void
    {
        $name = trim(strip_tags($name));
        if ($name !== '' || $email !== '') {
            $this->people[mb_strtolower($email !== '' ? $email : $name)] ??= ['name' => mb_substr($name, 0, 120), 'email' => $email];
        }
    }

    /** An imported notice of an official notice board (2.11, Core\Notices) starts its audit trail with a 'created' row by "import". */
    private function logImportedNotice(array $item): void
    {
        $idk = (int) $item['collection_id'];
        if (!array_key_exists($idk, $this->noticeBoards)) {
            $collection = Collections::byId($this->db, $idk);
            $this->noticeBoards[$idk] = $collection !== null && Notices::isNotices($collection) ? $collection : null;
        }
        if ($this->noticeBoards[$idk] !== null) {
            Notices::log($this->db, (int) $item['item_id'], 'created', Notices::changes($this->noticeBoards[$idk], null, $item), 'import');
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= $this->db->columns($table);
    }

    /** The public settings of the export (the same allowlist the export uses); the address of this site stays. */
    private function applySettings(string $file, string $fromVersion): void
    {
        $values = json_decode((string) @file_get_contents(self::workFolder($file, $this->folder) . '/settings.json'), true);
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $key = (string) $key;
            $base = (string) preg_replace('/_' . Language::TAG . '$/D', '', $key);
            if (!is_scalar($value) || $key === 'site_url' || (!in_array($key, SiteExport::SETTINGS, true) && !(in_array($base, Settings::PER_LANGUAGE, true) && $base !== $key))) {
                continue;
            }
            $value = (string) $value;
            $value = match ($key) {
                'design_system' => (string) json_encode(DesignSystem::sanitize(json_decode($value, true) ?: []), JSON_UNESCAPED_SLASHES),
                'logo', 'favicon', 'share_image' => self::file($value),
                'home_page' => (string) $this->ref('pages', $value),
                'news_per_page' => (string) max(0, (int) $value),
                'news_slug' => Routes::systemSlugError($value) === null ? $value : null,
                'time_zone' => in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null,
                // a regional language of the archive keeps the language the new site already has (en-gb into an English site stays English)
                'site_language' => isset(Language::AVAILABLE[$value]) && Language::forSite($value, [Language::defaults($this->settings)]) === $value ? $value : null,
                'additional_languages' => implode(',', array_filter(explode(',', $value), fn (string $c): bool => isset(Language::AVAILABLE[$c]))),
                'extensions' => $value === '-' ? '-' : implode(',', Extensions::imported($value, $values['extensions_off'] ?? null)),
                // a field of the admin form is validated like the form and MCP do (3.3.3, N55): company_map or social_*
                // "javascript:…" from a crafted archive is dropped and the setting keeps its value
                default => \Talea\Admin\Modules\Settings::checkable($key) ? \Talea\Admin\Modules\Settings::verifyValue($key, $value) : mb_substr($value, 0, 20_000),
            };
            if ($value !== null) {
                $this->settings->set($key, $value);
            }
        }
        $this->settings->set('look_draft', '');
        // an export from before 3.2 knew Bookings as part of the core: a site that brings its booking set-up keeps the
        // feature switched on (as migration 0073 does for an updated site)
        if (version_compare($fromVersion, '3.2.0', '<') && $this->db->value('SELECT 1 FROM {booking_services} LIMIT 1') !== null) {
            Extensions::save($this->settings, array_values(array_unique([...Extensions::enabled($this->settings), 'bookings'])));
        }
    }

    /* ---------- 3. media ---------- */

    /**
     * Files from the archive into media/, in batches. Only images and the attachment types Media accepts, only inside
     * media/, never PHP or hidden files; an SVG is cleaned like an uploaded one.
     *
     * @param array<string, mixed> $state
     */
    public function importMedia(array &$state): void
    {
        $this->bind($state);
        $zip = new \ZipArchive();
        $path = self::path((string) $state['file'], $this->folder);
        if ($path === null || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The file does not exist.');
        }
        $start = microtime(true);
        $done = 0;
        $total = $zip->numFiles;
        while ($state['media_position'] < $total && $done < self::MEDIA_BATCH && microtime(true) - $start < self::SECONDS) {
            $name = (string) $zip->getNameIndex((int) $state['media_position']);
            $state['media_position']++;
            $target = ArchiveMedia::target($zip, $name);
            if ($target === null) {
                continue;
            }
            $this->mediaMap ??= $this->siteMode() ? (array) json_decode((string) @file_get_contents(self::workFolder($this->stateFileName, $this->folder) . '/media-map.json'), true) : [];
            $target = $this->mediaMap[$target] ?? $target; // a site import: into the owner's folder, under a name that is free
            $done++;
            $full = TALEA_ROOT . '/' . $target;
            $ok = is_dir(dirname($full)) || @mkdir(dirname($full), 0775, true);
            if ($ok && str_ends_with(strtolower($target), '.svg')) {
                $content = Svg::sanitize((string) $zip->getFromName($name)); // an SVG is cleaned like an uploaded one
                $ok = $content !== null && file_put_contents($full, $content) !== false;
            } elseif ($ok) {
                // streamed into the working folder: a video or a large PDF does not have to fit in memory, and the file reaches
                // media/ only once its content is what the extension says
                $temporary = self::workFolder((string) $state['file'], $this->folder) . '/media-' . bin2hex(random_bytes(6)) . '.tmp';
                $in = $zip->getStream($name);
                $out = $in === false ? false : @fopen($temporary, 'wb');
                $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false;
                foreach ([$in, $out] as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                $ok = $ok && ArchiveMedia::contentMatches($temporary, $target) && @rename($temporary, $full);
                @unlink($temporary);
            }
            if (!$ok) {
                $state['media']['skipped']++;
                if (count($state['errors']) < 20) {
                    $state['errors'][] = $target;
                }
                continue;
            }
            $state['media']['saved']++;
        }
        $zip->close();
        if ($state['media_position'] >= $total) {
            $state['phase'] = 'done';
            $this->finish();
        }
    }

    /** After the import: the search index, the cache and the working files. */
    private function finish(): void
    {
        for ($i = 0; $i < 500 && Search::complete($this->db, 200) > 0; $i++) {
            // the search index of the imported news, 200 at a time
        }
        if (!$this->siteMode()) {
            \Talea\Front\Cache::clear(); // a site import is not served yet
        }
    }

    /* ---------- the import as a background job ---------- */

    /** Marks the running import: while it exists, the site answers with the maintenance notice and the administration shows only the progress. */
    private const string RUNNING = WpFile::FOLDER . '/talea-running';

    /** The export being imported right now, null = none. */
    public static function running(): ?string
    {
        $file = is_file(self::RUNNING) ? trim((string) @file_get_contents(self::RUNNING)) : '';

        return $file !== '' && is_file(self::stateFile($file)) ? $file : null;
    }

    /**
     * Confirmation in the preview: the import becomes the site's background job. The person who confirmed it owns the imported news.
     *
     * @param array<string, mixed> $state
     */
    public static function start(array $state, int $admin): void
    {
        $state['phase'] = 'data';
        $state['admin'] = $admin;
        $state['error'] = '';
        self::saveState($state);
        file_put_contents(self::RUNNING, (string) $state['file'], LOCK_EX);
    }

    /**
     * Runs the batches of the running import until it is done, fails or the budget is used up; the rest waits for the next
     * call (after an admin response, Scheduler on cron and visits). One run at a time: a file lock. A failure stops the import
     * and ends the lock of the site – the backup made by the first batch is the way back.
     */
    public static function work(App $app, float $seconds): string
    {
        $file = self::running();
        if ($file === null) {
            @unlink(self::RUNNING);

            return 'not running';
        }
        $lock = fopen(WpFile::folder() . '/import.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'busy';
        }
        $state = self::loadState($file);
        try {
            $end = microtime(true) + $seconds;
            $import = new self($app->db(), $app->settings(), (int) ($state['admin'] ?? 0));
            while ($state !== null && in_array($state['phase'], ['data', 'media'], true) && microtime(true) < $end) {
                $state['phase'] === 'data' ? $import->importData($state) : $import->importMedia($state);
                self::saveState($state);
            }
            if ($state !== null && $state['phase'] === 'done') {
                self::cleanUp($file);
                \Talea\Admin\ChangeLog::write($app, 'transfer', 'import of a Talea export', $file);
            }
        } catch (\Throwable $e) {
            $state ??= self::newState($file);
            $state['error'] = $e->getMessage();
            self::saveState($state);
        } finally {
            if ($state === null || !in_array($state['phase'], ['data', 'media'], true) || ($state['error'] ?? '') !== '') {
                @unlink(self::RUNNING);
            }
            flock($lock, LOCK_UN);
        }

        return $state === null ? 'state missing' : trim($state['phase'] . ' ' . ($state['error'] ?? ''));
    }

    /** After an admin response (admin.php): the import goes on without the browser waiting; the session is closed first, so the next page does not wait for it. */
    public static function afterResponse(App $app): void
    {
        if (self::running() === null) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(0);
        self::work($app, 3600.0); // ponytail: one process for up to an hour; a longer import is picked up by the next admin request or cron
    }

    /** Removes the working folder of a finished import (the export file itself stays). */
    public static function cleanUp(string $file, string $folder = WpFile::FOLDER): void
    {
        foreach (glob(self::workFolder($file, $folder) . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir(self::workFolder($file, $folder));
    }
}
