<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Partial edits of a build by element id – so that the language model (MCP) does not have to send the whole page to fix one link.
 * The operations run one after another on a copy; the result then goes through Build::sanitize() like any other build.
 *
 *   {"op":"update","id":"…","content":{…},"style":{"mobile":{"gap":"s","columns":null}},"classes":[…],"anchor":"…","tag":"…"}
 *       content and style are merged (null or "" removes a value), classes is replaced
 *   {"op":"replace","id":"…","element":{…}}
 *   {"op":"delete","id":"…"}
 *   {"op":"insert","elements":[…] (or "element"),"into":"parent id | null = root","position":0 | "after":"id" | "before":"id"}
 *   {"op":"move","id":"…","into":…,"position":… | "after":… | "before":…}
 */
final class Edits
{
    public const int MAX_OPERATIONS = 100;

    /**
     * @param array<string, mixed> $build
     * @param list<array<string, mixed>> $operations
     * @param array<string, string> $errors op[i] => error text (an operation with an error is skipped, the others run)
     * @return array<string, mixed>
     */
    public static function apply(array $build, array $operations, array &$errors = []): array
    {
        $root = ['id' => null, 'children' => array_values($build['children'] ?? [])];
        foreach (array_slice(array_values($operations), 0, self::MAX_OPERATIONS) as $i => $o) {
            $whereParts = 'op[' . $i . ']';
            if (!is_array($o)) {
                $errors[$whereParts] = 'Operace musí být objekt.';
                continue;
            }
            try {
                $root = self::applyOne($root, $o);
            } catch (\InvalidArgumentException $e) {
                $errors[$whereParts] = $e->getMessage();
            }
        }
        if (count($operations) > self::MAX_OPERATIONS) {
            $errors['op'] = 'Najednou jde provést nejvýš ' . self::MAX_OPERATIONS . ' operací – zbytek vynechán.';
        }

        return ['v' => $build['v'] ?? Build::VERSION, 'children' => $root['children']];
    }

    /** @return array<string, mixed> */
    private static function applyOne(array $root, array $o): array
    {
        $id = is_string($o['id'] ?? null) ? $o['id'] : '';
        switch ($o['op'] ?? '') {
            case 'update':
                return self::change($root, $id, function (array $p) use ($o): array {
                    if (is_array($o['content'] ?? null)) {
                        $p['content'] = self::merge(is_array($p['content'] ?? null) ? $p['content'] : [], $o['content']);
                    }
                    if (is_array($o['style'] ?? null)) {
                        $style = is_array($p['style'] ?? null) ? $p['style'] : [];
                        foreach ($o['style'] as $state => $properties) {
                            $style[$state] = $properties === null ? [] : self::merge(is_array($style[$state] ?? null) ? $style[$state] : [], (array) $properties);
                            if ($style[$state] === []) {
                                unset($style[$state]);
                            }
                        }
                        $p['style'] = $style;
                    }
                    foreach (['classes', 'anchor', 'tag', 'conditions', 'attributes', 'label'] as $key) {
                        if (array_key_exists($key, $o)) {
                            if ($o[$key] === null) {
                                unset($p[$key]);
                            } else {
                                $p[$key] = $o[$key];
                            }
                        }
                    }

                    return $p;
                });

            case 'replace':
                if (!is_array($o['element'] ?? null)) {
                    throw new \InvalidArgumentException('Chybí "prvek".');
                }

                return self::change($root, $id, fn (array $p): array => ['id' => $p['id']] + $o['element']);

            case 'delete':
                [$root, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }

                return $root;

            case 'insert':
                $elements = is_array($o['elements'] ?? null) ? array_values($o['elements']) : (is_array($o['element'] ?? null) ? [$o['element']] : []);
                if ($elements === []) {
                    throw new \InvalidArgumentException('Chybí "prvky" (pole prvků) nebo "prvek".');
                }

                return self::insert($root, $elements, $o);

            case 'move':
                [$without, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }
                if (isset($o['into']) && self::find($detached, (string) $o['into'])) {
                    throw new \InvalidArgumentException('Prvek nejde přesunout do sebe sama.');
                }

                return self::insert($without, [$detached], $o);
        }
        throw new \InvalidArgumentException('Neznámá operace (op): update | replace | delete | insert | move.');
    }

    /** Merges changes into an array: null or "" removes the key, anything else overwrites it. */
    private static function merge(array $previous, array $changes): array
    {
        foreach ($changes as $k => $v) {
            if ($v === null || $v === '') {
                unset($previous[$k]);
            } else {
                $previous[$k] = $v;
            }
        }

        return $previous;
    }

    /** Changes the element with the given id; when it is not in the build, reports an error. */
    private static function change(array $root, string $id, callable $change): array
    {
        $found = false;
        $root = self::changeInNode($root, $id, $change, $found);
        if (!$found) {
            throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není. Id najdeš ve stavba_nacti.');
        }

        return $root;
    }

    private static function changeInNode(array $node, string $id, callable $change, bool &$found): array
    {
        foreach ($node['children'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                $node['children'][$i] = $change($p);
                $found = true;

                return $node;
            }
            $node['children'][$i] = self::changeInNode($p, $id, $change, $found);
            if ($found) {
                return $node;
            }
        }

        return $node;
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} [tree without the element, the removed element] */
    private static function detach(array $node, string $id): array
    {
        foreach ($node['children'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                array_splice($node['children'], $i, 1);

                return [$node, $p];
            }
            [$new, $detached] = self::detach($p, $id);
            if ($detached !== null) {
                $node['children'][$i] = $new;

                return [$node, $detached];
            }
        }

        return [$node, null];
    }

    private static function find(array $node, string $id): bool
    {
        if (($node['id'] ?? null) === $id) {
            return true;
        }
        foreach ($node['children'] ?? [] as $p) {
            if (is_array($p) && self::find($p, $id)) {
                return true;
            }
        }

        return false;
    }

    /** Inserts elements into the parent „do“ (null = root) at a position, after the element „za“ or before the element „pred“; unspecified = at the end. */
    private static function insert(array $root, array $elements, array $o): array
    {
        $sibling = is_string($o['after'] ?? null) ? $o['after'] : (is_string($o['before'] ?? null) ? $o['before'] : null);
        if ($sibling !== null) {
            $done = false;
            $root = self::insertBeside($root, $sibling, $elements, isset($o['after']), $done);
            if (!$done) {
                throw new \InvalidArgumentException('Prvek „' . $sibling . '“ (za/pred) ve stavbě není.');
            }

            return $root;
        }
        $parent = isset($o['into']) && $o['into'] !== null && $o['into'] !== '' ? (string) $o['into'] : null;
        $position = isset($o['position']) && is_numeric($o['position']) ? (int) $o['position'] : null;
        if ($parent === null) {
            array_splice($root['children'], $position ?? count($root['children']), 0, $elements);

            return $root;
        }

        return self::change($root, $parent, function (array $p) use ($elements, $position): array {
            $p['children'] = array_values(is_array($p['children'] ?? null) ? $p['children'] : []);
            array_splice($p['children'], $position ?? count($p['children']), 0, $elements);

            return $p;
        });
    }

    private static function insertBeside(array $node, string $sibling, array $elements, bool $after, bool &$done): array
    {
        foreach ($node['children'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $sibling) {
                array_splice($node['children'], $after ? $i + 1 : $i, 0, $elements);
                $done = true;

                return $node;
            }
            $node['children'][$i] = self::insertBeside($p, $sibling, $elements, $after, $done);
            if ($done) {
                return $node;
            }
        }

        return $node;
    }
}
