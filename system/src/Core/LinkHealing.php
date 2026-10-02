<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Self-healing internal links (2.14): when an address of the site changes (a page, news item or category gets a new
 * slug, an address is redirected by hand or by the 404 repair), the links that pointed to the old address are rewritten
 * to the new one – in published builds and drafts of pages, collections, item templates, site parts, components and
 * pop-ups, in saved sections, in page and news texts, in collection items and in menus. Visitors and search engines
 * never meet the redirect, and the redirect table does not have to stay forever.
 *
 * Rewriting is limited to links that are certainly the site's own address: a path ("/old", "/en/old", "/old#part",
 * "/old?x=1", "/old/") or the site's own absolute URL from Settings. A link to another site with the same path, a longer
 * path ("/old-pricing") or a subpage ("/old/child" – it gets its own redirect and is healed by it) is left alone.
 * Revision history is not rewritten: it is what the content was. Every healing is written to the change log.
 */
final class LinkHealing
{
    /**
     * Where links can be: table => [key columns, [column => json|html]].
     *
     * @var array<string, array{list<string>, array<string, string>}>
     */
    private const array PLACES = [
        'stranky' => [['ids'], ['stavba' => 'json', 'stavba_koncept' => 'json', 'text' => 'html']],
        'novinky' => [['idc'], ['uvod' => 'html', 'text' => 'html']],
        'kolekce' => [['idk'], ['stavba' => 'json', 'stavba_koncept' => 'json']],
        'kolekce_sablony' => [['idk', 'jazyk'], ['stavba' => 'json', 'stavba_koncept' => 'json']],
        'kolekce_polozky' => [['idp'], ['data' => 'json']],
        'casti' => [['typ', 'jazyk', 'varianta'], ['stavba' => 'json', 'stavba_koncept' => 'json']],
        'komponenty' => [['idm'], ['stavba' => 'json', 'stavba_koncept' => 'json']],
        'popupy' => [['idpp'], ['stavba' => 'json', 'stavba_koncept' => 'json']],
        'sekce' => [['idx'], ['prvek' => 'json']],
        'menu' => [['umisteni', 'jazyk'], ['polozky' => 'json']],
    ];

    /**
     * Rewrites links from $old to $new everywhere; returns how many stored values changed. $new may be a path or an
     * absolute URL (a redirect to another site); an absolute target is written as it is.
     */
    public static function heal(Db $db, string $old, string $new): int
    {
        $old = trim($old, '/ ');
        $new = trim($new);
        if ($old === '' || $new === '' || trim($new, '/') === $old || str_contains($old, '?')) {
            return 0;
        }
        $origin = rtrim((string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'site_url'") ?? ''), '/');
        $pairs = [[$old, $new]];
        // news addresses also have an English public form (/news/x outside Czech) that links may use
        $english = Routes::publicPath($old, 'en', null);
        if ($english !== $old && preg_match('#^https?://#i', $new) !== 1) {
            $pairs[] = [$english, Routes::publicPath(trim($new, '/'), 'en', null)];
        }
        $changed = 0;
        foreach ($pairs as [$from, $to]) {
            $changed += self::replace($db, $from, $to, $origin);
        }
        if ($changed > 0) {
            Events::record($db, 'links.healed', 'info', t('Links to /%s now lead to %s (%d places).', $old, $new, $changed), ['from' => $old, 'to' => $new, 'count' => $changed]);
        }

        return $changed;
    }

    /** One old address to a new one in every place; returns how many stored rows changed. */
    private static function replace(Db $db, string $old, string $new, string $origin): int
    {
        $like = '%' . addcslashes($old, '%_\\') . '%';
        $changed = 0;
        foreach (self::PLACES as $table => [$keys, $columns]) {
            $where = implode(' OR ', array_map(fn (string $c): string => $c . ' LIKE ?', array_keys($columns)));
            $rows = $db->all('SELECT ' . implode(', ', array_merge($keys, array_keys($columns))) . ' FROM {' . $table . '} WHERE ' . $where, array_fill(0, count($columns), $like));
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $column => $kind) {
                    $value = $row[$column];
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $healed = $kind === 'json' ? self::json($value, $old, $new, $origin) : self::html($value, $old, $new, $origin);
                    if ($healed !== $value) {
                        $update[$column] = $healed;
                    }
                }
                if ($update !== []) {
                    $db->update($table, $update, array_intersect_key($row, array_flip($keys)));
                    $changed++;
                }
            }
        }

        return $changed;
    }

    /**
     * One link: the new address when it is the old one of this site, otherwise null. Keeps the form of the link – a
     * language prefix, a trailing slash, a #part and a ?query stay, an absolute own URL stays absolute.
     */
    public static function rewrite(string $url, string $old, string $new, string $origin = ''): ?string
    {
        $host = '';
        if ($origin !== '' && (str_starts_with($url, $origin . '/') || $url === $origin)) {
            $host = $origin;
            $url = substr($url, strlen($origin));
        }
        $pattern = '#^/(?:([a-z]{2})/)?' . preg_quote($old, '#') . '(/?)([?\#].*)?$#s';
        if (!str_starts_with($url, '/') || preg_match($pattern, $url, $m) !== 1) {
            return null;
        }
        $language = $m[1] ?? '';
        if ($language !== '' && !isset(Language::AVAILABLE[$language])) {
            return null;
        }
        $rest = ($m[2] ?? '') . ($m[3] ?? '');
        if (preg_match('#^https?://#i', $new) === 1) {
            return rtrim($new, '/') . (($m[3] ?? '') !== '' ? $m[3] : '');
        }
        $target = trim($new, '/');

        return $host . '/' . ($language !== '' ? $language . '/' : '') . $target . ($target === '' ? ltrim($rest, '/') : $rest);
    }

    /** Links in HTML: href attributes only (an image source is a file, not an address that moves). */
    public static function html(string $html, string $old, string $new, string $origin = ''): string
    {
        return (string) preg_replace_callback('#(\bhref\s*=\s*)(["\'])(.*?)\2#is', function (array $m) use ($old, $new, $origin): string {
            $decoded = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $healed = self::rewrite($decoded, $old, $new, $origin);

            return $healed === null ? $m[0] : $m[1] . $m[2] . htmlspecialchars($healed, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . $m[2];
        }, $html);
    }

    /**
     * Links in a stored JSON tree (a build, an element, item data, a menu): every string that is a link as a whole is
     * rewritten, every string with HTML has its href attributes rewritten. Keys do not matter, so new elements and
     * fields are covered without a list. Invalid JSON is left as it is.
     */
    public static function json(string $json, string $old, string $new, string $origin = ''): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $changed = false;
        $walk = function (mixed $value) use (&$walk, &$changed, $old, $new, $origin): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }
            if (!is_string($value) || !str_contains($value, $old)) {
                return $value;
            }
            $healed = str_contains($value, '<') ? self::html($value, $old, $new, $origin) : (self::rewrite($value, $old, $new, $origin) ?? $value);
            $changed = $changed || $healed !== $value;

            return $healed;
        };
        $healed = $walk($data);

        // unchanged JSON is returned byte for byte, so a value that only differs in escaping is never written back
        return $changed ? (string) json_encode($healed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
    }
}
