<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Kaleta 2.0 has one pop-up system: the per-page Modal element (type "okno") of 1.x becomes a site pop-up (ka_popupy).
 *
 * Each Modal found in a build turns into a pop-up of the type "window" shown only where the Modal was (its page, its
 * collection's item pages, the pages of its header or footer variant), with the same content, trigger and frequency:
 * "only via a link" becomes the trigger "click", after N seconds / after half the page / on leaving stay, "once a week"
 * becomes "once in 7 days", "never again" becomes "until closed". Links to the old anchor (#nabidka) are rewritten to the
 * pop-up (#popup-nabidka); the old anchor stays on the content, so an address like /page#nabidka still opens it.
 *
 * Used by the database migration 0034 and by the import of a 1.x export (Core\SiteImport).
 */
final class ModalConversion
{
    /** The Modal's "open automatically" => [pop-up trigger, value]. */
    private const array TRIGGERS = ['0' => ['klik', 0], '5' => ['cas', 5], '15' => ['cas', 15], '30' => ['cas', 30], 'translate' => ['translate', 50], 'odchod' => ['odchod', 0]];

    /** The Modal's "open again" => [pop-up frequency, days]. */
    private const array FREQUENCIES = ['relace' => ['relace', 7], 'tyden' => ['dni', 7], 'nikdy' => ['zavreni', 7]];

    /** Tables with builds: table => [key column, columns with builds]. */
    private const array SOURCES = ['pages' => ['page_id', ['build', 'build_draft']], 'site_parts' => [null, ['build', 'build_draft']],
        'collections' => ['collection_id', ['build', 'build_draft']], 'collection_templates' => [null, ['build', 'build_draft']],
        'components' => ['component_id', ['build', 'build_draft']], 'popups' => ['popup_id', ['build', 'build_draft']]];

    /** Converts every Modal on the site (the migration). Returns how many pop-ups were created. */
    public static function run(Db $db): int
    {
        $created = 0;
        foreach (self::SOURCES as $table => [$key, $columns]) {
            foreach ($db->all('SELECT * FROM {' . $table . '} WHERE build LIKE ? OR build_draft LIKE ?', ['%"type":"okno"%', '%"type":"okno"%']) as $row) {
                $builds = [];
                foreach ($columns as $column) {
                    $builds[$column] = $row[$column] === null ? null : (json_decode((string) $row[$column], true) ?: null);
                }
                [$builds, $count] = self::convertRow($db, $table, $row, $builds);
                $created += $count;
                $changes = array_map(fn (?array $b): ?string => $b === null ? null : Build::toJson($b), $builds);
                $db->update($table, $changes, self::where($table, $key, $row));
            }
        }
        // saved sections of the builder: the Modal's content stays, without the window around it
        foreach ($db->all('SELECT section_id, element FROM {sections} WHERE element LIKE ?', ['%"type":"okno"%']) as $r) {
            $element = json_decode((string) $r['element'], true);
            if (is_array($element)) {
                $db->update('sections', ['element' => (string) json_encode(self::unwrap(['children' => [$element]])['children'][0] ?? $element, JSON_UNESCAPED_UNICODE)], ['section_id' => $r['section_id']]);
            }
        }
        if ($created > 0) {
            \Kaleta\Front\Cache::clear();
        }

        return $created;
    }

    /**
     * One row (a page, a site part, a collection template, a component, a pop-up): pop-ups out of its Modals, the builds
     * without them and with links to the pop-ups. A Modal inside a pop-up only loses its window.
     *
     * @param array<string, ?array> $builds column => decoded build
     * @return array{0: array<string, ?array>, 1: int} the new builds and the number of pop-ups created
     */
    public static function convertRow(Db $db, string $table, array $row, array $builds): array
    {
        if ($table === 'popups') {
            return [array_map(fn (?array $b): ?array => $b === null ? null : self::unwrap($b), $builds), 0];
        }
        // the same Modal is usually in the published build and in the draft: one pop-up with both
        $found = [];
        foreach ($builds as $column => $build) {
            if ($build === null) {
                continue;
            }
            [$builds[$column], $modals] = self::extract($build);
            foreach ($modals as $m) {
                $found[self::anchor($m)][$column] = $m;
            }
        }
        $map = [];
        foreach ($found as $anchor => $versions) {
            $map[$anchor] = self::popup($db, $versions['build'] ?? null, $versions['build_draft'] ?? null, self::rules($db, $table, $row), self::name($table, $row, $versions['build'] ?? $versions['build_draft']));
        }
        foreach ($builds as $column => $build) {
            if ($build !== null && $map !== []) {
                $builds[$column] = json_decode(self::rewriteLinks((string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $map), true);
            }
        }

        return [$builds, count($map)];
    }

    /** @return array{0: array, 1: list<array>} the build without Modals (at any depth) and the Modals taken out */
    public static function extract(array $build): array
    {
        $modals = [];
        $walk = function (array $nodes) use (&$walk, &$modals): array {
            $out = [];
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['type'] ?? '') === 'okno') {
                    $modals[] = $n;
                    continue;
                }
                if (is_array($n['children'] ?? null)) {
                    $n['children'] = $walk($n['children']);
                }
                $out[] = $n;
            }

            return $out;
        };
        $build['children'] = $walk(is_array($build['children'] ?? null) ? $build['children'] : []);

        return [$build, $modals];
    }

    /** The anchor a link opened the Modal with: an id among its attributes, its own anchor, otherwise okno-<id>. */
    public static function anchor(array $modal): string
    {
        $id = $modal['attributes']['id'] ?? $modal['anchor'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'okno-' . (string) ($modal['id'] ?? '');
    }

    /** Creates the pop-up; returns its address (the link #popup-<address> opens it). */
    public static function popup(Db $db, ?array $published, ?array $draft, array $rules, string $name): string
    {
        $modal = $published ?? $draft ?? [];
        $content = is_array($modal['content'] ?? null) ? $modal['content'] : [];
        [$trigger, $value] = self::TRIGGERS[(string) ($content['samo'] ?? '0')] ?? self::TRIGGERS['0'];
        [$frequency, $days] = self::FREQUENCIES[(string) ($content['znovu'] ?? 'relace')] ?? self::FREQUENCIES['relace'];
        $conditions = is_array($modal['conditions'] ?? null) ? $modal['conditions'] : [];
        $rules = Popups::sanitizeRules($rules + ['od' => $conditions['from'] ?? '', 'do' => $conditions['to'] ?? '']);
        $anchor = self::anchor($modal);
        $address = Popups::address($db, $anchor);

        return (string) $db->value('SELECT slug FROM {popups} WHERE popup_id = ?', [$db->insert('popups', [
            'name' => mb_substr($name, 0, 100), 'slug' => $address, 'type' => 'okno', 'trigger_type' => $trigger, 'value' => $value,
            'rules' => (string) json_encode($rules, JSON_UNESCAPED_UNICODE), 'frequency' => $frequency, 'days' => $days, 'active' => $published !== null ? 1 : 0, 'sort_order' => 100,
            'build' => $published !== null ? self::content($published, $anchor) : null,
            'build_draft' => $draft !== null && $draft !== $published ? self::content($draft, $anchor) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ])]);
    }

    /** The pop-up's build: the Modal's content in a container that keeps its style and the old anchor. */
    private static function content(array $modal, string $anchor): string
    {
        $container = ['type' => 'container', 'children' => is_array($modal['children'] ?? null) ? $modal['children'] : []]
            + array_intersect_key($modal, array_flip(['style', 'classes', 'css']))
            + (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $anchor) && !preg_match('/^(s|ka)-/', $anchor) ? ['anchor' => $anchor] : []);
        [$clean] = Build::sanitize(['v' => Build::VERSION, 'children' => [$container]], true);

        return Build::toJson($clean);
    }

    /** Where the pop-up shows: where the Modal was. */
    private static function rules(Db $db, string $table, array $row): array
    {
        $pages = fn (mixed $json): array => array_values(array_filter(array_map('intval', is_array($json) ? $json : (json_decode((string) $json, true) ?: [])), fn (int $i): bool => $i > 0));

        return match ($table) {
            'pages' => ['kde' => 'vybrane', 'pages' => [(int) $row['page_id']]],
            'site_parts' => $pages($row['pages'] ?? null) !== [] ? ['kde' => 'vybrane', 'pages' => $pages($row['pages'])] : ['kde' => 'vse', 'language' => (string) ($row['language'] ?? '')],
            'collections' => ['kde' => 'vybrane', 'kolekce' => [(string) $row['slug']]],
            'collection_templates' => ['kde' => 'vybrane', 'kolekce' => [(string) $db->value('SELECT slug FROM {collections} WHERE collection_id = ?', [$row['collection_id']])], 'language' => (string) ($row['language'] ?? '')],
            default => ['kde' => 'vse'], // a component: wherever it is used – check the rules after the update
        };
    }

    private static function name(string $table, array $row, array $modal): string
    {
        $label = trim((string) ($modal['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $where = (string) ($row['title'] ?? $row['name'] ?? $row['type'] ?? $table);

        return 'Pop-up from “' . $where . '”';
    }

    /** Links to the old anchor (#nabidka in a button or in text) lead to the pop-up (#popup-<address>). */
    public static function rewriteLinks(string $json, array $map): string
    {
        foreach ($map as $anchor => $address) {
            $json = str_replace(['"#' . $anchor . '"', '\\"#' . $anchor . '\\"'], ['"#popup-' . $address . '"', '\\"#popup-' . $address . '\\"'], $json);
        }

        return $json;
    }

    /** A Modal in a pop-up or a saved section: its children stay, in a container instead of the window. */
    private static function unwrap(array $build): array
    {
        $walk = function (array $nodes) use (&$walk): array {
            foreach ($nodes as $i => $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['type'] ?? '') === 'okno') {
                    $n = ['type' => 'container'] + array_intersect_key($n, array_flip(['id', 'style', 'classes', 'css', 'anchor', 'children']));
                }
                if (is_array($n['children'] ?? null)) {
                    $n['children'] = $walk($n['children']);
                }
                $nodes[$i] = $n;
            }

            return $nodes;
        };
        $build['children'] = $walk(is_array($build['children'] ?? null) ? $build['children'] : []);

        return $build;
    }

    /** @return array<string, mixed> the WHERE of a row (site parts and collection templates have composite keys) */
    private static function where(string $table, ?string $key, array $row): array
    {
        return match ($table) {
            'site_parts' => ['type' => $row['type'], 'language' => $row['language'], 'variant' => $row['variant']],
            'collection_templates' => ['collection_id' => $row['collection_id'], 'language' => $row['language']],
            default => [(string) $key => $row[$key]],
        };
    }
}
