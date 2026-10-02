<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

/**
 * Collections – custom content types (references, team, products, branches…): field definitions, items and values for the builder.
 *
 * In the builder the Collection list element lists them: its inside repeats for each item and the {{field}} placeholders
 * in texts, images and links are replaced by the item's values. {{nazev}}, {{url}} (item page) and {{datum}} are always available.
 */
final class Collections
{
    /** Field types (key => label). */
    public const array FIELD_TYPES = ['text' => 'short text', 'radky' => 'longer text', 'html' => 'formatted text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz', 'cislo' => 'číslo', 'datum' => 'datum',
        'polozka' => 'item of another collection'];

    /** An item link (2.10) stores the address (seo_link) of the linked item – the same in every language version. */
    public const string ITEM_LINK_PATTERN = '/^[a-z0-9][a-z0-9-]{0,119}$/';

    /** @var array<string, array<string, array{0: string, 1: string}>> linked items for this request: "collection|language" => slug => [name, path] */
    private static array $linked = [];

    /** Built-in values of every item – custom fields must not use them. */
    public const array BUILT_IN = ['nazev', 'url', 'datum', 'seo'];

    public const string PLACEHOLDER_PATTERN = '/\{\{([a-z][a-z0-9_]{0,30})\}\}/';

    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    /** Ready-made collections (2.10): People – a team with photo, role, languages, contacts and absence; its hidden items redirect to the list. */
    public const array PRESETS = ['people' => ['Team', [['Photo', 'obrazek'], ['Role', 'text'], ['Languages', 'text'], ['Phone', 'text'], ['E-mail', 'text'], ['On leave', 'text'], ['About', 'html']]]];

    /** Where a hidden item's page may redirect: '' (404), a path on the site (/team) or an https address; null = not valid. */
    public static function cleanRedirect(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || preg_match('#^/[^\s"<>]{0,250}$#', $value) === 1 || (preg_match('#^https://[^\s"<>]{3,250}$#i', $value) === 1) ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {kolekce} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function bySlug(Db $db, string $seo): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE seo_link = ?', [$seo]);

        return $r === null ? null : self::extract($r);
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idk): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$idk]);

        return $r === null ? null : self::extract($r);
    }

    /**
     * Item template in a language version: the collection whose build and draft belong to the language (sablona_jazyk). The default
     * language ('') has its template in ka_kolekce, other languages in ka_kolekce_sablony; a language without its own template has
     * both build and draft null.
     *
     * @param array<string, mixed> $collection @return array<string, mixed>
     */
    public static function inLanguage(Db $db, array $collection, string $language): array
    {
        $collection['sablona_jazyk'] = $language;
        if ($language === '') {
            return $collection;
        }
        $r = $db->one('SELECT stavba, stavba_koncept, zmeneno FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]);

        return ['stavba' => $r['stavba'] ?? null, 'stavba_koncept' => $r['stavba_koncept'] ?? null, 'zmeneno' => $r['zmeneno'] ?? null] + $collection;
    }

    /**
     * Writes the columns of the language template returned by inLanguage (the default into ka_kolekce, another language creates a row).
     *
     * @param array<string, mixed> $columns
     */
    public static function writeTemplate(Db $db, array $collection, array $columns): void
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');
        if ($language === '') {
            $db->update('kolekce', $columns, ['idk' => $collection['idk']]);
        } elseif ($db->value('SELECT 1 FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]) !== null) {
            $db->update('kolekce_sablony', $columns, ['idk' => $collection['idk'], 'jazyk' => $language]);
        } else {
            $db->insert('kolekce_sablony', $columns + ['idk' => $collection['idk'], 'jazyk' => $language]);
        }
    }

    /** Key of the template's versions and signed preview: kolekce:<idk>, for another language kolekce:<idk>:<jazyk>. */
    public static function templateKey(array $collection): string
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');

        return 'kolekce:' . (int) $collection['idk'] . ($language !== '' ? ':' . $language : '');
    }

    /**
     * The draft the builder opens a language's template with until anyone saves it: another language starts with a copy of the
     * default language's template, the default language with a template assembled from the collection fields.
     */
    public static function initialTemplateDraft(Db $db, array $collection): string
    {
        if (($collection['sablona_jazyk'] ?? '') !== '') {
            $defaults = (array) self::byId($db, (int) $collection['idk']);
            if (($defaults['stavba_koncept'] ?? $defaults['stavba'] ?? null) !== null) {
                return (string) ($defaults['stavba_koncept'] ?? $defaults['stavba']);
            }
        }

        return Build::toJson(self::defaultTemplate($collection));
    }

    private static function extract(array $r): array
    {
        $r['pole'] = json_decode((string) $r['pole'], true) ?: [];

        return $r;
    }

    /**
     * Field definitions from the form or from AI: the key only lowercase letters, digits and underscore (made from the label), a known type.
     *
     * @return list<array{klic: string, popisek: string, typ: string}>
     */
    public static function sanitizeFields(mixed $input): array
    {
        $field = [];
        $keys = [];
        foreach (is_array($input) ? $input : [] as $p) {
            $labelText = mb_substr(trim(strip_tags((string) ($p['popisek'] ?? ''))), 0, 80);
            if ($labelText === '') {
                continue;
            }
            $key = (string) ($p['klic'] ?? '');
            $key = preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key) ? $key : substr(str_replace('-', '_', slugify($labelText, 30)), 0, 30);
            if (!preg_match('/^[a-z]/', $key)) {
                $key = 'pole_' . $key;
            }
            while (in_array($key, self::BUILT_IN, true) || isset($keys[$key])) {
                $key .= '_2';
            }
            $keys[$key] = true;
            $type = isset(self::FIELD_TYPES[$p['typ'] ?? '']) ? $p['typ'] : 'text';
            // a link to an item of another collection (2.10) knows which collection; without one it is a short text
            $target = (string) ($p['kolekce'] ?? '');
            if ($type === 'polozka' && preg_match('/^[a-z0-9][a-z0-9-]{0,109}$/', $target) !== 1) {
                $type = 'text';
            }
            $field[] = ['klic' => $key, 'popisek' => $labelText, 'typ' => $type] + ($type === 'polozka' ? ['kolekce' => $target] : []);
        }

        return array_slice($field, 0, 30);
    }

    /**
     * Item values by the field definitions. An invalid value is discarded and reported.
     *
     * @param list<array{klic: string, popisek: string, typ: string}> $field
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    public static function sanitizeData(array $field, array $input, array &$errors = []): array
    {
        $data = [];
        foreach ($field as $p) {
            $h = trim((string) (is_scalar($input[$p['klic']] ?? null) ? $input[$p['klic']] : ''));
            $clean = match ($p['typ']) {
                'text' => mb_substr(strip_tags(str_replace(["\r", "\n"], ' ', $h)), 0, 500),
                'radky' => mb_substr(strip_tags(str_replace("\r\n", "\n", $h)), 0, 5000),
                'html' => WpContent::safeHtml(mb_substr($h, 0, 100000)),
                'obrazek' => $h === '' || preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $h) ? $h : null,
                'odkaz' => $h === '' || (WpContent::isSafeUrl($h) && !preg_match('/[\s"<>]/', $h)) ? mb_substr($h, 0, 500) : null,
                'cislo' => $h === '' || is_numeric(str_replace([' ', ','], ['', '.'], $h)) ? str_replace(' ', '', $h) : null,
                'datum' => $h === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) && strtotime($h) !== false) ? $h : null,
                'polozka' => $h === '' || preg_match(self::ITEM_LINK_PATTERN, $h) === 1 ? $h : null,
                default => '',
            };
            if ($clean === null) {
                $errors[$p['klic']] = $p['popisek'];
                $clean = '';
            }
            $data[$p['klic']] = $clean;
        }

        return $data;
    }

    /**
     * Visible items of a collection in the site language: filter by field value, sorting (also by a custom field) and pagination.
     *
     * @param array{0: string, 1: string}|null $filter [field key, value]
     * @return array{0: list<array<string, mixed>>, 1: int} [items, total]
     */
    public static function items(Db $db, int $idk, string $language, int $count, string $sort = 'poradi', ?array $filter = null, int $pageNumber = 1, string $sortField = ''): array
    {
        $field = fn (string $key): string => "JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "'))"; // the key passed KEY_PATTERN
        $whereParts = 'idk = ? AND zobrazit = 1 AND jazyk = ?';
        $params = [$idk, $language];
        if ($filter !== null && preg_match(self::KEY_PATTERN, $filter[0]) && $filter[1] !== '') {
            $whereParts .= ' AND ' . $field($filter[0]) . ' = ?';
            $params[] = $filter[1];
        }
        $byField = preg_match(self::KEY_PATTERN, $sortField) === 1;
        $order = match (true) {
            $sort === 'nazev' => 'nazev',
            $sort === 'nejnovejsi' => 'datum DESC, idp DESC',
            // numbers sort as numbers, everything else as text
            $sort === 'pole' && $byField => '(' . $field($sortField) . ' + 0) ASC, ' . $field($sortField) . ' ASC, nazev',
            $sort === 'pole_sestupne' && $byField => '(' . $field($sortField) . ' + 0) DESC, ' . $field($sortField) . ' DESC, nazev',
            default => 'poradi, nazev',
        };
        $count = max(1, min(100, $count));
        $total = (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE ' . $whereParts, $params);
        $items = array_map(function (array $r): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return $r;
        }, $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . $whereParts . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $count, (max(1, $pageNumber) - 1) * $count]));

        return [$items, $total];
    }

    /** Distinct values of a field among the visible items (filter buttons in the list). @return list<string> */
    public static function fieldValues(Db $db, int $idk, string $language, string $key): array
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', array_column($db->all(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')) AS h FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND jazyk = ? ORDER BY h LIMIT 30",
            [$idk, $language],
        ), 'h')), fn (string $h): bool => $h !== '' && $h !== 'null'));
    }

    /**
     * Values for the placeholders: key => [value, type].
     *
     * @param callable(string): string $url url within the site
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $collection, array $item, callable $url, ?Db $db = null): array
    {
        $h = [
            'nazev' => [(string) $item['nazev'], 'text'],
            'url' => [$collection['detail'] ? $url($collection['seo_link'] . '/' . $item['seo_link']) : '', 'odkaz'],
            'datum' => [format_date((string) $item['datum']), 'text'],
            'seo' => [(string) $item['seo_link'], 'text'],
        ];
        foreach ($collection['pole'] as $p) {
            $value = (string) ($item['data'][$p['klic']] ?? '');
            if ($p['typ'] === 'polozka') {
                // {{branch}} = the name of the linked item, {{branch_url}} its page, {{branch_seo}} its address (for related lists)
                $linked = $db !== null && $value !== '' ? (self::linked($db, (string) ($p['kolekce'] ?? ''))[$value] ?? null) : null;
                $h[$p['klic']] = [$linked[0] ?? '', 'text'];
                $h[$p['klic'] . '_url'] ??= [$linked !== null && $linked[1] !== '' ? $url($linked[1]) : '', 'odkaz'];
                $h[$p['klic'] . '_seo'] ??= [$value, 'text'];
                continue;
            }
            $h[$p['klic']] = [$value, $p['typ']];
        }

        return $h;
    }

    /**
     * Visible items of a collection for item links (2.10): address => [name, path of its page ('' without item pages)], in the
     * language version of the site with the default language where the version has no own item.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function linked(Db $db, string $collectionSlug, ?string $language = null): array
    {
        $language ??= \Kaleta\Core\Language::siteColumn();
        $key = $collectionSlug . '|' . $language;
        if (isset(self::$linked[$key])) {
            return self::$linked[$key];
        }
        $collection = $collectionSlug !== '' ? self::bySlug($db, $collectionSlug) : null;
        $out = [];
        if ($collection !== null) {
            foreach ($db->all("SELECT nazev, seo_link, jazyk FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND smazano IS NULL AND jazyk IN ('', ?) ORDER BY jazyk = '' DESC, nazev",
                [(int) $collection['idk'], $language]) as $r) {
                $out[(string) $r['seo_link']] = [(string) $r['nazev'], $collection['detail'] ? $collection['seo_link'] . '/' . $r['seo_link'] : '']; // a translation overwrites the default
            }
        }

        return self::$linked[$key] = $out;
    }

    /**
     * Items of a collection to choose from in the admin (all, also hidden ones), name => address.
     *
     * @return array<string, string> address => name
     */
    public static function choices(Db $db, string $collectionSlug): array
    {
        $collection = $collectionSlug !== '' ? self::bySlug($db, $collectionSlug) : null;

        return $collection === null ? [] : $db->pairs("SELECT seo_link, nazev FROM {kolekce_polozky} WHERE idk = ? AND jazyk = '' AND smazano IS NULL ORDER BY nazev", [(int) $collection['idk']]);
    }

    /** Sample values for the editor when the collection has no items yet: field labels in square brackets. */
    public static function sample(array $collection): array
    {
        $h = ['nazev' => ['[' . t('Název') . ']', 'text'], 'url' => ['#', 'odkaz'], 'datum' => [format_date(date('Y-m-d H:i:s')), 'text'], 'seo' => ['', 'text']];
        foreach ($collection['pole'] as $p) {
            $h[$p['klic']] = [in_array($p['typ'], ['obrazek', 'odkaz'], true) ? '' : '[' . $p['popisek'] . ']', $p['typ']];
        }

        return $h;
    }

    /**
     * Fills values into an element's content field by the type of the target field (text is escaped only when rendering, inline and html right away).
     *
     * @param array<string, array{0: string, 1: string}> $values
     */
    public static function fill(string $text, string $target, array $values): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        // one pass: a filled-in value is not scanned again ({{…}} tags written in a field's text stay text)
        $htmlTag = substr(self::PLACEHOLDER_PATTERN, 1, -1);
        $pattern = $target === 'html' ? '#<p>\s*' . $htmlTag . '\s*</p>|' . $htmlTag . '#' : self::PLACEHOLDER_PATTERN;
        $result = (string) preg_replace_callback($pattern, function (array $m) use ($target, $values): string {
            $key = ($m[1] ?? '') !== '' ? $m[1] : $m[2];
            [$h, $type] = $values[$key] ?? ['', 'text'];
            if ($target === 'html' && ($m[1] ?? '') !== '') {
                // a paragraph with only a tag of formatted or longer text is replaced whole (otherwise <p><p>…</p></p> would result)
                return match ($type) {
                    'html' => $h,
                    'radky' => $h === '' ? '' : '<p>' . nl2br(e($h), false) . '</p>',
                    default => $h === '' ? '' : '<p>' . e($h) . '</p>',
                };
            }
            $plain = $type === 'html' ? trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)) : $h;

            return match ($target) {
                'html' => $type === 'html' ? $h : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                'inline' => $type === 'radky' ? nl2br(e($h), false) : e($plain),
                // Custom HTML is output as it is (the code filter ran on save, the filling only now): the value must not bring tags
                'kod' => $type === 'html' ? \Kaleta\Core\Html::safe($h) : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                default => $plain,
            };
        }, $text);
        if ($target === 'odkaz' && $result !== '' && !WpContent::isSafeUrl($result)) {
            return '';
        }
        if ($target === 'obrazek' && $result !== '' && !preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $result)) {
            return '';
        }

        return $result;
    }

    /** Item template until the administrator edits it in the builder: heading, image and all fields one below another. */
    public static function defaultTemplate(array $collection): array
    {
        $n = Build::fresh(...);
        $children = [['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}'])];
        foreach ($collection['pole'] as $p) {
            $children[] = match ($p['typ']) {
                'obrazek' => $n('obrazek', ['src' => '{{' . $p['klic'] . '}}', 'alt' => '{{nazev}}']),
                'odkaz' => $n('tlacitko', ['text' => $p['popisek'], 'odkaz' => '{{' . $p['klic'] . '}}', 'varianta' => 'obrys']),
                'html', 'radky' => $n('text', ['html' => '{{' . $p['klic'] . '}}']),
                default => $n('text', ['html' => '<p><strong>' . e($p['popisek']) . ':</strong> {{' . $p['klic'] . '}}</p>']),
            };
        }

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', ['sirka' => 'uzka'], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], $children),
        ])]])[0];
    }

    /* ---------- items as full pages (1.9) ---------- */

    /** Columns of an item that make up one version in the history (ka_stavba_revize, cast polozka:<idp>). */
    public const array VERSIONED = ['nazev', 'seo_link', 'data', 'seo_titulek', 'popis', 'obrazek', 'noindex'];

    /**
     * SEO fields and scheduled publishing of an item from a form or from Claude. A hidden item with a future time
     * publishes itself then (Notifications::process); a past time publishes it at once.
     *
     * @param array{seo_titulek?: mixed, popis?: mixed, obrazek?: mixed, noindex?: mixed, zverejnit_od?: mixed} $input
     * @return array{seo_titulek: string, popis: string, obrazek: string, noindex: int, zverejnit_od: ?string, zobrazit: int}
     */
    public static function pageFields(array $input, bool $visible): array
    {
        $text = fn (string $key, int $max): string => mb_substr(trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''), 0, $max);
        $image = $text('obrazek', 255);
        $from = is_string($input['zverejnit_od'] ?? null) && $input['zverejnit_od'] !== '' ? (strtotime(str_replace('T', ' ', $input['zverejnit_od'])) ?: null) : null;

        return [
            'seo_titulek' => $text('seo_titulek', 200), 'popis' => $text('popis', 300),
            'obrazek' => preg_match('#^(/?media/|https://)[^\s"\'<>]+$#', $image) && !str_contains($image, '..') ? $image : '',
            'noindex' => filter_var($input['noindex'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'zverejnit_od' => !$visible && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null,
            'zobrazit' => $visible || ($from !== null && $from <= time()) ? 1 : 0,
        ];
    }

    /** Keeps the item as it was before a save in its history (the last Publisher::VERSIONS_KEPT). */
    public static function saveVersion(\Kaleta\Core\App $app, array $previous, array $new): void
    {
        $snapshot = fn (array $r): string => (string) json_encode(array_intersect_key($r, array_flip(self::VERSIONED)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $old = $snapshot($previous);
        $new = $snapshot(array_replace($previous, $new));
        Publisher::version($app, ['cast' => 'polozka:' . (int) $previous['idp']], $old, $new, $previous['zmeneno'] ?? $previous['datum'] ?? null);
    }

    /** @return array<string, mixed>|null the item columns stored in one version */
    public static function loadVersion(Db $db, int $idp, int $idr): ?array
    {
        $stored = json_decode((string) Publisher::load($db, ['cast' => 'polozka:' . $idp], $idr), true);

        return is_array($stored) ? array_intersect_key($stored, array_flip(self::VERSIONED)) : null;
    }
}
