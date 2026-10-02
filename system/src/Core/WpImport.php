<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Redirects;
use Kaleta\Admin\Modules\Pages;

/**
 * Import from WordPress: pages, posts (→ news), categories, tags, redirects from old URLs and (separately) images.
 * Comments are not carried over – a company site does not have them.
 *
 * How it holds together:
 *  - The file is read as a stream (Core\WpFile) and the work is done IN BATCHES – at most BATCH posts or SECONDS seconds per request,
 *    so that the import survives the time limits of shared hosting. Where it stopped (which <item>) is kept in the state file
 *    storage/import/stav-<hash>.json; the next request continues from there.
 *  - The table ka_import_mapa remembers which foreign record became which of ours. So the same file can be run again without
 *    duplicates (an already converted news item is skipped and later edits are not overwritten) and images are not downloaded twice.
 *  - There are three passes: preview (only counts, does not touch the database), content import and – only on explicit request –
 *    downloading images.
 *  - No accounts are created: a news item belongs to whoever runs the import.
 *  - Imported news items are "announced" right away – hundreds of old texts must not trigger the webhook or IndexNow.
 */
final class WpImport
{
    public const int BATCH = 100;
    public const int IMAGE_BATCH = 10;
    public const int SECONDS = 8;

    /** Default import options (the Preview step). */
    public const array DEFAULT_OPTIONS = ['jazyk' => '', 'koncepty' => true, 'stranky' => true, 'stavitel' => true, 'presmerovani' => true, 'rubrika' => 0];

    /** Post types we can handle; the preview only lists the others (menus, custom types of add-ons…). */
    private const array TYPES = ['post', 'page', 'attachment'];

    private string $source = 'wp';

    /** @var array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>} */
    private array $header = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];

    /** @var array<string, int> categories converted in this request (category slug in WordPress => our idt) */
    private array $categories = [];

    private int $downloadsLeft = 0;
    private float $end = 0.0;

    /**
     * @param string $base the site folder for image URLs in the text (Request::basePath(), empty for a site in the root)
     * @param int $author the account the imported articles will belong to
     */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base, private readonly int $author)
    {
    }

    /* ---------- state file ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return [
            'soubor' => $file, 'faze' => 'analyza', 'pozice' => 0, 'celkem' => 0, 'web' => ['nazev' => '', 'adresa' => ''],
            'prehled' => ['clanky' => [], 'stranky' => [], 'rubriky' => 0, 'stitky' => 0, 'autori' => 0, 'prilohy' => 0, 'obrazky' => 0, 'jine' => [], 'zkratky' => [], 'seo' => []],
            'prilohy' => [], 'volby' => self::DEFAULT_OPTIONS, 'nahledy' => [],
            'vysledek' => ['clanky' => 0, 'stranky' => 0, 'rubriky' => 0, 'presmerovani' => 0, 'preskoceno' => 0, 'seo' => 0],
            'obr' => ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $path = self::stateFile($file);
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($state) && ($state['soubor'] ?? '') === $file ? array_replace_recursive(self::newState($file), $state) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['soubor']);
        // first next to it, then rename: an interrupted write must not leave a half-written file
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
    }

    private static function stateFile(string $file): string
    {
        return WpFile::folder() . '/stav-' . substr(sha1($file), 0, 16) . '.json';
    }

    /* ---------- pass 1: preview (writes nothing to the database) ---------- */

    /**
     * Goes through the next part of the file and adds it to the overview. When it reaches the end, it switches the phase to "nahled".
     *
     * @param array<string, mixed> $state
     */
    public static function analyze(array &$state, float $seconds = self::SECONDS, ?string $path = null): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor'])); // $path only for tests; otherwise always the file from storage/import
        if ($state['pozice'] === 0) {
            $h = $wp->header();
            $state['web'] = ['nazev' => $h['nazev'], 'adresa' => $h['adresa']];
            $state['prehled']['rubriky'] = count($h['rubriky']);
            $state['prehled']['stitky'] = count($h['stitky']);
            $state['prehled']['autori'] = count($h['autori']);
        }
        $end = microtime(true) + $seconds;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            self::tally($state, $p);
            $state['pozice'] = $order + 1;
            if (microtime(true) > $end) {
                return;
            }
        }
        $state['celkem'] = $state['pozice'];
        $state['pozice'] = 0;
        $state['faze'] = 'nahled';
        arsort($state['prehled']['zkratky']);
        $state['prehled']['zkratky'] = array_slice($state['prehled']['zkratky'], 0, 15, true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $p a post from WpFile::item()
     */
    private static function tally(array &$state, array $p): void
    {
        $overview = &$state['prehled'];
        if ($p['typ'] === 'attachment') {
            $overview['prilohy']++;
            if ($p['priloha_url'] !== '') {
                $state['prilohy'][(int) $p['id']] = $p['priloha_url']; // for [gallery ids] and the featured images of articles
            }
        } elseif ($p['typ'] === 'post' || $p['typ'] === 'page') {
            $destination = $p['typ'] === 'post' ? 'clanky' : 'stranky';
            $overview[$destination][$p['stav']] = ($overview[$destination][$p['stav']] ?? 0) + 1;
            $overview['obrazky'] += substr_count(strtolower($p['obsah']), '<img');
            foreach (WpContent::unknownShortcodes($p['obsah']) as $shortcode) {
                $overview['zkratky'][$shortcode] = ($overview['zkratky'][$shortcode] ?? 0) + 1;
            }
            // SEO plugin data (Core\WpSeo): how many items carry a custom title, description, noindex or canonical URL, per plugin
            $seo = WpSeo::raw($p['meta'] ?? []);
            if ($seo['plugin'] !== '') {
                $counts = $overview['seo'][$seo['plugin']] ?? ['title' => 0, 'description' => 0, 'noindex' => 0, 'canonical' => 0];
                $counts['title'] += (int) ($seo['title'] !== '' && !WpSeo::isDefaultPattern($seo['title']));
                $counts['description'] += (int) ($seo['description'] !== '' && !WpSeo::isDefaultPattern($seo['description']));
                $counts['noindex'] += (int) $seo['noindex'];
                $counts['canonical'] += (int) ($seo['canonical'] !== '');
                $overview['seo'][$seo['plugin']] = $counts;
            }
        } elseif (!in_array($p['typ'], self::TYPES, true)) {
            $overview['jine'][$p['typ']] = ($overview['jine'][$p['typ']] ?? 0) + 1;
        }
    }

    /* ---------- pure conversions (covered by tools/unit-tests.php) ---------- */

    /**
     * Post status in WordPress → our news item; null = not imported (private, trash, auto-drafts, revisions).
     * A scheduled post is a published news item with a future date for us; "pending review" is a draft.
     *
     * @return array{visible:int}|null
     */
    public static function articleStatus(string $wpStatus, bool $passwordProtected = false): ?array
    {
        $state = match ($wpStatus) {
            'publish', 'future' => ['visible' => 1],
            'draft', 'pending' => ['visible' => 0],
            default => null,
        };

        // a password-protected post has no equivalent here – it must not be published silently, it stays a draft
        return $state !== null && $passwordProtected ? ['visible' => 0] : $state;
    }

    /**
     * Publish date: the old site's local time; drafts often have it zeroed, then the GMT time, the RSS date and finally today are used.
     *
     * @param array<string, mixed> $p
     */
    public static function date(array $p, ?int $now = null): string
    {
        foreach ([$p['datum'] ?? '', $p['datum_gmt'] ?? '', $p['vydano'] ?? ''] as $i => $value) {
            $time = $value === '' || str_starts_with((string) $value, '0000') ? false : strtotime($value . ($i === 1 ? ' UTC' : ''));
            if ($time !== false && $time > 0) {
                return date('Y-m-d H:i:s', $time);
            }
        }

        return date('Y-m-d H:i:s', $now ?? time());
    }

    /**
     * A free slug (seo_link): when the base is taken, it gets a sequence number – the same as in the admin.
     *
     * @param callable(string): bool $isTaken
     */
    public static function availableSlug(string $base, callable $isTaken): string
    {
        return Slug::makeUnique($base, $isTaken, 120);
    }

    /** Path of the old URL for a redirect (without the domain and the slashes at the ends); empty = nothing to redirect. */
    public static function oldPath(string $link): string
    {
        $path = trim(rawurldecode((string) parse_url($link, PHP_URL_PATH)), '/ ');

        return mb_strlen($path) > 255 || !mb_check_encoding($path, 'UTF-8') ? '' : $path;
    }

    /** Image URL without the thumbnail size and without parameters: foto-300x200.jpg?x=1 → foto.jpg (WordPress inserts downsized copies into the text). */
    public static function withoutSize(string $url): string
    {
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        return (string) preg_replace('#-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|gif|webp)$)#i', '', $url);
    }

    /** Source label in ka_import_mapa: two different old sites have the same post numbers, which is why it contains the domain. */
    public static function source(string $siteUrl): string
    {
        $domain = ImageDownloader::domainFromUrl($siteUrl);

        return mb_substr($domain === '' ? 'wp' : 'wp:' . $domain, 0, 40);
    }

    /* ---------- pass 2: content import ---------- */

    /**
     * Converts the next batch of posts. Each post is one transaction: it is either in the database completely (with comments and the map), or not at all.
     *
     * @param array<string, mixed> $state
     */
    public function import(array &$state, ?string $path = null, int $batch = self::BATCH): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor']));
        $this->header = $wp->header();
        $this->source = self::source((string) $state['web']['adresa']);
        $end = microtime(true) + self::SECONDS;
        $count = 0;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            $this->db->transaction(function () use ($p, &$state): void {
                match ($p['typ']) {
                    'post' => $this->article($p, $state),
                    'page' => $state['volby']['stranky'] ? $this->page($p, $state) : null,
                    default => null,
                };
            });
            $state['pozice'] = $order + 1;
            if ((++$count >= $batch || microtime(true) > $end) && $state['pozice'] < $state['celkem']) {
                return; // the rest next time; the import knows the number of posts (celkem) from the preview
            }
        }
        $state['faze'] = 'hotovo';
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function article(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            return;
        }
        $idc = $this->convertedId('clanek', (string) $p['id'], 'novinky', 'idc');
        if ($idc !== null) {
            $state['vysledek']['preskoceno']++; // an already converted news item stays as it is – someone may have edited it in the meantime
        } else {
            $idc = $this->createArticle($p, $articleStatus, $state);
        }
        // for now we only note the featured image – it is downloaded in a separate step (also for a previously converted news item that does not have it yet)
        $preview = (string) ($state['prilohy'][$p['nahled']] ?? '');
        if ($preview !== '' && (string) $this->db->value('SELECT obrazek FROM {novinky} WHERE idc = ?', [$idc]) === '') {
            $state['nahledy'][$idc] = $preview;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array{visible:int} $articleStatus
     * @param array<string, mixed> $state
     */
    private function createArticle(array $p, array $articleStatus, array &$state): int
    {
        [$home, $text] = WpContent::introAndText($p['perex'], $p['obsah'], $state['prilohy']);
        $colorScheme = $p['rubriky'] === [] ? $this->defaultCategory($state) : $this->category((string) array_key_first($p['rubriky']), (string) reset($p['rubriky']), $state);
        $language = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$colorScheme]); // the news item takes over the category's language, as when saving in the admin
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(untitled)'), 0, 255);
        $now = date('Y-m-d H:i:s');

        $seo = self::availableSlug(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 150),
            fn (string $url): bool => $this->db->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$url]) !== null,
        );
        $plugin = $this->seo($p, (string) (reset($p['rubriky']) ?: ''), 255, 320, $state);
        $idc = $this->db->insert('novinky', [
            'seo_link' => $seo, 'titulek' => $title, 'uvod' => $home, 'text' => $text, 'tema' => $colorScheme, 'jazyk' => $language,
            'autor' => $this->author,
            'datum' => self::date($p),
            'visible' => $articleStatus['visible'],
            'seo_titulek' => $plugin['title'], 'seo_popis' => $plugin['description'], 'noindex' => $plugin['noindex'],
            'zmeneno' => $now,
            'oznameno' => $now, // an old news item is not announced (webhook, IndexNow)
        ]);
        foreach (array_slice($p['stitky'], 0, 20, true) as $url => $name) {
            $this->tag($idc, (string) $url, $name !== '' ? $name : (string) ($this->header['stitky'][$url] ?? $url));
        }
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', $home, $text);
        $this->writeMap('clanek', (string) $p['id'], $idc);
        $state['vysledek']['clanky']++;

        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . 'novinky/' . $seo);
        }

        return $idc;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function page(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            return;
        }
        if ($this->convertedId('stranka', (string) $p['id'], 'stranky', 'ids') !== null) {
            $state['vysledek']['preskoceno']++;

            return;
        }
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(untitled)'), 0, 200);
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        // a page has its slug directly under the site root, so it must not take a slug the system uses
        $seo = self::availableSlug(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 110),
            fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ?', [$url]) !== null,
        );
        $text = WpContent::sanitize($p['obsah'], $state['prilohy']);
        $plugin = $this->seo($p, '', 200, 300, $state);
        $ids = $this->db->insert('stranky', [
            'seo_link' => $seo, 'titulek' => $title, 'text' => $text,
            'stavba' => ($state['volby']['stavitel'] ?? false) ? $this->build($title, $text) : null,
            'popis' => $plugin['description'] !== '' ? $plugin['description'] : mb_substr(trim(html_entity_decode(strip_tags($p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300),
            'seo_titulek' => $plugin['title'], 'noindex' => $plugin['noindex'],
            'zobrazit' => $articleStatus['visible'],
            'v_menu' => 0, // dozens of old pages would flood the navigation; the administrator adds them to the menu themselves
            'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $language,
        ]);
        $this->writeMap('stranka', (string) $p['id'], $ids);
        $state['vysledek']['stranky']++;
        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $seo);
        }
    }

    /**
     * SEO title, description and noindex from the SEO plugin meta of the post (Core\WpSeo), with the plugin variables filled in
     * from the new site and the post; trimmed to the columns of the target table.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     * @return array{title:string, description:string, noindex:int}
     */
    private function seo(array $p, string $category, int $titleLimit, int $descriptionLimit, array &$state): array
    {
        $raw = WpSeo::raw($p['meta'] ?? []);
        if ($raw['plugin'] === '') {
            return ['title' => '', 'description' => '', 'noindex' => 0];
        }
        $plain = fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/\[[^\]]*\]/', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $excerpt = $plain($p['perex']);
        $context = [
            'title' => $p['titulek'], 'sitename' => $this->settings->get('site_name'), 'sitedesc' => $this->settings->get('site_description'),
            'excerpt' => mb_strimwidth($excerpt !== '' ? $excerpt : $plain($p['obsah']), 0, 160, '…'), 'category' => $category,
        ];
        $result = ['title' => WpSeo::title($raw['title'], $context, $titleLimit), 'description' => WpSeo::description($raw['description'], $context, $descriptionLimit), 'noindex' => (int) $raw['noindex']];
        if ($result['title'] !== '' || $result['description'] !== '' || $result['noindex'] === 1) {
            $state['vysledek']['seo']++;
        }

        return $result;
    }

    /**
     * Category by the category slug in WordPress (the tree is flattened); creates it when it does not exist yet. Only categories with posts are created.
     *
     * @param array<string, mixed> $state
     */
    private function category(string $url, string $name, array &$state): int
    {
        if (isset($this->categories[$url])) {
            return $this->categories[$url];
        }
        $idt = $this->convertedId('rubrika', $url, 'kategorie', 'idt');
        if ($idt === null) {
            $description = $this->header['rubriky'][$url] ?? ['nazev' => $name, 'predek' => ''];
            $name = mb_substr($description['nazev'] !== '' ? $description['nazev'] : ($name !== '' ? $name : $url), 0, 100);
            $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
            $seo = slugify(rawurldecode($url), 110);
            // the same slug, name and language = the same category that is already on the site; otherwise a new one with a free slug
            $idt = $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND jazyk = ? AND LOWER(nazev) = LOWER(?)', [$seo, $language, $name]);
            if ($idt === null) {
                $idt = $this->db->insert('kategorie', [
                    'nazev' => $name, 'popis' => '', 'jazyk' => $language,
                    'seo_link' => self::availableSlug($seo, fn (string $a): bool => $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ?', [$a]) !== null),
                ]);
                $state['vysledek']['rubriky']++;
            }
            $this->writeMap('rubrika', $url, (int) $idt);
        }

        return $this->categories[$url] = (int) $idt;
    }

    /**
     * Category for posts without a category: the one chosen in the preview, otherwise 'Nezařazené' (Uncategorized) is created.
     *
     * @param array<string, mixed> $state
     */
    private function defaultCategory(array &$state): int
    {
        $idt = (int) $state['volby']['rubrika'];
        if ($idt > 0 && $this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$idt]) !== null) {
            return $idt;
        }

        return $state['volby']['rubrika'] = $this->category('nezarazene', t('Nezařazené'), $state);
    }

    /** A tag is looked up by the slug made from its name and an unknown one is created – the same as when saving a news item in the admin. */
    private function tag(int $idc, string $wpSlug, string $name): void
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            return;
        }
        $seo = slugify($name, 90);
        $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
        $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo]);
        $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        $this->writeMap('stitek', $wpSlug, $ids);
    }

    /**
     * Redirect from the old URL (both the pretty and the numeric /?p=123) to the new one. Redirects::add skips an identical URL by itself.
     *
     * @param array<string, mixed> $p
     */
    private function redirect(array $p, string $newVersion): int
    {
        $count = 0;
        foreach (array_unique([self::oldPath($p['odkaz']), $p['id'] > 0 ? '?p=' . (int) $p['id'] : '']) as $old) {
            if ($old !== '' && $old !== $newVersion) {
                Redirects::add($this->db, $old, $newVersion);
                $count++;
            }
        }

        return $count;
    }

    /* ---------- pass 3: images from the old site ---------- */

    /**
     * Start (or restart) of downloading images: counters from zero; what failed to download last time gets a second chance.
     *
     * @param array<string, mixed> $state
     */
    public function startImages(array &$state): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND nase_id = 0", [$this->source]);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {import_mapa} WHERE zdroj = ? AND typ IN ('clanek', 'stranka')", [$this->source]);
        $state['obr'] = ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => $total, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
        $state['faze'] = 'obrazky';
    }

    /**
     * Downloads the next batch of images: the article's featured image and the images in the text that are on the old site's domain.
     * Works on already converted records (not on the file); position = the last finished article or page.
     *
     * @param array<string, mixed> $state
     */
    public function images(array &$state, ImageDownloader $downloader): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->downloadsLeft = self::IMAGE_BATCH;
        $this->end = microtime(true) + self::SECONDS;
        while (true) {
            $id = $this->db->value('SELECT MIN(nase_id) FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND nase_id > ?', [$this->source, $state['obr']['typ'], (int) $state['obr']['id']]);
            if ($id === null && $state['obr']['typ'] === 'clanek') {
                $state['obr'] = ['typ' => 'stranka', 'id' => 0] + $state['obr']; // pages after the articles
                continue;
            }
            if ($id === null) {
                $state['faze'] = 'obrazky-hotovo';

                return;
            }
            if (!$this->recordImages((string) $state['obr']['typ'], (int) $id, $state, $downloader)) {
                return; // the batch ran out in the middle of a record – next time it continues with the same one
            }
            $state['obr']['id'] = (int) $id;
            $state['obr']['hotovo']++;
            if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return bool false = the batch budget ran out, the record is not complete yet
     */
    private function recordImages(string $type, int $id, array &$state, ImageDownloader $downloader): bool
    {
        $record = $type === 'clanek'
            ? $this->db->one('SELECT idc, titulek, uvod, text, obrazek FROM {novinky} WHERE idc = ?', [$id])
            : $this->db->one("SELECT ids, titulek, '' AS uvod, text, '' AS obrazek FROM {stranky} WHERE ids = ?", [$id]);
        if ($record === null) {
            return true; // someone deleted the record in the meantime
        }
        $complete = true;
        $newItems = ['uvod' => (string) $record['uvod'], 'text' => (string) $record['text'], 'obrazek' => (string) $record['obrazek']];
        foreach (['uvod', 'text'] as $field) {
            $newItems[$field] = (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use ($downloader, &$state, &$complete, $record): string {
                $src = preg_match('#\bsrc="([^"]+)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                if (!$complete || !$downloader->isAllowedUrl($src)) {
                    return $m[0]; // foreign images (another domain) stay as they are – they are never downloaded
                }
                $alt = preg_match('#\balt="([^"]*)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                $image = $this->image($src, $alt !== '' ? $alt : (string) $record['titulek'], $state, $downloader);
                if ($image === false) {
                    $complete = false;
                }

                return is_array($image)
                    ? '<img src="' . e($this->base . '/' . $image['obr_poloha']) . '" alt="' . e($alt) . '" width="' . (int) $image['obr_width'] . '" height="' . (int) $image['obr_height'] . '" loading="lazy" data-id="' . (int) $image['ido'] . '">'
                    : $m[0];
            }, $newItems[$field]);
        }
        $preview = (string) ($state['nahledy'][$id] ?? '');
        if ($type === 'clanek' && $complete && $preview !== '') {
            $image = $this->image($preview, (string) $record['titulek'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $newItems['obrazek'] === '') {
                $newItems['obrazek'] = (string) $image['obr_poloha'];
            }
            if ($complete) {
                unset($state['nahledy'][$id]);
            }
        }
        if ($type === 'clanek' && $newItems !== ['uvod' => $record['uvod'], 'text' => $record['text'], 'obrazek' => $record['obrazek']]) {
            $this->db->update('novinky', $newItems, ['idc' => $id]);
            Media::recordUsage($this->db, $id, $newItems['obrazek'], $newItems['uvod'], $newItems['text']);
        } elseif ($type === 'stranka' && $newItems['text'] !== $record['text']) {
            // the images are already in Media: the build of the imported page is converted again so that it refers to them
            $build = $this->db->value('SELECT stavba FROM {stranky} WHERE ids = ?', [$id]) !== null && $this->db->value('SELECT stavba_koncept FROM {stranky} WHERE ids = ?', [$id]) === null
                ? ['stavba' => $this->build((string) $record['titulek'], $newItems['text'])] : [];
            $this->db->update('stranky', ['text' => $newItems['text']] + $build, ['ids' => $id]);
        }

        return $complete;
    }

    /**
     * A WordPress page as a build (Builder\HtmlConverter): heading and content in a narrow section, Gutenberg blocks as elements,
     * WordPress classes without a style removed. Custom HTML (embedded maps, iframe) may be created – the import is run by an administrator.
     */
    private function build(string $title, string $html): ?string
    {
        $conversion = \Kaleta\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, true);
        $build = \Kaleta\Builder\HtmlConverter::withoutClasses($conversion['stavba'], array_column($this->db->all('SELECT nazev FROM {tridy}'), 'nazev'));
        foreach ($build['deti'] as &$section) {
            if ($section['typ'] === 'sekce' && !isset($section['kotva'])) {
                $section['obsah']['sirka'] = 'uzka'; // page text reads better in a narrower column
            }
        }
        unset($section);
        [$clean] = \Kaleta\Builder\Build::sanitize($build, true);

        return $clean['deti'] === [] ? null : \Kaleta\Builder\Build::toJson($clean);
    }

    /**
     * One image: from the map (already downloaded), or from the old site through Core\Images into Media.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null|false a ka_media row; null = cannot be downloaded; false = the batch has run out
     */
    private function image(string $url, string $name, array &$state, ImageDownloader $downloader): array|null|false
    {
        $original = self::withoutSize($url);
        $key = sha1($original);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND cizi_id = ?", [$this->source, $key]);
        $row = $ido === null ? null : $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $ido]);
        if ($row !== null || ($ido !== null && (int) $ido === 0)) {
            return $row; // done earlier, or it already failed once (null)
        }
        if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
            return false;
        }
        $this->downloadsLeft--;
        $temporary = WpFile::folder() . '/obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            try {
                $data = $downloader->download($original);
            } catch (\RuntimeException $e) {
                if ($original === $url) {
                    throw $e;
                }
                $data = $downloader->download((string) preg_replace('/[?#].*$/', '', $url)); // the original is missing, try at least the downsized copy from the text
            }
            file_put_contents($temporary, $data);
            $saved = Images::saveFile($temporary, basename((string) parse_url($original, PHP_URL_PATH)));
            $saved['nazev'] = mb_substr($name !== '' ? $name : $saved['nazev'], 0, 150);
            $saved['ido'] = $this->db->insert('media', $saved + ['vlastnik' => $this->author, 'datum' => date('Y-m-d H:i:s')]);
            $this->writeMap('obrazek', $key, (int) $saved['ido']);
            $state['obr']['stazeno']++;

            return $saved;
        } catch (\RuntimeException $e) {
            $this->writeMap('obrazek', $key, 0); // do not retry for every article that uses the image
            $state['obr']['chyb']++;
            $state['obr']['chyby'] = array_slice(array_merge($state['obr']['chyby'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /* ---------- map of foreign and our records ---------- */

    /** The id of our record the foreign one was already converted into – only if it still exists (a deleted one is imported again). */
    private function convertedId(string $type, string $foreignId, string $table, string $key): ?int
    {
        $ourId = $this->db->value('SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND cizi_id = ?', [$this->source, $type, mb_substr($foreignId, 0, 190)]);
        if ($ourId === null || $this->db->value('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' = ?', [(int) $ourId]) === null) {
            return null;
        }

        return (int) $ourId;
    }

    private function writeMap(string $type, string $foreignId, int $ourId): void
    {
        $this->db->run(
            'INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)',
            [$this->source, $type, mb_substr($foreignId, 0, 190), $ourId],
        );
    }
}
