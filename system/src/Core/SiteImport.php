<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Collections;
use Talea\Builder\Components;
use Talea\Builder\DesignSystem;
use Talea\Builder\Popups;
use Talea\Builder\SiteParts;
use Talea\Builder\Style;

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
 * Steps (Admin\Modules\Transfer, one step per request): prepare – content.json is split into one file per table (one row per
 * line, so even a large export does not need to fit in memory); preview; data – a database backup, emptying the content
 * and the rows in batches; media – files from the archive in batches; done. The state is a file in storage/import.
 */
final class SiteImport
{
    /** Tables in the order of import (a folder before media, a collection before its items). */
    public const array TABLES = ['categories', 'tags', 'popups', 'pages', 'news', 'redirects', 'classes', 'site_parts', 'components', 'sections', 'menus',
        'collections', 'collection_templates', 'collection_items', 'document_versions', 'media_folders', 'media', 'facts', 'hours_exceptions', 'notice_log', 'blueprints', 'notebook',
        'booking_services', 'booking_staff', 'booking_staff_services', 'booking_hours', 'booking_off'];

    /** Content emptied before the import (including what depends on it: versions, drafts, usage and link checks). */
    private const array EMPTIED = ['news_tags', 'news_revisions', 'news_drafts', 'page_revisions', 'build_revisions', 'media_usage', 'broken_links',
        'collection_items', 'collection_templates', 'collections', 'news', 'categories', 'tags', 'pages', 'redirects', 'classes', 'site_parts', 'components', 'sections',
        'menus', 'popups', 'media', 'media_folders', 'import_map', 'facts', 'fact_history', 'hours_exceptions', 'document_versions', 'document_downloads', 'notice_log', 'blueprints', 'notebook', 'draft_comments',
        'booking_staff_services', 'booking_hours', 'booking_off', 'booking_staff', 'booking_services']; // the bookings themselves stay: personal data of this site's customers

    /** Files that may come from the archive into media/ (images and the attachments Media accepts). */
    private const array MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];

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

    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $admin)
    {
    }

    /** The integer key the exported row with this public id gets (0: not a public id of the export). */
    private function ref(string $table, mixed $publicId): int
    {
        return Uuid::valid($publicId) ? (int) ($this->ids[$table][(string) $publicId] ?? 0) : 0;
    }

    /** The row's public id when it is a valid one (the import keeps it), otherwise a new one. */
    private static function publicId(array $r): string
    {
        return Uuid::valid($r['public_id'] ?? null) ? (string) $r['public_id'] : Uuid::v4();
    }

    /* ---------- files in storage/import ---------- */

    /** @return list<array{soubor:string, velikost:int, cas:int}> Talea exports in storage/import, newest on top */
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

    public static function path(string $file): ?string
    {
        return self::isValidName($file) && is_file(WpFile::FOLDER . '/' . $file) ? WpFile::FOLDER . '/' . $file : null;
    }

    public static function uploadName(string $original): string
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) === 'json' ? 'json' : 'zip';

        return slugify(pathinfo($original, PATHINFO_FILENAME), 80) . '.' . $extension;
    }

    /* ---------- state ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return ['file' => $file, 'phase' => 'preparing', 'header' => [], 'counts' => [], 'media_total' => 0, 'table' => 0, 'position' => 0,
            'emptied' => false, 'backup' => '', 'media_position' => 0, 'result' => [], 'media' => ['saved' => 0, 'skipped' => 0], 'errors' => []];
    }

    private static function stateFile(string $file): string
    {
        return WpFile::FOLDER . '/talea-state-' . substr(sha1($file), 0, 16) . '.json';
    }

    /** Working folder of the import: content.json and one file per table. */
    private static function workFolder(string $file): string
    {
        return WpFile::FOLDER . '/talea-' . substr(sha1($file), 0, 16);
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $data = is_file(self::stateFile($file)) ? json_decode((string) file_get_contents(self::stateFile($file)), true) : null;

        return is_array($data) ? array_replace(self::newState($file), $data) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['file']);
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    /** Deletes the state and the working folder (the file itself stays until the administrator deletes it). */
    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
        foreach (glob(self::workFolder($file) . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir(self::workFolder($file));
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
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException the file is not a Talea export or comes from a newer Talea
     */
    public static function prepare(array &$state): void
    {
        $path = self::path((string) $state['file']) ?? throw new \RuntimeException('The file does not exist.');
        $work = self::workFolder((string) $state['file']);
        if (!is_dir($work) && !@mkdir($work, 0775, true)) {
            throw new \RuntimeException('Cannot create the storage/import folder – check write permissions.');
        }
        $json = $work . '/content.json';
        $mediaCount = 0;
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
                if (self::mediaTarget($name) !== null) {
                    $mediaCount++;
                }
            }
            $zip->close();
        } else {
            copy($path, $json);
        }
        [$header, $counts] = self::split($json, $work);
        @unlink($json);
        if (($header['format'] ?? '') !== 'talea-export') {
            throw new \RuntimeException('The file is not a Talea export.');
        }
        if ((int) ($header['format_version'] ?? 0) < 3) {
            throw new \RuntimeException('This export is from before public ids (format version 2 or older) – export the site again with a current Talea.');
        }
        if ((int) ($header['format_version'] ?? 0) > SiteExport::FORMAT_VERSION || version_compare((string) ($header['talea'] ?? '0'), TALEA_VERSION, '>')) {
            throw new \RuntimeException('The export comes from a newer version of Talea – update this site first (Settings → Backups and updates).');
        }
        $state['header'] = ['talea' => (string) ($header['talea'] ?? ''), 'created_at' => (string) ($header['created_at'] ?? ''),
            'name' => (string) ($header['settings']['site_name'] ?? ''), 'format_version' => (int) ($header['format_version'] ?? 1)];
        $state['counts'] = $counts;
        $state['media_total'] = $mediaCount;
        $state['phase'] = 'preview';
    }

    /**
     * content.json as written by SiteExport has one row per line – read as a stream. Any other layout (e.g. formatted by hand)
     * is read whole, when it is not too large.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int>} header with settings, rows per table
     */
    private static function split(string $json, string $work): array
    {
        $parts = [];
        $counts = [];
        $ids = [];
        $write = function (string $table, array $row) use (&$parts, &$counts, &$ids, $work): void {
            if (!in_array($table, self::TABLES, true)) {
                return;
            }
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
            file_put_contents($work . '/settings.json', (string) json_encode($header['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        if (!$state['emptied']) {
            if (!self::siteContent($this->db)['empty']) {
                throw new \RuntimeException('The site already has its own content. A Talea export can be imported only into a new, empty site.');
            }
            $state['backup'] = Backup::create($this->db, 'before_import');
            $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
            foreach (self::EMPTIED as $table) {
                $this->db->run('DELETE FROM {' . $table . '}');
            }
            $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
            $state['emptied'] = true;

            return;
        }
        $start = microtime(true);
        $done = 0;
        $this->exportHasNoticeLog = (int) ($state['counts']['notice_log'] ?? 0) > 0;
        $this->loadIds((string) $state['file']);
        while ($state['table'] < count(self::TABLES) && $done < self::BATCH && microtime(true) - $start < self::SECONDS) {
            $table = self::TABLES[$state['table']];
            $file = self::workFolder((string) $state['file']) . '/' . $table . '.ndjson';
            if (!is_file($file) || $state['position'] >= (int) ($state['counts'][$table] ?? 0)) {
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
                    $done++;
                    $row = $line === '' ? null : json_decode($line, true);
                    $ok = is_array($row) && $this->insert($table, $row, (int) $state['position']);
                    $state['result'][$table][$ok ? 'ok' : 'skipped'] = ($state['result'][$table][$ok ? 'ok' : 'skipped'] ?? 0) + 1;
                }
            });
        }
        if ($state['table'] >= count(self::TABLES)) {
            $this->loadIds((string) $state['file']);
            $this->applySettings((string) $state['file'], (string) ($state['header']['talea'] ?? ''));
            $state['phase'] = $state['media_total'] > 0 ? 'media' : 'done';
            if ($state['phase'] === 'done') {
                $this->finish();
            }
        }
    }

    private function loadIds(string $file): void
    {
        $this->ids ??= (array) json_decode((string) @file_get_contents(self::workFolder($file) . '/ids.json'), true);
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
            'sections' => $this->section($r),
            'menus' => $this->menu($r),
            'collections' => $this->collection($r),
            'collection_templates' => $this->collectionTemplate($r),
            'collection_items' => $this->collectionItem($r),
            'document_versions' => $this->documentVersion($r),
            'popups' => $this->popup($r),
            'media_folders' => ['folder_id' => $ordinal, 'public_id' => self::publicId($r), 'name' => mb_substr(trim(strip_tags((string) ($r['name'] ?? ''))), 0, 100)],
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
        $clean = array_intersect_key($clean, array_flip($this->columns($table)));
        try {
            $this->db->insert($table, $clean);
        } catch (\PDOException) {
            return false; // a duplicate number or address in the export
        }
        foreach ($tags as $tagId) {
            $this->db->run('INSERT IGNORE INTO {news_tags} (news_id, tag_id) VALUES (?, ?)', [$clean['news_id'], $tagId]);
        }
        if ($table === 'collection_items' && !$this->exportHasNoticeLog) {
            $this->logImportedNotice($clean);
        }

        return true;
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
        return $this->columns[$table] ??= array_column($this->db->all('SHOW COLUMNS FROM {' . $table . '}'), 'Field');
    }

    /* ---------- rows ---------- */

    /** An address from the export when it is valid, otherwise one made from the name. */
    private static function slug(mixed $slug, string $fallback, int $max): string
    {
        $slug = is_string($slug) ? $slug : '';

        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) && strlen($slug) <= $max ? $slug : slugify($fallback, $max);
    }

    /** A value from a fixed list (types of pop-ups and the like), otherwise the first one. */
    private static function pick(array $list, mixed $value): string
    {
        return is_string($value) && isset($list[$value]) ? $value : (string) array_key_first($list);
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
    }

    /**
     * The HTML of a page or news item from the export, sanitized the way a save without code rights is (Html::safe): the archive
     * may come from anywhere, so it never brings script into the site. Structure, classes, images and links stay; an embedded
     * YouTube or Vimeo player becomes its URL on its own line, which the site shows as a player again.
     */
    private static function html(mixed $v, int $max): string
    {
        return Html::safe(WpContent::embeddedVideos(self::text($v, $max)));
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v) ? $v : null;
    }

    /**
     * "True until" and "review by" (2.10) of pages, news, items and pop-ups.
     *
     * @return array{valid_until: ?string, review_by: ?string}
     */
    private static function validity(array $r): array
    {
        $day = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;

        return ['valid_until' => $day($r['valid_until'] ?? null), 'review_by' => $day($r['review_by'] ?? null)];
    }

    /** A business fact (2.10); a value that does not fit its type is left out. @return array<string, mixed>|null */
    private static function fact(array $r): ?array
    {
        $key = (string) ($r['fact_key'] ?? '');
        $type = isset(Facts::TYPES[$r['type'] ?? '']) ? (string) $r['type'] : 'text';
        $value = Facts::clean($type, (string) ($r['value'] ?? ''));
        if (!preg_match(Facts::KEY_PATTERN, $key) || isset(Facts::BUILT_IN[$key]) || $value === null || ($type === 'text' && Facts::startsWithScheme($value))) {
            return null; // a text fact "javascript:…" is refused like in Facts::save (3.3.3, N50)
        }

        return ['fact_key' => $key, 'language' => self::language($r['language'] ?? ''), 'label' => self::text(strip_tags((string) ($r['label'] ?? $key)), 150), 'type' => $type, 'value' => $value,
            'schema_prop' => isset(Facts::SCHEMA_PROPS[$r['schema_prop'] ?? '']) ? (string) $r['schema_prop'] : '', 'source' => self::text(strip_tags((string) ($r['source'] ?? '')), 255),
            'updated_at' => date('Y-m-d H:i:s')];
    }

    /** An exception to the opening hours (2.10). @return array<string, mixed>|null */
    /** An applied industry blueprint (2.11): only a manifest that passes Core\Blueprint::sanitize. */
    private static function blueprint(array $r): ?array
    {
        [$manifest] = \Talea\Core\Blueprint::sanitize(is_array($r['manifest'] ?? null) ? $r['manifest'] : json_decode((string) ($r['manifest'] ?? ''), true));

        return $manifest === null ? null : ['bkey' => $manifest['key'], 'name' => \Talea\Core\Blueprint::text($manifest['name']), 'manifest' => (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'applied_at' => date('Y-m-d H:i:s')];
    }

    /** A note of the agent notebook (2.15, Core\Notebook) – the author and the dates stay as they were. @return array<string, mixed>|null */
    private static function note(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 150);
        $text = self::text(trim(strip_tags((string) ($r['text'] ?? ''))), Notebook::MAX_TEXT);
        if ($title === '' || $text === '') {
            return null;
        }
        $date = fn (mixed $v): string => is_string($v) && strtotime($v) !== false ? date('Y-m-d H:i:s', (int) strtotime($v)) : date('Y-m-d H:i:s');

        return ['id' => (int) ($r['id'] ?? 0) > 0 ? (int) $r['id'] : null, 'topic' => Notebook::topic($r['topic'] ?? '') ?? 'other', 'title' => $title, 'text' => $text, 'pinned' => !empty($r['pinned']) ? 1 : 0,
            'author' => self::text(trim(strip_tags((string) ($r['author'] ?? ''))), 100), 'created_at' => $date($r['created_at'] ?? null), 'updated_at' => $date($r['updated_at'] ?? null)];
    }

    /** @param array<string, mixed> $r */
    private static function bookingService(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $duration = (int) ($r['duration_min'] ?? 0);
        if (trim($name) === '' || $duration < 5 || $duration > Booking::MAX_DURATION) {
            return null;
        }

        return ['id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'duration_min' => $duration, 'buffer_min' => max(0, min(240, (int) ($r['buffer_min'] ?? 0))), 'price_text' => self::text(strip_tags((string) ($r['price_text'] ?? '')), 60),
            'description' => self::text(strip_tags((string) ($r['description'] ?? '')), 500), 'active' => !empty($r['active']) ? 1 : 0, 'requires_confirmation' => !empty($r['requires_confirmation']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r the account link (user_id) does not travel – the users of the new site are different */
    private static function bookingStaff(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $email = trim((string) ($r['email'] ?? ''));
        if (trim($name) === '') {
            return null;
        }

        return ['id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 190) : '', 'active' => !empty($r['active']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r */
    private function bookingHours(array $r): ?array
    {
        $ranges = Booking::parseHours([(int) ($r['weekday'] ?? 0) => (string) ($r['time_from'] ?? '') . '-' . (string) ($r['time_to'] ?? '')]);
        $staff = $this->ref('booking_staff', $r['staff_id'] ?? null);
        if ($staff <= 0 || $ranges === null || $ranges === []) {
            return null;
        }
        [$from, $to] = $ranges[(int) $r['weekday']][0];

        return ['staff_id' => $staff, 'weekday' => (int) $r['weekday'], 'time_from' => $from, 'time_to' => $to];
    }

    /** @param array<string, mixed> $r */
    private function bookingOff(array $r): ?array
    {
        $range = Booking::offRange(substr((string) ($r['off_from'] ?? ''), 0, 16), substr((string) ($r['off_to'] ?? ''), 0, 16));
        if ($range === null) {
            return null;
        }

        return ['staff_id' => ($staff = $this->ref('booking_staff', $r['staff_id'] ?? null)) > 0 ? $staff : null, 'off_from' => $range[0], 'off_to' => $range[1], 'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150)];
    }

    private static function hoursException(array $r): ?array
    {
        $from = (string) ($r['date_from'] ?? '');
        $to = (string) ($r['date_to'] ?? '');
        $closed = !empty($r['closed']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from
            || (!$closed && (Hours::parseRanges((string) ($r['hours'] ?? '')) ?? []) === [])) {
            return null;
        }

        return ['date_from' => $from, 'date_to' => $to, 'closed' => $closed ? 1 : 0, 'hours' => $closed ? '' : self::text((string) $r['hours'], 100),
            'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150), 'notice_days' => max(0, min(60, (int) ($r['notice_days'] ?? 7))), 'created_at' => date('Y-m-d H:i:s')];
    }

    /** A row of the notice log (2.11, Core\Notices) as exported – the trail is kept as it was. @return array<string, mixed>|null */
    private function noticeLogRow(array $r): ?array
    {
        $at = (string) ($r['at'] ?? '');
        $item = $this->ref('collection_items', $r['item_id'] ?? null);
        if ((int) ($r['id'] ?? 0) <= 0 || $item <= 0 || !in_array($r['action'] ?? '', Notices::ACTIONS, true) || strtotime($at) === false) {
            return null;
        }
        $fields = is_array($r['fields'] ?? null) ? $r['fields'] : json_decode((string) ($r['fields'] ?? ''), true);

        return ['id' => (int) $r['id'], 'item_id' => $item, 'action' => (string) $r['action'], 'at' => date('Y-m-d H:i:s', (int) strtotime($at)),
            'by' => self::text(strip_tags((string) ($r['by'] ?? '')), 100), 'fields' => (string) json_encode(is_array($fields) ? $fields : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    private static function language(mixed $v): string
    {
        return is_string($v) && preg_match('/^[a-z]{2}$/', $v) ? $v : '';
    }

    /** A path of an image or file: from media/, or an https:// address; anything else (javascript:…) is dropped. */
    private static function file(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('#^(/?media/[^\s"\'<>]+|https://[^\s"\'<>]+)$#', $v) && !str_contains($v, '..') ? $v : '';
    }

    /** A builder build from the export: through the validator like any save; null for an empty or broken one. */
    private function build(mixed $v): ?string
    {
        $build = is_array($v) ? $v : (is_string($v) && $v !== '' ? json_decode($v, true) : null);
        if (!is_array($build)) {
            return null;
        }
        // components and booking elements point at public ids in the export: now at the keys they get on this site
        $build = json_decode(SiteExport::mapReferences('build', (string) json_encode($build), $this->mapper()), true) ?: $build;
        [$clean] = Build::sanitize($build, true);

        return Build::toJson($clean);
    }

    /** @return \Closure(string, mixed): int the reference map for SiteExport::mapReferences – a public id of the export to the new key (0 = none) */
    private function mapper(): \Closure
    {
        return fn (string $table, mixed $publicId): int => $this->ref($table, $publicId);
    }

    private function category(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 100);

        return $name !== '' ? ['category_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 120),
            'description' => self::text($r['description'] ?? '', 5000), 'weight' => (int) ($r['weight'] ?? 0), 'language' => self::language($r['language'] ?? ''),
            'translation_of' => $this->ref('categories', $r['translation_of'] ?? null) ?: null] : null;
    }

    private function tag(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 100);

        return $name !== '' ? ['tag_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 120),
            'description' => self::text($r['description'] ?? '', 5000), 'image' => self::file($r['image'] ?? '')] : null;
    }

    private function page(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 200);
        if ($title === '') {
            return null;
        }

        return ['page_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'title' => $title, 'slug' => self::slug($r['slug'] ?? '', $title, 120), 'description' => self::text($r['description'] ?? '', 300),
            'seo_title' => self::text($r['seo_title'] ?? '', 200), 'image' => self::file($r['image'] ?? ''), 'noindex' => (int) !empty($r['noindex']),
            'text' => self::html($r['text'] ?? '', 4_000_000), 'visible' => (int) !empty($r['visible']), 'publish_at' => self::date($r['publish_at'] ?? null),
            'in_menu' => (int) !empty($r['in_menu']), 'sort_order' => (int) ($r['sort_order'] ?? 0), 'updated_at' => self::date($r['updated_at'] ?? null) ?? date('Y-m-d H:i:s'),
            'language' => self::language($r['language'] ?? ''), 'translation_of' => $this->ref('pages', $r['translation_of'] ?? null) ?: null, 'parent_id' => $this->ref('pages', $r['parent_id'] ?? null) ?: null,
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null)] + self::validity($r);
    }

    private function newsItem(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 255);
        if ($title === '') {
            return null;
        }
        $row = ['news_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'title' => $title, 'slug' => self::slug($r['slug'] ?? '', $title, 160), 'intro' => self::html($r['intro'] ?? '', 100_000),
            'text' => self::html($r['text'] ?? '', 4_000_000), 'image' => self::file($r['image'] ?? ''), 'image_caption' => self::text($r['image_caption'] ?? '', 300),
            'image_author' => self::text($r['image_author'] ?? '', 120), 'category_id' => $this->ref('categories', $r['category_id'] ?? null), 'author_id' => $this->admin,
            'published_at' => self::date($r['published_at'] ?? null) ?? date('Y-m-d H:i:s'), 'visible' => (int) !empty($r['visible']), 'keywords' => self::text($r['keywords'] ?? '', 500),
            'seo_title' => self::text($r['seo_title'] ?? '', 255), 'seo_description' => self::text($r['seo_description'] ?? '', 320), 'noindex' => (int) !empty($r['noindex']),
            'visit' => (int) ($r['visit'] ?? 0), 'edited_at' => self::date($r['edited_at'] ?? null), 'updated_at' => self::date($r['updated_at'] ?? null),
            // already announced on the old site: the import sends no webhook and no IndexNow for the whole archive
            'announced_at' => date('Y-m-d H:i:s'), 'language' => self::language($r['language'] ?? ''), 'translation_of' => $this->ref('news', $r['translation_of'] ?? null) ?: null, 'search_text' => null,
            '_tags' => array_values(array_filter(array_map(fn (mixed $t): int => $this->ref('tags', $t), is_array($r['tags'] ?? null) ? $r['tags'] : []), fn (int $i): bool => $i > 0))] + self::validity($r);
        if (is_string($r['faq'] ?? null)) {
            $row['faq'] = self::text($r['faq'], 60_000);
        }

        return $row;
    }

    private function redirect(array $r): ?array
    {
        // addresses as the Redirects module stores them: the old one without the slashes around, the target a path or a URL
        $from = is_string($r['from_path'] ?? null) ? trim($r['from_path'], '/ ') : '';
        $to = is_string($r['to_path'] ?? null) ? trim($r['to_path']) : '';

        return preg_match('#^[^\s/][^\s]{0,254}$#', $from) && preg_match('#^(?!//)(?!javascript:)(?!data:)[^\s]{1,255}$#i', $to)
            ? ['redirect_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'from_path' => $from, 'to_path' => $to, 'type' => (int) ($r['type'] ?? 301) === 302 ? 302 : 301, 'hits' => 0, 'created_at' => date('Y-m-d H:i:s'),
                'auto_score' => is_numeric($r['auto_score'] ?? null) ? max(0, min(100, (int) $r['auto_score'])) : null]
            : null;
    }

    private function sharedClass(array $r): ?array
    {
        $name = (string) ($r['name'] ?? '');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return null;
        }
        $errors = [];
        $discarded = [];
        $style = Style::sanitize(is_array($r['style'] ?? null) ? $r['style'] : json_decode((string) ($r['style'] ?? ''), true), $name, $errors);

        return ['name' => $name, 'style' => (string) json_encode($style ?: new \stdClass(), JSON_UNESCAPED_UNICODE), 'css' => Style::customCss((string) ($r['css'] ?? ''), $discarded), 'updated_at' => date('Y-m-d H:i:s')];
    }

    private function sitePart(array $r): ?array
    {
        $type = (string) ($r['type'] ?? '');
        $variant = (string) ($r['variant'] ?? '');
        if (!isset(SiteParts::TYPES[$type]) || ($variant !== '' && !preg_match(SiteParts::VARIANT_PATTERN, $variant))) {
            return null;
        }
        $pages = is_array($r['pages'] ?? null) ? $r['pages'] : json_decode((string) ($r['pages'] ?? ''), true);
        $pages = is_array($pages) ? array_map(fn (mixed $p): int => $this->ref('pages', $p), $pages) : $pages;

        return ['type' => $type, 'language' => self::language($r['language'] ?? ''), 'variant' => $variant, 'name' => self::text(strip_tags((string) ($r['name'] ?? '')), 100),
            'pages' => is_array($pages) ? (string) json_encode(array_values(array_filter(array_map('intval', $pages), fn (int $i): bool => $i > 0))) : null,
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')];
    }

    private function component(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $properties = is_array($r['properties'] ?? null) ? $r['properties'] : json_decode((string) ($r['properties'] ?? ''), true);

        return $name !== '' ? ['component_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name,
            'properties' => (string) json_encode(Components::sanitizeProperties($properties), JSON_UNESCAPED_UNICODE),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    /** The key a component or section got from a fleet design kit (2.16, Fleet\Kit) – kept, so the next kit updates it instead of adding a copy. */
    private static function kitKey(mixed $v): ?string
    {
        return is_string($v) && preg_match(\Talea\Fleet\Kit::KEY_PATTERN, $v) ? $v : null;
    }

    private function section(array $r): ?array
    {
        $element = is_array($r['element'] ?? null) ? $r['element'] : json_decode((string) ($r['element'] ?? ''), true);
        $element = is_array($element) ? json_decode(SiteExport::mapReferences('element', (string) json_encode($element), $this->mapper()), true) : $element;
        $build = is_array($element) ? json_decode((string) $this->build(['v' => Build::VERSION, 'children' => [$element]]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);

        return $name !== '' && isset($build['children'][0])
            ? ['section_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'element' => (string) json_encode($build['children'][0], JSON_UNESCAPED_UNICODE), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function menu(array $r): ?array
    {
        $location = (string) ($r['location'] ?? '');
        $items = is_array($r['items'] ?? null) ? $r['items'] : json_decode((string) ($r['items'] ?? ''), true);
        $items = is_array($items) ? json_decode(SiteExport::mapReferences('menu', (string) json_encode($items), $this->mapper()), true) : $items;

        return isset(Menu::LOCATIONS[$location]) ? ['location' => $location, 'language' => self::language($r['language'] ?? ''),
            'items' => (string) json_encode(Menu::sanitize($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collection(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $fields = is_array($r['fields'] ?? null) ? $r['fields'] : json_decode((string) ($r['fields'] ?? ''), true);

        return $name !== '' ? ['collection_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 110),
            'fields' => (string) json_encode(Collections::sanitizeFields($fields), JSON_UNESCAPED_UNICODE), 'detail' => (int) !empty($r['detail']),
            'hidden_redirect' => Collections::cleanRedirect((string) ($r['hidden_redirect'] ?? '')) ?? '',
            'preset' => \Talea\Builder\Presets::get((string) ($r['preset'] ?? '')) !== null ? (string) $r['preset'] : '',
            'schema_org' => ($schema = \Talea\Builder\CollectionSchema::sanitize(is_array($r['schema_org'] ?? null) ? $r['schema_org'] : json_decode((string) ($r['schema_org'] ?? ''), true), Collections::sanitizeFields($fields))) === null
                ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionTemplate(array $r): ?array
    {
        return ($idk = $this->ref('collections', $r['collection_id'] ?? null)) > 0 ? ['collection_id' => $idk, 'language' => self::language($r['language'] ?? ''), 'build' => $this->build($r['build'] ?? null),
            'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionItem(array $r): ?array
    {
        $idk = $this->ref('collections', $r['collection_id'] ?? null);
        $fields = $idk > 0 ? json_decode((string) $this->db->value('SELECT fields FROM {collections} WHERE collection_id = ?', [$idk]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 200);
        if (!is_array($fields) || $name === '') {
            return null; // an item without its collection
        }
        $data = is_array($r['data'] ?? null) ? $r['data'] : json_decode((string) ($r['data'] ?? ''), true);

        return ['item_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'collection_id' => $idk, 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 160),
            'data' => (string) json_encode(Collections::sanitizeData($fields, is_array($data) ? $data : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => (int) ($r['sort_order'] ?? 0), 'visible' => (int) !empty($r['visible']), 'language' => self::language($r['language'] ?? ''),
            'created_at' => self::date($r['created_at'] ?? null) ?? date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            'seo_title' => self::text($r['seo_title'] ?? '', 200), 'description' => self::text($r['description'] ?? '', 300), 'image' => self::file($r['image'] ?? ''),
            'noindex' => (int) !empty($r['noindex']), 'publish_at' => self::date($r['publish_at'] ?? null)] + self::validity($r);
    }

    /** A previous file of a document (2.11); a row whose document was not imported fails on the foreign key and is skipped. */
    private function documentVersion(array $r): ?array
    {
        $file = is_string($r['file'] ?? null) ? trim($r['file']) : '';
        $item = $this->ref('collection_items', $r['item_id'] ?? null);
        if ($item <= 0 || preg_match(Collections::MEDIA_PATTERN, $file) !== 1 || str_contains($file, '..')) {
            return null;
        }

        return ['item_id' => $item, 'file' => $file, 'version' => self::text(strip_tags((string) ($r['version'] ?? '')), 100),
            'replaced_at' => self::date($r['replaced_at'] ?? null) ?? date('Y-m-d H:i:s'), 'replaced_by' => self::text(strip_tags((string) ($r['replaced_by'] ?? '')), 100)];
    }

    private function popup(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $address = (string) ($r['slug'] ?? '');
        if ($name === '' || !preg_match(Popups::ADDRESS_PATTERN, $address)) {
            return null;
        }
        $rules = is_array($r['rules'] ?? null) ? $r['rules'] : json_decode((string) ($r['rules'] ?? ''), true);
        $rules = is_array($rules) ? json_decode(SiteExport::mapReferences('rules', (string) json_encode($rules), $this->mapper()), true) : $rules;

        return ['popup_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => $address,
            'type' => self::pick(Popups::TYPES, $r['type'] ?? ''), 'trigger_type' => self::pick(Popups::TRIGGERS, $r['trigger_type'] ?? ''),
            'value' => max(0, min(100_000, (int) ($r['value'] ?? 0))), 'rules' => (string) json_encode(Popups::sanitizeRules(is_array($rules) ? $rules : []), JSON_UNESCAPED_UNICODE),
            'frequency' => self::pick(Popups::FREQUENCIES, $r['frequency'] ?? ''),
            'days' => max(0, min(3650, (int) ($r['days'] ?? 0))), 'active' => (int) !empty($r['active']), 'sort_order' => (int) ($r['sort_order'] ?? 0),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] + self::validity($r);
    }

    private function mediaRow(array $r): ?array
    {
        // format 1 named the columns differently (file, width, height)
        $file = self::file($r['image_path'] ?? ($r['file'] ?? ''));
        if (!str_starts_with(ltrim($file, '/'), 'media/')) {
            return null;
        }
        $folder = $this->ref('media_folders', $r['folder_id'] ?? null);

        return ['media_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'owner_id' => $this->admin,
            'folder_id' => $folder > 0 && $this->db->value('SELECT 1 FROM {media_folders} WHERE folder_id = ?', [$folder]) !== null ? $folder : null,
            'name' => self::text($r['name'] ?? '', 150), 'description' => self::text($r['description'] ?? '', 500), 'author' => self::text($r['author'] ?? '', 120),
            'image_path' => ltrim($file, '/'), 'image_width' => max(0, min(65535, (int) ($r['image_width'] ?? ($r['width'] ?? 0)))),
            'image_height' => max(0, min(65535, (int) ($r['image_height'] ?? ($r['height'] ?? 0)))), 'image_size' => max(0, (int) ($r['image_size'] ?? 0)),
            'thumb_path' => ltrim(self::file($r['thumb_path'] ?? ''), '/'), 'thumb_width' => max(0, min(65535, (int) ($r['thumb_width'] ?? 0))),
            'thumb_height' => max(0, min(65535, (int) ($r['thumb_height'] ?? 0))), 'color' => is_string($r['color'] ?? null) && preg_match('/^(#[0-9a-f]{6}|-)?$/i', $r['color']) ? $r['color'] : '',
            'focal_point' => is_string($r['focal_point'] ?? null) && preg_match('/^(\d{1,3}% \d{1,3}%)?$/', $r['focal_point']) ? $r['focal_point'] : '', 'created_at' => self::date($r['created_at'] ?? null) ?? date('Y-m-d H:i:s')];
    }

    /** The public settings of the export (the same allowlist the export uses); the address of this site stays. */
    private function applySettings(string $file, string $fromVersion): void
    {
        $values = json_decode((string) @file_get_contents(self::workFolder($file) . '/settings.json'), true);
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $key = (string) $key;
            $base = (string) preg_replace('/_[a-z]{2}$/', '', $key);
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
                'site_language' => isset(Language::AVAILABLE[$value]) ? $value : null,
                'additional_languages' => implode(',', array_filter(explode(',', $value), fn (string $c): bool => isset(Language::AVAILABLE[$c]))),
                'extensions' => $value === '-' ? '-' : implode(',', array_intersect(explode(',', $value), array_keys(Extensions::CATALOG))),
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
        $zip = new \ZipArchive();
        $path = self::path((string) $state['file']);
        if ($path === null || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The file does not exist.');
        }
        $start = microtime(true);
        $done = 0;
        $total = $zip->numFiles;
        while ($state['media_position'] < $total && $done < self::MEDIA_BATCH && microtime(true) - $start < self::SECONDS) {
            $name = (string) $zip->getNameIndex((int) $state['media_position']);
            $state['media_position']++;
            $target = self::mediaTarget($name);
            if ($target === null) {
                continue;
            }
            $done++;
            $full = TALEA_ROOT . '/' . $target;
            $ok = is_dir(dirname($full)) || @mkdir(dirname($full), 0775, true);
            if ($ok && str_ends_with(strtolower($target), '.svg')) {
                $content = Svg::sanitize((string) $zip->getFromName($name)); // an SVG is cleaned like an uploaded one
                $ok = $content !== null && file_put_contents($full, $content) !== false;
            } elseif ($ok) {
                // streamed: a video or a large PDF does not have to fit in memory
                $in = $zip->getStream($name);
                $out = $in === false ? false : @fopen($full, 'wb');
                $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false;
                foreach ([$in, $out] as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
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

    /** Where a file from the archive goes; null = it does not belong in media/ or its type is not allowed. */
    public static function mediaTarget(string $name): ?string
    {
        if (!preg_match('#^media/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_][A-Za-z0-9_.-]*$#', $name) || str_contains($name, '..')) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, [...self::MEDIA_EXTENSIONS, ...Files::FILE_EXTENSIONS], true) ? $name : null;
    }

    /** After the import: the search index, the cache and the working files. */
    private function finish(): void
    {
        for ($i = 0; $i < 500 && Search::complete($this->db, 200) > 0; $i++) {
            // the search index of the imported news, 200 at a time
        }
        \Talea\Front\Cache::clear();
    }

    /** Removes the working folder of a finished import (the export file itself stays). */
    public static function cleanUp(string $file): void
    {
        foreach (glob(self::workFolder($file) . '/*') ?: [] as $part) {
            @unlink($part);
        }
        @rmdir(self::workFolder($file));
    }
}
