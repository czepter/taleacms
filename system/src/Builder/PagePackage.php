<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;
use Talea\Core\Look;
use Talea\Core\Settings;

/**
 * What a page export carries besides the page (1.8): the shared classes and the components its build uses – components
 * inside components too – so the page looks the same on the other site.
 *
 * On import a class the target site already has is kept as it is (the site's look wins); a missing class is created.
 * A component with the same name and the same build is reused, any other is created, and the page's uses are pointed
 * at the new IDs. In the file a component is identified by its public id (a package from before public ids carries integers,
 * which only match inside the file); the integer keys of the database are never in it. Everything goes through the same validators as when saving in the builder.
 */
final class PagePackage
{
    /** @return array{classes: list<array{name: string, style: mixed, css: string}>, components: list<array{id: string, name: string, properties: array, build: array}>} */
    public static function collect(Db $db, array $build): array
    {
        $classes = [];
        $components = [];
        $queue = self::componentIds($build['children'] ?? [], $classes);
        while ($queue !== [] && count($components) < 50) {
            $idm = (int) array_shift($queue);
            if (isset($components[$idm]) || ($c = Components::byId($db, $idm)) === null) {
                continue;
            }
            $componentBuild = Build::fromJson($c['build'] ?? $c['build_draft']) ?? ['v' => Build::VERSION, 'children' => []];
            $components[$idm] = ['id' => $db->publicId('components', $idm), 'name' => $c['name'], 'properties' => $c['properties'], 'build' => self::toPublic($db, $componentBuild)];
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
            return [self::pointAt($pageBuild, []), $created]; // the other site's components mean nothing here
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
            $old = is_array($k) && is_scalar($k['id'] ?? null) ? (string) $k['id'] : '';
            $name = mb_substr(trim(strip_tags((string) ($k['name'] ?? ''))), 0, 100);
            if ($old !== '' && $old !== '0' && $name !== '' && !isset($pending[$old])) {
                $pending[$old] = [$name, (string) json_encode(Components::sanitizeProperties($k['properties'] ?? []), JSON_UNESCAPED_UNICODE),
                    is_array($k['build'] ?? null) ? $k['build'] : ['v' => Build::VERSION, 'children' => []]];
            }
        }
        $map = [];
        while ($pending !== []) {
            $ready = null;
            foreach ($pending as $old => [, , $componentBuild]) {
                $unused = [];
                if (array_intersect(array_diff(self::componentIds($componentBuild['children'] ?? [], $unused), [(string) $old]), array_map('strval', array_keys($pending))) === []) {
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
            \Talea\Front\Cache::clear();
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

    /**
     * The build with the component and booking references turned into public ids (what a file carries).
     *
     * @param array<string, mixed> $build
     * @return array<string, mixed>
     */
    public static function toPublic(Db $db, array $build): array
    {
        $map = fn (string $table, mixed $id): string|int => (int) $id > 0 ? ($db->publicId($table, (int) $id) ?: 0) : 0;

        return json_decode(\Talea\Core\SiteExport::mapReferences('build', (string) json_encode($build), $map), true) ?: $build;
    }

    /**
     * The opposite, for a build that stays on the site it came from: public ids back to this site's keys.
     *
     * @param array<string, mixed> $build
     * @return array<string, mixed>
     */
    public static function toInternal(Db $db, array $build): array
    {
        $map = fn (string $table, mixed $id): int => $db->internalId($table, $id);

        return json_decode(\Talea\Core\SiteExport::mapReferences('build', (string) json_encode($build), $map), true) ?: $build;
    }

    /** @param array<string, true> $classes collected class names @return list<string> the references (public ids; integers in an older file) of the components the elements use */
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
            if (($n['type'] ?? '') === 'component' && is_scalar($n['content']['component'] ?? null) && !in_array((string) $n['content']['component'], ['', '0'], true)) {
                $ids[] = (string) $n['content']['component'];
            }
            array_push($ids, ...self::componentIds(is_array($n['children'] ?? null) ? $n['children'] : [], $classes));
        }

        return $ids;
    }

    /**
     * The build with component uses pointed at this site: a component that did not come with the export is dropped from
     * the use (an empty component element), never pointed at an unrelated component of this site.
     *
     * @param array<int|string, int> $map exported reference (public id) => ID on this site
     */
    private static function pointAt(array $build, array $map): array
    {
        $walk = function (array $nodes) use (&$walk, $map): array {
            foreach ($nodes as $i => $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['type'] ?? '') === 'component' && isset($n['content']) && is_array($n['content'])) {
                    $ref = is_scalar($n['content']['component'] ?? null) ? (string) $n['content']['component'] : '';
                    $n['content']['component'] = $ref !== '' && isset($map[$ref]) ? (string) $map[$ref] : '';
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
