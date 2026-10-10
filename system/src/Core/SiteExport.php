<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Export of the whole site into one archive – so that the content never stays locked in Talea.
 *
 * The archive storage/backups/export-YYYYMMDD-HHMMSS.zip contains content.json (pages, categories, tags, news, redirects,
 * the media library and public settings), README.txt describing the format and the folder media/.
 *
 * What is NEVER in the export: passwords, API keys, tokens, SMTP and FTP details, admin user accounts.
 * That is why settings are picked from an allowlist (SETTINGS), not by exclusion.
 * It is not a backup for restoring Talea (the database backup is that), but a portable open format.
 */
final class SiteExport
{
    /** Upper limit of the media size in the archive; above it (or when disk space is short) the export is created without media. */
    public const int MAX_MEDIA = 1024 * 1024 * 1024;
    private const int KEEP = 3;

    /**
     * Version of content.json: 2 (1.8) adds the drafts of builds, whole media rows, media folders and redirect types; 3 (HF-16) identifies
     * every row by its public_id (UUID v4) and writes every reference between rows – foreign keys, the "component" and booking elements
     * of builds, menu pages, pop-up pages, the home page – as public ids. The integer keys of the database never leave the site.
     */
    public const int FORMAT_VERSION = 3;

    /** The only settings that are exported: the site's name, description, identity, languages and public texts (SiteImport reads the same list). */
    public const array SETTINGS = ['site_name', 'site_description', 'keywords', 'site_url', 'logo', 'favicon', 'design_system', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_hours', 'company_map', 'company_gps', 'dark_mode',
        'footer_text', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin',
        'time_zone', 'site_language', 'additional_languages', 'home_page', 'news_slug', 'company_email', 'theme_switcher', 'news_per_page', 'extensions', 'share_image', 'share_image_auto',
        'cookies_policy_url', 'cookies_text', 'schema_org', 'robots_extra', 'llms_txt', 'maintenance_text', 'share_buttons', 'article_outline', 'related_news_auto',
        'screen_mode', 'screen_seconds', 'screen_collections', 'screen_news', 'screen_hours', 'screen_clock']; // the screen mode without its secret (2.11)

    /** News item columns that are only operational (search index, link check…) and do not belong in the export. */
    private const array EXCLUDED_ARTICLE_COLUMNS = ['search_text', 'links_checked_at', 'announced_at', 'author_id', 'author_name'];

    /**
     * @return array{soubor:string, media:bool, duvod:string} name of the created file; media = false when it contains only data (duvod says why)
     * @throws \RuntimeException
     */
    public static function create(Db $db, Settings $settings): array
    {
        if (!is_dir(Backup::FOLDER) && !mkdir(Backup::FOLDER, 0775, true)) {
            throw new \RuntimeException('The folder storage/backups cannot be created - check the write permissions.');
        }
        @set_time_limit(300);
        $base = Backup::FOLDER . '/export-' . date('Ymd-His');
        $json = $base . '.json';
        self::writeContent($db, $settings, $json);
        if (!class_exists(\ZipArchive::class)) {
            self::cleanUp();

            return ['file' => basename($json), 'media' => false, 'reason' => 'The PHP zip extension is missing on the server, so the export contains data only (JSON). Download the media/ folder over FTP.'];
        }

        $files = self::mediaFiles();
        $size = array_sum($files);
        $freeSpace = @disk_free_space(Backup::FOLDER);
        $reason = match (true) {
            $size > self::MAX_MEDIA => 'The media exceed 1 GB, so the export contains data only (JSON). Download the media/ folder over FTP.',
            $freeSpace !== false && $size * 1.1 + (int) filesize($json) > $freeSpace => 'There is not enough disk space for an archive with media, so the export contains data only (JSON). Download the media/ folder over FTP.',
            default => '',
        };
        $zip = new \ZipArchive();
        if ($zip->open($base . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The archive could not be created – check write permissions for storage/backups.');
        }
        $zip->addFile($json, 'content.json');
        $zip->addFromString('README.txt', self::readme($reason === ''));
        if ($reason === '') {
            // file by file; images are already compressed, so they are only stored (CM_STORE) – the archive is then done quickly
            foreach (array_keys($files) as $path) {
                $zip->addFile(TALEA_ROOT . '/' . $path, $path);
                $zip->setCompressionName($path, \ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) {
            @unlink($base . '.zip');
            throw new \RuntimeException('The archive could not be finished – the disk is probably full.');
        }
        unlink($json);
        self::cleanUp();

        return ['file' => basename($base) . '.zip', 'media' => $reason === '', 'reason' => $reason];
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> newest on top */
    public static function listAll(): array
    {
        $exports = [];
        foreach (glob(Backup::FOLDER . '/export-*') ?: [] as $path) {
            if (self::path(basename($path)) !== null) {
                $exports[] = ['file' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        usort($exports, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $exports;
    }

    /** Path to an existing export by the name from the URL; null = invalid name. */
    public static function path(string $file): ?string
    {
        return preg_match('/^export-\d{8}-\d{6}\.(zip|json)$/', $file) && is_file(Backup::FOLDER . '/' . $file) ? Backup::FOLDER . '/' . $file : null;
    }

    /* ---------- content.json ---------- */

    /** Written straight into the file and row by row from the database – so even a site with tens of thousands of articles need not fit in memory. */
    private static function writeContent(Db $db, Settings $settings, string $path): void
    {
        $f = fopen($path, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Cannot write to storage/backups – check write permissions.');
        }
        fwrite($f, '{"format":"talea-export","format_version":' . self::FORMAT_VERSION . ',"talea":' . self::json(TALEA_VERSION) . ',"created_at":' . self::json(date('c')) . ',"settings":' . self::json(self::settings($db)));

        $authors = "(SELECT NULLIF(u.name, '') FROM {users} u WHERE u.user_id = c.author_id) AS author_name";
        $one = fn (string $table, string $sql, string $key, array $refs = [], array $json = []) => self::mapped($db, $table, self::streamRows($db, $sql, $key), $refs, $json);
        $all = fn (string $table, string $sql, array $refs = [], array $json = []) => self::mapped($db, $table, $db->all($sql), $refs, $json);
        $builds = ['build' => 'build', 'build_draft' => 'build'];
        self::fields($f, 'pages', $one('pages', 'SELECT * FROM {pages} WHERE page_id > ? AND deleted_at IS NULL ORDER BY page_id LIMIT 200', 'page_id', ['parent_id' => 'pages', 'translation_of' => 'pages'], $builds)); // the trash is not exported
        self::fields($f, 'categories', $one('categories', 'SELECT category_id, public_id, name, slug, description, weight, language, translation_of FROM {categories} WHERE category_id > ? ORDER BY category_id LIMIT 500', 'category_id', ['translation_of' => 'categories']));
        self::fields($f, 'tags', $one('tags', 'SELECT tag_id, public_id, name, slug, description, image FROM {tags} WHERE tag_id > ? ORDER BY tag_id LIMIT 500', 'tag_id'));
        self::fields($f, 'news', self::articles($db, $authors));
        self::fields($f, 'redirects', $one('redirects', 'SELECT redirect_id, public_id, from_path, to_path, type, auto_score FROM {redirects} WHERE redirect_id > ? ORDER BY redirect_id LIMIT 1000', 'redirect_id'));
        // builder: shared classes, site parts (header, footer, wrappers) and collections; not enquiries – they are visitors' personal data
        self::fields($f, 'classes', $db->all('SELECT name, style, css FROM {classes} ORDER BY name'));
        // the drafts go along (build_draft): a site moved in the middle of a redesign keeps its unfinished work
        self::fields($f, 'site_parts', $all('site_parts', 'SELECT type, language, variant, name, pages, build, build_draft FROM {site_parts} WHERE build IS NOT NULL OR build_draft IS NOT NULL ORDER BY type, language, variant', [], ['pages' => 'pages'] + $builds));
        // components ("component" elements refer to them by public id) and the library's own sections
        self::fields($f, 'components', $all('components', 'SELECT component_id, public_id, name, properties, build, build_draft, kit_key FROM {components} ORDER BY component_id', [], $builds));
        self::fields($f, 'sections', $all('sections', 'SELECT section_id, public_id, name, element, kit_key FROM {sections} ORDER BY section_id', [], ['element' => 'element']));
        self::fields($f, 'menus', $all('menus', 'SELECT location, language, items FROM {menus} ORDER BY location, language', [], ['items' => 'menu']));
        self::fields($f, 'collections', $all('collections', 'SELECT collection_id, public_id, name, slug, fields, detail, hidden_redirect, preset, schema_org, build, build_draft FROM {collections} ORDER BY collection_id', [], $builds));
        self::fields($f, 'collection_templates', $all('collection_templates', 'SELECT collection_id, language, build, build_draft FROM {collection_templates} WHERE build IS NOT NULL OR build_draft IS NOT NULL ORDER BY collection_id, language', ['collection_id' => 'collections'], $builds));
        // popups with rules and the published build; not the counters (they are only this site's statistics)
        self::fields($f, 'popups', $all('popups', 'SELECT popup_id, public_id, name, slug, type, trigger_type, value, rules, frequency, days, active, sort_order, valid_until, review_by, build, build_draft FROM {popups} ORDER BY popup_id', [], ['rules' => 'rules'] + $builds));
        self::fields($f, 'collection_items', $one('collection_items', 'SELECT item_id, public_id, collection_id, name, slug, data, seo_title, description, image, noindex, sort_order, visible, publish_at, valid_until, review_by, language, created_at FROM {collection_items} WHERE item_id > ? AND deleted_at IS NULL ORDER BY item_id LIMIT 500', 'item_id', ['collection_id' => 'collections']));
        // the previous files of documents (2.11) go along – they are content, kept for good; download counts are only this site's statistics
        self::fields($f, 'document_versions', $one('document_versions', 'SELECT id, item_id, file, version, replaced_at, replaced_by FROM {document_versions} WHERE id > ? ORDER BY id LIMIT 1000', 'id', ['item_id' => 'collection_items']));
        self::fields($f, 'media_folders', $all('media_folders', 'SELECT folder_id, public_id, name FROM {media_folders} ORDER BY folder_id'));
        self::fields($f, 'media', $one('media', 'SELECT media_id, public_id, folder_id, name, description, author, image_path, image_width, image_height, image_size, thumb_path, thumb_width, thumb_height, color, focal_point, created_at FROM {media} WHERE media_id > ? ORDER BY media_id LIMIT 500', 'media_id', ['folder_id' => 'media_folders']));
        // the business (2.10): facts and exceptions to the opening hours – the week itself is in the settings (company_hours)
        self::fields($f, 'facts', $db->all('SELECT fact_key, language, label, type, value, schema_prop, source FROM {facts} ORDER BY fact_key, language'));
        self::fields($f, 'hours_exceptions', $db->all('SELECT date_from, date_to, closed, hours, note, notice_days FROM {hours_exceptions} WHERE date_to >= CURDATE() AND proposed = 0 ORDER BY date_from'));
        // online booking (3.0, Core\Booking): the set-up goes along – the bookings themselves are personal data and stay
        self::fields($f, 'booking_services', $all('booking_services', 'SELECT id, public_id, name, duration_min, buffer_min, price_text, description, active, requires_confirmation, sort_order FROM {booking_services} ORDER BY id'));
        self::fields($f, 'booking_staff', $all('booking_staff', 'SELECT id, public_id, name, email, active, sort_order FROM {booking_staff} ORDER BY id'));
        self::fields($f, 'booking_staff_services', $all('booking_staff_services', 'SELECT staff_id, service_id FROM {booking_staff_services} ORDER BY staff_id, service_id', ['staff_id' => 'booking_staff', 'service_id' => 'booking_services']));
        self::fields($f, 'booking_hours', $all('booking_hours', 'SELECT staff_id, weekday, time_from, time_to FROM {booking_hours} ORDER BY staff_id, weekday, time_from', ['staff_id' => 'booking_staff']));
        self::fields($f, 'booking_off', $all('booking_off', 'SELECT staff_id, off_from, off_to, note FROM {booking_off} WHERE off_to >= NOW() ORDER BY off_from', ['staff_id' => 'booking_staff']));
        self::fields($f, 'blueprints', $db->all('SELECT bkey, name, manifest FROM {blueprints} ORDER BY applied_at'));
        // the agent notebook (2.15, Core\Notebook): what the next person working on the site should know moves with it
        self::fields($f, 'notebook', $db->all('SELECT id, topic, title, text, pinned, author, created_at, updated_at FROM {notebook} ORDER BY id'));
        // the audit trail of official notice boards (2.11, Core\Notices) moves with the notices it belongs to
        self::fields($f, 'notice_log', $one('notice_log', 'SELECT id, item_id, action, `at`, `by`, fields FROM {notice_log} WHERE id > ? ORDER BY id LIMIT 1000', 'id', ['item_id' => 'collection_items']));
        // deliberately not here: the requests to Claude (2.15, Core\Requests) – the team's work list, not content (their attachments are Media and go along);
        // nor the scheduled Claude runs (2.17, Core\AgentSchedules) – a routine in Claude is set up per site and the run history belongs to it
        fwrite($f, "}\n");
        fclose($f);
    }

    /**
     * News with all columns; the author as a name (accounts are not exported), plus tags.
     * Translations hold translation_of = the public id of the news item in the default language, valid inside the export too.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function articles(Db $db, string $authors): \Generator
    {
        foreach (self::streamRows($db, "SELECT c.*, {$authors} FROM {news} c WHERE c.news_id > ? AND c.deleted_at IS NULL ORDER BY c.news_id LIMIT 100", 'news_id') as $c) {
            $newsItem = array_diff_key($c, array_flip(self::EXCLUDED_ARTICLE_COLUMNS));
            $newsItem['author'] = (string) ($c['author_name'] ?? '');
            $newsItem['tags'] = array_values(array_filter(array_map(fn (array $t): string => $db->publicId('tags', (int) $t['tag_id']), $db->all('SELECT tag_id FROM {news_tags} WHERE news_id = ?', [$c['news_id']]))));
            yield self::row($db, 'news', $newsItem, ['category_id' => 'categories', 'translation_of' => 'news']);
        }
    }

    /**
     * A row for the archive: the integer key out, the public id first, every foreign key as the public id of the row it points to
     * (a column that holds JSON – builds, menus, pop-up rules – has the references inside it turned the same way).
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $refs column => table it points to
     * @param array<string, string> $json column => kind (build, element, menu, rules, pages)
     * @return array<string, mixed>
     */
    private static function row(Db $db, string $table, array $row, array $refs = [], array $json = []): array
    {
        $public = fn (string $to, mixed $id): ?string => (int) $id > 0 ? ($db->publicId($to, (int) $id) ?: null) : null;
        if (isset(Db::PRIMARY_KEYS[$table])) {
            unset($row[Db::PRIMARY_KEYS[$table]]);
        }
        foreach ($refs as $column => $to) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $public($to, $row[$column]);
            }
        }
        $map = fn (string $to, mixed $id): string|int => (int) $id > 0 ? ($db->publicId($to, (int) $id) ?: 0) : 0;
        foreach ($json as $column => $kind) {
            if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                $row[$column] = self::mapReferences($kind, $row[$column], $map);
            }
        }

        return isset($row['public_id']) ? ['public_id' => $row['public_id']] + $row : $row;
    }

    /** @param iterable<array<string, mixed>> $rows @return \Generator<int, array<string, mixed>> */
    private static function mapped(Db $db, string $table, iterable $rows, array $refs, array $json): \Generator
    {
        foreach ($rows as $row) {
            yield self::row($db, $table, $row, $refs, $json);
        }
    }

    /**
     * The references inside a JSON column, turned by $map(table, id): the export gives it the integer and wants the public id,
     * the import gives it the public id and wants the new integer. Kinds: build (component and booking elements), element (one
     * element of the sections library), menu (page items), rules (the pages of a pop-up), pages (a list of page numbers).
     * Anything that is not valid JSON of that shape stays as it is (the importer's validators deal with it).
     *
     * @param callable(string, mixed): (string|int) $map
     */
    public static function mapReferences(string $kind, string $json, callable $map): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $element = function (array $n) use (&$element, $map): array {
            $content = is_array($n['content'] ?? null) ? $n['content'] : null;
            if ($content !== null && ($n['type'] ?? '') === 'component' && isset($content['component'])) {
                $to = $map('components', $content['component']);
                $n['content']['component'] = $to ? (string) $to : '';
            }
            if ($content !== null && ($n['type'] ?? '') === 'booking') {
                foreach (['service' => 'booking_services', 'staff_member' => 'booking_staff'] as $key => $table) {
                    if (isset($content[$key])) {
                        $n['content'][$key] = $map($table, $content[$key]);
                    }
                }
            }
            if (is_array($n['children'] ?? null)) {
                $n['children'] = array_map(fn (mixed $c): mixed => is_array($c) ? $element($c) : $c, $n['children']);
            }

            return $n;
        };
        $items = function (array $list) use (&$items, $map): array {
            foreach ($list as $i => $item) {
                if (is_array($item)) {
                    $list[$i] = $items($item);
                    if (array_key_exists('page_id', $item) && !is_array($item['page_id'])) {
                        $list[$i]['page_id'] = $map('pages', $item['page_id']);
                    }
                }
            }

            return $list;
        };
        $data = match ($kind) {
            'build' => ['children' => array_map(fn (mixed $c): mixed => is_array($c) ? $element($c) : $c, is_array($data['children'] ?? null) ? $data['children'] : [])] + $data,
            'element' => $element($data),
            'menu' => $items($data),
            'rules' => isset($data['pages']) && is_array($data['pages']) ? ['pages' => array_map(fn (mixed $p): string|int => $map('pages', $p), $data['pages'])] + $data : $data,
            'pages' => array_map(fn (mixed $p): string|int => $map('pages', $p), $data),
            default => $data,
        };

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Reads a table page by page by the primary key (the query has a single question mark: "key > ?").
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function streamRows(Db $db, string $sql, string $key): \Generator
    {
        $from = 0;
        do {
            $rows = $db->all($sql, [$from]);
            foreach ($rows as $row) {
                $from = (int) $row[$key];
                yield $row;
            }
        } while ($rows !== []);
    }

    /**
     * @param resource $f
     * @param iterable<array<string, mixed>> $rows
     */
    private static function fields($f, string $name, iterable $rows): void
    {
        fwrite($f, ",\n" . self::json($name) . ':[');
        $first = true;
        foreach ($rows as $row) {
            fwrite($f, ($first ? "\n" : ",\n") . self::json($row));
            $first = false;
        }
        fwrite($f, "\n]");
    }

    /** @return array<string, string> */
    private static function settings(Db $db): array
    {
        $all = $db->pairs('SELECT name, value FROM {settings}');
        $selection = [];
        foreach ($all as $key => $value) {
            // the site name and description may have a variant for another language (site_name_en)
            $base = (string) preg_replace('/_[a-z]{2}$/', '', (string) $key);
            if (in_array($key, self::SETTINGS, true) || (in_array($base, Settings::PER_LANGUAGE, true) && $base !== $key)) {
                $selection[(string) $key] = (string) $value;
            }
        }
        if (isset($selection['home_page'])) {
            $selection['home_page'] = $db->publicId('pages', (int) $selection['home_page']); // a public id like every reference (empty = none)
        }
        ksort($selection);

        return $selection;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /* ---------- media and cleanup ---------- */

    /** @return array<string, int> path from the site root => size; without hidden files and without PHP (also for RemoteBackup::syncMedia) */
    public static function mediaFiles(): array
    {
        $files = [];
        if (!is_dir(TALEA_ROOT . '/media')) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = 'media/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(TALEA_ROOT . '/media'))), '/');
            if ($file->isFile() && !$file->isLink() && !preg_match('#(^|/)\.|\.php\d?$#i', $path)) {
                $files[$path] = (int) $file->getSize();
            }
        }
        ksort($files);

        return $files;
    }

    /** Exports tend to be large: only the last three are kept. */
    private static function cleanUp(): void
    {
        foreach (array_slice(self::listAll(), self::KEEP) as $old) {
            @unlink(Backup::FOLDER . '/' . $old['file']);
        }
    }

    private static function readme(bool $withMedia): string
    {
        return "Site export from Talea " . TALEA_VERSION . " (" . date('Y-m-d H:i') . ")\n"
            . "==========================================\n\n"
            . "content.json  all content of the site, UTF-8\n"
            . ($withMedia ? "media/      uploaded images and files; the paths match the \"image\" columns and the media rows\n" : "media/      NOT in the archive (too large or too little disk space) – download the media/ folder from the site over FTP\n")
            . "\ncontent.json (format version " . self::FORMAT_VERSION . ")\n------------------------------\n"
            . "format, format_version, talea, created_at   header (format \"talea-export\", format version, Talea version, created)\n"
            . "settings         site name and description, identity (logo, colours, fonts, networks), time zone, languages, home page\n"
            . "Public ids: every row of pages, categories, tags, news, redirects, components, sections, collections, collection_items, popups, media,\n"
            . "                 media_folders, booking_services and booking_staff has a public_id (UUID v4). The integer keys of the database are not in the file;\n"
            . "                 every reference between rows (parent_id, translation_of, category_id, folder_id, collection_id, item_id, tags, staff_id, service_id,\n"
            . "                 the home_page setting, menu pages, pop-up pages, the pages of a site part, the component and booking elements inside builds)\n"
            . "                 is the public_id of the row it points to. An import keeps the public ids, so the same site exported again has the same ids.\n"
            . "pages            pages: public_id, slug, title, description, text (HTML), build and build_draft (builder JSON), language, translation_of, parent_id\n"
            . "categories       news categories: public_id, name, slug, description, language, translation_of\n"
            . "tags             tags: public_id, name, slug, description, image\n"
            . "news             news: public_id, title, intro and text (HTML), published_at, visible (1 = published), category_id (public id of the category), language,\n"
            . "                 translation_of (public id of the news item it translates), author (a name), tags (list of public ids of tags) and more\n"
            . "redirects        redirects: from_path -> to_path, type (301 or 302), auto_score (NULL = by hand; 0-100 = created by the site itself)\n"
            . "classes          shared classes of the builder: name, style (JSON), css\n"
            . "site_parts       site parts (header, footer, wrappers): type, language, variant, pages, build, build_draft\n"
            . "components       components: public_id, name, properties, build, build_draft (the \"component\" element refers to a public_id), kit_key (from a fleet kit, 2.16)\n"
            . "sections         saved sections: public_id, name, element, kit_key (from a fleet kit, 2.16)\n"
            . "menus            menus: location, language, items (JSON; a page item refers to a page's public_id)\n"
            . "collections, collection_templates, collection_items   collections, their templates and items\n"
            . "document_versions  previous files of documents (a document library): item_id (public id of the collection item), file, version, replaced_at, replaced_by\n"
            . "popups           pop-ups with rules and builds\n"
            . "media_folders    media folders: public_id, name\n"
            . "media            media library: image_path (path in media/), name (alternative text), description, author, sizes, folder_id\n"
            . "facts            business facts: fact_key ({{fact.<key>}} in texts), language, label, type, value, schema_prop, source\n"
            . "hours_exceptions exceptions to the opening hours still to come: date_from, date_to, closed, hours, note, notice_days\n"
            . "booking_services online booking (3.0): services – public_id, name, duration_min, buffer_min, price_text, description, active, requires_confirmation, sort_order\n"
            . "booking_staff    people who take bookings: public_id, name, email, active, sort_order; booking_staff_services links them (staff_id, service_id: public ids)\n"
            . "booking_hours    weekly hours of a person: staff_id (public id), weekday (1–7), time_from, time_to; booking_off days off still to come (staff_id or null = everyone, off_from, off_to, note). The bookings themselves never travel – personal data.\n"            . "blueprints       industry blueprints applied: bkey, nazev, manifest (JSON: presets, facts, questions, audit, claude)\n"
            . "notebook         agent notebook (2.15): notes for whoever works on the site next – topic, title, text, pinned, author\n"
            . "notice_log       audit trail of official notice boards (2.11): item_id (public id of the collection item), action, at, by, fields (JSON) – append-only\n"
            . "\nAddresses on the site: page /<seo_link>; other language versions have the prefix /<language>/.\n"
            . "\nNot in the export on purpose: user accounts and passwords, keys and tokens, mail and backup settings, enquiries,\n"
            . "subscribers and visit statistics. Another Talea site imports this file in Import and export -> Import from Talea.\n"
            . "To restore this same site, use the database backup (Settings -> Backups and updates).\n";
    }
}
