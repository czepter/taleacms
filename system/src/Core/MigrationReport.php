<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;

/**
 * The migration parity report (2.7): before a moved site goes live, every address of the old site must still work on
 * the new one and nothing important may get lost on the way.
 *
 * How it works:
 *  - The old site's pages are found the same way as for the website import (Core\WebImport: sitemaps, else its links).
 *  - Each old address is looked up on this site without a request: a page, a news item or a collection item at the
 *    same path, or a redirect (one hop is fine, two are a warning). A page that exists but is not published yet is
 *    reported, because it will end in 404 for visitors.
 *  - The old page is downloaded once (Core\ImageDownloader: public addresses only, limits) and compared with the new
 *    one: a search engine description, a form, the number of images in the content.
 *  - At the end come the checks of the whole site from the site audit (Before handing over) and whether the Redirects
 *    extension is on – without it no redirect works.
 * The work runs in batches of SECONDS like the import; the state is a file in storage/import. Nothing is changed.
 */
final class MigrationReport
{
    private const float SECONDS = 15.0;

    /** Problems by code => severity (error = visitors or search engines lose something, warning = check it). */
    public const array PROBLEMS = [
        'missing' => 'error', 'form_missing' => 'error', 'hidden' => 'warning', 'chain' => 'warning',
        'no_description' => 'warning', 'fewer_images' => 'warning', 'not_read' => 'info', 'redirect_out' => 'info',
    ];

    private float $end = 0.0;

    public function __construct(private readonly App $app, private readonly ImageDownloader $downloader)
    {
    }

    /** @return array<string, mixed> */
    public static function newState(string $url): array
    {
        $discovery = WebImport::newState($url);

        return ['id' => $discovery['id'], 'web' => $discovery['web'], 'phase' => 'finding', 'finding' => $discovery,
            'urls' => [], 'position' => 0, 'rows' => [], 'created' => date('Y-m-d H:i:s')];
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

    /** @return list<array<string, mixed>> saved reports, newest first */
    public static function listAll(): array
    {
        $all = array_values(array_filter(array_map(fn (string $f): ?array => self::load(substr(basename($f, '.json'), 7)), glob(WpFile::folder() . '/parita-*.json') ?: [])));
        usort($all, fn (array $a, array $b): int => strcmp((string) $b['created'], (string) $a['created']));

        return $all;
    }

    private static function file(string $id): string
    {
        return WpFile::folder() . '/parita-' . $id . '.json';
    }

    /* ---------- one batch ---------- */

    /** @param array<string, mixed> $state */
    public function step(array &$state): void
    {
        $this->end = microtime(true) + self::SECONDS;
        if ($state['phase'] === 'finding') {
            $discovery = $state['finding'];
            (new WebImport($this->app->db(), $this->app->settings(), 0, $this->downloader))->step($discovery);
            $state['finding'] = $discovery;
            if ($discovery['phase'] !== 'finding') {
                $state['urls'] = array_keys($discovery['urls']);
                $state['finding'] = ['urls' => count($state['urls'])];
                $state['phase'] = 'check';
            }
        }
        while ($state['phase'] === 'check' && $state['position'] < count($state['urls']) && microtime(true) < $this->end) {
            $url = $state['urls'][$state['position']];
            $state['rows'][] = $this->check($url);
            $state['position']++;
        }
        if ($state['phase'] === 'check' && $state['position'] >= count($state['urls'])) {
            $state['phase'] = 'done';
            $state['completed'] = date('Y-m-d H:i:s');
        }
    }

    /**
     * One old address: where it leads on this site and what got lost.
     *
     * @return array{stara: string, nova: string, stav: string, problemy: list<string>, titulek_stary: string, titulek_novy: string}
     */
    private function check(string $url): array
    {
        $path = WebImport::path($url);
        $target = $this->resolve($path);
        $problems = [];
        $status = $target['status'];
        if (in_array($status, ['missing', 'hidden', 'chain', 'redirect_out'], true)) {
            $problems[] = $status;
        }
        $old = null;
        $html = $this->fetch($url);
        if ($html !== null) {
            $old = self::analyse($html, $url);
        } else {
            $problems[] = 'not_read';
        }
        if ($old !== null && $target['type'] !== '') {
            if ($old['description'] !== '' && $target['description'] === '') {
                $problems[] = 'no_description';
            }
            if ($old['form'] && !$target['form'] && $target['type'] !== 'news') {
                $problems[] = 'form_missing';
            }
            if ($old['images'] >= 3 && $target['images'] * 2 < $old['images']) {
                $problems[] = 'fewer_images';
            }
        }

        return ['old' => '/' . $path, 'new' => $target['url'], 'status' => $status, 'problems' => $problems,
            'old_title' => $old['title'] ?? '', 'new_title' => $target['title']];
    }

    /**
     * Where a path leads on this site: ok (published content), redirect (one hop to published content), chain (more hops),
     * redirect_out (to another site), hidden (content that is not published) or missing.
     *
     * @return array{status: string, url: string, type: string, title: string, description: string, form: bool, images: int}
     */
    public function resolve(string $path, int $hops = 0): array
    {
        $none = ['status' => 'missing', 'url' => '', 'type' => '', 'title' => '', 'description' => '', 'form' => false, 'images' => 0];
        $path = trim(rawurldecode($path), '/');
        $db = $this->app->db();
        $content = $this->content($path);
        if ($content !== null) {
            return $content + ['url' => '/' . $path, 'status' => $content['visible'] ? ($hops === 0 ? 'ok' : ($hops === 1 ? 'redirect' : 'chain')) : 'hidden'];
        }
        $to = $db->value('SELECT to_path FROM {redirects} WHERE from_path = ?', [$path]);
        if ($to === null || $hops >= 3) {
            return $none;
        }
        $to = (string) $to;
        if (preg_match('#^https?://#i', $to)) {
            $origin = $this->app->request->origin();
            if (!str_starts_with($to, $origin . '/') && $to !== $origin) {
                return ['status' => 'redirect_out', 'url' => $to] + $none;
            }
            $to = substr($to, strlen($origin));
        }
        $next = $this->resolve((string) parse_url($to, PHP_URL_PATH), $hops + 1);

        return $next['status'] === 'missing' ? ['url' => $to] + $next : $next;
    }

    /**
     * Published or hidden content at a path, without redirects.
     *
     * @return array{type: string, title: string, description: string, form: bool, images: int, visible: bool}|null
     */
    private function content(string $path): ?array
    {
        $db = $this->app->db();
        $settings = $this->app->settings();
        $segments = $path === '' ? [] : explode('/', $path);
        if ($segments !== [] && in_array($segments[0], Language::additional($settings), true)) {
            array_shift($segments);
        }
        [$internal] = Routes::internalPath('/' . implode('/', $segments), $db);
        $s = $internal === '/' ? [] : explode('/', ltrim((string) $internal, '/'));
        if ($s === []) {
            $home = (int) $settings->get('home_page');
            $page = $home > 0 ? $db->one('SELECT title, seo_title, description, visible, build, build_draft, text FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$home]) : null;

            return $page !== null ? self::page($page) : ['type' => 'home', 'title' => (string) $settings->get('site_name'), 'description' => (string) $settings->get('site_description'), 'form' => false, 'images' => 0, 'visible' => true];
        }
        if ($s[0] === 'news' && count($s) === 2) {
            $n = $db->one('SELECT title, seo_title, seo_description, intro, text, visible FROM {news} WHERE slug = ? AND deleted_at IS NULL', [$s[1]]);

            return $n === null ? null : ['type' => 'news', 'title' => (string) ($n['seo_title'] ?: $n['title']),
                'description' => trim((string) ($n['seo_description'] ?: strip_tags((string) $n['intro']))), 'form' => false,
                'images' => substr_count(strtolower((string) $n['text']), '<img'), 'visible' => (bool) $n['visible']];
        }
        $page = $db->one('SELECT title, seo_title, description, visible, build, build_draft, text FROM {pages} WHERE slug = ? AND deleted_at IS NULL', [implode('/', $s)]);
        if ($page !== null) {
            return self::page($page);
        }
        if (count($s) === 2) {
            $item = $db->one('SELECT p.name, p.seo_title, p.description, p.visible, p.data FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.slug = ? AND k.detail = TRUE AND p.slug = ? AND p.deleted_at IS NULL', [$s[0], $s[1]]);
            if ($item !== null) {
                return ['type' => 'item', 'title' => (string) ($item['seo_title'] ?: $item['name']), 'description' => trim((string) $item['description']), 'form' => false,
                    'images' => preg_match_all('#\.(jpe?g|png|webp|gif|avif)"#i', (string) $item['data']), 'visible' => (bool) $item['visible']];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $p a row of tl_pages
     * @return array{type: string, title: string, description: string, form: bool, images: int, visible: bool}
     */
    private static function page(array $p): array
    {
        // a hidden imported page is still being worked on: count its draft
        $json = $p['visible'] ? ($p['build'] ?? null) : ($p['build_draft'] ?? $p['build'] ?? null);
        [$forms, $images] = [0, substr_count(strtolower((string) $p['text']), '<img')];
        if ($json !== null) {
            $build = Build::fromJson((string) $json);
            [$forms, $images] = self::countElements($build['children'] ?? []);
        }

        return ['type' => 'page', 'title' => (string) ($p['seo_title'] ?: $p['title']), 'description' => trim((string) $p['description']),
            'form' => $forms > 0, 'images' => $images, 'visible' => (bool) $p['visible']];
    }

    /**
     * Forms and images in a build: the Form element, and the Image element plus the photos of galleries and carousels.
     *
     * @param list<array<string, mixed>> $children
     * @return array{0: int, 1: int}
     */
    public static function countElements(array $children): array
    {
        $forms = 0;
        $images = 0;
        foreach ($children as $el) {
            $type = (string) ($el['type'] ?? '');
            if ($type === 'form') {
                $forms++;
            } elseif ($type === 'image') {
                $images++;
            } elseif ($type === 'gallery') {
                $images += count((array) ($el['content']['photos'] ?? []));
            } elseif ($type === 'text') {
                $images += substr_count(strtolower((string) ($el['content']['html'] ?? '')), '<img');
            }
            [$f, $i] = self::countElements(is_array($el['children'] ?? null) ? $el['children'] : []);
            $forms += $f;
            $images += $i;
        }

        return [$forms, $images];
    }

    /**
     * What the old page had: its full title, the search engine description, a form (not just a search box) and the
     * number of images in the main content.
     *
     * @return array{title: string, description: string, form: bool, images: int}
     */
    public static function analyse(string $html, string $url): array
    {
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent));
        $description = trim((string) $doc->querySelector('meta[name="description"]')?->getAttribute('content'));
        $form = false;
        foreach ($doc->querySelectorAll('form') as $f) {
            $role = strtolower($f->getAttribute('role') ?? '');
            $fields = $f->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="search"]), textarea, select');
            if ($role !== 'search' && $fields->length > 0 && !preg_match('/search|hledat|suche/i', ($f->getAttribute('class') ?? '') . ' ' . ($f->getAttribute('action') ?? ''))) {
                $form = true;
                break;
            }
        }
        $main = WebImport::extract($html, $url);

        return ['title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 320), 'form' => $form,
            'images' => substr_count(strtolower($main['content']), '<img')];
    }

    /* ---------- the result ---------- */

    /**
     * Counts, the rows with a problem first, and the checks of the whole site.
     *
     * @param array<string, mixed> $state
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, web: list<array{message: string, fix: string}>}
     */
    public function result(array $state): array
    {
        $summary = ['urls' => count($state['urls']), 'checked' => count($state['rows']), 'ok' => 0, 'redirected' => 0, 'hidden' => 0, 'missing' => 0, 'failed' => 0, 'warnings' => 0];
        foreach ($state['rows'] as $r) {
            match ($r['status']) {
                'ok' => $summary['ok']++,
                'redirect', 'chain', 'redirect_out' => $summary['redirected']++,
                'hidden' => $summary['hidden']++,
                default => $summary['missing']++,
            };
            foreach ($r['problems'] as $p) {
                match (self::PROBLEMS[$p] ?? 'info') {
                    'error' => $summary['failed']++,
                    'warning' => $summary['warnings']++,
                    default => null,
                };
            }
        }
        $rank = fn (array $r): int => min(array_map(fn (string $p): int => ['error' => 0, 'warning' => 1, 'info' => 2][self::PROBLEMS[$p] ?? 'info'], $r['problems'] ?: ['ok'])) + ($r['problems'] === [] ? 3 : 0);
        $rows = $state['rows'];
        usort($rows, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        $site = [];
        if (!Extensions::isEnabled($this->app->settings(), 'redirects')) {
            $site[] = ['message' => t('The Redirects feature is off: no redirect from an old address works.'), 'fix' => 'admin.php?module=extensions'];
        }
        if ($state['phase'] === 'done') {
            foreach ((new Audit($this->app))->handoverFindings() as $f) {
                $site[] = ['message' => (string) $f['message'], 'fix' => (string) $f['edit']];
            }
        }

        return ['summary' => $summary, 'rows' => $rows, 'web' => $site];
    }

    /** A problem code in words, for the admin and for Claude. */
    public static function describe(string $code): string
    {
        return match ($code) {
            'missing' => t('Nothing at this address and no redirect – it will end in 404.'),
            'hidden' => t('The page is here but not published yet.'),
            'chain' => t('Redirected more than once – point the redirect straight at the final page.'),
            'redirect_out' => t('Redirected to another site.'),
            'no_description' => t('The old page had a search engine description, the new one has none.'),
            'form_missing' => t('The old page had a form, the new one has none.'),
            'fewer_images' => t('The new page has less than half of the old page’s images.'),
            'not_read' => t('The old page could not be read, so only the address was checked.'),
            default => $code,
        };
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
}
