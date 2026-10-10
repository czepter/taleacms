<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;
use Talea\Core\WpContent;

/**
 * Collections – custom content types (references, team, products, branches…): field definitions, items and values for the builder.
 *
 * In the builder the Collection list element lists them: its inside repeats for each item and the {{field}} placeholders
 * in texts, images and links are replaced by the item's values. {{name}}, {{url}} (item page) and {{date}} are always available.
 */
final class Collections
{
    /** Field types (key => label). */
    public const array FIELD_TYPES = ['text' => 'short text', 'lines' => 'longer text', 'html' => 'formatted text', 'image' => 'image', 'link' => 'link', 'number' => 'number', 'date' => 'date',
        'datetime' => 'date and time', 'file' => 'file', 'location' => 'location (latitude, longitude)', 'radio' => 'choice from options',
        'parameters' => 'parameters (Name: value per line)', 'variants' => 'variants (name | code | price per line)', 'item' => 'item of another collection'];

    /** A file or an image: an https address or a path in Media. */
    public const string MEDIA_PATTERN = '#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#';

    /** An item link (2.10) stores the address (seo_link) of the linked item – the same in every language version. */
    public const string ITEM_LINK_PATTERN = '/^[a-z0-9][a-z0-9-]{0,119}$/';

    /** @var array<string, array<string, array{0: string, 1: string}>> linked items for this request: "collection|language" => slug => [name, path] */
    private static array $linked = [];

    /** Built-in values of every item – custom fields must not use them. */
    public const array BUILT_IN = ['name', 'url', 'date', 'seo'];

    public const string PLACEHOLDER_PATTERN = '/\{\{([a-z][a-z0-9_]{0,30})\}\}/';

    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';

    /** Where a hidden item's page may redirect: '' (404), a path on the site (/team) or an https address; null = not valid. */
    public static function cleanRedirect(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || preg_match('#^/[^\s"<>]{0,250}$#', $value) === 1 || (preg_match('#^https://[^\s"<>]{3,250}$#i', $value) === 1) ? $value : null;
    }

    /**
     * A date and time field (2.11): YYYY-MM-DD HH:MM, or only the day (an all-day event); the T of a date-time input and
     * midnight are accepted ("…T00:00" = the whole day). '' stays empty, null = not valid.
     */
    public static function cleanDateTime(string $value): ?string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m) !== 1 || !checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return null;
        }
        if (!isset($m[2]) || ((int) $m[2] === 0 && (int) $m[3] === 0)) {
            return $m[1];
        }

        return (int) $m[2] > 23 || (int) $m[3] > 59 ? null : sprintf('%s %02d:%02d', $m[1], (int) $m[2], (int) $m[3]);
    }

    /** A location (2.11): "latitude, longitude" in degrees, kept with up to six decimals. '' stays empty, null = not valid. */
    public static function cleanLocation(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(-?\d{1,2}(?:[.]\d+)?)\s*[,;]\s*(-?\d{1,3}(?:[.]\d+)?)$/', $value, $m) !== 1 || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $m[1], 6, '.', ''), '0'), '.') . ', ' . rtrim(rtrim(number_format((float) $m[2], 6, '.', ''), '0'), '.');
    }

    /** A date and time field for visitors: the day in the site's format, with the time when it has one. */
    public static function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strlen($value) > 10 ? format_date($value, true) : format_date($value);
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {collections} ORDER BY name'));
    }

    /** @return array<string, mixed>|null */
    public static function bySlug(Db $db, string $seo): ?array
    {
        $r = $db->one('SELECT * FROM {collections} WHERE slug = ?', [$seo]);

        return $r === null ? null : self::extract($r);
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idk): ?array
    {
        $r = $db->one('SELECT * FROM {collections} WHERE collection_id = ?', [$idk]);

        return $r === null ? null : self::extract($r);
    }

    /**
     * Item template in a language version: the collection whose build and draft belong to the language (template_language). The default
     * language ('') has its template in collections, other languages in collection_templates; a language without its own template has
     * both build and draft null.
     *
     * @param array<string, mixed> $collection @return array<string, mixed>
     */
    public static function inLanguage(Db $db, array $collection, string $language): array
    {
        $collection['template_language'] = $language;
        if ($language === '') {
            return $collection;
        }
        $r = $db->one('SELECT build, build_draft, updated_at FROM {collection_templates} WHERE collection_id = ? AND language = ?', [$collection['collection_id'], $language]);

        return ['build' => $r['build'] ?? null, 'build_draft' => $r['build_draft'] ?? null, 'updated_at' => $r['updated_at'] ?? null] + $collection;
    }

    /**
     * Writes the columns of the language template returned by inLanguage (the default into collections, another language creates a row).
     *
     * @param array<string, mixed> $columns
     */
    public static function writeTemplate(Db $db, array $collection, array $columns): void
    {
        $language = (string) ($collection['template_language'] ?? '');
        if ($language === '') {
            $db->update('collections', $columns, ['collection_id' => $collection['collection_id']]);
        } elseif ($db->value('SELECT 1 FROM {collection_templates} WHERE collection_id = ? AND language = ?', [$collection['collection_id'], $language]) !== null) {
            $db->update('collection_templates', $columns, ['collection_id' => $collection['collection_id'], 'language' => $language]);
        } else {
            $db->insert('collection_templates', $columns + ['collection_id' => $collection['collection_id'], 'language' => $language]);
        }
    }

    /** Key of the template's versions and signed preview: collection:<id>, for another language collection:<id>:<language>. */
    public static function templateKey(array $collection): string
    {
        $language = (string) ($collection['template_language'] ?? '');

        return 'collection:' . (int) $collection['collection_id'] . ($language !== '' ? ':' . $language : '');
    }

    /**
     * The draft the builder opens a language's template with until anyone saves it: another language starts with a copy of the
     * default language's template, the default language with a template assembled from the collection fields.
     */
    public static function initialTemplateDraft(Db $db, array $collection): string
    {
        if (($collection['template_language'] ?? '') !== '') {
            $defaults = (array) self::byId($db, (int) $collection['collection_id']);
            if (($defaults['build_draft'] ?? $defaults['build'] ?? null) !== null) {
                return (string) ($defaults['build_draft'] ?? $defaults['build']);
            }
        }

        return Build::toJson(self::defaultTemplate($collection));
    }

    private static function extract(array $r): array
    {
        $r['fields'] = json_decode((string) $r['fields'], true) ?: [];

        return $r;
    }

    /**
     * Field definitions from the form or from AI: the key only lowercase letters, digits and underscore (made from the label), a known type.
     *
     * @return list<array{key: string, label: string, type: string, collection?: string, options?: list<string>}>
     */
    public static function sanitizeFields(mixed $input): array
    {
        $field = [];
        $keys = [];
        foreach (is_array($input) ? $input : [] as $p) {
            $labelText = mb_substr(trim(strip_tags((string) ($p['label'] ?? ''))), 0, 80);
            if ($labelText === '') {
                continue;
            }
            $key = (string) ($p['key'] ?? '');
            $key = preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key) ? $key : substr(str_replace('-', '_', slugify($labelText, 30)), 0, 30);
            if (!preg_match('/^[a-z]/', $key)) {
                $key = 'field_' . $key;
            }
            while (in_array($key, self::BUILT_IN, true) || isset($keys[$key])) {
                $key .= '_2';
            }
            $keys[$key] = true;
            $type = isset(self::FIELD_TYPES[$p['type'] ?? '']) ? $p['type'] : 'text';
            // a link to an item of another collection (2.10) knows which collection; without one it is a short text
            $target = (string) ($p['collection'] ?? '');
            if ($type === 'item' && preg_match('/^[a-z0-9][a-z0-9-]{0,109}$/', $target) !== 1) {
                $type = 'text';
            }
            // a choice (2.11) keeps its options: up to 30 short texts, one per line in the form
            $options = $type === 'radio' ? self::cleanOptions($p['options'] ?? []) : [];
            if ($type === 'radio' && $options === []) {
                $type = 'text';
            }
            $field[] = ['key' => $key, 'label' => $labelText, 'type' => $type] + ($type === 'item' ? ['collection' => $target] : []) + ($type === 'radio' ? ['options' => $options] : []);
        }

        return array_slice($field, 0, 30);
    }

    /** Options of a choice field: a list or lines of text, trimmed, without tags and duplicates, at most 30 of 80 characters. @return list<string> */
    public static function cleanOptions(mixed $input): array
    {
        $lines = is_array($input) ? $input : preg_split('/\R/', (string) (is_scalar($input) ? $input : ''));
        $out = [];
        foreach ((array) $lines as $line) {
            $line = mb_substr(trim(strip_tags(is_scalar($line) ? (string) $line : '')), 0, 80);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return array_slice($out, 0, 30);
    }

    /**
     * Item values by the field definitions. An invalid value is discarded and reported.
     *
     * @param list<array{key: string, label: string, type: string, collection?: string, options?: list<string>}> $field
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    public static function sanitizeData(array $field, array $input, array &$errors = []): array
    {
        $data = [];
        foreach ($field as $p) {
            $h = trim((string) (is_scalar($input[$p['key']] ?? null) ? $input[$p['key']] : ''));
            $clean = match ($p['type']) {
                'text' => mb_substr(strip_tags(str_replace(["\r", "\n"], ' ', $h)), 0, 500),
                'lines' => mb_substr(strip_tags(str_replace("\r\n", "\n", $h)), 0, 5000),
                'html' => WpContent::safeHtml(mb_substr($h, 0, 100000)),
                'image' => $h === '' || preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $h) ? $h : null,
                'link' => $h === '' || (WpContent::isSafeUrl($h) && !preg_match('/[\s"<>]/', $h)) ? mb_substr($h, 0, 500) : null,
                'number' => $h === '' || is_numeric(str_replace([' ', ','], ['', '.'], $h)) ? str_replace(' ', '', $h) : null,
                'date' => $h === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h) && strtotime($h) !== false) ? $h : null,
                'datetime' => self::cleanDateTime($h),
                'file' => $h === '' || (preg_match(self::MEDIA_PATTERN, $h) === 1 && !str_contains($h, '..')) ? $h : null,
                'location' => self::cleanLocation($h),
                'radio' => $h === '' || in_array($h, (array) ($p['options'] ?? []), true) ? $h : null,
                'parameters' => \Talea\Builder\Products::cleanParameters($h),
                'variants' => \Talea\Builder\Products::cleanVariants($h),
                'item' => $h === '' || preg_match(self::ITEM_LINK_PATTERN, $h) === 1 ? $h : null,
                default => '',
            };
            if ($clean === null) {
                $errors[$p['key']] = $p['label'];
                $clean = '';
            }
            $data[$p['key']] = $clean;
        }

        return $data;
    }

    /**
     * Visible items of a collection in the site language: filter by field value, sorting (also by a custom field) and pagination.
     *
     * @param array{0: string, 1: string}|null $filter [field key, value]
     * @param array{0: string, 1: string, 2: string}|null $period [PERIODS key, start field, end field] (2.11)
     * @return array{0: list<array<string, mixed>>, 1: int} [items, total]
     */
    public static function items(Db $db, int $idk, string $language, int $count, string $sort = 'order', ?array $filter = null, int $pageNumber = 1, string $sortField = '', ?array $period = null): array
    {
        $field = fn (string $key): string => "JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "'))"; // the key passed KEY_PATTERN
        $whereParts = 'collection_id = ? AND visible = 1 AND language = ?';
        $params = [$idk, $language];
        if ($filter !== null && preg_match(self::KEY_PATTERN, $filter[0]) && $filter[1] !== '') {
            $whereParts .= ' AND ' . $field($filter[0]) . ' = ?';
            $params[] = $filter[1];
        }
        if ($period !== null && ($condition = self::periodCondition($period[0], $period[1], $period[2], date('Y-m-d H:i'))) !== null) {
            $whereParts .= ' AND ' . $condition[0];
            $params = [...$params, ...$condition[1]];
        }
        $byField = preg_match(self::KEY_PATTERN, $sortField) === 1;
        $order = match (true) {
            $sort === 'name' => 'name',
            $sort === 'newest' => 'created_at DESC, item_id DESC',
            // numbers sort as numbers, everything else as text
            $sort === 'field' && $byField => '(' . $field($sortField) . ' + 0) ASC, ' . $field($sortField) . ' ASC, name',
            $sort === 'field_descending' && $byField => '(' . $field($sortField) . ' + 0) DESC, ' . $field($sortField) . ' DESC, name',
            default => 'sort_order, name',
        };
        $count = max(1, min(100, $count));
        $total = (int) $db->value('SELECT COUNT(*) FROM {collection_items} WHERE ' . $whereParts, $params);
        $items = array_map(function (array $r): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return $r;
        }, $db->all('SELECT * FROM {collection_items} WHERE ' . $whereParts . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $count, (max(1, $pageNumber) - 1) * $count]));

        return [$items, $total];
    }

    /** Which items a list shows by their dates (2.11): events that are still to come, notices that are posted now, the archive. */
    public const array PERIODS = ['' => 'all items', 'upcoming' => 'upcoming – not ended yet', 'current' => 'current – started and not ended', 'past' => 'past – ended'];

    /**
     * The SQL condition of a period over a start field and an optional end field (date, or date and time; a day without a
     * time starts at 00:00 and lasts until 23:59):
     *
     *  - upcoming: has a start and has not ended – it ends at its end, or at its start when it has none (an event);
     *  - past: has ended by the same rule (the archive);
     *  - current: has started (or has no start) and has not ended – without an end it never ends, so a notice with no
     *    takedown date stays up; without an end field the start's day is the whole period. An item with no dates is never current.
     *
     * null = no condition (an unknown period or field key).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function periodCondition(string $period, string $startKey, string $endKey, string $now): ?array
    {
        if ($period === '' || !isset(self::PERIODS[$period]) || preg_match(self::KEY_PATTERN, $startKey) !== 1 || ($endKey !== '' && preg_match(self::KEY_PATTERN, $endKey) !== 1)) {
            return null;
        }
        $value = fn (string $key): string => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')), '')";
        $endOfDay = fn (string $v): string => 'IF(LENGTH(' . $v . ') = 10, CONCAT(' . $v . ", ' 23:59'), " . $v . ')';
        $start = $value($startKey);
        $startOfDay = 'IF(LENGTH(' . $start . ') = 10, CONCAT(' . $start . ", ' 00:00'), " . $start . ')';
        $ends = $endKey !== '' ? 'COALESCE(NULLIF(' . $value($endKey) . ", ''), " . $start . ')' : $start; // upcoming and past
        $end = $endKey !== '' ? $value($endKey) : $start; // current

        return match ($period) {
            'upcoming' => ['(' . $start . " <> '' AND " . $endOfDay($ends) . ' >= ?)', [$now]],
            'past' => ['(' . $ends . " <> '' AND " . $endOfDay($ends) . ' < ?)', [$now]],
            default => ['((' . $start . " <> '' OR " . $end . " <> '') AND (" . $start . " = '' OR " . $startOfDay . ' <= ?) AND (' . $end . " = '' OR " . $endOfDay($end) . ' >= ?))', [$now, $now]],
        };
    }

    /** Distinct values of a field among the visible items (filter buttons in the list). @return list<string> */
    public static function fieldValues(Db $db, int $idk, string $language, string $key): array
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', array_column($db->all(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')) AS h FROM {collection_items} WHERE collection_id = ? AND visible = 1 AND language = ? ORDER BY h LIMIT 30",
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
            'name' => [(string) $item['name'], 'text'],
            'url' => [$collection['detail'] ? $url($collection['slug'] . '/' . $item['slug']) : '', 'link'],
            'date' => [format_date((string) $item['created_at']), 'text'],
            'seo' => [(string) $item['slug'], 'text'],
        ];
        foreach ($collection['fields'] as $p) {
            $value = (string) ($item['data'][$p['key']] ?? '');
            if ($p['type'] === 'item') {
                // {{branch}} = the name of the linked item, {{branch_url}} its page, {{branch_seo}} its address (for related lists)
                $linked = $db !== null && $value !== '' ? (self::linked($db, (string) ($p['collection'] ?? ''))[$value] ?? null) : null;
                $h[$p['key']] = [$linked[0] ?? '', 'text'];
                $h[$p['key'] . '_url'] ??= [$linked !== null && $linked[1] !== '' ? $url($linked[1]) : '', 'link'];
                $h[$p['key'] . '_seo'] ??= [$value, 'text'];
                continue;
            }
            if ($p['type'] === 'datetime') {
                // {{start}} = the day (and time) for visitors, {{start_iso}} = as stored, for machines (a time element, iCal)
                $h[$p['key']] = [self::formatDateTime($value), 'text'];
                $h[$p['key'] . '_iso'] ??= [$value, 'text'];
                continue;
            }
            if ($p['type'] === 'file') {
                // {{datasheet}} = the file's address (a link or a button), {{datasheet_name}} = its file name
                $h[$p['key']] = [$value, 'link'];
                $h[$p['key'] . '_name'] ??= [$value !== '' ? rawurldecode(basename((string) parse_url($value, PHP_URL_PATH))) : '', 'text'];
                continue;
            }
            if ($p['type'] === 'parameters' || $p['type'] === 'variants') {
                // a table for visitors (2.11): parameters to compare, variants with their code and price
                $h[$p['key']] = [$p['type'] === 'parameters' ? Products::parametersTable($value) : Products::variantsTable($value), 'html'];
                continue;
            }
            if ($p['type'] === 'radio') {
                $h[$p['key']] = [$value !== '' ? t($value) : '', 'text']; // a preset's options are English keys with site translations
                continue;
            }
            $h[$p['key']] = [$value, $p['type']];
        }
        if ($db !== null && ($collection['preset'] ?? '') !== '') {
            $h += \Talea\Core\Calendar::values($db, $collection, $item, $url, date('Y-m-d H:i')); // an event's when, where, status, iCal (2.11)
            $h += Products::values($collection, $item); // a product for the enquiry basket and comparison (2.11)
        }
        foreach (\Talea\Core\Notices::placeholders($collection, $item) as $key => $value) {
            $h[$key] ??= $value; // {{notice_status}} of an official notice board (2.11) – a field with that key wins
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
        $language ??= \Talea\Core\Language::siteColumn();
        $key = $collectionSlug . '|' . $language;
        if (isset(self::$linked[$key])) {
            return self::$linked[$key];
        }
        $collection = $collectionSlug !== '' ? self::bySlug($db, $collectionSlug) : null;
        $out = [];
        if ($collection !== null) {
            foreach ($db->all("SELECT name, slug, language FROM {collection_items} WHERE collection_id = ? AND visible = 1 AND deleted_at IS NULL AND language IN ('', ?) ORDER BY language = '' DESC, name",
                [(int) $collection['collection_id'], $language]) as $r) {
                $out[(string) $r['slug']] = [(string) $r['name'], $collection['detail'] ? $collection['slug'] . '/' . $r['slug'] : '']; // a translation overwrites the default
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

        return $collection === null ? [] : $db->pairs("SELECT slug, name FROM {collection_items} WHERE collection_id = ? AND language = '' AND deleted_at IS NULL ORDER BY name", [(int) $collection['collection_id']]);
    }

    /** Sample values for the editor when the collection has no items yet: field labels in square brackets. */
    public static function sample(array $collection): array
    {
        $h = ['name' => ['[' . t('Name') . ']', 'text'], 'url' => ['#', 'link'], 'date' => [format_date(date('Y-m-d H:i:s')), 'text'], 'seo' => ['', 'text']];
        foreach ($collection['fields'] as $p) {
            $h[$p['key']] = [in_array($p['type'], ['image', 'link', 'file'], true) ? '' : '[' . $p['label'] . ']', $p['type'] === 'file' ? 'link' : (in_array($p['type'], ['datetime', 'radio', 'location', 'parameters', 'variants'], true) ? 'text' : $p['type'])];
        }
        if (\Talea\Core\Notices::isBoard($collection)) {
            $h['notice_status'] ??= ['[' . t('Notice status') . ']', 'text'];
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
                    'lines' => $h === '' ? '' : '<p>' . nl2br(e($h), false) . '</p>',
                    default => $h === '' ? '' : '<p>' . e($h) . '</p>',
                };
            }
            $plain = $type === 'html' ? trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)) : $h;

            return match ($target) {
                'html' => $type === 'html' ? $h : ($type === 'lines' ? nl2br(e($h), false) : e($h)),
                'inline_text' => $type === 'lines' ? nl2br(e($h), false) : e($plain),
                // Custom HTML is output as it is (the code filter ran on save, the filling only now): the value must not bring tags
                'code' => $type === 'html' ? \Talea\Core\Html::safe($h) : ($type === 'lines' ? nl2br(e($h), false) : e($h)),
                default => $plain,
            };
        }, $text);
        if ($target === 'link' && $result !== '' && !WpContent::isSafeUrl($result)) {
            return '';
        }
        if ($target === 'image' && $result !== '' && !preg_match(self::MEDIA_PATTERN, $result)) {
            return '';
        }

        return $result;
    }

    /** Item template until the administrator edits it in the builder: heading, image and all fields one below another. */
    public static function defaultTemplate(array $collection): array
    {
        $n = Build::fresh(...);
        $children = [['tag' => 'h1'] + $n('heading', ['text' => '{{name}}'])];
        foreach ($collection['fields'] as $p) {
            $children[] = match ($p['type']) {
                'image' => $n('image', ['src' => '{{' . $p['key'] . '}}', 'alt' => '{{name}}']),
                'link' => $n('button', ['text' => $p['label'], 'link' => '{{' . $p['key'] . '}}', 'variant' => 'outline']),
                'file' => $n('button', ['text' => $p['label'] . ' ({{' . $p['key'] . '_name}})', 'link' => '{{' . $p['key'] . '}}', 'variant' => 'outline']),
                'html', 'lines' => $n('text', ['html' => '{{' . $p['key'] . '}}']),
                default => $n('text', ['html' => '<p><strong>' . e($p['label']) . ':</strong> {{' . $p['key'] . '}}</p>']),
            };
        }

        return Build::sanitize(['v' => Build::VERSION, 'children' => [$n('section', ['width' => 'narrow'], [
            ['style' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]] + $n('container', [], $children),
        ])]])[0];
    }

    /* ---------- items as full pages (1.9) ---------- */

    /** Columns of an item that make up one version in the history (tl_build_revisions, cast item:<idp>). */
    public const array VERSIONED = ['name', 'slug', 'data', 'seo_title', 'description', 'image', 'noindex'];

    /**
     * SEO fields and scheduled publishing of an item from a form or from Claude. A hidden item with a future time
     * publishes itself then (Notifications::process); a past time publishes it at once.
     *
     * @param array{seo_title?: mixed, description?: mixed, image?: mixed, noindex?: mixed, publish_at?: mixed} $input
     * @return array{seo_title: string, description: string, image: string, noindex: int, publish_at: ?string, visible: int}
     */
    public static function pageFields(array $input, bool $visible): array
    {
        $text = fn (string $key, int $max): string => mb_substr(trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''), 0, $max);
        $image = $text('image', 255);
        $from = is_string($input['publish_at'] ?? null) && $input['publish_at'] !== '' ? (strtotime(str_replace('T', ' ', $input['publish_at'])) ?: null) : null;

        return [
            'seo_title' => $text('seo_title', 200), 'description' => $text('description', 300),
            'image' => preg_match('#^(/?media/|https://)[^\s"\'<>]+$#', $image) && !str_contains($image, '..') ? $image : '',
            'noindex' => filter_var($input['noindex'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'publish_at' => !$visible && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null,
            'visible' => $visible || ($from !== null && $from <= time()) ? 1 : 0,
        ];
    }

    /**
     * Keeps the item as it was before a save in its history (the last Publisher::VERSIONS_KEPT) – and, for a document
     * whose file changes, the previous file for good (2.11, Core\Documents).
     */
    public static function saveVersion(\Talea\Core\App $app, array $previous, array $new): void
    {
        \Talea\Core\Documents::keepVersion($app, $previous, $new);
        $snapshot = fn (array $r): string => (string) json_encode(array_intersect_key($r, array_flip(self::VERSIONED)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $old = $snapshot($previous);
        $new = $snapshot(array_replace($previous, $new));
        Publisher::version($app, ['part' => 'item:' . (int) $previous['item_id']], $old, $new, $previous['updated_at'] ?? $previous['created_at'] ?? null);
    }

    /** @return array<string, mixed>|null the item columns stored in one version */
    public static function loadVersion(Db $db, int $idp, int $idr): ?array
    {
        $stored = json_decode((string) Publisher::load($db, ['part' => 'item:' . $idp], $idr), true);

        return is_array($stored) ? array_intersect_key($stored, array_flip(self::VERSIONED)) : null;
    }
}
