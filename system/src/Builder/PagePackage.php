<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\Look;
use Kaleta\Core\Settings;

/**
 * What a page export carries besides the page (1.8): the shared classes and the components its build uses – components
 * inside components too – so the page looks the same on the other site.
 *
 * On import a class the target site already has is kept as it is (the site's look wins); a missing class is created.
 * A component with the same name and the same build is reused, any other is created, and the page's uses are pointed
 * at the new IDs. Everything goes through the same validators as when saving in the builder.
 */
final class PagePackage
{
    /** @return array{classes: list<array{name: string, style: mixed, css: string}>, components: list<array{id: int, name: string, properties: array, build: array}>} */
    public static function collect(Db $db, array $build): array
    {
        $classes = [];
        $components = [];
        $queue = self::componentIds($build['children'] ?? [], $classes);
        while ($queue !== [] && count($components) < 50) {
            $idm = array_shift($queue);
            if (isset($components[$idm]) || ($c = Components::byId($db, $idm)) === null) {
                continue;
            }
            $componentBuild = Build::fromJson($c['build'] ?? $c['build_draft']) ?? ['v' => Build::VERSION, 'children' => []];
            $components[$idm] = ['id' => $idm, 'name' => $c['name'], 'properties' => $c['properties'], 'build' => $componentBuild];
            array_push($queue, ...self::componentIds($componentBuild['children'] ?? [], $classes));
        }
        $rows = $classes === [] ? [] : $db->all('SELECT name, style, css FROM {classes} WHERE name IN (' . implode(',', array_fill(0, count($classes), '?')) . ') ORDER BY name', array_keys($classes));

        return [
            'classes' => array_map(fn (array $r): array => ['name' => $r['name'], 'style' => json_decode((string) $r['style'], true) ?: new \stdClass(), 'css' => (string) $r['css']], $rows),
            'components' => array_values($components),
        ];
    }

    /**
     * Creates the missing classes and the components of an export and returns the page build with the component uses
     * pointed at this site. Only an administrator changes shared classes and components; for others the page comes alone.
     *
     * @return array{0: array, 1: array{classes: int, components: int}} the page build (not yet sanitized) and what was created
     */
    public static function import(Settings $s, array $data, array $pageBuild, bool $admin): array
    {
        $created = ['classes' => 0, 'components' => 0];
        if (!$admin) {
            return [$pageBuild, $created];
        }
        $db = $s->db();
        foreach (array_slice(is_array($data['classes'] ?? null) ? $data['classes'] : [], 0, 200) as $t) {
            $name = is_array($t) ? (string) ($t['name'] ?? '') : '';
            if (!preg_match(Build::CLASS_PATTERN, $name) || $db->value('SELECT 1 FROM {classes} WHERE name = ?', [$name]) !== null) {
                continue; // the target site's own class stays
            }
            $errors = [];
            $discarded = [];
            Look::setClass($s, $name, ['style' => Style::sanitize(is_array($t['style'] ?? null) ? $t['style'] : [], $name, $errors), 'css' => Style::customCss((string) ($t['css'] ?? ''), $discarded)]);
            $created['classes']++;
        }
        // components: a component used inside another one goes first, so the outer one can point at its final ID
        $pending = [];
        foreach (array_slice(is_array($data['components'] ?? null) ? $data['components'] : [], 0, 50) as $k) {
            $old = is_array($k) ? (int) ($k['id'] ?? 0) : 0;
            $name = mb_substr(trim(strip_tags((string) ($k['name'] ?? ''))), 0, 100);
            if ($old > 0 && $name !== '' && !isset($pending[$old])) {
                $pending[$old] = [$name, (string) json_encode(Components::sanitizeProperties($k['properties'] ?? []), JSON_UNESCAPED_UNICODE),
                    is_array($k['build'] ?? null) ? $k['build'] : ['v' => Build::VERSION, 'children' => []]];
            }
        }
        $map = [];
        while ($pending !== []) {
            $ready = null;
            foreach ($pending as $old => [, , $componentBuild]) {
                $unused = [];
                if (array_intersect(array_diff(self::componentIds($componentBuild['children'] ?? [], $unused), [$old]), array_keys($pending)) === []) {
                    $ready = $old;
                    break;
                }
            }
            $ready ??= array_key_first($pending); // components using each other: the loop is broken at the first one
            [$name, $properties, $componentBuild] = $pending[$ready];
            unset($pending[$ready]);
            [$clean] = Build::sanitize(self::pointAt($componentBuild, $map), true);
            $json = Build::toJson($clean);
            $same = null;
            foreach ($db->all('SELECT component_id, build FROM {components} WHERE (name = ? OR name LIKE ?) AND properties = ? ORDER BY component_id', [$name, addcslashes($name, '%_\\') . ' (%)', $properties]) as $candidate) {
                if (self::withoutIds((array) Build::fromJson($candidate['build'])) === self::withoutIds($clean)) {
                    $same = (int) $candidate['component_id'];
                    break;
                }
            }
            if ($same !== null) {
                $map[$ready] = (int) $same; // the same component imported earlier (another page of the same site)

                continue;
            }
            $unique = $name;
            for ($i = 2; $db->value('SELECT 1 FROM {components} WHERE name = ?', [$unique]) !== null && $i < 100; $i++) {
                $unique = mb_substr($name, 0, 94) . ' (' . $i . ')';
            }
            $map[$ready] = $db->insert('components', ['name' => $unique, 'properties' => $properties, 'build' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
            $created['components']++;
        }
        if ($created !== ['classes' => 0, 'components' => 0]) {
            \Kaleta\Front\Cache::clear();
        }

        return [self::pointAt($pageBuild, $map), $created];
    }

    /** The build without element IDs (they are generated anew on every save) – for comparing two builds. */
    private static function withoutIds(array $build): array
    {
        unset($build['id']);
        foreach ($build as $key => $value) {
            if (is_array($value)) {
                $build[$key] = self::withoutIds($value);
            }
        }

        return $build;
    }

    /** @param array<string, true> $classes collected class names @return list<int> components the elements use */
    private static function componentIds(array $nodes, array &$classes): array
    {
        $ids = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            foreach (is_array($n['classes'] ?? null) ? $n['classes'] : [] as $t) {
                if (is_string($t) && preg_match(Build::CLASS_PATTERN, $t)) {
                    $classes[$t] = true;
                }
            }
            if (($n['type'] ?? '') === 'component' && (int) ($n['content']['component'] ?? 0) > 0) {
                $ids[] = (int) $n['content']['component'];
            }
            array_push($ids, ...self::componentIds(is_array($n['children'] ?? null) ? $n['children'] : [], $classes));
        }

        return $ids;
    }

    /**
     * The build with component uses pointed at this site: a component that did not come with the export is dropped from
     * the use (an empty component element), never pointed at an unrelated component of this site.
     *
     * @param array<int, int> $map exported ID => ID on this site
     */
    private static function pointAt(array $build, array $map): array
    {
        $walk = function (array $nodes) use (&$walk, $map): array {
            foreach ($nodes as $i => $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['type'] ?? '') === 'component' && isset($n['content']) && is_array($n['content'])) {
                    $id = (int) ($n['content']['component'] ?? 0);
                    $n['content']['component'] = isset($map[$id]) ? (string) $map[$id] : '';
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
}
