<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Redirects;

/**
 * Import from any website by its address (2.6): Wix, Webnode, Jimdo, Squarespace, Joomla, Drupal, a WordPress site
 * without an export – whatever serves HTML.
 *
 * How it holds together:
 *  - Finding the pages: the sitemap (robots.txt, /sitemap.xml and the usual variants, sitemap indexes); a site without one
 *    is crawled from the home page along its own links. At most MAX_PAGES addresses, only the site's own domain.
 *  - Each page is downloaded through Core\ImageDownloader (public addresses only, no redirects elsewhere, limits), its
 *    main content is taken out (main, article, the usual content containers; never the header, footer, navigation,
 *    cookie bars or forms) and turned into a builder page by Builder\HtmlConverter. Images – also from the site's CDN –
 *    go into Media through Core\Images. Addresses that look like articles (/blog/, /news/, a date in the path, a
 *    publication date in the page) become news items when the News extension is on.
 *  - Pages are created hidden and outside the menu, so nothing changes for visitors until the administrator looks at
 *    them; old addresses redirect to the new ones.
 *  - The work runs in batches of SECONDS (shared hosting), the state is a file in storage/import, and tl_import_map
 *    remembers what was imported, so running it again skips finished pages.
 * The design is not copied: the pages take the site's design system; Claude can match the look afterwards.
 */
final class WebImport
{
    public const int MAX_PAGES = 300;
    private const float SECONDS = 15.0;
    private const int IMAGES_PER_PAGE = 40;
    private const int MAX_SITEMAPS = 20;

    /** Addresses that are not content pages. */
    private const string SKIP = '#/(wp-admin|wp-json|wp-login|feed|tag|tags|author|category|kategorie|search|hledat|cart|kosik|checkout|login|account|my-account)(/|$)|/page/\d+/?$|\.(xml|json|rss|atom|pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp3|mp4|css|js)$#i';

    /** Addresses that look like articles. */
    private const string ARTICLE = '#/(blog|news|novinky|aktuality|clanky|clanek|articles?|posts?|aktuelles|neuigkeiten|actualites|noticias|notizie|aktualnosci|novinky-a-akce)/[^/]+|/\d{4}/\d{2}/#i';

    /** Containers with the main content, in order of preference. */
    private const array CONTENT = ['main', 'article', '[role="main"]', '#content', '#main', '.entry-content', '.post-content', '.page-content', '.content'];

    /** Parts of a page that are never content. */
    private const string NOISE = 'script, style, noscript, template, nav, header, footer, aside, [role="navigation"], [role="banner"], [role="contentinfo"]';

    /** Parts that are never content either, removed after the widgets (forms, frames, hidden panels of an accordion) were taken out of the page. */
    private const string NOISE_AFTER_WIDGETS = 'iframe, form, svg, button, dialog, [aria-hidden="true"]';

    /** Classes and ids of cookie bars, pop-ups, sharing and comment blocks. */
    private const string NOISE_NAMES = '/cookie|consent|gdpr|popup|modal|newsletter|share|sharing|social|breadcrumb|comment|related|sidebar|(?<![\w-])widget|skip-link|screen-reader/i';

    private float $end = 0.0;

    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $author, private readonly ImageDownloader $downloader, private readonly string $base = '')
    {
    }

    /* ---------- state ---------- */

    /**
     * @param array{language?: string, images?: bool, redirects?: bool, news?: bool} $options
     * @return array<string, mixed>
     */
    public static function newState(string $url, array $options = []): array
    {
        $c = parse_url(trim($url));
        $origin = strtolower((string) ($c['scheme'] ?? '')) . '://' . strtolower((string) ($c['host'] ?? '')) . (isset($c['port']) ? ':' . $c['port'] : '');

        return [
            'id' => substr(sha1($origin . microtime()), 0, 16), 'web' => $origin, 'domain' => ImageDownloader::domainFromUrl($origin), 'phase' => 'finding',
            'queue' => [$origin . '/'], 'maps' => [], 'maps_done' => false, 'urls' => [], 'position' => 0,
            'options' => ['language' => (string) ($options['language'] ?? ''), 'images' => (bool) ($options['images'] ?? true),
                'redirects' => (bool) ($options['redirects'] ?? true), 'news' => (bool) ($options['news'] ?? true),
                'keep_starters' => null], // ids of the installer's starter pages that stay when an imported page wants their address; null = untouched ones are replaced (Core\StarterPages)
            'starters' => ['replaced' => [], 'kept' => [], 'renamed' => []],
            'result' => ['pages' => 0, 'articles' => 0, 'images' => 0, 'redirects' => 0, 'skipped' => 0, 'failed' => 0, 'parents' => 0, 'files' => 0],
            'kept' => [], 'attention' => [], // what arrived as elements, by type; what needs the owner: [kind, old path, host] (Core\WebImportWidgets)
            'paths' => [], // old path => id of the page this run made for it (the parent of a nested address)
            'moved' => [], 'parents' => [], // the redirects written, and the empty parent pages made for nested addresses (listed in the result, at most MAX_LISTED each)
            'errors' => [], 'created' => date('Y-m-d H:i:s'),
        ];
    }

    /** Whether the address can be imported at all: http(s), a domain, no user name. */
    public static function validUrl(string $url): bool
    {
        $c = parse_url(trim($url));

        return is_array($c) && in_array(strtolower((string) ($c['scheme'] ?? '')), ['http', 'https'], true) && ($c['host'] ?? '') !== '' && !isset($c['username']) && !isset($c['pass']);
    }

    /**
     * What each page to be imported is going to be called, for the preview of the starter pages: [old address, slug, language column].
     *
     * @param array<string, mixed> $state
     * @return list<array{old: string, slug: string, language: string}>
     */
    public static function targets(array $state, Settings $settings): array
    {
        $language = Language::column($settings, (string) $state['options']['language']);
        $asNews = $state['options']['news'] && Extensions::isEnabled($settings, 'news');
        $targets = [];
        foreach (array_keys($state['urls']) as $url) {
            $path = self::path($url);
            if ($asNews && preg_match(self::ARTICLE, (string) parse_url($url, PHP_URL_PATH)) === 1) {
                continue;
            }
            $slug = self::plannedSlug($path, '');
            $targets[] = ['old' => '/' . $path, 'slug' => $slug, 'language' => $language];
            // the parents of a nested address are made too – a starter page at one of them (Services) is met as well
            $parts = explode('/', $slug);
            while (count($parts) > 1) {
                array_pop($parts);
                $targets[] = ['old' => '/' . implode('/', $parts), 'slug' => implode('/', $parts), 'language' => $language];
            }
        }

        return $targets;
    }

    /**
     * The starter pages of this site that the pages to be imported would meet (action replace, keep, edited or home).
     *
     * @param array<string, mixed> $state
     * @return list<array{old: string, page_id: int, public_id: string, title: string, address: string, published: bool, action: string}>
     */
    public static function starterChoices(Db $db, Settings $settings, array $state): array
    {
        return StarterPages::choices($db, $settings, self::targets($state, $settings), $state['options']['keep_starters']);
    }

    /**
     * The preview is confirmed: the import starts.
     *
     * @param array<string, mixed> $state
     * @param list<int>|null $keepStarters ids of the starter pages that stay; null = untouched ones are replaced
     */
    public static function confirm(array &$state, ?array $keepStarters): void
    {
        $state['options']['keep_starters'] = $keepStarters === null ? null : array_values(array_map('intval', $keepStarters));
        // shallow addresses first: the parent of a nested address is a real page of the old site when the sitemap has it
        uksort($state['urls'], fn (string $a, string $b): int => substr_count(self::path($a), '/') <=> substr_count(self::path($b), '/'));
        $state['phase'] = 'import';
        $state['position'] = 0;
    }

    /** @return array<string, mixed>|null */
    public static function load(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id) || !is_file(self::file($id))) {
            return null;
        }
        $state = json_decode((string) file_get_contents(self::file($id)), true);

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    public static function save(array $state): void
    {
        file_put_contents(self::file((string) $state['id']), (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{16}$/', $id)) {
            @unlink(self::file($id));
        }
    }

    private static function file(string $id): string
    {
        return WpFile::folder() . '/web-' . $id . '.json';
    }

    /* ---------- one batch ---------- */

    /** @param array<string, mixed> $state */
    public function step(array &$state): void
    {
        $this->end = microtime(true) + self::SECONDS;
        match ($state['phase']) {
            'finding' => $this->discover($state),
            'import' => $this->import($state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function discover(array &$state): void
    {
        // first the sitemaps; a site without them is crawled from the home page
        if (!$state['maps_done']) {
            if ($state['maps'] === []) {
                $robots = $this->fetch($state['web'] . '/robots.txt');
                preg_match_all('/^\s*sitemap:\s*(\S+)/mi', (string) $robots, $m);
                $state['maps'] = array_values(array_unique([...$m[1], $state['web'] . '/sitemap.xml', $state['web'] . '/sitemap_index.xml', $state['web'] . '/wp-sitemap.xml']));
                $state['maps_read'] = [];
            }
            while ($state['maps'] !== [] && microtime(true) < $this->end && count($state['maps_read']) < self::MAX_SITEMAPS) {
                $map = array_shift($state['maps']);
                if (in_array($map, $state['maps_read'], true) || !$this->downloader->isAllowedUrl($map)) {
                    continue;
                }
                $state['maps_read'][] = $map;
                [$pages, $maps] = self::sitemap((string) $this->fetch($map));
                foreach ($maps as $child) {
                    $state['maps'][] = $child;
                }
                foreach ($pages as $url) {
                    $this->add($state, $url);
                }
            }
            if ($state['maps'] === [] || count($state['maps_read']) >= self::MAX_SITEMAPS) {
                $state['maps_done'] = true;
                if ($state['urls'] !== []) {
                    $state['queue'] = []; // the sitemap is enough
                }
                $this->add($state, $state['web'] . '/');
            }
        }
        // crawling along the site's own links (also adds pages the sitemap forgot about, when there is none)
        while ($state['queue'] !== [] && microtime(true) < $this->end && count($state['urls']) < self::MAX_PAGES) {
            $url = array_shift($state['queue']);
            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }
            foreach (self::links($html, $url) as $link) {
                if ($this->add($state, $link)) {
                    $state['queue'][] = $link;
                }
            }
        }
        if ($state['maps_done'] && ($state['queue'] === [] || count($state['urls']) >= self::MAX_PAGES)) {
            $state['phase'] = 'preview';
            $state['queue'] = [];
        }
    }

    /** @param array<string, mixed> $state */
    private function add(array &$state, string $url): bool
    {
        $url = self::normalize($url);
        if ($url === '' || isset($state['urls'][$url]) || count($state['urls']) >= self::MAX_PAGES || !$this->downloader->isAllowedUrl($url)
            || ImageDownloader::domainFromUrl($url) !== $state['domain'] || preg_match(self::SKIP, (string) parse_url($url, PHP_URL_PATH))) {
            return false;
        }
        $state['urls'][$url] = 1;

        return true;
    }

    /** @param array<string, mixed> $state */
    private function import(array &$state): void
    {
        $urls = array_keys($state['urls']);
        while ($state['position'] < count($urls) && microtime(true) < $this->end) {
            $url = $urls[$state['position']];
            try {
                $this->importPage($url, $state);
            } catch (\RuntimeException $e) {
                $state['result']['failed']++;
                $state['errors'] = array_slice([...$state['errors'], mb_substr(self::path($url) ?: '/', 0, 120) . ' – ' . t($e->getMessage())], -15);
            }
            $state['position']++;
        }
        if ($state['position'] >= count($urls)) {
            $state['phase'] = 'done';
        }
    }

    /** @param array<string, mixed> $state */
    private function importPage(string $url, array &$state): void
    {
        $source = 'web:' . mb_substr((string) $state['domain'], 0, 36);
        $key = sha1($url);
        if ($this->db->value('SELECT local_id FROM {import_map} WHERE source = ? AND type IN (\'page\', \'news\') AND source_id = ?', [$source, $key]) !== null) {
            $state['result']['skipped']++;

            return;
        }
        $html = $this->fetch($url);
        if ($html === null) {
            throw new \RuntimeException('The page could not be downloaded.');
        }
        $this->importHtml($url, $html, $state);
    }

    /**
     * Turns one downloaded page into a hidden page or news item (the part of importPage that needs no network).
     *
     * @param array<string, mixed> $state
     */
    public function importHtml(string $url, string $html, array &$state): void
    {
        $source = 'web:' . mb_substr((string) $state['domain'], 0, 36);
        $key = sha1($url);
        $page = self::extract($html, $url);
        if (trim(strip_tags($page['content'], '<img><talea-widget>')) === '') {
            throw new \RuntimeException('The page has no content to import.');
        }
        $language = Language::column($this->settings, (string) $state['options']['language']);
        $old = self::path($url);
        // widgets: placeholders for what cannot be brought over, images of the answers and panels in Media
        $page['widgets'] = WebImportWidgets::prepare($page['widgets'], $page['content'], $language !== '' ? $language : Language::defaults($this->settings), Extensions::isEnabled($this->settings, 'enquiries'), '/' . $old, $state);
        $seo = ['seo_title' => $page['seo_title'] !== $page['title'] ? $page['seo_title'] : '', 'image' => ''];
        if ($state['options']['images']) {
            $files = new WebImportFiles($this->db, $this->downloader, $this->author, $this->base);
            $page['content'] = $files->bring($this->images($page['content'], $state), $state);
            foreach ($page['widgets'] as $i => $widget) {
                $page['widgets'][$i]['html'] = $widget['html'] === '' ? '' : $files->bring($this->images($widget['html'], $state), $state);
            }
            // the picture the old page showed in a share (og:image) is the page's own share image
            $shared = $page['share_image'] !== '' ? $this->image($page['share_image'], $page['title'], $state) : null;
            $seo['image'] = $shared === null ? '' : (string) $shared['image_path'];
        }
        $page['seo'] = $seo;
        if ($seo['seo_title'] !== '' || $seo['image'] !== '') {
            $state['kept']['seo'] = (int) ($state['kept']['seo'] ?? 0) + 1;
        }
        $page['content'] = self::safeContent($page['content']); // sanitized once more as the very last step before it is stored
        $page['text'] = self::safeContent(WebImportWidgets::text($page['content'], $page['widgets']));
        $article = $state['options']['news'] && $page['article'] && Extensions::isEnabled($this->settings, 'news');
        if ($article) {
            $idc = $this->createArticle($page, $language);
            $this->map($source, 'news', $key, $idc);
            $state['result']['articles']++;
            $new = ($language !== '' ? $language . '/' : '') . 'news/' . (string) $this->db->value('SELECT slug FROM {news} WHERE news_id = ?', [$idc]);
        } else {
            $planned = self::plannedSlug($old, $page['title']);
            $parent = $this->parentOf($old, $language, $state);
            $wanted = self::addressBelow($planned, $parent);
            $held = StarterPages::makeRoom($this->db, $this->settings, $wanted, $language, $state['options']['keep_starters'] ?? null, $state['starters'], '/' . $old);
            $ids = $this->createPage($page, $wanted, $language, $url, $parent, $parent === null, $state, '/' . $old);
            $this->map($source, 'page', $key, $ids);
            $state['paths'][implode('/', self::segments($old))] = $ids;
            $state['result']['pages']++;
            $final = (string) $this->db->value('SELECT slug FROM {pages} WHERE page_id = ?', [$ids]);
            if ($held && $final !== $wanted && count($state['starters']['renamed']) < WpImport::MAX_LISTED) {
                $state['starters']['renamed'][] = ['old' => '/' . $old, 'new' => '/' . $final];
            }
            $new = ($language !== '' ? $language . '/' : '') . $final;
        }
        if ($state['options']['redirects'] && $old !== '' && $old !== $new && Extensions::isEnabled($this->settings, 'redirects')) {
            if (Redirects::addImported($this->db, $old, $new)) {
                $state['result']['redirects']++;
                if (count($state['moved'] ?? []) < WpImport::MAX_LISTED) {
                    $state['moved'][] = ['from' => '/' . $old, 'to' => '/' . $new];
                }
            }
        }
    }

    /**
     * The parts of an old path that make its address here: a leading language code of the old site (/de/…) is not one.
     *
     * @return list<string>
     */
    private static function segments(string $oldPath): array
    {
        $segments = $oldPath === '' ? [] : explode('/', $oldPath);
        if ($segments !== [] && isset(Language::AVAILABLE[$segments[0]]) && preg_match('/^' . Language::TAG . '$/D', $segments[0]) === 1) {
            array_shift($segments);
        }

        return $segments;
    }

    /**
     * The slug an old address would get here: the whole path, so /services/heating/boilers stays below its parents (an extension of
     * the last part is cut off); the title when the last part has nothing to make a slug of; an empty path is the home page.
     */
    public static function plannedSlug(string $oldPath, string $title): string
    {
        $segments = self::segments($oldPath);
        $last = count($segments) - 1;
        $slugs = [];
        foreach ($segments as $i => $segment) {
            $slug = slugify($i === $last ? (string) preg_replace('/\.(html?|php|aspx?)$/i', '', $segment) : $segment, 110);
            if ($slug === 'n-a' && $i === $last && $title !== '') {
                $slug = slugify($title, 110);
            }
            $slugs[] = $slug;
        }

        return $slugs === [] ? 'home' : mb_substr(implode('/', $slugs), 0, 118);
    }

    /**
     * The address of a page below its parent: the parent's address and the last part of its own.
     *
     * @param array{slug: string}|null $parent
     */
    private static function addressBelow(string $planned, ?array $parent): string
    {
        return $parent === null ? $planned : mb_substr($parent['slug'] . '/' . (string) preg_replace('#^.*/#', '', $planned), 0, 120);
    }

    /**
     * The page a nested address lives below. This run's own page for that path, or a page of an earlier run; otherwise an empty
     * hidden placeholder (its title from the address, no content), made on the spot with its own parents, so the address
     * has a place in the tree – a page slug is the whole path (Admin\Modules\Pages::placement).
     *
     * @param array<string, mixed> $state
     * @return array{page_id: int, slug: string}|null
     */
    private function parentOf(string $oldPath, string $language, array &$state): ?array
    {
        $segments = self::segments($oldPath);
        array_pop($segments);
        if ($segments === []) {
            return null;
        }
        $path = implode('/', $segments);
        $known = fn (int $id): ?array => $id > 0 ? $this->db->one('SELECT page_id, slug FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$id]) : null;
        if (($page = $known((int) ($state['paths'][$path] ?? 0))) !== null) {
            return $page;
        }
        $source = 'web:' . mb_substr((string) $state['domain'], 0, 36);
        foreach ([$state['web'] . '/' . $path . '/', $state['web'] . '/' . $path] as $address) {
            $mapped = $this->db->value("SELECT local_id FROM {import_map} WHERE source = ? AND type IN ('page', 'page_parent') AND source_id = ?", [$source, sha1($address)]);
            if ($mapped !== null && ($page = $known((int) $mapped)) !== null) {
                return $page;
            }
        }
        $grandparent = $this->parentOf($path, $language, $state);
        $planned = self::plannedSlug($path, '');
        $slug = $this->freeAddress(self::addressBelow($planned, $grandparent), $language, $grandparent === null, $state, '/' . $path);
        $id = $this->db->insert('pages', [
            'slug' => $slug, 'title' => mb_substr(mb_convert_case(str_replace(['-', '_'], ' ', rawurldecode((string) end($segments))), MB_CASE_TITLE), 0, 200), 'text' => '', 'build' => null,
            'visible' => 0, 'in_menu' => 0, 'parent_id' => $grandparent['page_id'] ?? null, 'language' => $language,
            'import_source' => mb_substr($state['web'] . '/' . $path . '/', 0, 500), 'layout_pending' => 1, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->map($source, 'page_parent', sha1($state['web'] . '/' . $path . '/'), $id);
        $state['paths'][$path] = $id;
        $state['result']['parents'] = (int) ($state['result']['parents'] ?? 0) + 1;
        if (count($state['parents']) < WpImport::MAX_LISTED) {
            $state['parents'][] = ['old' => '/' . $path, 'new' => '/' . $slug];
        }

        return ['page_id' => $id, 'slug' => $slug];
    }

    /** Is the address no longer free for a new page: taken by a page or a short link in the language version, or – at the top level – reserved by the system? */
    private function addressTaken(string $candidate, string $language, bool $topLevel): bool
    {
        return ($topLevel && (in_array($candidate, \Talea\Front\Kernel::reservedSlugs(), true) || isset(Language::AVAILABLE[$candidate])))
            || Slug::taken($this->db, 'pages', $candidate, $language);
    }

    /**
     * A free address for a new page: the wanted one unless a starter page of the installation that stays, a page, a short link or – at the top
     * level – a reserved address already has it (the first free number then). Untouched starter pages at the address go to the trash first.
     *
     * @param array<string, mixed> $state
     */
    private function freeAddress(string $wanted, string $language, bool $topLevel, array &$state, string $old): string
    {
        StarterPages::makeRoom($this->db, $this->settings, $wanted, $language, $state['options']['keep_starters'] ?? null, $state['starters'], $old);

        return WpImport::availableSlug(
            $wanted,
            fn (string $candidate): bool => $this->addressTaken($candidate, $language, $topLevel),
        );
    }

    /**
     * @param array{title: string, description: string, content: string, date: string, article: bool} $page
     * @param array{page_id: int, slug: string}|null $parent
     */
    private function createPage(array $page, string $wanted, string $language, string $url, ?array $parent, bool $topLevel, array &$state, string $old): int
    {
        $seo = WpImport::availableSlug(
            $wanted,
            fn (string $candidate): bool => $this->addressTaken($candidate, $language, $topLevel),
        );
        $build = $this->build($page['title'], $page['content'], $page['widgets'], $state, $old);

        return $this->db->insert('pages', [
            'slug' => $seo, 'title' => mb_substr($page['title'], 0, 200), 'text' => $page['text'], 'build' => $build,
            'description' => mb_substr($page['description'], 0, 300), 'seo_title' => mb_substr($page['seo']['seo_title'], 0, 200), 'image' => $page['seo']['image'],
            'visible' => 0, // hidden until the administrator checks it – nothing changes for visitors
            'in_menu' => 0, 'updated_at' => date('Y-m-d H:i:s'), 'language' => $language, 'parent_id' => $parent['page_id'] ?? null,
            'import_source' => mb_substr($url, 0, 500), 'layout_pending' => 1,
        ]);
    }

    /** @param array{title: string, description: string, content: string, date: string, article: bool} $page */
    private function createArticle(array $page, string $language): int
    {
        $category = (int) $this->db->value('SELECT category_id FROM {categories} WHERE language = ? ORDER BY category_id LIMIT 1', [$language]);
        if ($category === 0) {
            $name = Language::runWith($language !== '' ? $language : Language::defaults($this->settings), fn (): string => t('News'));
            $category = $this->db->insert('categories', ['name' => $name, 'slug' => slugify($name), 'description' => '', 'language' => $language]);
        }
        $seo = WpImport::availableSlug(slugify($page['title'], 150), fn (string $url): bool => $this->db->value('SELECT news_id FROM {news} WHERE slug = ?', [$url]) !== null);
        $now = date('Y-m-d H:i:s');
        $idc = $this->db->insert('news', [
            'slug' => $seo, 'title' => mb_substr($page['title'], 0, 255), 'intro' => $page['description'] !== '' ? '<p>' . e($page['description']) . '</p>' : '',
            'seo_title' => mb_substr($page['seo']['seo_title'], 0, 255), 'image' => str_contains($page['text'], $page['seo']['image']) ? '' : $page['seo']['image'],
            'text' => $page['text'], 'category_id' => $category, 'language' => $language, 'author_id' => $this->author,
            'published_at' => $page['date'] !== '' ? $page['date'] : $now, 'visible' => 0, 'edited_at' => $now, 'announced_at' => $now, // never announced (webhook, IndexNow)
        ]);
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', '', $page['text']);

        return $idc;
    }

    /** The page's HTML as a build, the same way as pages from WordPress: a heading and the content in a narrow section. */
    private function build(string $title, string $html, array $widgets, array &$state, string $old): ?string
    {
        // the non-administrator converter: whatever site is imported never decides what goes into Custom HTML
        $conversion = \Talea\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false, $widgets);
        $build = \Talea\Builder\HtmlConverter::withoutClasses($conversion['build'], array_column($this->db->all('SELECT name FROM {classes}'), 'name'));
        foreach ($build['children'] as &$section) {
            if ($section['type'] === 'section' && !isset($section['anchor'])) {
                $section['content']['width'] = 'narrow';
            }
        }
        unset($section);
        [$clean] = \Talea\Builder\Build::sanitize($build, false);
        ['kept' => $kept, 'lostMaps' => $lostMaps] = WebImportWidgets::kept($clean, $widgets);
        foreach ($kept as $type => $count) {
            $state['kept'][$type] = (int) ($state['kept'][$type] ?? 0) + $count;
        }
        for ($i = 0; $i < $lostMaps && count($state['attention']) < 100; $i++) {
            $state['attention'][] = ['kind' => 'map', 'path' => $old, 'host' => ''];
        }

        return $clean['children'] === [] ? null : \Talea\Builder\Build::toJson($clean);
    }

    /**
     * Downloads the images of a page into Media and points the page at them; an image that cannot be downloaded is
     * left out (a page must not keep loading images from the old site).
     *
     * @param array<string, mixed> $state
     */
    private function images(string $html, array &$state): string
    {
        $count = 0;

        // on the DOM, not with a regular expression over the markup: an attribute's text must never become a tag
        return Html::rewriteImages($html, function (string $url, string $alt) use (&$count, &$state): array|false {
            if (++$count > self::IMAGES_PER_PAGE || $url === '') {
                return false;
            }
            $image = $this->image($url, $alt, $state);

            return $image === null ? false : ['src' => $this->base . '/' . $image['image_path'], 'alt' => $alt, 'width' => (int) $image['image_width'], 'height' => (int) $image['image_height']];
        });
    }

    /**
     * Safe HTML without the old site's classes, ids and responsive image sets (they mean nothing here and could collide with
     * Talea's). Done on the DOM, never with a regular expression over the sanitized markup.
     */
    public static function safeContent(string $html): string
    {
        return Html::transform(Html::safe($html), function (\Dom\HTMLElement $body): void {
            // a <picture> keeps just its <img>: the image goes to Media, which makes its own sizes
            foreach (iterator_to_array($body->querySelectorAll('picture')) as $picture) {
                foreach (iterator_to_array($picture->querySelectorAll('source')) as $source) {
                    $source->remove();
                }
                $picture->replaceWith(...iterator_to_array($picture->childNodes));
            }
            foreach (iterator_to_array($body->querySelectorAll('[class], [id], [srcset], [sizes]')) as $element) {
                foreach (['class', 'id', 'srcset', 'sizes'] as $name) {
                    $element->removeAttribute($name);
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null a tl_media row
     */
    private function image(string $url, string $alt, array &$state): ?array
    {
        $source = 'web:' . mb_substr((string) $state['domain'], 0, 36);
        $key = sha1($url);
        $mediaId = $this->db->value("SELECT local_id FROM {import_map} WHERE source = ? AND type = 'image' AND source_id = ?", [$source, $key]);
        if ($mediaId !== null) {
            return (int) $mediaId === 0 ? null : $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [(int) $mediaId]);
        }
        $temporary = WpFile::folder() . '/web-image-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, $this->downloader->download($url));
            $saved = Images::saveFile($temporary, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image.jpg');
            $saved['name'] = mb_substr($alt !== '' ? $alt : $saved['name'], 0, 150);
            $saved['media_id'] = $this->db->insert('media', $saved + ['owner_id' => $this->author, 'created_at' => date('Y-m-d H:i:s')]);
            $this->map($source, 'image', $key, (int) $saved['media_id']);
            $state['result']['images']++;

            return $saved;
        } catch (\RuntimeException) {
            $this->map($source, 'image', $key, 0);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    private function map(string $source, string $type, string $key, int $id): void
    {
        $this->db->upsert('import_map', ['source' => $source, 'type' => $type, 'source_id' => $key, 'local_id' => $id], ['source', 'type', 'source_id']);
    }

    private function fetch(string $url): ?string
    {
        try {
            $data = $this->downloader->download($url, false);
        } catch (\RuntimeException) {
            return null;
        }

        return $data !== '' && strlen($data) < 5_000_000 ? $data : null;
    }

    /* ---------- pure helpers (unit-tested) ---------- */

    /**
     * Page addresses and further sitemaps from a sitemap or a sitemap index.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function sitemap(string $xml): array
    {
        if ($xml === '' || !str_contains($xml, '<')) {
            return [[], []];
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return [[], []];
        }
        $pages = [];
        $maps = [];
        foreach ($doc->children() as $child) {
            $loc = trim((string) ($child->loc ?? $child->children('http://www.sitemaps.org/schemas/sitemap/0.9')->loc ?? ''));
            if ($loc === '') {
                continue;
            }
            $child->getName() === 'sitemap' ? $maps[] = $loc : $pages[] = $loc;
        }

        return [$pages, $maps];
    }

    /**
     * Links of a page to other pages, as absolute addresses without the fragment.
     *
     * @return list<string>
     */
    public static function links(string $html, string $base): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"#][^"]*)"/i', $html, $m);
        $links = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^(mailto|tel|javascript|data):#i', $href)) {
                continue;
            }
            $links[] = self::absolute($href, $base);
        }

        return array_values(array_unique(array_filter($links)));
    }

    /**
     * An address without the fragment, tracking parameters and a trailing index file. Raw UTF-8 (an emoji in a sitemap) comes
     * back percent-encoded, so parse_url() cannot damage it and the same page written either way is one address.
     */
    public static function normalize(string $url): string
    {
        $c = parse_url(WpImport::encodeNonAscii($url));
        if (!is_array($c) || !isset($c['scheme'], $c['host']) || !in_array(strtolower($c['scheme']), ['http', 'https'], true)) {
            return '';
        }
        $c['host'] = rawurldecode($c['host']); // an internationalised domain stays as it was written
        $path = (string) preg_replace('#/index\.(html?|php)$#i', '/', $c['path'] ?? '/');
        $query = '';
        if (isset($c['query'])) {
            parse_str($c['query'], $params);
            $params = array_filter($params, fn (string $k): bool => !preg_match('/^(utm_|fbclid|gclid|ref$|lang$)/i', $k), ARRAY_FILTER_USE_KEY);
            $query = $params === [] ? '' : '?' . http_build_query($params);
        }

        return strtolower($c['scheme']) . '://' . strtolower($c['host']) . (isset($c['port']) ? ':' . $c['port'] : '') . ($path === '' ? '/' : $path) . $query;
    }

    /** The path of an address without slashes at the ends: https://a.cz/o-nas/ → o-nas */
    public static function path(string $url): string
    {
        return WpImport::oldPath($url);
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return '';
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin . $dir . $href;
    }

    /**
     * The content of a page: title, description, publication date, whether it is an article, and the main content as
     * clean HTML with absolute image addresses.
     *
     * @return array{title: string, description: string, content: string, date: string, article: bool, widgets: list<array{kind: string, html: string, host: string}>, seo_title: string, share_image: string}
     */
    public static function extract(string $html, string $url): array
    {
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $meta = function (string $selector) use ($doc): string {
            return trim((string) $doc->querySelector($selector)?->getAttribute('content'));
        };
        $h1 = $doc->querySelector('h1');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $h1?->textContent));
        if ($title === '') {
            $title = $meta('meta[property="og:title"]') ?: trim((string) $doc->querySelector('title')?->textContent);
            $title = trim((string) preg_split('/\s+[|–—-]\s+/u', $title)[0]);
        }
        $description = $meta('meta[name="description"]') ?: $meta('meta[property="og:description"]');
        $date = $meta('meta[property="article:published_time"]') ?: trim((string) $doc->querySelector('article time[datetime], time[datetime]')?->getAttribute('datetime'));
        $timestamp = $date !== '' ? strtotime($date) : false;

        $root = null;
        foreach (self::CONTENT as $selector) {
            $candidate = $doc->querySelector($selector);
            if ($candidate !== null && mb_strlen(trim($candidate->textContent)) > 80) {
                $root = $candidate;
                break;
            }
        }
        $root ??= $doc->body;
        if ($root === null) {
            return ['title' => $title, 'description' => $description, 'content' => '', 'date' => '', 'article' => false, 'widgets' => [], 'seo_title' => '', 'share_image' => ''];
        }
        // lazy-loaded images get their real address and links their path first: the widgets are cut out of the page after that
        foreach (iterator_to_array($root->querySelectorAll('img')) as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src') ?: '';
            if ($src === '' || str_starts_with($src, 'data:')) {
                $srcset = $img->getAttribute('data-srcset') ?: $img->getAttribute('srcset') ?: '';
                $src = $srcset !== '' ? trim((string) preg_replace('/\s+\S+$/', '', trim((string) explode(',', $srcset)[array_key_last(explode(',', $srcset))]))) : '';
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                $img->remove();
                continue;
            }
            $img->setAttribute('src', self::absolute($src, $url));
        }
        foreach (iterator_to_array($root->querySelectorAll('a[href]')) as $a) {
            $href = $a->getAttribute('href') ?? '';
            $absolute = self::absolute($href, $url);
            // links within the old site become paths; its pages are redirected to the new addresses after the import
            if ($absolute !== '' && ImageDownloader::domainFromUrl($absolute) === ImageDownloader::domainFromUrl($url)) {
                $a->setAttribute('href', '/' . self::path($absolute));
            }
        }
        // an element inside one removed earlier is already gone with it
        foreach (iterator_to_array($root->querySelectorAll(self::NOISE)) as $node) {
            if ($root->contains($node)) {
                $node->remove();
            }
        }
        foreach (iterator_to_array($root->querySelectorAll('[class], [id]')) as $node) {
            if ($root->contains($node) && preg_match(self::NOISE_NAMES, $node->getAttribute('class') . ' ' . $node->getAttribute('id'))) {
                $node->remove();
            }
        }
        // forms, questions and answers, tabs, videos, maps and counters leave a marker; then the rest of the noise (frames, forms, buttons, hidden panels) goes
        $widgets = \Talea\Builder\HtmlWidgets::extract($root);
        foreach (iterator_to_array($root->querySelectorAll(self::NOISE_AFTER_WIDGETS)) as $node) {
            if ($root->contains($node)) {
                $node->remove();
            }
        }
        $root->querySelector('h1')?->remove(); // the title becomes the page heading
        $content = trim((string) preg_replace('/\s+/u', ' ', $root->innerHTML));
        $article = ($timestamp !== false && $doc->querySelector('article') !== null) || preg_match(self::ARTICLE, (string) parse_url($url, PHP_URL_PATH)) === 1;

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 200) : t('(untitled)'),
            'description' => mb_substr(trim($description), 0, 300),
            'content' => self::safeContent($content),
            'date' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '',
            'article' => $article,
            'widgets' => $widgets,
            'seo_title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent)), 0, 200),
            'share_image' => ($share = $meta('meta[property="og:image"]') ?: $meta('meta[name="twitter:image"]')) !== '' ? self::absolute($share, $url) : '',
        ];
    }
}
