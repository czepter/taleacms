<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Export of the whole site into one archive – so that the content never stays locked in Kaleta.
 *
 * The archive storage/zalohy/export-YYYYMMDD-HHMMSS.zip contains obsah.json (pages, categories, tags, news, redirects,
 * the media library and public settings), README.txt describing the format and the folder media/.
 *
 * What is NEVER in the export: passwords, API keys, tokens, SMTP and FTP details, admin user accounts.
 * That is why settings are picked from an allowlist (SETTINGS), not by exclusion.
 * It is not a backup for restoring Kaleta (the database backup is that), but a portable open format.
 */
final class SiteExport
{
    /** Upper limit of the media size in the archive; above it (or when disk space is short) the export is created without media. */
    public const int MAX_MEDIA = 1024 * 1024 * 1024;
    private const int KEEP = 3;

    /** Version of obsah.json: 2 (1.8) adds the drafts of builds, whole media rows, media folders and redirect types. */
    public const int FORMAT_VERSION = 2;

    /** The only settings that are exported: the site's name, description, identity, languages and public texts (SiteImport reads the same list). */
    public const array SETTINGS = ['site_name', 'site_description', 'keywords', 'site_url', 'logo', 'favicon', 'design_system', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_hours', 'company_map', 'company_gps', 'brand_accent', 'dark_mode',
        'brand_heading_font', 'brand_text_font', 'footer_text', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin',
        'time_zone', 'site_language', 'additional_languages', 'home_page', 'company_email', 'theme_switcher', 'news_per_page', 'extensions', 'share_image',
        'cookies_policy_url', 'cookies_text', 'schema_org', 'robots_extra', 'llms_txt', 'maintenance_text', 'share_buttons', 'article_outline', 'related_news_auto'];

    /** News item columns that are only operational (search index, link check…) and do not belong in the export. */
    private const array EXCLUDED_ARTICLE_COLUMNS = ['hledani', 'odkazy_cas', 'oznameno', 'autor', 'autor_jmeno'];

    /**
     * @return array{soubor:string, media:bool, duvod:string} name of the created file; media = false when it contains only data (duvod says why)
     * @throws \RuntimeException
     */
    public static function create(Db $db, Settings $settings): array
    {
        if (!is_dir(Backup::FOLDER) && !mkdir(Backup::FOLDER, 0775, true)) {
            throw new \RuntimeException('The folder storage/zalohy cannot be created - check the write permissions.');
        }
        @set_time_limit(300);
        $base = Backup::FOLDER . '/export-' . date('Ymd-His');
        $json = $base . '.json';
        self::writeContent($db, $settings, $json);
        if (!class_exists(\ZipArchive::class)) {
            self::cleanUp();

            return ['soubor' => basename($json), 'media' => false, 'duvod' => 'The PHP zip extension is missing on the server, so the export contains data only (JSON). Download the media/ folder over FTP.'];
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
            throw new \RuntimeException('The archive could not be created – check write permissions for storage/zalohy.');
        }
        $zip->addFile($json, 'obsah.json');
        $zip->addFromString('README.txt', self::readme($reason === ''));
        if ($reason === '') {
            // file by file; images are already compressed, so they are only stored (CM_STORE) – the archive is then done quickly
            foreach (array_keys($files) as $path) {
                $zip->addFile(KALETA_ROOT . '/' . $path, $path);
                $zip->setCompressionName($path, \ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) {
            @unlink($base . '.zip');
            throw new \RuntimeException('The archive could not be finished – the disk is probably full.');
        }
        unlink($json);
        self::cleanUp();

        return ['soubor' => basename($base) . '.zip', 'media' => $reason === '', 'duvod' => $reason];
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> newest on top */
    public static function listAll(): array
    {
        $exports = [];
        foreach (glob(Backup::FOLDER . '/export-*') ?: [] as $path) {
            if (self::path(basename($path)) !== null) {
                $exports[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($exports, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $exports;
    }

    /** Path to an existing export by the name from the URL; null = invalid name. */
    public static function path(string $file): ?string
    {
        return preg_match('/^export-\d{8}-\d{6}\.(zip|json)$/', $file) && is_file(Backup::FOLDER . '/' . $file) ? Backup::FOLDER . '/' . $file : null;
    }

    /* ---------- obsah.json ---------- */

    /** Written straight into the file and row by row from the database – so even a site with tens of thousands of articles need not fit in memory. */
    private static function writeContent(Db $db, Settings $settings, string $path): void
    {
        $f = fopen($path, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Cannot write to storage/zalohy – check write permissions.');
        }
        fwrite($f, '{"format":"kaleta-export","verze_formatu":' . self::FORMAT_VERSION . ',"kaleta":' . self::json(KALETA_VERSION) . ',"vytvoreno":' . self::json(date('c')) . ',"nastaveni":' . self::json(self::settings($db)));

        $authors = "(SELECT NULLIF(u.jmeno, '') FROM {uzivatele} u WHERE u.idu = c.autor) AS autor_jmeno";
        self::fields($f, 'stranky', self::streamRows($db, 'SELECT * FROM {stranky} WHERE ids > ? AND smazano IS NULL ORDER BY ids LIMIT 200', 'ids')); // the trash is not exported
        self::fields($f, 'kategorie', self::streamRows($db, 'SELECT idt, nazev, seo_link, popis, hodnost, jazyk, preklad_z FROM {kategorie} WHERE idt > ? ORDER BY idt LIMIT 500', 'idt'));
        self::fields($f, 'stitky', self::streamRows($db, 'SELECT ids, nazev, seo_link, popis, obrazek FROM {stitky} WHERE ids > ? ORDER BY ids LIMIT 500', 'ids'));
        self::fields($f, 'novinky', self::articles($db, $authors));
        self::fields($f, 'presmerovani', self::streamRows($db, 'SELECT idp, z_adresy, na_adresu, typ FROM {presmerovani} WHERE idp > ? ORDER BY idp LIMIT 1000', 'idp'));
        // builder: shared classes, site parts (header, footer, wrappers) and collections; not enquiries – they are visitors' personal data
        self::fields($f, 'tridy', $db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev'));
        // the drafts go along (stavba_koncept): a site moved in the middle of a redesign keeps its unfinished work
        self::fields($f, 'casti', $db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba, stavba_koncept FROM {casti} WHERE stavba IS NOT NULL OR stavba_koncept IS NOT NULL ORDER BY typ, jazyk, varianta'));
        // components ("komponenta" elements refer to them by number) and the library's own sections
        self::fields($f, 'komponenty', $db->all('SELECT idm, nazev, vlastnosti, stavba, stavba_koncept FROM {komponenty} ORDER BY idm'));
        self::fields($f, 'sekce', $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY idx'));
        self::fields($f, 'menu', $db->all('SELECT umisteni, jazyk, polozky FROM {menu} ORDER BY umisteni, jazyk'));
        self::fields($f, 'kolekce', $db->all('SELECT idk, nazev, seo_link, pole, detail, hidden_redirect, preset, schema_org, stavba, stavba_koncept FROM {kolekce} ORDER BY idk'));
        self::fields($f, 'kolekce_sablony', $db->all('SELECT idk, jazyk, stavba, stavba_koncept FROM {kolekce_sablony} WHERE stavba IS NOT NULL OR stavba_koncept IS NOT NULL ORDER BY idk, jazyk'));
        // popups with rules and the published build; not the counters (they are only this site's statistics)
        self::fields($f, 'popupy', $db->all('SELECT idpp, nazev, adresa, typ, spoustec, hodnota, pravidla, cetnost, dni, aktivni, poradi, valid_until, review_by, stavba, stavba_koncept FROM {popupy} ORDER BY idpp'));
        self::fields($f, 'kolekce_polozky', self::streamRows($db, 'SELECT idp, idk, nazev, seo_link, data, seo_titulek, popis, obrazek, noindex, poradi, zobrazit, zverejnit_od, valid_until, review_by, jazyk, datum FROM {kolekce_polozky} WHERE idp > ? AND smazano IS NULL ORDER BY idp LIMIT 500', 'idp'));
        // the previous files of documents (2.11) go along – they are content, kept for good; download counts are only this site's statistics
        self::fields($f, 'document_versions', self::streamRows($db, 'SELECT id, idp, file, version, replaced_at, replaced_by FROM {document_versions} WHERE id > ? ORDER BY id LIMIT 1000', 'id'));
        self::fields($f, 'media_slozky', $db->all('SELECT ids, nazev FROM {media_slozky} ORDER BY ids'));
        self::fields($f, 'media', self::streamRows($db, 'SELECT ido, sekce, nazev, popis, autor, obr_poloha, obr_width, obr_height, obr_vel, nahl_poloha, nahl_width, nahl_height, barva, ohnisko, datum FROM {media} WHERE ido > ? ORDER BY ido LIMIT 500', 'ido'));
        // the business (2.10): facts and exceptions to the opening hours – the week itself is in the settings (company_hours)
        self::fields($f, 'facts', $db->all('SELECT fact_key, language, label, type, value, schema_prop, source FROM {facts} ORDER BY fact_key, language'));
        self::fields($f, 'hours_exceptions', $db->all('SELECT date_from, date_to, closed, hours, note, notice_days FROM {hours_exceptions} WHERE date_to >= CURDATE() ORDER BY date_from'));
        self::fields($f, 'blueprints', $db->all('SELECT bkey, nazev, manifest FROM {blueprints} ORDER BY applied_at'));
        fwrite($f, "}\n");
        fclose($f);
    }

    /**
     * News with all columns; the author as a name (accounts are not exported), plus tags.
     * Translations hold the column preklad_z = idc of the news item in the default language, i.e. a number valid inside the export too.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function articles(Db $db, string $authors): \Generator
    {
        foreach (self::streamRows($db, "SELECT c.*, {$authors} FROM {novinky} c WHERE c.idc > ? AND c.smazano IS NULL ORDER BY c.idc LIMIT 100", 'idc') as $c) {
            $newsItem = array_diff_key($c, array_flip(self::EXCLUDED_ARTICLE_COLUMNS));
            $newsItem['autor'] = (string) ($c['autor_jmeno'] ?? '');
            $newsItem['stitky'] = array_map(intval(...), array_column($db->all('SELECT ids FROM {novinky_stitky} WHERE idc = ?', [$c['idc']]), 'ids'));
            yield $newsItem;
        }
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
        $all = $db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
        $selection = [];
        foreach ($all as $key => $value) {
            // the site name and description may have a variant for another language (site_name_en)
            $base = (string) preg_replace('/_[a-z]{2}$/', '', (string) $key);
            if (in_array($key, self::SETTINGS, true) || (in_array($base, Settings::PER_LANGUAGE, true) && $base !== $key)) {
                $selection[(string) $key] = (string) $value;
            }
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
        if (!is_dir(KALETA_ROOT . '/media')) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = 'media/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(KALETA_ROOT . '/media'))), '/');
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
            @unlink(Backup::FOLDER . '/' . $old['soubor']);
        }
    }

    private static function readme(bool $withMedia): string
    {
        return "Site export from Kaleta " . KALETA_VERSION . " (" . date('Y-m-d H:i') . ")\n"
            . "==========================================\n\n"
            . "obsah.json  all content of the site, UTF-8\n"
            . ($withMedia ? "media/      uploaded images and files; the paths match the \"obrazek\" columns and the media rows\n" : "media/      NOT in the archive (too large or too little disk space) – download the media/ folder from the site over FTP\n")
            . "\nobsah.json (format version " . self::FORMAT_VERSION . ")\n------------------------------\n"
            . "format, verze_formatu, kaleta, vytvoreno   header (format \"kaleta-export\", format version, Kaleta version, created)\n"
            . "nastaveni        site name and description, identity (logo, colours, fonts, networks), time zone, languages, home page\n"
            . "stranky          pages: ids, seo_link, titulek, popis, text (HTML), stavba and stavba_koncept (builder JSON), jazyk, preklad_z, nadrazena\n"
            . "kategorie        news categories: idt, nazev, seo_link, popis, jazyk, preklad_z\n"
            . "stitky           tags: ids, nazev, seo_link, popis, obrazek\n"
            . "novinky          news: titulek, uvod and text (HTML), datum, visible (1 = published), tema (= kategorie.idt), jazyk,\n"
            . "                 preklad_z (= idc of the news item it translates), autor (a name), stitky (list of stitky.ids) and more\n"
            . "presmerovani     redirects: z_adresy -> na_adresu, typ (301 or 302)\n"
            . "tridy            shared classes of the builder: nazev, styl (JSON), css\n"
            . "casti            site parts (header, footer, wrappers): typ, jazyk, varianta, stranky, stavba, stavba_koncept\n"
            . "komponenty       components: idm, nazev, vlastnosti, stavba, stavba_koncept (the \"komponenta\" element refers to idm)\n"
            . "sekce            saved sections: idx, nazev, prvek\n"
            . "menu             menus: umisteni, jazyk, polozky (JSON; a page item refers to stranky.ids)\n"
            . "kolekce, kolekce_sablony, kolekce_polozky   collections, their templates and items\n"
            . "document_versions  previous files of documents (a document library): idp (= kolekce_polozky.idp), file, version, replaced_at, replaced_by\n"
            . "popupy           pop-ups with rules and builds\n"
            . "media_slozky     media folders: ids, nazev\n"
            . "media            media library: obr_poloha (path in media/), nazev (alternative text), popis, autor, sizes, sekce (= folder)\n"
            . "facts            business facts: fact_key ({{fact.<key>}} in texts), language, label, type, value, schema_prop, source\n"
            . "hours_exceptions exceptions to the opening hours still to come: date_from, date_to, closed, hours, note, notice_days\n"
            . "blueprints       industry blueprints applied: bkey, nazev, manifest (JSON: presets, facts, questions, audit, claude)\n"
            . "\nAddresses on the site: page /<seo_link>; other language versions have the prefix /<language>/.\n"
            . "\nNot in the export on purpose: user accounts and passwords, keys and tokens, mail and backup settings, enquiries,\n"
            . "subscribers and visit statistics. Another Kaleta site imports this file in Import and export -> Import from Kaleta.\n"
            . "To restore this same site, use the database backup (Settings -> Backups and updates).\n";
    }
}
