<?php

declare(strict_types=1);

namespace Kaleta\Import;

use Kaleta\Admin\Modules\Media as MediaLibrary;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Admin\Modules\Redirects;
use Kaleta\Core\Db;
use Kaleta\Core\ImageDownloader;
use Kaleta\Core\Images;
use Kaleta\Core\Language;
use Kaleta\Core\Search;
use Kaleta\Core\Settings;
use Kaleta\Core\Slug;
use Kaleta\Core\WebImport;
use Kaleta\Core\WpContent;
use Kaleta\Core\WpFile;
use Kaleta\Core\WpImport;

/**
 * The batch runner shared by every structured importer (Import\Source): Ghost, Blogger and the systems that follow.
 * It follows Core\WpImport step by step, so the admin flow and the guarantees are the same:
 *  - the export lies in storage/import/sources/<key>-<name>.<ext> (a subfolder, so a Ghost .json is never mistaken for a
 *    Kaleta site export in storage/import/), the state in stav-<hash>.json next to it; the work runs in batches of at most
 *    BATCH records or SECONDS seconds per request, the position is the record's order from the source;
 *  - three passes: analyze (the preview, writes nothing), import (each record one transaction) and – on explicit request –
 *    images through Core\ImageDownloader with its SSRF rules (the old site's domain, or any public host when the source says
 *    its images live on a CDN);
 *  - ka_import_mapa with the source label <key>:<domain> remembers what became what: the same file can be run again and
 *    nothing is duplicated, failed images are not retried for every post;
 *  - HTML from the file is never trusted: it goes through Core\WpContent::sanitize like WordPress content;
 *  - no accounts are created (the mapping assigns authors to existing users), imported news is not announced, pages are
 *    created outside the menu, redirects go through Admin\Modules\Redirects::add (which also heals links).
 */
final class Batch
{
    public const int BATCH = 100;
    public const int IMAGE_BATCH = 10;
    public const int SECONDS = 8;

    /** Upper limit of an export's size; a JSON export is parsed whole, so it is lower than for WordPress. */
    public const int MAX_BYTES = 256 * 1024 * 1024;

    private string $source = '';

    /** @var array<string, int> categories converted in this request (key in the source => our idt) */
    private array $categories = [];

    private int $downloadsLeft = 0;
    private float $end = 0.0;

    /**
     * @param string $base the site folder for image URLs in the text (Request::basePath(), empty for a site in the root)
     * @param int $author the account the imported records belong to unless the mapping says otherwise
     */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base, private readonly int $author)
    {
    }

    /* ---------- files in storage/import/sources ---------- */

    public static function folder(): string
    {
        $folder = WpFile::folder() . '/sources';
        if (!is_dir($folder) && !@mkdir($folder, 0775, true)) {
            throw new \RuntimeException('Cannot create the storage/import folder – check write permissions.');
        }

        return $folder;
    }

    /** A file name from a form must stay in the folder and belong to a known system. */
    public static function isValidName(string $file): bool
    {
        return $file !== '' && strlen($file) <= 150 && basename($file) === $file && !str_starts_with($file, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $file) && Sources::keyOfFile($file) !== null;
    }

    /** Path to an existing file by the name from the form; null = invalid name or the file does not exist. */
    public static function path(string $file): ?string
    {
        return self::isValidName($file) && is_file(self::folder() . '/' . $file) ? self::folder() . '/' . $file : null;
    }

    /** A safe name for an uploaded export: <key>-<name without diacritics>.<the system's extension>. */
    public static function uploadName(string $key, string $original): string
    {
        $class = Sources::byKey($key) ?? throw new \InvalidArgumentException('Unknown import source: ' . $key);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        return $key . '-' . slugify(pathinfo($original, PATHINFO_FILENAME), 70) . '.' . (in_array($extension, $class::extensions(), true) ? $extension : $class::extensions()[0]);
    }

    /**
     * The exports in the folder (uploaded through the form or over FTP), newest on top.
     *
     * @return list<array{soubor: string, zdroj: string, velikost: int, cas: int}>
     */
    public static function listAll(): array
    {
        $files = [];
        foreach (glob(self::folder() . '/*.*') ?: [] as $path) {
            $key = Sources::keyOfFile(basename($path));
            if ($key !== null && self::isValidName(basename($path))) {
                $files[] = ['file' => basename($path), 'source' => $key, 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $files;
    }

    /* ---------- state file ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return [
            'file' => $file, 'source' => (string) Sources::keyOfFile($file), 'faze' => 'analyza', 'position' => 0, 'celkem' => 0,
            'web' => ['nazev' => '', 'adresa' => ''], 'prehled' => Preview::empty(), 'mapovani' => Mapping::DEFAULTS,
            'slovnik' => ['autori' => [], 'rubriky' => [], 'stitky' => []], 'nahledy' => [],
            'vysledek' => ['clanky' => 0, 'pages' => 0, 'rubriky' => 0, 'stitky' => 0, 'presmerovani' => 0, 'preskoceno' => 0],
            'obr' => ['type' => 'news', 'id' => 0, 'hotovo' => 0, 'celkem' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []],
            'stahovani' => [], // the fetch from a site's API (Import\Fetch::state) for the systems without an export file
        ];
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $path = self::stateFile($file);
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($state) && ($state['file'] ?? '') === $file ? array_replace_recursive(self::newState($file), $state) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['file']);
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path); // an interrupted write must not leave a half-written file
    }

    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
    }

    private static function stateFile(string $file): string
    {
        return self::folder() . '/stav-' . substr(sha1($file), 0, 16) . '.json';
    }

    /** The source as it opens for a state: the file in the folder, the old site's address from the mapping. */
    public static function sourceFor(array $state, ?string $path = null): Source
    {
        return Sources::open((string) $state['source'], $path ?? (string) self::path((string) $state['file']), (string) ($state['mapovani']['site_url'] ?? ''));
    }

    /** The old site's address: from the file, or the one the administrator entered. */
    public static function siteUrl(array $state): string
    {
        return (string) ($state['web']['adresa'] !== '' ? $state['web']['adresa'] : ($state['mapovani']['site_url'] ?? ''));
    }

    /* ---------- pure conversions (covered by tools/unit-tests.php) ---------- */

    /**
     * Status in the source → our record; null = not imported. A scheduled post is published with its future date; the site
     * publishes it when the date comes.
     *
     * @return array{visible: int}|null
     */
    public static function status(string $status): ?array
    {
        return match ($status) {
            'published', 'scheduled' => ['visible' => 1],
            'draft' => ['visible' => 0],
            default => null,
        };
    }

    /** Publish date from ISO 8601 (or anything strtotime() reads) in the server's time; empty or unreadable = now. */
    public static function date(string $value, ?int $now = null): string
    {
        $time = trim($value) === '' || str_starts_with(trim($value), '0000') ? false : strtotime(trim($value));

        return date('Y-m-d H:i:s', $time !== false && $time > 0 ? $time : ($now ?? time()));
    }

    /** Source label in ka_import_mapa: <key>:<domain of the old site>, so two old sites never share post numbers. */
    public static function label(string $key, string $siteUrl): string
    {
        $domain = ImageDownloader::domainFromUrl($siteUrl);

        return mb_substr($domain === '' ? $key : $key . ':' . $domain, 0, 40);
    }

    /* ---------- pass 1: preview (writes nothing to the database) ---------- */

    /**
     * Reads the next part of the file into the overview; at the end switches the phase to "nahled".
     *
     * @param array<string, mixed> $state
     * @param string|null $path only for tests; otherwise always the file from the folder
     */
    public static function analyze(array &$state, float $seconds = self::SECONDS, ?string $path = null): void
    {
        $source = self::sourceFor($state, $path);
        if ($state['position'] === 0) {
            $state['web'] = $source->site();
        }
        $end = microtime(true) + $seconds;
        foreach ($source->read((int) $state['position']) as $order => $record) {
            Preview::tally($state['prehled'], $record);
            self::remember($state, $record);
            $state['position'] = $order + 1;
            if (microtime(true) > $end) {
                return;
            }
        }
        $state['celkem'] = $state['position'];
        $state['position'] = 0;
        $state['faze'] = 'nahled';
        Preview::finish($state['prehled'], $source);
    }

    /**
     * Authors, categories and tags go into the state's dictionary: the import pass needs their names when it meets a post
     * in a later batch, and the preview form offers the authors for mapping.
     *
     * @param array<string, mixed> $state
     */
    private static function remember(array &$state, Author|Category|Tag|Post|Media $record): void
    {
        $dictionary = &$state['slovnik'];
        if ($record instanceof Author && count($dictionary['autori']) < 500) {
            $dictionary['autori'][mb_substr($record->key, 0, 190)] = mb_substr($record->name !== '' ? $record->name : $record->key, 0, 100);
        } elseif ($record instanceof Category && count($dictionary['rubriky']) < 5000) {
            $dictionary['rubriky'][mb_substr($record->key, 0, 190)] = ['nazev' => mb_substr($record->name, 0, 100), 'slug' => mb_substr($record->slug, 0, 110)];
        } elseif ($record instanceof Tag && count($dictionary['stitky']) < 20000) {
            $dictionary['stitky'][mb_substr($record->key, 0, 190)] = ['nazev' => mb_substr($record->name, 0, 80), 'slug' => mb_substr($record->slug, 0, 90)];
        }
    }

    /* ---------- pass 2: content import ---------- */

    /**
     * Converts the next batch of records. Each post is one transaction: either it is in the database completely (with the map
     * and the redirect), or not at all.
     *
     * @param array<string, mixed> $state
     */
    public function import(array &$state, ?string $path = null, int $batch = self::BATCH): void
    {
        $source = self::sourceFor($state, $path);
        $this->source = self::label((string) $state['source'], self::siteUrl($state));
        $end = microtime(true) + self::SECONDS;
        $count = 0;
        foreach ($source->read((int) $state['position']) as $order => $record) {
            if ($record instanceof Post) {
                $this->db->transaction(function () use ($record, &$state): void {
                    $this->post($record, $state);
                });
            }
            $state['position'] = $order + 1;
            if ((++$count >= $batch || microtime(true) > $end) && $state['position'] < $state['celkem']) {
                return; // the rest next time
            }
        }
        $state['faze'] = 'hotovo';
    }

    /** @param array<string, mixed> $state */
    private function post(Post $p, array &$state): void
    {
        $m = $state['mapovani'];
        $target = $p->type === 'page' ? $m['pages'] : $m['posts'];
        $status = self::status($p->status);
        if ($target === 'skip' || $status === null || (!$status['visible'] && !$m['drafts'])) {
            return;
        }
        if ($target === 'news') {
            $idc = $this->convertedId('news', $p->key, 'news', 'news_id');
            if ($idc !== null) {
                $state['vysledek']['preskoceno']++; // an already converted news item stays as it is – someone may have edited it since
            } else {
                $idc = $this->article($p, $status, $state);
            }
            // the featured image is only noted; it is downloaded in the separate images pass (also for an earlier item without one)
            if (preg_match('#^https?://#i', $p->featureImageUrl) && (string) $this->db->value('SELECT image FROM {news} WHERE news_id = ?', [$idc]) === '') {
                $state['nahledy'][$idc] = $p->featureImageUrl;
            }
        } elseif ($this->convertedId('page', $p->key, 'pages', 'page_id') !== null) {
            $state['vysledek']['preskoceno']++;
        } else {
            $this->page($p, $status, $state);
        }
    }

    /**
     * @param array{visible: int} $status
     * @param array<string, mixed> $state
     */
    private function article(Post $p, array $status, array &$state): int
    {
        $m = $state['mapovani'];
        [$intro, $text] = WpContent::introAndText($p->excerpt, $p->html);
        $categoryKey = $m['categories'] === 'category' && $p->categoryKeys !== [] ? (string) $p->categoryKeys[0] : '';
        $idt = $categoryKey !== '' ? $this->category($categoryKey, $state) : $this->defaultCategory($state);
        $language = (string) $this->db->value('SELECT language FROM {categories} WHERE category_id = ?', [$idt]); // the news item takes the category's language, as in the admin
        $title = mb_substr($p->title !== '' ? $p->title : t('(untitled)'), 0, 255);
        $now = date('Y-m-d H:i:s');
        $seo = Slug::makeUnique(
            slugify($p->slug !== '' ? rawurldecode($p->slug) : $title, 150),
            fn (string $url): bool => $this->db->value('SELECT news_id FROM {news} WHERE slug = ?', [$url]) !== null,
            120,
        );
        $idc = $this->db->insert('news', [
            'slug' => $seo, 'title' => $title, 'intro' => $intro, 'text' => $text, 'category_id' => $idt, 'language' => $language,
            'author_id' => $this->authorFor($p->authorKey, $state),
            'published_at' => self::date($p->publishedAt),
            'visible' => $status['visible'],
            'seo_title' => mb_substr(trim($p->seoTitle), 0, 255), 'seo_description' => mb_substr(trim($p->seoDescription), 0, 320),
            'edited_at' => $now,
            'announced_at' => $now, // an old news item is not announced (webhook, IndexNow)
        ]);
        $tags = $m['tags'] === 'tag' ? $p->tagKeys : [];
        if ($m['categories'] === 'tag') {
            $tags = [...$tags, ...$p->categoryKeys];
        }
        foreach (array_slice(array_unique($tags), 0, 20) as $key) {
            $this->tag($idc, (string) $key, $state);
        }
        Search::index($this->db, $idc);
        MediaLibrary::recordUsage($this->db, $idc, '', $intro, $text);
        $this->writeMap('news', $p->key, $idc);
        $state['vysledek']['clanky']++;
        if ($m['redirects']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . 'novinky/' . $seo);
        }

        return $idc;
    }

    /**
     * @param array{visible: int} $status
     * @param array<string, mixed> $state
     */
    private function page(Post $p, array $status, array &$state): void
    {
        $m = $state['mapovani'];
        $title = mb_substr($p->title !== '' ? $p->title : t('(untitled)'), 0, 200);
        $language = Language::column($this->settings, (string) $m['language']);
        // a page has its slug directly under the site root, so it must not take a slug the system uses
        $seo = Slug::makeUnique(
            slugify($p->slug !== '' ? rawurldecode($p->slug) : $title, 110),
            fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT page_id FROM {pages} WHERE slug = ?', [$url]) !== null,
            120,
        );
        $text = WpContent::sanitize($p->html);
        $plain = fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $ids = $this->db->insert('pages', [
            'slug' => $seo, 'title' => $title, 'text' => $text,
            'build' => $m['builder'] ? WpImport::pageBuild($this->db, $title, $text) : null,
            'description' => mb_substr($p->seoDescription !== '' ? $plain($p->seoDescription) : $plain($p->excerpt), 0, 300),
            'seo_title' => mb_substr(trim($p->seoTitle), 0, 200),
            'visible' => $status['visible'],
            'in_menu' => 0, // dozens of old pages would flood the navigation; the administrator adds them to the menu themselves
            'updated_at' => date('Y-m-d H:i:s'), 'language' => $language,
        ]);
        $this->writeMap('page', $p->key, $ids);
        $state['vysledek']['pages']++;
        if ($m['redirects']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $seo);
        }
    }

    /**
     * Our user for an author of the old site: the one from the mapping when it still exists, otherwise the importing user.
     *
     * @param array<string, mixed> $state
     */
    private function authorFor(string $authorKey, array $state): int
    {
        $user = (int) ($state['mapovani']['authors'][$authorKey] ?? 0);

        return $user > 0 && $this->db->value('SELECT user_id FROM {users} WHERE user_id = ?', [$user]) !== null ? $user : $this->author;
    }

    /**
     * Category by its key in the source; created when it does not exist yet. A key that is not among the categories is
     * looked up among the tags (Ghost has no categories – its primary tag stands in for one).
     *
     * @param array<string, mixed> $state
     */
    private function category(string $key, array &$state): int
    {
        if (isset($this->categories[$key])) {
            return $this->categories[$key];
        }
        $idt = $this->convertedId('category', $key, 'categories', 'category_id');
        if ($idt === null) {
            $known = $state['slovnik']['rubriky'][$key] ?? $state['slovnik']['stitky'][$key] ?? ['nazev' => $key, 'slug' => $key];
            $name = mb_substr($known['nazev'] !== '' ? $known['nazev'] : $key, 0, 100);
            $language = Language::column($this->settings, (string) $state['mapovani']['language']);
            $seo = slugify($known['slug'] !== '' ? rawurldecode($known['slug']) : $name, 110);
            // the same slug, name and language = a category already on the site; otherwise a new one with a free slug
            $idt = $this->db->value('SELECT category_id FROM {categories} WHERE slug = ? AND language = ? AND LOWER(name) = LOWER(?)', [$seo, $language, $name]);
            if ($idt === null) {
                $idt = $this->db->insert('categories', [
                    'name' => $name, 'description' => '', 'language' => $language,
                    'slug' => Slug::makeUnique($seo, fn (string $a): bool => $this->db->value('SELECT category_id FROM {categories} WHERE slug = ?', [$a]) !== null, 120),
                ]);
                $state['vysledek']['rubriky']++;
            }
            $this->writeMap('category', $key, (int) $idt);
        }

        return $this->categories[$key] = (int) $idt;
    }

    /**
     * Category for posts without one: the one chosen in the mapping, otherwise "Uncategorised" is created.
     *
     * @param array<string, mixed> $state
     */
    private function defaultCategory(array &$state): int
    {
        $idt = (int) $state['mapovani']['default_category'];
        if ($idt > 0 && $this->db->value('SELECT category_id FROM {categories} WHERE category_id = ?', [$idt]) !== null) {
            return $idt;
        }
        $state['slovnik']['rubriky']['nezarazene'] ??= ['nazev' => t('Uncategorized'), 'slug' => 'nezarazene'];

        return $state['mapovani']['default_category'] = $this->category('nezarazene', $state);
    }

    /**
     * A tag by its key in the source: looked up by the slug of its name, created when unknown – as when saving a news item.
     *
     * @param array<string, mixed> $state
     */
    private function tag(int $idc, string $key, array &$state): void
    {
        $known = $state['slovnik']['stitky'][$key] ?? $state['slovnik']['rubriky'][$key] ?? ['nazev' => $key, 'slug' => $key];
        $name = mb_substr(trim($known['nazev'] !== '' ? $known['nazev'] : $key), 0, 80);
        if ($name === '') {
            return;
        }
        $seo = slugify($name, 90);
        $ids = $this->db->value('SELECT tag_id FROM {tags} WHERE slug = ?', [$seo]);
        if ($ids === null) {
            $ids = $this->db->insert('tags', ['name' => $name, 'slug' => $seo]);
            $state['vysledek']['stitky']++;
        }
        $this->db->run('INSERT IGNORE INTO {news_tags} (news_id, tag_id) VALUES (?, ?)', [$idc, (int) $ids]);
        $this->writeMap('tag', $key, (int) $ids);
    }

    /** Redirect from the old address to the new one; Redirects::add skips an identical address by itself. */
    private function redirect(Post $p, string $new): int
    {
        $old = WpImport::oldPath($p->oldUrl);
        if ($old === '' || $old === $new) {
            return 0;
        }
        Redirects::add($this->db, $old, $new);

        return 1;
    }

    /* ---------- pass 3: images from the old site ---------- */

    /** The downloader for a state: the old site's domain, or any public host when the source keeps images on a CDN. */
    public static function downloader(array $state): ImageDownloader
    {
        return new ImageDownloader(self::siteUrl($state), self::sourceFor($state)->imagesFromAnyHost());
    }

    /**
     * Start (or restart) of downloading images: counters from zero; what failed last time gets a second chance.
     *
     * @param array<string, mixed> $state
     */
    public function startImages(array &$state): void
    {
        $this->source = self::label((string) $state['source'], self::siteUrl($state));
        $this->db->run("DELETE FROM {import_map} WHERE source = ? AND type = 'image' AND local_id = 0", [$this->source]);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {import_map} WHERE source = ? AND type IN ('news', 'page')", [$this->source]);
        $state['obr'] = ['type' => 'news', 'id' => 0, 'hotovo' => 0, 'celkem' => $total, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
        $state['faze'] = 'images';
    }

    /**
     * Downloads the next batch of images: the featured image and the images in the text. Works on the converted records
     * (not on the file); the position is the last finished news item or page.
     *
     * @param array<string, mixed> $state
     */
    public function images(array &$state, ImageDownloader $downloader): void
    {
        $this->source = self::label((string) $state['source'], self::siteUrl($state));
        $this->downloadsLeft = self::IMAGE_BATCH;
        $this->end = microtime(true) + self::SECONDS;
        while (true) {
            $id = $this->db->value('SELECT MIN(local_id) FROM {import_map} WHERE source = ? AND type = ? AND local_id > ?', [$this->source, $state['obr']['type'], (int) $state['obr']['id']]);
            if ($id === null && $state['obr']['type'] === 'news') {
                $state['obr'] = ['type' => 'page', 'id' => 0] + $state['obr']; // pages after the news items
                continue;
            }
            if ($id === null) {
                $state['faze'] = 'obrazky-hotovo';

                return;
            }
            if (!$this->recordImages((string) $state['obr']['type'], (int) $id, $state, $downloader)) {
                return; // the batch ran out in the middle of a record – next time it continues with the same one
            }
            $state['obr']['id'] = (int) $id;
            $state['obr']['hotovo']++;
            if (!$this->budgetLeft()) {
                return;
            }
        }
    }

    /** Whether this request may download another image: at most IMAGE_BATCH downloads and SECONDS seconds per batch. */
    private function budgetLeft(): bool
    {
        return $this->downloadsLeft > 0 && microtime(true) <= $this->end;
    }

    /**
     * @param array<string, mixed> $state
     * @return bool false = the batch budget ran out, the record is not complete yet
     */
    private function recordImages(string $type, int $id, array &$state, ImageDownloader $downloader): bool
    {
        $record = $type === 'news'
            ? $this->db->one('SELECT news_id, title, intro, text, image FROM {news} WHERE news_id = ?', [$id])
            : $this->db->one("SELECT page_id, title, '' AS intro, text, '' AS image FROM {pages} WHERE page_id = ?", [$id]);
        if ($record === null) {
            return true; // someone deleted the record in the meantime
        }
        $complete = true;
        $new = ['intro' => (string) $record['intro'], 'text' => (string) $record['text'], 'image' => (string) $record['image']];
        foreach (['intro', 'text'] as $field) {
            // on the DOM and sanitized again afterwards: an attribute's text never becomes a tag
            $new[$field] = WpImport::rewriteImages($new[$field], function (string $src, string $alt) use ($downloader, &$state, &$complete, $record): ?array {
                if (!$complete || !$downloader->isAllowedUrl($src)) {
                    return null; // images the rules do not allow stay as they are
                }
                $image = $this->image($src, $alt !== '' ? $alt : (string) $record['title'], $state, $downloader);
                if ($image === false) {
                    $complete = false;
                }

                return is_array($image) ? WpImport::mediaImage($this->base, $image, $alt) : null;
            });
        }
        $featured = (string) ($state['nahledy'][$id] ?? '');
        if ($type === 'news' && $complete && $featured !== '' && $downloader->isAllowedUrl($featured)) {
            $image = $this->image($featured, (string) $record['title'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $new['image'] === '') {
                $new['image'] = (string) $image['image_path'];
            }
            if ($complete) {
                unset($state['nahledy'][$id]);
            }
        }
        if ($type === 'news' && $new !== ['intro' => $record['intro'], 'text' => $record['text'], 'image' => $record['image']]) {
            $this->db->update('news', $new, ['news_id' => $id]);
            MediaLibrary::recordUsage($this->db, $id, $new['image'], $new['intro'], $new['text']);
        } elseif ($type === 'page' && $new['text'] !== $record['text']) {
            // the images are in Media now: the build of the imported page is converted again so that it refers to them
            $build = $this->db->value('SELECT build FROM {pages} WHERE page_id = ?', [$id]) !== null && $this->db->value('SELECT build_draft FROM {pages} WHERE page_id = ?', [$id]) === null
                ? ['build' => WpImport::pageBuild($this->db, (string) $record['title'], $new['text'])] : [];
            $this->db->update('pages', ['text' => $new['text']] + $build, ['page_id' => $id]);
        }

        return $complete;
    }

    /**
     * One image: from the map (already downloaded), or from the old site through Core\Images into Media.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null|false a ka_media row; null = cannot be downloaded; false = the batch has run out
     */
    private function image(string $url, string $name, array &$state, ImageDownloader $downloader): array|null|false
    {
        $original = WpImport::withoutSize($url);
        $key = sha1($original);
        $ido = $this->db->value("SELECT local_id FROM {import_map} WHERE source = ? AND type = 'image' AND source_id = ?", [$this->source, $key]);
        $row = $ido === null ? null : $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [(int) $ido]);
        if ($row !== null || ($ido !== null && (int) $ido === 0)) {
            return $row; // done earlier, or it already failed once (null)
        }
        if (!$this->budgetLeft()) {
            return false;
        }
        $this->downloadsLeft--;
        $temporary = self::folder() . '/obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            try {
                $data = $downloader->download($original);
            } catch (\RuntimeException $e) {
                if ($original === $url) {
                    throw $e;
                }
                $data = $downloader->download((string) preg_replace('/[?#].*$/', '', $url)); // the original is missing, try at least the copy from the text
            }
            file_put_contents($temporary, $data);
            $saved = Images::saveFile($temporary, basename((string) parse_url($original, PHP_URL_PATH)));
            $saved['name'] = mb_substr($name !== '' ? $name : $saved['name'], 0, 150);
            $saved['media_id'] = $this->db->insert('media', $saved + ['owner_id' => $this->author, 'created_at' => date('Y-m-d H:i:s')]);
            $this->writeMap('image', $key, (int) $saved['media_id']);
            $state['obr']['stazeno']++;

            return $saved;
        } catch (\RuntimeException $e) {
            $this->writeMap('image', $key, 0); // do not retry for every post that uses the image
            $state['obr']['chyb']++;
            $state['obr']['chyby'] = array_slice(array_merge($state['obr']['chyby'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /* ---------- map of foreign and our records (ka_import_mapa) ---------- */

    /** The id of our record the foreign one was already converted into – only if it still exists (a deleted one is imported again). */
    private function convertedId(string $type, string $foreignId, string $table, string $key): ?int
    {
        $ourId = $this->db->value('SELECT local_id FROM {import_map} WHERE source = ? AND type = ? AND source_id = ?', [$this->source, $type, mb_substr($foreignId, 0, 190)]);
        if ($ourId === null || $this->db->value('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' = ?', [(int) $ourId]) === null) {
            return null;
        }

        return (int) $ourId;
    }

    private function writeMap(string $type, string $foreignId, int $ourId): void
    {
        $this->db->run(
            'INSERT INTO {import_map} (source, type, source_id, local_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE local_id = VALUES(local_id)',
            [$this->source, $type, mb_substr($foreignId, 0, 190), $ourId],
        );
    }

    /** Whether the address can be used as the old site (http(s), a domain, no user name) – for the mapping form. */
    public static function validSiteUrl(string $url): bool
    {
        return WebImport::validUrl($url);
    }
}
