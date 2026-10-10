<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Redirects;
use Talea\Admin\Modules\Pages;

/**
 * Import from WordPress: pages, posts (→ news), categories, tags, redirects from old URLs and (separately) images.
 * Comments are not carried over – a company site does not have them.
 *
 * How it holds together:
 *  - The file is read as a stream (Core\WpFile) and the work is done IN BATCHES – at most BATCH posts or SECONDS seconds per request,
 *    so that the import survives the time limits of shared hosting. Where it stopped (which <item>) is kept in the state file
 *    storage/import/state-<hash>.json; the next request continues from there.
 *  - The table tl_import_map remembers which foreign record became which of ours. So the same file can be run again without
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
    public const array DEFAULT_OPTIONS = ['language' => '', 'drafts' => true, 'pages' => true, 'builder' => true, 'redirects' => true, 'category' => 0, 'collections' => true];

    /** Post types we can handle; the preview only lists the others (menus, custom types of add-ons…). */
    private const array TYPES = ['post', 'page', 'attachment'];

    private string $source = 'wp';

    /** @var array{name:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{name:string, predek:string}>, stitky:array<string,string>} */
    private array $header = ['name' => '', 'url' => '', 'authors' => [], 'categories' => [], 'tags' => []];

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
            'file' => $file, 'phase' => 'analysis', 'position' => 0, 'total' => 0, 'web' => ['name' => '', 'url' => ''],
            'overview' => ['articles' => [], 'pages' => [], 'categories' => 0, 'tags' => 0, 'authors' => 0, 'attachments' => 0, 'images' => 0, 'other' => [], 'shortcodes' => [], 'seo' => [], 'types' => []],
            'attachments' => [], 'options' => self::DEFAULT_OPTIONS, 'previews' => [],
            'result' => ['articles' => 0, 'pages' => 0, 'categories' => 0, 'redirects' => 0, 'skipped' => 0, 'seo' => 0, 'items' => 0],
            'images' => ['type' => 'news', 'id' => 0, 'done' => 0, 'total' => 0, 'downloaded' => 0, 'failed' => 0, 'errors' => []],
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
        return WpFile::folder() . '/state-' . substr(sha1($file), 0, 16) . '.json';
    }

    /* ---------- pass 1: preview (writes nothing to the database) ---------- */

    /**
     * Goes through the next part of the file and adds it to the overview. When it reaches the end, it switches the phase to "nahled".
     *
     * @param array<string, mixed> $state
     */
    public static function analyze(array &$state, float $seconds = self::SECONDS, ?string $path = null): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['file'])); // $path only for tests; otherwise always the file from storage/import
        if ($state['position'] === 0) {
            $h = $wp->header();
            $state['web'] = ['name' => $h['name'], 'url' => $h['url']];
            $state['overview']['categories'] = count($h['categories']);
            $state['overview']['tags'] = count($h['tags']);
            $state['overview']['authors'] = count($h['authors']);
        }
        $end = microtime(true) + $seconds;
        foreach ($wp->items((int) $state['position']) as $order => $p) {
            self::tally($state, $p);
            $state['position'] = $order + 1;
            if (microtime(true) > $end) {
                return;
            }
        }
        $state['total'] = $state['position'];
        $state['position'] = 0;
        $state['phase'] = 'preview';
        arsort($state['overview']['shortcodes']);
        $state['overview']['shortcodes'] = array_slice($state['overview']['shortcodes'], 0, 15, true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $p a post from WpFile::item()
     */
    private static function tally(array &$state, array $p): void
    {
        $overview = &$state['overview'];
        if ($p['type'] === 'attachment') {
            $overview['attachments']++;
            if ($p['attachment_url'] !== '') {
                $state['attachments'][(int) $p['id']] = $p['attachment_url']; // for [gallery ids] and the featured images of articles
            }
        } elseif ($p['type'] === 'post' || $p['type'] === 'page') {
            $destination = $p['type'] === 'post' ? 'articles' : 'pages';
            $overview[$destination][$p['status']] = ($overview[$destination][$p['status']] ?? 0) + 1;
            $overview['images'] += substr_count(strtolower($p['content']), '<img');
            foreach (WpContent::unknownShortcodes($p['content']) as $shortcode) {
                $overview['shortcodes'][$shortcode] = ($overview['shortcodes'][$shortcode] ?? 0) + 1;
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
        } elseif (WpTypes::isCustomType($p['type']) && self::articleStatus($p['status']) !== null) {
            // a custom post type becomes a collection (2.7): count its items, vote on the type of each field, remember the address
            $t = $overview['types'][$p['type']] ?? ['count' => 0, 'prefixes' => [], 'fields' => [], 'left_out' => [], 'content' => false, 'excerpt' => false];
            $t['count']++;
            $prefix = WpTypes::prefix($p['link']);
            if ($prefix !== '') {
                $t['prefixes'][$prefix] = ($t['prefixes'][$prefix] ?? 0) + 1;
            }
            $t['content'] = $t['content'] || trim(strip_tags($p['content'])) !== '' || str_contains($p['content'], '<img');
            $t['excerpt'] = $t['excerpt'] || trim($p['excerpt']) !== '';
            foreach (WpTypes::fields($p['fields'] ?? []) as $key => $value) {
                if (!isset($t['fields'][$key]) && count($t['fields']) >= WpTypes::MAX_FIELDS) {
                    continue;
                }
                $type = WpTypes::guessType($key, $value, $state['attachments']);
                if ($type === null) {
                    $t['left_out'][$key] = true;
                    continue;
                }
                $t['fields'][$key][$type] = ($t['fields'][$key][$type] ?? 0) + ($value === '' ? 0 : 1);
            }
            $overview['types'][$p['type']] = $t;
        } elseif (!in_array($p['type'], self::TYPES, true)) {
            $overview['other'][$p['type']] = ($overview['other'][$p['type']] ?? 0) + 1;
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
        foreach ([$p['date'] ?? '', $p['date_gmt'] ?? '', $p['pub_date'] ?? ''] as $i => $value) {
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

    /** Source label in tl_import_map: two different old sites have the same post numbers, which is why it contains the domain. */
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
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['file']));
        $this->header = $wp->header();
        $this->source = self::source((string) $state['web']['url']);
        $end = microtime(true) + self::SECONDS;
        $count = 0;
        foreach ($wp->items((int) $state['position']) as $order => $p) {
            $this->db->transaction(function () use ($p, &$state): void {
                match ($p['type']) {
                    'post' => $this->article($p, $state),
                    'page' => $state['options']['pages'] ? $this->page($p, $state) : null,
                    default => ($state['options']['collections'] ?? true) && isset($state['overview']['types'][$p['type']]) ? $this->collectionItem($p, $state) : null,
                };
            });
            $state['position'] = $order + 1;
            if ((++$count >= $batch || microtime(true) > $end) && $state['position'] < $state['total']) {
                return; // the rest next time; the import knows the number of posts (celkem) from the preview
            }
        }
        $state['phase'] = 'done';
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function article(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['status'], $p['password'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['options']['drafts'])) {
            return;
        }
        $idc = $this->convertedId('news', (string) $p['id'], 'news', 'news_id');
        if ($idc !== null) {
            $state['result']['skipped']++; // an already converted news item stays as it is – someone may have edited it in the meantime
        } else {
            $idc = $this->createArticle($p, $articleStatus, $state);
        }
        // for now we only note the featured image – it is downloaded in a separate step (also for a previously converted news item that does not have it yet)
        $preview = (string) ($state['attachments'][$p['preview']] ?? '');
        if ($preview !== '' && (string) $this->db->value('SELECT image FROM {news} WHERE news_id = ?', [$idc]) === '') {
            $state['previews'][$idc] = $preview;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array{visible:int} $articleStatus
     * @param array<string, mixed> $state
     */
    private function createArticle(array $p, array $articleStatus, array &$state): int
    {
        [$home, $text] = WpContent::introAndText($p['excerpt'], $p['content'], $state['attachments']);
        $colorScheme = $p['categories'] === [] ? $this->defaultCategory($state) : $this->category((string) array_key_first($p['categories']), (string) reset($p['categories']), $state);
        $language = (string) $this->db->value('SELECT language FROM {categories} WHERE category_id = ?', [$colorScheme]); // the news item takes over the category's language, as when saving in the admin
        $title = mb_substr($p['title'] !== '' ? $p['title'] : t('(untitled)'), 0, 255);
        $now = date('Y-m-d H:i:s');

        $seo = self::availableSlug(
            slugify(rawurldecode($p['url']) !== '' ? rawurldecode($p['url']) : $title, 150),
            fn (string $url): bool => $this->db->value('SELECT news_id FROM {news} WHERE slug = ?', [$url]) !== null,
        );
        $plugin = $this->seo($p, (string) (reset($p['categories']) ?: ''), 255, 320, $state);
        $idc = $this->db->insert('news', [
            'slug' => $seo, 'title' => $title, 'intro' => $home, 'text' => $text, 'category_id' => $colorScheme, 'language' => $language,
            'author_id' => $this->author,
            'published_at' => self::date($p),
            'visible' => $articleStatus['visible'],
            'seo_title' => $plugin['title'], 'seo_description' => $plugin['description'], 'noindex' => $plugin['noindex'],
            'edited_at' => $now,
            'announced_at' => $now, // an old news item is not announced (webhook, IndexNow)
        ]);
        foreach (array_slice($p['tags'], 0, 20, true) as $url => $name) {
            $this->tag($idc, (string) $url, $name !== '' ? $name : (string) ($this->header['tags'][$url] ?? $url));
        }
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', $home, $text);
        $this->writeMap('news', (string) $p['id'], $idc);
        $state['result']['articles']++;

        if ($state['options']['redirects']) {
            $state['result']['redirects'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . 'news/' . $seo);
        }

        return $idc;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function page(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['status'], $p['password'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['options']['drafts'])) {
            return;
        }
        if ($this->convertedId('page', (string) $p['id'], 'pages', 'page_id') !== null) {
            $state['result']['skipped']++;

            return;
        }
        $title = mb_substr($p['title'] !== '' ? $p['title'] : t('(untitled)'), 0, 200);
        $language = Language::column($this->settings, (string) $state['options']['language']);
        // a page has its slug directly under the site root, so it must not take a slug the system uses
        $seo = self::availableSlug(
            slugify(rawurldecode($p['url']) !== '' ? rawurldecode($p['url']) : $title, 110),
            fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT page_id FROM {pages} WHERE slug = ?', [$url]) !== null,
        );
        $text = WpContent::sanitize($p['content'], $state['attachments']);
        $plugin = $this->seo($p, '', 200, 300, $state);
        $pageId = $this->db->insert('pages', [
            'slug' => $seo, 'title' => $title, 'text' => $text,
            'build' => ($state['options']['builder'] ?? false) ? self::pageBuild($this->db, $title, $text) : null,
            'description' => $plugin['description'] !== '' ? $plugin['description'] : mb_substr(trim(html_entity_decode(strip_tags($p['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300),
            'seo_title' => $plugin['title'], 'noindex' => $plugin['noindex'],
            'visible' => $articleStatus['visible'],
            'in_menu' => 0, // dozens of old pages would flood the navigation; the administrator adds them to the menu themselves
            'updated_at' => date('Y-m-d H:i:s'), 'language' => $language,
        ]);
        $this->writeMap('page', (string) $p['id'], $pageId);
        $state['result']['pages']++;
        if ($state['options']['redirects']) {
            $state['result']['redirects'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $seo);
        }
    }

    /**
     * An item of a custom post type as a collection item (2.7). The collection is created with the first item: fields from the
     * preview's votes, the old address prefix as its address (so the items keep their addresses), item pages on.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function collectionItem(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['status'], $p['password'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['options']['drafts'])) {
            return;
        }
        if ($this->convertedId('item', (string) $p['id'], 'collection_items', 'item_id') !== null) {
            $state['result']['skipped']++;

            return;
        }
        $collection = $this->collectionFor((string) $p['type'], $state);
        $language = Language::column($this->settings, (string) $state['options']['language']);
        $input = [];
        foreach ($collection['map'] as $old => $new) {
            $value = (string) ($p['fields'][$old] ?? '');
            $input[$new] = match ($collection['types'][$new]) {
                'date' => WpTypes::date($value),
                'image' => ctype_digit(trim($value)) ? (string) ($state['attachments'][(int) $value] ?? '') : trim($value),
                'html' => WpContent::sanitize($value, $state['attachments']),
                default => $value,
            };
        }
        if (isset($collection['types']['content'])) {
            $input['content'] = WpContent::sanitize($p['content'], $state['attachments']);
        }
        if (isset($collection['types']['excerpt'])) {
            $input['excerpt'] = trim(html_entity_decode(strip_tags($p['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $data = \Talea\Builder\Collections::sanitizeData($collection['fields'], $input);
        $title = mb_substr($p['title'] !== '' ? $p['title'] : t('(untitled)'), 0, 200);
        $idk = (int) $collection['collection_id'];
        $seo = self::availableSlug(
            slugify(rawurldecode($p['url']) !== '' ? rawurldecode($p['url']) : $title, 150),
            fn (string $url): bool => $this->db->value('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ?', [$idk, $language, $url]) !== null,
        );
        $plugin = $this->seo($p, '', 200, 300, $state);
        $row = [
            'collection_id' => $idk, 'name' => $title, 'slug' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'seo_title' => $plugin['title'], 'description' => $plugin['description'], 'noindex' => $plugin['noindex'],
            'visible' => $articleStatus['visible'], 'language' => $language, 'created_at' => self::date($p), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $idp = $this->db->insert('collection_items', $row);
        $this->writeMap('item', (string) $p['id'], $idp);
        // an imported notice of an official notice board (2.11, Core\Notices) starts its audit trail
        $board = \Talea\Builder\Collections::byId($this->db, $idk);
        if ($board !== null && Notices::isNotices($board)) {
            Notices::log($this->db, $idp, 'created', Notices::changes($board, null, $row), 'import');
        }
        if ($p['preview'] > 0 && isset($state['attachments'][(int) $p['preview']])) {
            $state['previews']['p' . $idp] = $state['attachments'][(int) $p['preview']]; // the featured image as the item's share image
        }
        $state['result']['items']++;
        if ($state['options']['redirects']) {
            $state['result']['redirects'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $collection['slug'] . '/' . $seo);
        }
    }

    /**
     * The collection of a custom post type: created on first use, remembered in the state.
     *
     * @param array<string, mixed> $state
     * @return array{idk: int, seo_link: string, pole: list<array{key: string, label: string, type: string}>, mapa: array<string, string>, typy: array<string, string>}
     */
    private function collectionFor(string $type, array &$state): array
    {
        if (isset($state['collections'][$type])) {
            return $state['collections'][$type];
        }
        $t = $state['overview']['types'][$type];
        $definitions = [];
        $oldKeys = [];
        foreach ($t['fields'] as $old => $votes) {
            $definitions[] = ['label' => WpTypes::label((string) $old), 'type' => WpTypes::fieldType($votes)];
            $oldKeys[] = (string) $old;
        }
        if ($t['excerpt']) {
            $definitions[] = ['key' => 'excerpt', 'label' => t('Excerpt'), 'type' => 'lines'];
        }
        if ($t['content']) {
            $definitions[] = ['key' => 'content', 'label' => t('Content'), 'type' => 'html'];
        }
        $fields = \Talea\Builder\Collections::sanitizeFields($definitions);
        $map = [];
        foreach ($oldKeys as $i => $old) {
            if (isset($fields[$i])) {
                $map[$old] = $fields[$i]['key'];
            }
        }
        arsort($t['prefixes']);
        $wanted = (string) (array_key_first($t['prefixes']) ?? '') ?: slugify($type, 100);
        $earlier = $this->convertedId('collection', $type, 'collections', 'collection_id');
        $existing = $earlier !== null ? $this->db->one('SELECT collection_id, slug, fields FROM {collections} WHERE collection_id = ?', [$earlier]) : null;
        if ($existing !== null) {
            $idk = (int) $existing['collection_id']; // the same collection from an earlier run of this import
            $seo = (string) $existing['slug'];
            $fields = json_decode((string) $existing['fields'], true) ?: $fields;
        } else {
            $seo = self::availableSlug($wanted, fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT collection_id FROM {collections} WHERE slug = ?', [$url]) !== null || $this->db->value('SELECT page_id FROM {pages} WHERE slug = ?', [$url]) !== null);
            $idk = $this->db->insert('collections', ['name' => mb_substr(WpTypes::label($type), 0, 100), 'slug' => $seo, 'detail' => 1,
                'fields' => (string) json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->writeMap('collection', $type, $idk);
            $state['result']['collections'] = ($state['result']['collections'] ?? 0) + 1;
        }

        return $state['collections'][$type] = ['collection_id' => $idk, 'slug' => $seo, 'fields' => $fields, 'map' => $map,
            'types' => array_column($fields, 'type', 'key')];
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
        $excerpt = $plain($p['excerpt']);
        $context = [
            'title' => $p['title'], 'sitename' => $this->settings->get('site_name'), 'sitedesc' => $this->settings->get('site_description'),
            'excerpt' => mb_strimwidth($excerpt !== '' ? $excerpt : $plain($p['content']), 0, 160, '…'), 'category' => $category,
        ];
        $result = ['title' => WpSeo::title($raw['title'], $context, $titleLimit), 'description' => WpSeo::description($raw['description'], $context, $descriptionLimit), 'noindex' => (int) $raw['noindex']];
        if ($result['title'] !== '' || $result['description'] !== '' || $result['noindex'] === 1) {
            $state['result']['seo']++;
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
        $idt = $this->convertedId('category', $url, 'categories', 'category_id');
        if ($idt === null) {
            $description = $this->header['categories'][$url] ?? ['name' => $name, 'parent' => ''];
            $name = mb_substr($description['name'] !== '' ? $description['name'] : ($name !== '' ? $name : $url), 0, 100);
            $language = Language::column($this->settings, (string) $state['options']['language']);
            $seo = slugify(rawurldecode($url), 110);
            // the same slug, name and language = the same category that is already on the site; otherwise a new one with a free slug
            $idt = $this->db->value('SELECT category_id FROM {categories} WHERE slug = ? AND language = ? AND LOWER(name) = LOWER(?)', [$seo, $language, $name]);
            if ($idt === null) {
                $idt = $this->db->insert('categories', [
                    'name' => $name, 'description' => '', 'language' => $language,
                    'slug' => self::availableSlug($seo, fn (string $a): bool => $this->db->value('SELECT category_id FROM {categories} WHERE slug = ?', [$a]) !== null),
                ]);
                $state['result']['categories']++;
            }
            $this->writeMap('category', $url, (int) $idt);
        }

        return $this->categories[$url] = (int) $idt;
    }

    /**
     * Category for posts without a category: the one chosen in the preview, otherwise 'Uncategorized' is created.
     *
     * @param array<string, mixed> $state
     */
    private function defaultCategory(array &$state): int
    {
        $idt = (int) $state['options']['category'];
        if ($idt > 0 && $this->db->value('SELECT category_id FROM {categories} WHERE category_id = ?', [$idt]) !== null) {
            return $idt;
        }

        return $state['options']['category'] = $this->category('uncategorised', t('Uncategorized'), $state);
    }

    /** A tag is looked up by the slug made from its name and an unknown one is created – the same as when saving a news item in the admin. */
    private function tag(int $idc, string $wpSlug, string $name): void
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            return;
        }
        $seo = slugify($name, 90);
        $ids = $this->db->value('SELECT tag_id FROM {tags} WHERE slug = ?', [$seo]);
        $ids = $ids !== null ? (int) $ids : $this->db->insert('tags', ['name' => $name, 'slug' => $seo]);
        $this->db->insertIgnore('news_tags', ['news_id' => $idc, 'tag_id' => $ids]);
        $this->writeMap('tag', $wpSlug, $ids);
    }

    /**
     * Redirect from the old URL (both the pretty and the numeric /?p=123) to the new one. Redirects::add skips an identical URL by itself.
     *
     * @param array<string, mixed> $p
     */
    private function redirect(array $p, string $newVersion): int
    {
        $count = 0;
        foreach (array_unique([self::oldPath($p['link']), $p['id'] > 0 ? '?p=' . (int) $p['id'] : '']) as $old) {
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
        $this->source = self::source((string) $state['web']['url']);
        $this->db->run("DELETE FROM {import_map} WHERE source = ? AND type = 'image' AND local_id = 0", [$this->source]);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {import_map} WHERE source = ? AND type IN ('news', 'page', 'item')", [$this->source]);
        $state['images'] = ['type' => 'news', 'id' => 0, 'done' => 0, 'total' => $total, 'downloaded' => 0, 'failed' => 0, 'errors' => []];
        $state['phase'] = 'images';
    }

    /**
     * Downloads the next batch of images: the article's featured image and the images in the text that are on the old site's domain.
     * Works on already converted records (not on the file); position = the last finished article or page.
     *
     * @param array<string, mixed> $state
     */
    public function images(array &$state, ImageDownloader $downloader): void
    {
        $this->source = self::source((string) $state['web']['url']);
        $this->downloadsLeft = self::IMAGE_BATCH;
        $this->end = microtime(true) + self::SECONDS;
        while (true) {
            $id = $this->db->value('SELECT MIN(local_id) FROM {import_map} WHERE source = ? AND type = ? AND local_id > ?', [$this->source, $state['images']['type'], (int) $state['images']['id']]);
            if ($id === null && $state['images']['type'] !== 'item') {
                $state['images'] = ['type' => $state['images']['type'] === 'news' ? 'page' : 'item', 'id' => 0] + $state['images']; // pages after the articles, collection items last
                continue;
            }
            if ($id === null) {
                $state['phase'] = 'images_done';

                return;
            }
            if (!$this->recordImages((string) $state['images']['type'], (int) $id, $state, $downloader)) {
                return; // the batch ran out in the middle of a record – next time it continues with the same one
            }
            $state['images']['id'] = (int) $id;
            $state['images']['done']++;
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
        if ($type === 'item') {
            return $this->itemImages($id, $state, $downloader);
        }
        $record = $type === 'news'
            ? $this->db->one('SELECT news_id, title, intro, text, image FROM {news} WHERE news_id = ?', [$id])
            : $this->db->one("SELECT page_id, title, '' AS intro, text, '' AS image FROM {pages} WHERE page_id = ?", [$id]);
        if ($record === null) {
            return true; // someone deleted the record in the meantime
        }
        $complete = true;
        $newItems = ['intro' => (string) $record['intro'], 'text' => (string) $record['text'], 'image' => (string) $record['image']];
        foreach (['intro', 'text'] as $field) {
            $newItems[$field] = self::rewriteImages($newItems[$field], function (string $src, string $alt) use ($downloader, &$state, &$complete, $record): ?array {
                if (!$complete || !$downloader->isAllowedUrl($src)) {
                    return null; // foreign images (another domain) stay as they are – they are never downloaded
                }
                $image = $this->image($src, $alt !== '' ? $alt : (string) $record['title'], $state, $downloader);
                if ($image === false) {
                    $complete = false;
                }

                return is_array($image) ? self::mediaImage($this->base, $image, $alt) : null;
            });
        }
        $preview = (string) ($state['previews'][$id] ?? '');
        if ($type === 'news' && $complete && $preview !== '') {
            $image = $this->image($preview, (string) $record['title'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $newItems['image'] === '') {
                $newItems['image'] = (string) $image['image_path'];
            }
            if ($complete) {
                unset($state['previews'][$id]);
            }
        }
        if ($type === 'news' && $newItems !== ['intro' => $record['intro'], 'text' => $record['text'], 'image' => $record['image']]) {
            $this->db->update('news', $newItems, ['news_id' => $id]);
            Media::recordUsage($this->db, $id, $newItems['image'], $newItems['intro'], $newItems['text']);
        } elseif ($type === 'page' && $newItems['text'] !== $record['text']) {
            // the images are already in Media: the build of the imported page is converted again so that it refers to them
            $build = $this->db->value('SELECT build FROM {pages} WHERE page_id = ?', [$id]) !== null && $this->db->value('SELECT build_draft FROM {pages} WHERE page_id = ?', [$id]) === null
                ? ['build' => self::pageBuild($this->db, (string) $record['title'], $newItems['text'])] : [];
            $this->db->update('pages', ['text' => $newItems['text']] + $build, ['page_id' => $id]);
        }

        return $complete;
    }

    /**
     * Images of a collection item (2.7): image fields and images in its formatted fields from the old site go to Media, the
     * featured image of the post becomes the item's share image.
     *
     * @param array<string, mixed> $state
     * @return bool false = the batch budget ran out, the item is not complete yet
     */
    private function itemImages(int $id, array &$state, ImageDownloader $downloader): bool
    {
        $item = $this->db->one('SELECT p.item_id, p.name, p.data, p.image, k.fields FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.item_id = ?', [$id]);
        if ($item === null) {
            return true;
        }
        $data = json_decode((string) $item['data'], true) ?: [];
        $share = (string) $item['image'];
        $complete = true;
        foreach (json_decode((string) $item['fields'], true) ?: [] as $field) {
            $key = (string) $field['key'];
            $value = (string) ($data[$key] ?? '');
            if ($field['type'] === 'image' && $value !== '' && $complete && $downloader->isAllowedUrl($value)) {
                $image = $this->image($value, (string) $item['name'], $state, $downloader);
                $complete = $image !== false;
                $data[$key] = is_array($image) ? (string) $image['image_path'] : ($image === null ? '' : $value);
            } elseif ($field['type'] === 'html' && str_contains($value, '<img')) {
                $data[$key] = self::rewriteImages($value, function (string $src, string $alt) use ($downloader, &$state, &$complete, $item): ?array {
                    if (!$complete || !$downloader->isAllowedUrl($src)) {
                        return null;
                    }
                    $image = $this->image($src, $alt !== '' ? $alt : (string) $item['name'], $state, $downloader);
                    $complete = $image !== false;

                    return is_array($image) ? self::mediaImage($this->base, $image, $alt) : null;
                });
            }
        }
        $preview = (string) ($state['previews']['p' . $id] ?? '');
        if ($complete && $preview !== '') {
            $image = $this->image($preview, (string) $item['name'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $share === '') {
                $share = (string) $image['image_path'];
            }
            if ($complete) {
                unset($state['previews']['p' . $id]);
            }
        }
        $this->db->update('collection_items', ['data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'image' => $share], ['item_id' => $id]);

        return $complete;
    }

    /**
     * Points the images of imported HTML at Media on the DOM (Core\Html::rewriteImages – never a regular expression over the
     * markup) and, when anything changed, sanitizes the result once more as the very last step before it is stored.
     * Shared with the structured importers (Import\Batch).
     *
     * @param callable(string, string): (array<string, string|int>|false|null) $image gets src and alt, see Html::rewriteImages
     */
    public static function rewriteImages(string $html, callable $image): string
    {
        $rewritten = Html::rewriteImages($html, $image);

        return $rewritten === $html ? $html : WpContent::safeHtml($rewritten);
    }

    /**
     * The attributes of an imported image that is in Media now.
     *
     * @param array<string, mixed> $image a tl_media row
     * @return array<string, string|int>
     */
    public static function mediaImage(string $base, array $image, string $alt): array
    {
        return ['src' => $base . '/' . $image['image_path'], 'alt' => $alt, 'width' => (int) $image['image_width'], 'height' => (int) $image['image_height'],
            'loading' => 'lazy', 'data-id' => $image['public_id'] ?? (int) $image['media_id']];
    }

    /**
     * An imported page as a build (Builder\HtmlConverter): heading and content in a narrow section, Gutenberg blocks as elements,
     * WordPress classes without a style removed. Converted as for a non-administrator: imported content never becomes Custom HTML,
     * whoever runs the import. Shared with the structured importers (Import\Batch), so every imported page looks the same in the builder.
     */
    public static function pageBuild(Db $db, string $title, string $html): ?string
    {
        $conversion = \Talea\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false);
        $build = \Talea\Builder\HtmlConverter::withoutClasses($conversion['build'], array_column($db->all('SELECT name FROM {classes}'), 'name'));
        foreach ($build['children'] as &$section) {
            if ($section['type'] === 'section' && !isset($section['anchor'])) {
                $section['content']['width'] = 'narrow'; // page text reads better in a narrower column
            }
        }
        unset($section);
        [$clean] = \Talea\Builder\Build::sanitize($build, false);

        return $clean['children'] === [] ? null : \Talea\Builder\Build::toJson($clean);
    }

    /**
     * One image: from the map (already downloaded), or from the old site through Core\Images into Media.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null|false a tl_media row; null = cannot be downloaded; false = the batch has run out
     */
    private function image(string $url, string $name, array &$state, ImageDownloader $downloader): array|null|false
    {
        $original = self::withoutSize($url);
        $key = sha1($original);
        $mediaId = $this->db->value("SELECT local_id FROM {import_map} WHERE source = ? AND type = 'image' AND source_id = ?", [$this->source, $key]);
        $row = $mediaId === null ? null : $this->db->one('SELECT * FROM {media} WHERE media_id = ?', [(int) $mediaId]);
        if ($row !== null || ($mediaId !== null && (int) $mediaId === 0)) {
            return $row; // done earlier, or it already failed once (null)
        }
        if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
            return false;
        }
        $this->downloadsLeft--;
        $temporary = WpFile::folder() . '/image-' . bin2hex(random_bytes(6)) . '.tmp';
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
            $saved['name'] = mb_substr($name !== '' ? $name : $saved['name'], 0, 150);
            $saved['media_id'] = $this->db->insert('media', $saved + ['owner_id' => $this->author, 'created_at' => date('Y-m-d H:i:s')]);
            $this->writeMap('image', $key, (int) $saved['media_id']);
            $state['images']['downloaded']++;
            $saved['public_id'] = $this->db->publicId('media', (int) $saved['media_id']);

            return $saved;
        } catch (\RuntimeException $e) {
            $this->writeMap('image', $key, 0); // do not retry for every article that uses the image
            $state['images']['failed']++;
            $state['images']['errors'] = array_slice(array_merge($state['images']['errors'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /* ---------- map of foreign and our records ---------- */

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
        $this->db->upsert('import_map', ['source' => $this->source, 'type' => $type, 'source_id' => mb_substr($foreignId, 0, 190), 'local_id' => $ourId], ['source', 'type', 'source_id']);
    }
}
