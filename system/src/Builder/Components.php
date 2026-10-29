<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;
use Talea\Core\Sites;

/**
 * Components – reusable blocks of the builder (table tl_components). Inside a component there are {{properties}} – the same
 * tags as in collections (Collections::fill) – and each use on a page (the "component" element) gives them its own values.
 */
final class Components
{
    /** Property types (a subset of collection fields). */
    public const array TYPES = ['text' => 'short text', 'lines' => 'longer text', 'html' => 'formatted text', 'image' => 'image', 'link' => 'link', 'radio' => 'choice from options'];

    /** Maximum nesting of components (a component in a component…). */
    public const int MAX_NESTING = 4;

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {components} WHERE ' . Sites::visible($db) . ' ORDER BY name'));
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idm): ?array
    {
        $r = $db->one('SELECT * FROM {components} WHERE component_id = ? AND ' . Sites::visible($db), [$idm]); // another site's own component is not found

        return $r === null ? null : self::extract($r);
    }

    /**
     * The builds of all components as the site shows them (published, otherwise the draft – as Elements\Component renders them), by id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function builds(Db $db): array
    {
        $builds = [];
        foreach ($db->all('SELECT component_id, build, build_draft FROM {components} WHERE ' . Sites::visible($db)) as $r) {
            $build = Build::fromJson($r['build'] ?? $r['build_draft']);
            if ($build !== null) {
                $builds[(int) $r['component_id']] = $build;
            }
        }

        return $builds;
    }

    /**
     * Adds a component: its own to the current site, or with $shared one every site of the owner can use (site_id NULL).
     *
     * @param array<string, mixed> $data
     */
    public static function create(Db $db, array $data, bool $shared = false): int
    {
        return $db->insert('components', $data + ['site_id' => $shared ? null : Sites::currentId($db)]);
    }

    /** Whether a component (a row of all()/byId()) is shared with every site. @param array<string, mixed> $component */
    public static function isShared(array $component): bool
    {
        return ($component['site_id'] ?? null) === null;
    }

    /**
     * Shares a component with every site of the owner. One way: other sites may already use it, so it is not made private again
     * (delete it to take it away). The caller has found the row with byId(), so it is one this site sees.
     */
    public static function share(Db $db, int $idm): void
    {
        $db->update('components', ['site_id' => null], ['component_id' => $idm]);
    }

    private static function extract(array $r): array
    {
        $r['properties'] = json_decode((string) $r['properties'], true) ?: [];

        return $r;
    }

    /**
     * Property definitions from the form or from AI: key, label, type and default value (checked by type); a choice keeps its options.
     *
     * @return list<array{key: string, label: string, type: string, default: string, options?: list<string>}>
     */
    public static function sanitizeProperties(mixed $input): array
    {
        // only rows with a label; the default value goes hand in hand with the field, the order must not diverge
        $rows = array_values(array_filter(is_array($input) ? $input : [], fn (mixed $v): bool => is_array($v) && trim(strip_tags((string) ($v['label'] ?? ''))) !== ''));
        $rows = array_slice($rows, 0, 30);
        $field = Collections::sanitizeFields(array_map(fn (array $v): array => ['type' => isset(self::TYPES[$v['type'] ?? '']) ? $v['type'] : 'text'] + $v, $rows));
        $defaults = Collections::sanitizeData($field, array_combine(array_column($field, 'key'), array_map(fn (array $v): string => is_scalar($v['default'] ?? null) ? (string) $v['default'] : '', $rows)));

        return array_map(fn (array $p): array => $p + ['default' => $defaults[$p['key']] ?? ''], $field);
    }

    /**
     * Values for {{tags}}: those given at the use, otherwise the defaults. They are checked by property type (image, link, HTML).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $component, array $given): array
    {
        $clean = Collections::sanitizeData($component['properties'], $given);
        $h = [];
        foreach ($component['properties'] as $v) {
            $h[$v['key']] = [($clean[$v['key']] ?? '') !== '' ? $clean[$v['key']] : (string) $v['default'], $v['type']];
        }

        return $h;
    }
}
