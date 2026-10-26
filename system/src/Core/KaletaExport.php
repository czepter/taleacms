<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Turns a Kaleta site export into a Talea site export, so a site built with Kaleta (the Czech upstream) moves to Talea through the
 * normal import: SiteImport::prepare() calls it when the uploaded archive holds obsah.json instead of content.json.
 *
 * Kaleta writes obsah.json (format version 2): Czech tables and columns, integer keys, builds, menus, pop-up rules and the design
 * system in Czech words. Talea reads content.json (format version 3): English names, every row identified by a public id (UUID v4)
 * and every reference between rows written as that public id. This class renames (system/data/kaleta-vocabulary.php), gives each row
 * a public id derived from the old site address, table and key (the same archive converts to the same ids) and points every reference
 * at it. It validates nothing: the import runs every row through the same sanitizers as any other archive. The media files are
 * copied as they are. SiteImport itself keeps no compatibility layer: it only ever reads the converted content.json.
 */
final class KaletaExport
{
    /** Features (Extensions::CATALOG) Kaleta does not have: its archive cannot name them, so the import keeps their default. */
    private const array TALEA_ONLY_FEATURES = ['page_check'];

    /** Talea table => the column that held the row's integer key in the export (after renaming). */
    private const KEYS = ['pages' => 'page_id', 'categories' => 'category_id', 'tags' => 'tag_id', 'news' => 'news_id', 'redirects' => 'redirect_id',
        'components' => 'component_id', 'sections' => 'section_id', 'collections' => 'collection_id', 'collection_items' => 'item_id', 'popups' => 'popup_id',
        'media_folders' => 'folder_id', 'media' => 'media_id', 'booking_services' => 'id', 'booking_staff' => 'id'];

    /** Talea table => [column => the table its integer points at]. */
    private const REFS = ['pages' => ['parent_id' => 'pages', 'translation_of' => 'pages'], 'categories' => ['translation_of' => 'categories'],
        'news' => ['category_id' => 'categories', 'translation_of' => 'news'], 'collection_templates' => ['collection_id' => 'collections'],
        'collection_items' => ['collection_id' => 'collections'], 'document_versions' => ['item_id' => 'collection_items'], 'notice_log' => ['item_id' => 'collection_items'],
        'media' => ['folder_id' => 'media_folders'], 'booking_staff_services' => ['staff_id' => 'booking_staff', 'service_id' => 'booking_services'],
        'booking_hours' => ['staff_id' => 'booking_staff'], 'booking_off' => ['staff_id' => 'booking_staff']];

    /** Does the archive (or JSON file) hold a Kaleta export? */
    public static function is(string $path): bool
    {
        if (str_ends_with(strtolower($path), '.zip')) {
            if (!class_exists(\ZipArchive::class)) {
                return false;
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            $found = $zip->locateName('obsah.json') !== false && $zip->locateName('content.json') === false;
            $zip->close();

            return $found;
        }

        return str_contains((string) @file_get_contents($path, length: 512), '"kaleta-export"');
    }

    /**
     * Writes the Talea export of the Kaleta export $in (a .zip with obsah.json and media/, or obsah.json alone) to $out.
     * The whole obsah.json is read into memory.
     *
     * @return array<string, int> what did not convert cleanly: message => how often
     * @throws \RuntimeException not a Kaleta export, an unknown format version, or the file cannot be written
     */
    public static function convert(string $in, string $out): array
    {
        $v = require dirname(__DIR__, 2) . '/data/kaleta-vocabulary.php';
        $isZip = str_ends_with(strtolower($in), '.zip');
        if ($isZip) {
            $zip = new \ZipArchive();
            if ($zip->open($in, \ZipArchive::RDONLY) !== true || ($json = $zip->getFromName('obsah.json')) === false) {
                throw new \RuntimeException('The file is not a Talea export (content.json is missing in the archive).');
            }
            $zip->close();
        } else {
            $json = (string) file_get_contents($in);
        }
        $data = json_decode($json, true);
        unset($json);
        if (!is_array($data) || ($data['format'] ?? '') !== 'kaleta-export') {
            throw new \RuntimeException('The file is not a Talea export.');
        }
        if ((int) ($data['verze_formatu'] ?? 0) !== 2) {
            throw new \RuntimeException('This Kaleta export has a format version Talea does not know – export the site again with Kaleta 3.7 or newer.');
        }

        $warnings = [];
        $warn = function (string $message) use (&$warnings): void {
            $warnings[$message] = ($warnings[$message] ?? 0) + 1;
        };

        /* ---------- public ids ---------- */

        $seed = (string) ($data['nastaveni']['site_url'] ?? '') . "\0" . (string) ($data['vytvoreno'] ?? '');
        $uuid = function (string $table, int $id) use ($seed): string {
            $h = sha1($seed . "\0" . $table . "\0" . $id);

            return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-' . dechex(8 | (hexdec($h[16]) & 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
        };
        // the keys that exist in the export: a reference to anything else (a deleted page) becomes empty
        $known = [];
        foreach ($v['tables'] as $czech => $table) {
            $key = array_search(self::KEYS[$table] ?? '', $v['columns'][$table] ?? [], true) ?: (self::KEYS[$table] ?? null);
            foreach (is_array($data[$czech] ?? null) ? $data[$czech] : [] as $row) {
                if ($key !== null && (int) ($row[$key] ?? 0) > 0) {
                    $known[$table][(int) $row[$key]] = true;
                }
            }
        }
        /** The public id of a row of the export; null for no reference or one to a row that is not in the export. */
        $ref = function (string $table, mixed $id) use ($uuid, $known, $warn): ?string {
            if (!is_numeric($id) || (int) $id <= 0) {
                return null;
            }
            if (!isset($known[$table][(int) $id])) {
                $warn("a reference to a missing row of $table was dropped");

                return null;
            }

            return $uuid($table, (int) $id);
        };

        /* ---------- words ---------- */

        $rename = fn (array $row, array $map): array => array_combine(array_map(fn (int|string $k): int|string => $map[$k] ?? $k, array_keys($row)), $row);
        $decode = fn (mixed $value): mixed => is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[') ? json_decode($value, true) : $value;
        $encode = fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // custom properties and classes of the site in CSS and HTML, as whole names (a file "bianka-obal.jpg" stays); the longest name wins
        $cssNames = array_keys($v['css']);
        usort($cssNames, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $cssPattern = '/(?<![A-Za-z0-9_-])(' . implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), $cssNames)) . ')/';
        $css = fn (string $text): string => str_contains($text, 'ka-') && !str_starts_with($text, 'media/')
            ? (string) preg_replace_callback($cssPattern, fn (array $m): string => $v['css'][$m[1]], $text) : $text;
        $placeholders = [];
        foreach ($v['placeholders'] as $czech => $english) {
            $placeholders['{{' . $czech . '}}'] = '{{' . $english . '}}';
        }
        /** Every string inside a build: class and variable names, the built-in placeholders of a collection item. */
        $strings = function (mixed $value) use (&$strings, $css, $placeholders): mixed {
            if (is_string($value)) {
                return strtr($css($value), $placeholders);
            }

            return is_array($value) ? array_map($strings, $value) : $value;
        };

        $style = function (array $style) use ($v, $css): array {
            $out = [];
            foreach ($style as $state => $properties) {
                $props = [];
                foreach (is_array($properties) ? $properties : [] as $k => $value) {
                    $map = match (true) {
                        in_array($k, $v['color_properties'], true) => $v['colors'],
                        $k === 'typ_styl' => $v['text_styles'],
                        $k === 'zaobleni' => $v['radii'],
                        default => [],
                    };
                    $props[$v['style'][$k] ?? $k] = is_string($value) ? ($map[$value] ?? $css($value)) : $value;
                }
                $out[$v['states'][$state] ?? $state] = is_array($properties) ? $props : $properties;
            }

            return $out;
        };

        $item = function (array $item) use ($v): array {
            $out = [];
            foreach ($item as $k => $value) {
                $out[$v['items'][$k] ?? $k] = is_string($value) && isset($v['item_values'][$k]) ? ($v['item_values'][$k][$value] ?? $value) : $value;
            }

            return $out;
        };

        $content = function (string $type, array $content) use ($v, $item): array {
            $out = [];
            foreach ($content as $k => $value) {
                $out[$v['content_by_type'][$type][$k] ?? $v['content'][$k] ?? $k] = match (true) {
                    is_string($value) => $v['values'][$k][$value] ?? $value,
                    // component values keep the component's own keys
                    is_array($value) && array_is_list($value) && $k !== 'hodnoty' => array_map(fn (mixed $i): mixed => is_array($i) ? $item($i) : $i, $value),
                    default => $value,
                };
            }

            return $out;
        };

        $conditions = function (array $conditions) use ($v): array {
            $out = [];
            foreach ($conditions as $k => $value) {
                if ($k === 'parametr' && is_array($value)) {
                    $value = array_combine(array_map(fn (int|string $p): int|string => $v['url_parameter'][$p] ?? $p, array_keys($value)), $value);
                }
                $out[$v['conditions'][$k] ?? $k] = is_string($value) ? ($v['condition_values'][$value] ?? $value) : $value;
            }

            return $out;
        };

        /** One element of a build in Talea words, with the component and booking references as public ids. */
        $element = function (mixed $node) use (&$element, $v, $content, $style, $conditions, $ref, $strings): mixed {
            if (!is_array($node)) {
                return $node;
            }
            $type = (string) ($node['typ'] ?? '');
            $out = [];
            foreach ($node as $k => $value) {
                $out[$v['node'][$k] ?? $k] = match ($k) {
                    'typ' => is_string($value) ? ($v['types'][$value] ?? $value) : $value,
                    'obsah' => is_array($value) ? $strings($content($type, $value)) : $value,
                    'styl' => is_array($value) ? $style($value) : $value,
                    'deti' => is_array($value) ? array_map($element, $value) : $value,
                    'podminky' => is_array($value) ? $conditions($value) : $value,
                    default => $value,
                };
            }
            $c = is_array($out['content'] ?? null) ? $out['content'] : null;
            if ($c !== null && ($out['type'] ?? '') === 'component' && isset($c['component'])) {
                $out['content']['component'] = (string) $ref('components', $c['component']);
            }
            if ($c !== null && ($out['type'] ?? '') === 'booking') {
                foreach (['service' => 'booking_services', 'staff_member' => 'booking_staff'] as $key => $table) {
                    if (isset($c[$key]) && $c[$key] !== '' && $c[$key] !== 0) {
                        $out['content'][$key] = (string) $ref($table, $c[$key]);
                    }
                }
            }

            return $out;
        };

        $build = function (mixed $value) use ($decode, $encode, $element): ?string {
            $b = $decode($value);
            if (!is_array($b)) {
                return null;
            }
            $out = [];
            foreach ($b as $k => $x) {
                $out[$k === 'deti' ? 'children' : $k] = $k === 'deti' && is_array($x) ? array_map($element, $x) : $x;
            }

            return $encode($out);
        };

        /** Collection fields and component properties. */
        $fields = function (mixed $value) use ($v, $decode, $warn): mixed {
            $list = $decode($value);
            if (!is_array($list)) {
                return $value;
            }

            return array_map(function (mixed $f) use ($v, $warn): mixed {
                if (!is_array($f)) {
                    return $f;
                }
                $out = [];
                foreach ($f as $k => $x) {
                    $out[$v['fields'][$k] ?? $k] = $k === 'typ' && is_string($x) ? ($v['field_types'][$x] ?? $x) : $x;
                }
                if (in_array($out['key'] ?? null, ['name', 'url', 'date', 'seo'], true)) {
                    $warn('field key "' . $out['key'] . '" is a built-in placeholder in Talea: the import renames it to ' . $out['key'] . '_2, check its {{placeholders}}');
                }

                return $out;
            }, $list);
        };

        $menu = function (mixed $items) use (&$menu, $v, $ref): array {
            $out = [];
            foreach (is_array($items) ? $items : [] as $i) {
                if (!is_array($i)) {
                    continue;
                }
                $o = [];
                foreach ($i as $k => $x) {
                    $o[$v['menu_items'][$k] ?? $k] = match ($k) {
                        'typ' => $v['menu_types'][$x] ?? $x,
                        'ikona' => $v['values']['ikona'][$x] ?? $x,
                        'ids' => $ref('pages', $x),
                        'deti' => $menu($x),
                        default => $x,
                    };
                }
                $out[] = $o;
            }

            return $out;
        };

        $designSystem = function (mixed $value) use ($v, $decode, $encode, $rename): string {
            $ds = $decode($value);
            if (!is_array($ds)) {
                return '';
            }
            $ds = $rename($ds, $v['design_system']);
            foreach (['colors', 'colors_dark'] as $k) {
                if (is_array($ds[$k] ?? null)) {
                    $ds[$k] = $rename($ds[$k], $v['colors']);
                }
            }
            foreach (['font_heading', 'font_body'] as $k) {
                if (is_string($ds[$k] ?? null)) {
                    $ds[$k] = preg_replace('/^vlastni-(\d)$/', 'custom-$1', $v['font_choices'][$ds[$k]] ?? $ds[$k]);
                }
            }
            if (is_array($ds['custom_fonts'] ?? null)) {
                $ds['custom_fonts'] = array_map(fn (mixed $f): mixed => is_array($f) ? $rename($f, $v['design_fonts']) : $f, $ds['custom_fonts']);
            }
            if (is_array($ds['typography'] ?? null) && !array_is_list($ds['typography'])) {
                $typography = [];
                foreach ($ds['typography'] as $key => $t) {
                    $typography[$v['design_typography_keys'][$key] ?? $key] = is_array($t) ? $rename($t, $v['design_typography']) : $t;
                }
                $ds['typography'] = $typography;
            }

            return $encode($ds);
        };

        /* ---------- rows ---------- */

        $convert = function (string $table, array $r) use ($v, $rename, $ref, $uuid, $decode, $encode, $build, $element, $fields, $menu, $style, $css): array {
            $r = $rename($r, $v['columns'][$table] ?? []);
            if (isset(self::KEYS[$table])) {
                $id = (int) ($r[self::KEYS[$table]] ?? 0);
                unset($r[self::KEYS[$table]]);
                if ($id > 0) {
                    $r = ['public_id' => $uuid($table, $id)] + $r;
                }
            }
            foreach (self::REFS[$table] ?? [] as $column => $to) {
                if (array_key_exists($column, $r)) {
                    $r[$column] = $ref($to, $r[$column]);
                }
            }
            foreach (['build', 'build_draft'] as $column) {
                if (array_key_exists($column, $r)) {
                    $r[$column] = $build($r[$column]);
                }
            }
            foreach (['text', 'intro'] as $column) {
                if (is_string($r[$column] ?? null) && in_array($table, ['pages', 'news'], true)) {
                    $r[$column] = $css($r[$column]);
                }
            }
            switch ($table) {
                case 'news':
                    $r['author'] = (string) ($r['autor'] ?? $r['author'] ?? '');
                    unset($r['autor']);
                    $r['tags'] = array_values(array_filter(array_map(fn (mixed $t): ?string => $ref('tags', $t), is_array($r['tags'] ?? null) ? $r['tags'] : [])));
                    break;
                case 'classes':
                    $s = $decode($r['style'] ?? '');
                    $r['style'] = $encode(is_array($s) && $s !== [] ? $style($s) : new \stdClass());
                    $r['css'] = $css((string) ($r['css'] ?? ''));
                    break;
                case 'site_parts':
                    $r['type'] = $v['site_part_types'][$r['type'] ?? ''] ?? ($r['type'] ?? '');
                    $pages = $decode($r['pages'] ?? null);
                    $r['pages'] = is_array($pages) ? $encode(array_values(array_filter(array_map(fn (mixed $p): ?string => $ref('pages', $p), $pages)))) : null;
                    unset($r['rules']);
                    break;
                case 'components':
                    $r['properties'] = $encode($fields($r['properties'] ?? '[]'));
                    break;
                case 'sections':
                    $e = $decode($r['element'] ?? '');
                    $r['element'] = is_array($e) ? $encode($element($e)) : null;
                    break;
                case 'menus':
                    $r['location'] = $v['menu_locations'][$r['location'] ?? ''] ?? ($r['location'] ?? '');
                    $r['items'] = $encode($menu($decode($r['items'] ?? '[]')));
                    break;
                case 'collections':
                    $r['fields'] = $encode($fields($r['fields'] ?? '[]'));
                    break;
                case 'popups':
                    $r['type'] = $v['popup_types'][$r['type'] ?? ''] ?? ($r['type'] ?? '');
                    $r['trigger_type'] = $v['popup_triggers'][$r['trigger_type'] ?? ''] ?? ($r['trigger_type'] ?? '');
                    $r['frequency'] = $v['popup_frequencies'][$r['frequency'] ?? ''] ?? ($r['frequency'] ?? '');
                    $rules = $decode($r['rules'] ?? '');
                    if (is_array($rules)) {
                        $o = [];
                        foreach ($rules as $k => $x) {
                            $o[$v['popup_rules'][$k] ?? $k] = match ($k) {
                                'stranky' => array_values(array_filter(array_map(fn (mixed $p): ?string => $ref('pages', $p), is_array($x) ? $x : []))),
                                default => is_string($x) ? ($v['popup_rule_values'][$k][$x] ?? $x) : $x,
                            };
                        }
                        $r['rules'] = $encode($o);
                    }
                    break;
            }

            return $r;
        };

        /* ---------- settings ---------- */

        $settings = $data['nastaveni'] ?? [];
        if (isset($settings['home_page'])) {
            $settings['home_page'] = (string) $ref('pages', $settings['home_page']);
        }
        if (isset($settings['dark_mode'])) {
            $settings['dark_mode'] = $v['dark_mode'][$settings['dark_mode']] ?? $settings['dark_mode'];
        }
        if (isset($settings['extensions']) && $settings['extensions'] !== '-') {
            $settings['extensions'] = implode(',', array_map(fn (string $e): string => $v['extensions'][$e] ?? $e, explode(',', (string) $settings['extensions'])));
            // what Kaleta has and left off stays off; a feature only Talea has keeps its default (SiteImport)
            $settings['extensions_off'] = implode(',', array_diff(array_keys(Extensions::CATALOG), self::TALEA_ONLY_FEATURES, Extensions::fromValue($settings['extensions'])));
        }
        if (isset($settings['design_system'])) {
            $settings['design_system'] = $designSystem($settings['design_system']);
        }

        /* ---------- content.json ---------- */

        $tmp = tempnam(sys_get_temp_dir(), 'talea-content-');
        $f = fopen($tmp, 'wb');
        // the layout of SiteExport (one row per line), so the import reads it as a stream
        fwrite($f, '{"format":"talea-export","format_version":3,"talea":' . $encode(TALEA_VERSION) . ',"created_at":' . $encode((string) ($data['vytvoreno'] ?? date('c')))
            . ',"converted_from":' . $encode('Kaleta ' . ($data['kaleta'] ?? '')) . ',"settings":' . $encode($settings));
        foreach ($v['tables'] as $czech => $table) {
            fwrite($f, ",\n" . $encode($table) . ':[');
            $first = true;
            foreach (is_array($data[$czech] ?? null) ? $data[$czech] : [] as $row) {
                if (!is_array($row) || ($table === 'pages' && !empty($row['smazano']))) {
                    continue; // the trash does not move (Talea's own export leaves it out too)
                }
                fwrite($f, ($first ? "\n" : ",\n") . $encode($convert($table, $row)));
                $first = false;
            }
            fwrite($f, "\n]");
        }
        fwrite($f, "}\n");
        fclose($f);
        foreach (array_diff(array_keys($data), [...array_keys($v['tables']), 'format', 'verze_formatu', 'kaleta', 'vytvoreno', 'nastaveni']) as $left) {
            if (is_array($data[$left]) && $data[$left] !== []) {
                $warn("$left (" . count($data[$left]) . " rows) has no place in a Talea export and was left out");
            }
        }

        /* ---------- self-check: Czech words left in the builds ---------- */

        $czech = [];
        foreach (['node', 'content', 'items', 'style', 'states'] as $map) {
            foreach ($v[$map] as $cs => $en) {
                if ($cs !== $en) {
                    $czech[$cs] = true;
                }
            }
        }
        $leftovers = [];
        $scan = function (mixed $node) use (&$scan, &$leftovers, $czech): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $x) {
                if (is_string($k) && isset($czech[$k]) && !in_array($k, ['text', 'html', 'src', 'alt', 'url', 'video', 'faq'], true)) {
                    $leftovers[$k] = ($leftovers[$k] ?? 0) + 1;
                }
                if (is_array($x) && $k !== 'values') {
                    $scan($x);
                }
            }
        };
        foreach (file($tmp) ?: [] as $line) {
            $row = json_decode(rtrim(trim($line), ','), true);
            foreach (['build', 'build_draft', 'element'] as $column) {
                if (is_array($row) && is_string($row[$column] ?? null)) {
                    $scan(json_decode($row[$column], true));
                }
            }
        }
        if (preg_match_all('/(?<![A-Za-z0-9_])-{0,2}ka-[a-z][a-z0-9_-]*/', (string) file_get_contents($tmp), $rest)) {
            foreach (array_count_values($rest[0]) as $name => $n) {
                $warn("Kaleta CSS name $name left as it is ($n times) – no Talea name is known");
            }
        }

        foreach ($leftovers as $word => $n) {
            $warn("Czech builder key \"$word\" left in builds ($n times)");
        }

        /* ---------- archive ---------- */

        try {
            if (!$isZip) {
                if (!copy($tmp, $out)) {
                    throw new \RuntimeException('Cannot write the converted export.');
                }

                return $warnings;
            }
            if (!copy($in, $out)) {
                throw new \RuntimeException('Cannot write the converted export.');
            }
            $zip = new \ZipArchive();
            $zip->open($out);
            $zip->deleteName('obsah.json');
            $zip->deleteName('README.txt');
            $zip->addFile($tmp, 'content.json');
            $zip->addFromString('README.txt', 'Talea site export converted from a Kaleta ' . ($data['kaleta'] ?? '') . ' export by Talea ' . TALEA_VERSION . ' on ' . date('Y-m-d H:i') . ".\n"
                . "content.json is a Talea export, format version 3; media/ is copied from the Kaleta archive unchanged.\n");
            if (!$zip->close()) {
                @unlink($out);
                throw new \RuntimeException('Cannot write the converted export.');
            }

            return $warnings;
        } finally {
            @unlink($tmp);
        }
    }
}
