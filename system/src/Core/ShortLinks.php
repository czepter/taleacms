<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\Kernel;

/**
 * Short links (docs/superpowers/specs/2026-10-31-short-links-design.md): a short address for print (`/flyer`, or `/s/k7f2qm`) that
 * leads to a page, news item, collection item or any address, with campaign parameters (UTM) that stay off the paper. The target
 * is looked up when the link is followed, so a short link follows a renamed page and can be pointed at new content any time.
 *
 * The answering routes live in Front\Kernel; the writes of the admin module and of MCP go through save() and delete(), which
 * leave the change log to their callers. Refusals are English texts: callers wrap them in t().
 */
final class ShortLinks
{
    /** No 0/o, 1/l/i: a code that is read from paper and typed. */
    public const string ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    public const array UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public const array TYPES = ['page', 'news', 'item', 'url'];

    /** Same cap as Front\Forms::campaign. */
    private const int UTM_LENGTH = 80;

    /** A named short part: one lower-case segment. */
    private const string NAME = '/^[a-z0-9][a-z0-9-]{1,59}$/D';

    /** `s/` and six characters of ALPHABET (31^6, about 887 million). */
    public static function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 's/' . $code;
    }

    /** Why a name cannot be a short address (the English text), null = it is free. */
    public static function nameError(Db $db, string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return 'Use 2 to 60 lower-case letters, digits or hyphens, starting with a letter or digit.';
        }
        if (in_array($name, Kernel::reservedSlugs(), true) || isset(Language::AVAILABLE[$name]) || Routes::isNewsSlug($name, $db)
            || \Talea\Extension\Registry::ownsPrefix($name)) {
            return 'This address is used by the system, choose another one.';
        }
        if ($db->value('SELECT 1 FROM {pages} WHERE slug = ?', [$name]) !== null || $db->value('SELECT 1 FROM {collections} WHERE slug = ?', [$name]) !== null) {
            return 'A page or a collection already uses this address.';
        }
        if ($db->value("SELECT 1 FROM {redirects} WHERE from_path = ? AND active = TRUE AND match_type = 'exact'", [$name]) !== null) {
            return 'A redirect already uses this address.';
        }
        if (self::takesName($db, $name)) {
            return 'Another short link already uses this address.';
        }

        return null;
    }

    /** Does a short link own this address? The check pages, collections and the news slug make before they take a slug; it ignores the extension switch on purpose. */
    public static function takesName(Db $db, string $slug): bool
    {
        return $db->value('SELECT 1 FROM {short_links} WHERE path = ?', [$slug]) !== null;
    }

    /** Appends the filled UTM fields to the query of $url; a stored field replaces the same key already in the query, the fragment stays last. @param array<string, string> $utm */
    public static function merge(string $url, array $utm): string
    {
        $add = array_filter(array_intersect_key($utm, array_flip(self::UTM)), fn (string $v): bool => $v !== '');
        if ($add === []) {
            return $url;
        }
        [$rest, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $rest, 2), 2, '');
        $kept = array_filter(explode('&', $query), fn (string $pair): bool => $pair !== '' && !isset($add[urldecode(explode('=', $pair, 2)[0])]));

        return $base . '?' . implode('&', [...$kept, http_build_query($add, '', '&', PHP_QUERY_RFC3986)]) . ($fragment !== null ? '#' . $fragment : '');
    }

    /**
     * Where a visitor of the short link goes: an https:// address as stored, or a site path ready for App::url() ('' = the home page) –
     * with the campaign fields merged in. null = the content target is not reachable (trashed, hidden, the row is gone, a collection
     * without item pages): the caller sends the visitor to the home page and flags the link. Gated content and a hidden item whose
     * collection redirects hidden items keep their own address, where the gate or the collection's rule applies.
     *
     * @param array<string, mixed> $row
     */
    public static function targetUrl(App $app, array $row): ?string
    {
        $db = $app->db();
        $id = (int) $row['target_id'];
        $path = match ($row['target_type']) {
            'url' => (string) $row['target_url'],
            'page' => self::pagePath($app, $id),
            'news' => ($n = $db->one('SELECT slug, language FROM {news} WHERE news_id = ? AND visible = TRUE AND published_at <= NOW() AND deleted_at IS NULL', [$id])) === null
                ? null : self::inLanguage((string) $n['language'], Routes::publicPath('news/' . $n['slug'], $db)),
            'item' => self::itemPath($db, $id),
            default => null,
        };

        return $path === null ? null : self::merge($path, $row);
    }

    /**
     * Validates and stores a short link. Create when $id is 0 (a name is optional: none = a random code), else update (target, campaign fields
     * and label; the short part never changes). Returns the stored row or the English refusal. target_id is the internal row id.
     *
     * @param array<string, mixed> $input name, target_type, target_id, target_url, the UTM keys, label
     * @return array<string, mixed>|string
     */
    public static function save(App $app, array $input, int $id = 0): array|string
    {
        $db = $app->db();
        $type = (string) ($input['target_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return 'Choose where the short link leads.';
        }
        $data = ['target_type' => $type, 'target_id' => null, 'target_url' => null];
        if ($type === 'url') {
            $data['target_url'] = self::urlTarget((string) ($input['target_url'] ?? ''));
            if ($data['target_url'] === null) {
                return 'Enter an address on this site or one that starts with https://.';
            }
        } else {
            $data['target_id'] = (int) ($input['target_id'] ?? 0);
            if (!self::contentExists($db, $type, $data['target_id'])) {
                return 'The chosen content does not exist.';
            }
        }
        foreach (self::UTM as $key) {
            $data[$key] = mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', '', trim((string) ($input[$key] ?? ''))), 0, self::UTM_LENGTH);
        }
        $data['label'] = mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', '', trim((string) ($input['label'] ?? ''))), 0, 120);

        if ($id > 0) {
            $db->update('short_links', $data, ['short_link_id' => $id]);

            return $db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$id]) ?? 'The short link does not exist.';
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            do {
                $name = self::randomCode();
            } while (self::takesName($db, $name));
        } elseif (($error = self::nameError($db, $name)) !== null) {
            return $error;
        }
        $newId = $db->insert('short_links', $data + ['path' => $name, 'created_at' => date('Y-m-d H:i:s')]);

        return $db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$newId]) ?? 'The short link could not be saved.';
    }

    public static function delete(App $app, int $id): void
    {
        $app->db()->delete('short_links', ['short_link_id' => $id]);
    }

    /** The QR code of the printed address (the plain path, so a trailing-slash setting never changes what is printed). */
    public static function qrSvg(App $app, string $path): string
    {
        $address = $app->request->origin() . $app->request->basePath() . '/' . $path;

        return Qr::svg($address, $address);
    }

    private static function pagePath(App $app, int $id): ?string
    {
        $page = $app->db()->one('SELECT slug, language, translation_of FROM {pages} WHERE page_id = ? AND visible = TRUE AND deleted_at IS NULL', [$id]);
        if ($page === null) {
            return null;
        }
        $home = $app->settings()->int('home_page');
        $isHome = $home > 0 && ($id === $home || (int) $page['translation_of'] === $home);

        return self::inLanguage((string) $page['language'], $isHome ? '' : (string) $page['slug']);
    }

    private static function itemPath(Db $db, int $id): ?string
    {
        $item = $db->one('SELECT i.slug, i.language, i.visible, i.deleted_at, c.slug AS collection, c.detail, c.hidden_redirect FROM {collection_items} i JOIN {collections} c ON c.collection_id = i.collection_id WHERE i.item_id = ?', [$id]);
        if ($item === null || !$item['detail']) {
            return null;
        }
        $public = $item['visible'] && $item['deleted_at'] === null;
        if (!$public && (string) $item['hidden_redirect'] === '') {
            return null;
        }

        return self::inLanguage((string) $item['language'], $item['collection'] . '/' . $item['slug']);
    }

    /** The path in a language version of the site ('' = the default language). */
    private static function inLanguage(string $language, string $path): string
    {
        return $language === '' ? $path : trim($language . '/' . $path, '/');
    }

    private static function contentExists(Db $db, string $type, int $id): bool
    {
        return $id > 0 && match ($type) {
            'page' => $db->value('SELECT 1 FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$id]) !== null,
            'news' => $db->value('SELECT 1 FROM {news} WHERE news_id = ? AND deleted_at IS NULL', [$id]) !== null,
            'item' => $db->value('SELECT 1 FROM {collection_items} i JOIN {collections} c ON c.collection_id = i.collection_id WHERE i.item_id = ? AND i.deleted_at IS NULL AND c.detail = TRUE', [$id]) !== null,
            default => false,
        };
    }

    /** An https?:// address or a site path (stored without the leading slash, for App::url()); null = refused. */
    private static function urlTarget(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://[^/?\#]+#i', $url) === 1) {
            return $url;
        }
        // no scheme (javascript:, data:, mailto:) and no protocol-relative //host
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 || str_starts_with($url, '//')) {
            return null;
        }

        return ltrim($url, '/');
    }
}
