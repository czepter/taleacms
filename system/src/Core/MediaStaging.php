<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Files waiting for approval (#150): what a Claude connection limited to drafts uploads (upload_file) does not go to the public
 * media/ folder. It gets the same checks (Images re-encodes, Files checks the content, SVG is cleaned) and is saved outside the
 * web root under the path it will have (storage/staging/media/2026/10/photo-ab12cd.jpg); the media row has staged_at set. The
 * path in the row and in the tool's answer is the public one, so drafts point at it unchanged.
 *
 * - Showing: a request for a missing media/… file reaches index.php (Apache .htaccess, Caddy php_server, the try_files of the
 *   nginx example, dev-router.php) and Front\Kernel asks serve(). Signed-in users and draft previews get the file (a valid preview
 *   link sets a cookie for /media/, previewCookie(); the whole-site preview counts too), everyone else the usual 404.
 * - Going public: once published content refers to a waiting file, it moves to media/ (promotePublished(): after every change in
 *   the administration, every write call of a connection that may publish, the background job, and the first anonymous request
 *   for the file). A person can also approve it in Media → Waiting for approval (promote()).
 * - Nobody approved it: the background job deletes it after DAYS days (cleanup()).
 *
 * Full-access connections and people's own uploads never wait (appliesTo()).
 */
final class MediaStaging
{
    /** Waiting files, outside the web root (storage/ is refused by .htaccess, the Caddyfile, the nginx example and dev-router.php). */
    public const string FOLDER = 'storage/staging';

    /** A waiting file nobody approved is deleted after this many days. */
    public const int DAYS = 30;

    /** Cookie of a draft preview that lets its page load waiting files (path /media/, a preview key of the target "media"). */
    public const string COOKIE = 'tl_preview_media';

    /**
     * Content on the site that may name a media file: table => [the content, which rows are on the site]. Only what a person
     * published: a drafts-only connection writes none of it (builds go to build_draft, the look to look_draft, pages, items and
     * news stay hidden). A class counts once a published build uses it (classUsed): such a connection may create a new class live.
     */
    private const array PUBLISHED = [
        'pages' => ["CONCAT_WS(' ', build, text, image)", "deleted_at IS NULL AND (visible = TRUE OR page_id IN (SELECT variant_page_id FROM {experiments} WHERE status = 'running'))"],
        'site_parts' => ['build', '1 = 1'],
        'popups' => ['build', '1 = 1'],
        'components' => ['build', '1 = 1'],
        'collections' => ['build', '1 = 1'],
        'collection_templates' => ['build', '1 = 1'],
        'news' => ["CONCAT_WS(' ', image, intro, text, faq)", 'visible = TRUE AND deleted_at IS NULL'],
        'collection_items' => ["CONCAT_WS(' ', data, image)", 'visible = TRUE AND deleted_at IS NULL'],
        'categories' => ['description', '1 = 1'],
        'tags' => ["CONCAT_WS(' ', description, image)", '1 = 1'],
        'users' => ['photo', '1 = 1'],
        'settings' => ['value', "name <> 'look_draft'"],
        'newsletters' => ['html', "status IN ('sending', 'sent')"],
    ];

    /** The tables of PUBLISHED whose content is a build, where an element may use a class. */
    private const array BUILDS = ['pages', 'site_parts', 'popups', 'components', 'collections', 'collection_templates'];

    /** A file under media/ as Images, Files and the SVG upload name it, or one of its variants (-1200, -nahled, .webp, .avif). */
    private const string PATH = '#^media/\d{4}/\d{2}/[a-z0-9-]+\.[a-z0-9]+(?:\.(?:webp|avif))?$#D';

    /** Does a file stored by this caller wait for approval: a Claude connection limited to drafts. */
    public static function appliesTo(Auth $auth): bool
    {
        return $auth->connection() !== null && $auth->draftsOnly();
    }

    /** The folder a media/… path is written under: the site itself, or the staging folder for a waiting file. */
    public static function root(bool $staged): string
    {
        return $staged ? TALEA_ROOT . '/' . self::FOLDER : TALEA_ROOT;
    }

    /**
     * What names one upload: 'media/2026/10/photo-ab12cd-nahled.jpg.webp' => 'media/2026/10/photo-ab12cd'. Every name ends with six
     * random hex digits, so a variant has the stem of its original.
     */
    public static function stem(string $mediaPath): string
    {
        return (string) preg_replace('/\.[A-Za-z0-9]+$/', '', MediaHygiene::paths($mediaPath)[0] ?? $mediaPath);
    }

    /**
     * The files of an upload under a root: the file, its variants and their WebP/AVIF siblings.
     *
     * @return list<string> absolute paths
     */
    public static function files(string $imagePath, bool $staged): array
    {
        $stem = self::root($staged) . '/' . self::stem($imagePath);

        return array_values(array_filter([...(glob($stem . '.*') ?: []), ...(glob($stem . '-1200.*') ?: []), ...(glob($stem . '-nahled.*') ?: [])], is_file(...)));
    }

    /**
     * A request for a missing media/… file (Front\Kernel, before the page cache): the waiting file for a signed-in user or a draft
     * preview ($preview is asked only when needed), private and not for search engines. When published content already refers to
     * the file, it goes public for everyone first. Null = the usual 404.
     */
    public static function serve(App $app, string $requestPath, \Closure $preview): ?Response
    {
        $path = ltrim($requestPath, '/');
        if (!preg_match(self::PATH, $path) || is_file(TALEA_ROOT . '/' . $path)) {
            return null;
        }
        $db = $app->db();
        $row = $db->one('SELECT media_id, image_path FROM {media} WHERE staged_at IS NOT NULL AND image_path LIKE ?', [addcslashes(self::stem($path), '%_\\') . '.%']);
        if ($row === null) {
            return null;
        }
        $private = $app->auth()->user() !== null || $preview();
        if (!$private && (self::publishedStems($db, [$row]) === [] || !self::promote($db, $row))) {
            return null;
        }
        $file = self::root($private) . '/' . $path;
        if (!is_file($file)) {
            return null;
        }

        return new Response((string) file_get_contents($file), 200, self::headers($path) + ($private ? ['Cache-Control' => 'private, no-store', 'Expires' => '0', 'X-Robots-Tag' => 'noindex'] : []));
    }

    /**
     * The headers the web server gives media/ (.htaccess, Caddyfile, nginx example): the type by the extension, nosniff, SVG in a
     * sandbox, documents as a download – a type not listed here is a download too.
     *
     * @return array<string, string>
     */
    public static function headers(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'webm' => 'video/webm'][$extension] ?? null;

        return ['Content-Type' => $type ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']
            + ($extension === 'svg' ? ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"] : [])
            + ($type === null ? ['Content-Disposition' => 'attachment'] : []);
    }

    /**
     * After a preview key was verified (Front\Kernel::canSeeDraft): a cookie with a key of the target "media", valid as long as the
     * preview's key and sent only with /media/ requests, so the preview shows the waiting files its draft uses.
     */
    public static function previewCookie(App $app, string $previewKey): void
    {
        $until = (int) substr($previewKey, 0, 10);
        $minutes = intdiv($until - time(), 60);
        if ($minutes < 1 || headers_sent()) {
            return;
        }
        $r = $app->request;
        setcookie(self::COOKIE, Preview::key($app->db(), $app->settings(), 'media', $minutes),
            ['expires' => $until, 'path' => $r->basePath() . '/media/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $r->isHttps()]);
    }

    /** Does the request carry a valid media preview cookie? */
    public static function hasPreview(App $app): bool
    {
        $key = (string) ($_COOKIE[self::COOKIE] ?? '');

        return $key !== '' && Preview::verify($app->db(), $app->settings(), 'media', $key);
    }

    /**
     * Every waiting file that published content refers to goes public. One indexed query when nothing waits; called after every
     * change in the administration, every write call of a connection that may publish, and by the background job.
     *
     * @return int how many went public
     */
    public static function promotePublished(Db $db): int
    {
        // ponytail: the 300 oldest waiting files per run; they wait for a person, so there are few – the rest go in the next run
        $rows = $db->all('SELECT media_id, image_path FROM {media} WHERE staged_at IS NOT NULL ORDER BY media_id LIMIT 300');
        $used = $rows === [] ? [] : array_fill_keys(self::publishedStems($db, $rows), true);
        $count = 0;
        foreach ($rows as $row) {
            $count += isset($used[self::stem((string) $row['image_path'])]) && self::promote($db, $row) ? 1 : 0;
        }

        return $count;
    }

    /**
     * The stems of the given waiting files that published content refers to (also in JSON, media\/2026\/…, and as a variant).
     *
     * @param list<array<string, mixed>> $rows media rows with image_path
     * @return list<string>
     */
    private static function publishedStems(Db $db, array $rows): array
    {
        $stems = array_map(fn (array $o): string => self::stem((string) $o['image_path']), $rows);
        $content = '';
        foreach (array_chunk(array_map(fn (string $stem): string => '%' . addcslashes(basename($stem), '%_\\') . '%', $stems), 50) as $needles) {
            $like = fn (string $column): string => '(' . implode(' OR ', array_fill(0, count($needles), $column . ' LIKE ?')) . ')';
            foreach (self::PUBLISHED as $table => [$column, $where]) {
                foreach ($db->all('SELECT ' . $column . ' AS content FROM {' . $table . '} WHERE ' . $where . ' AND ' . $like($column), $needles) as $r) {
                    $content .= ' ' . $r['content'];
                }
            }
            $class = "CONCAT_WS(' ', style, css)";
            foreach ($db->all('SELECT name, ' . $class . ' AS content FROM {classes} WHERE ' . $like($class), $needles) as $r) {
                $content .= self::classUsed($db, (string) $r['name']) ? ' ' . $r['content'] : '';
            }
        }

        return array_values(array_intersect($stems, array_map(self::stem(...), MediaHygiene::paths($content))));
    }

    /** Does an element of a published build use the class? */
    private static function classUsed(Db $db, string $name): bool
    {
        foreach (self::BUILDS as $table) {
            foreach ($db->all('SELECT build FROM {' . $table . '} WHERE ' . self::PUBLISHED[$table][1] . ' AND build LIKE ?', ['%"' . addcslashes($name, '%_\\') . '"%']) as $r) {
                if (self::usesClass(json_decode((string) $r['build'], true)['children'] ?? [], $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Does an element of the tree, or one inside it, carry the class? */
    public static function usesClass(mixed $children, string $name): bool
    {
        foreach (is_array($children) ? $children : [] as $element) {
            if (is_array($element) && (in_array($name, (array) ($element['classes'] ?? []), true) || self::usesClass($element['children'] ?? [], $name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moves a waiting file with its variants to media/, then marks the row public. The file itself moves last, so whoever finds it
     * finds its variants too; a move that fails leaves the row waiting and the next attempt moves the rest.
     *
     * @param array<string, mixed> $row media row with media_id and image_path
     */
    public static function promote(Db $db, array $row): bool
    {
        $path = (string) $row['image_path'];
        $target = dirname(TALEA_ROOT . '/' . $path);
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return false;
        }
        $main = self::root(true) . '/' . $path;
        $files = self::files($path, true);
        usort($files, fn (string $a, string $b): int => ($a === $main) <=> ($b === $main));
        foreach ($files as $file) {
            if (!@rename($file, $target . '/' . basename($file))) {
                return false;
            }
        }
        if (!is_file(TALEA_ROOT . '/' . $path)) {
            return false;
        }
        $db->update('media', ['staged_at' => null], ['media_id' => (int) $row['media_id']]);
        \Talea\Front\Cache::clear();

        return true;
    }

    /**
     * The background job: waiting files published content uses go public; those nobody approved within DAYS days are deleted with
     * their rows, and so are files left without a row (an undone Claude session removed it) once they are older than that.
     */
    public static function cleanup(Db $db): string
    {
        $published = self::promotePublished($db);
        $deleted = 0;
        foreach ($db->all('SELECT media_id, image_path FROM {media} WHERE staged_at IS NOT NULL AND staged_at < NOW() - INTERVAL ' . self::DAYS . ' DAY') as $row) {
            foreach (self::files((string) $row['image_path'], true) as $file) {
                @unlink($file);
            }
            $deleted += $db->delete('media', ['media_id' => (int) $row['media_id']]);
        }
        if (is_dir(self::root(true))) {
            $before = time() - (self::DAYS + 1) * 86400;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root(true), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getMTime() < $before) {
                    @unlink($file->getPathname());
                }
            }
        }

        return 'published ' . $published . ', deleted ' . $deleted;
    }

    /** How many files wait for approval. */
    public static function count(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {media} WHERE staged_at IS NOT NULL');
    }
}
