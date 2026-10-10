<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Pages;
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
    private const string NOISE = 'script, style, noscript, template, iframe, form, nav, header, footer, aside, svg, button, dialog, [role="navigation"], [role="banner"], [role="contentinfo"], [aria-hidden="true"]';

    /** Classes and ids of cookie bars, pop-ups, sharing and comment blocks. */
    private const string NOISE_NAMES = '/cookie|consent|gdpr|popup|modal|newsletter|share|sharing|social|breadcrumb|comment|related|sidebar|widget|skip-link|screen-reader/i';

    private float $end = 0.0;

    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $author, private readonly ImageDownloader $downloader)
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
                'redirects' => (bool) ($options['redirects'] ?? true), 'news' => (bool) ($options['news'] ?? true)],
            'result' => ['pages' => 0, 'articles' => 0, 'images' => 0, 'redirects' => 0, 'skipped' => 0, 'failed' => 0],
            'errors' => [], 'created' => date('Y-m-d H:i:s'),
        ];
    }

    /** Whether the address can be imported at all: http(s), a domain, no user name. */
    public static function validUrl(string $url): bool
    {
        $c = parse_url(trim($url));

        return is_array($c) && in_array(strtolower((string) ($c['scheme'] ?? '')), ['http', 'https'], true) && ($c['host'] ?? '') !== '' && !isset($c['username']) && !isset($c['pass']);
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
        $page = self::extract($html, $url);
        if (trim(strip_tags($page['content'], '<img>')) === '') {
            throw new \RuntimeException('The page has no content to import.');
        }
        if ($state['options']['images']) {
            $page['content'] = $this->images($page['content'], $state);
        }
        $page['content'] = self::safeContent($page['content']); // sanitized once more as the very last step before it is stored
        $language = Language::column($this->settings, (string) $state['options']['language']);
        $article = $state['options']['news'] && $page['article'] && Extensions::isEnabled($this->settings, 'news');
        $old = self::path($url);
        if ($article) {
            $idc = $this->createArticle($page, $language);
            $this->map($source, 'news', $key, $idc);
            $state['result']['articles']++;
            $new = ($language !== '' ? $language . '/' : '') . 'news/' . (string) $this->db->value('SELECT slug FROM {news} WHERE news_id = ?', [$idc]);
        } else {
            $ids = $this->createPage($page, $old, $language);
            $this->map($source, 'page', $key, $ids);
            $state['result']['pages']++;
            $new = ($language !== '' ? $language . '/' : '') . (string) $this->db->value('SELECT slug FROM {pages} WHERE page_id = ?', [$ids]);
        }
        if ($state['options']['redirects'] && $old !== '' && $old !== $new && Extensions::isEnabled($this->settings, 'redirects')) {
            Redirects::add($this->db, $old, $new);
            $state['result']['redirects']++;
        }
    }

    /** @param array{title: string, description: string, content: string, date: string, article: bool} $page */
    private function createPage(array $page, string $oldPath, string $language): int
    {
        $base = $oldPath !== '' ? basename($oldPath) : 'home';
        $seo = WpImport::availableSlug(
            slugify((string) preg_replace('/\.(html?|php|aspx?)$/i', '', $base) ?: $page['title'], 110),
            fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT page_id FROM {pages} WHERE slug = ?', [$url]) !== null,
        );
        $build = $this->build($page['title'], $page['content']);

        return $this->db->insert('pages', [
            'slug' => $seo, 'title' => mb_substr($page['title'], 0, 200), 'text' => $page['content'], 'build' => $build,
            'description' => mb_substr($page['description'], 0, 300),
            'visible' => 0, // hidden until the administrator checks it – nothing changes for visitors
            'in_menu' => 0, 'updated_at' => date('Y-m-d H:i:s'), 'language' => $language,
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
            'text' => $page['content'], 'category_id' => $category, 'language' => $language, 'author_id' => $this->author,
            'published_at' => $page['date'] !== '' ? $page['date'] : $now, 'visible' => 0, 'edited_at' => $now, 'announced_at' => $now, // never announced (webhook, IndexNow)
        ]);
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', '', $page['content']);

        return $idc;
    }

    /** The page's HTML as a build, the same way as pages from WordPress: a heading and the content in a narrow section. */
    private function build(string $title, string $html): ?string
    {
        // the non-administrator converter: whatever site is imported never decides what goes into Custom HTML
        $conversion = \Talea\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false);
        $build = \Talea\Builder\HtmlConverter::withoutClasses($conversion['build'], array_column($this->db->all('SELECT name FROM {classes}'), 'name'));
        foreach ($build['children'] as &$section) {
            if ($section['type'] === 'section' && !isset($section['anchor'])) {
                $section['content']['width'] = 'narrow';
            }
        }
        unset($section);
        [$clean] = \Talea\Builder\Build::sanitize($build, false);

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

            return $image === null ? false : ['src' => (string) $image['image_path'], 'alt' => $alt, 'width' => (int) $image['image_width'], 'height' => (int) $image['image_height']];
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

    /** An address without the fragment, tracking parameters and a trailing index file. */
    public static function normalize(string $url): string
    {
        $c = parse_url($url);
        if (!is_array($c) || !isset($c['scheme'], $c['host']) || !in_array(strtolower($c['scheme']), ['http', 'https'], true)) {
            return '';
        }
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
     * @return array{title: string, description: string, content: string, date: string, article: bool}
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
            return ['title' => $title, 'description' => $description, 'content' => '', 'date' => '', 'article' => false];
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
        // the title becomes the page heading; lazy-loaded images get their real address
        $root->querySelector('h1')?->remove();
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
        $content = trim((string) preg_replace('/\s+/u', ' ', $root->innerHTML));
        $article = ($timestamp !== false && $doc->querySelector('article') !== null) || preg_match(self::ARTICLE, (string) parse_url($url, PHP_URL_PATH)) === 1;

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 200) : t('(untitled)'),
            'description' => mb_substr(trim($description), 0, 300),
            'content' => self::safeContent($content),
            'date' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '',
            'article' => $article,
        ];
    }
}
