<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Partial edits of a build by element id – so that the language model (MCP) does not have to send the whole page to fix one link.
 * The operations run one after another on a copy; the result then goes through Build::sanitize() like any other build.
 *
 *   {"op":"uprav","id":"…","obsah":{…},"styl":{"mobil":{"mezera":"s","sloupce":null}},"tridy":[…],"kotva":"…","znacka":"…"}
 *       obsah and styl are merged (null or "" removes a value), tridy is replaced
 *   {"op":"nahrad","id":"…","prvek":{…}}
 *   {"op":"smaz","id":"…"}
 *   {"op":"vloz","prvky":[…] (or "prvek"),"do":"parent id | null = root","pozice":0 | "za":"id" | "pred":"id"}
 *   {"op":"presun","id":"…","do":…,"pozice":… | "za":… | "pred":…}
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
        $root = ['id' => null, 'deti' => array_values($build['deti'] ?? [])];
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

        return ['v' => $build['v'] ?? Build::VERSION, 'deti' => $root['deti']];
    }

    /** @return array<string, mixed> */
    private static function applyOne(array $root, array $o): array
    {
        $id = is_string($o['id'] ?? null) ? $o['id'] : '';
        switch ($o['op'] ?? '') {
            case 'uprav':
                return self::change($root, $id, function (array $p) use ($o): array {
                    if (is_array($o['obsah'] ?? null)) {
                        $p['obsah'] = self::merge(is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $o['obsah']);
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
                    foreach (['tridy', 'kotva', 'znacka', 'podminky', 'atributy', 'popis'] as $key) {
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

            case 'nahrad':
                if (!is_array($o['element'] ?? null)) {
                    throw new \InvalidArgumentException('Chybí "prvek".');
                }

                return self::change($root, $id, fn (array $p): array => ['id' => $p['id']] + $o['element']);

            case 'smaz':
                [$root, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }

                return $root;

            case 'vloz':
                $elements = is_array($o['prvky'] ?? null) ? array_values($o['prvky']) : (is_array($o['element'] ?? null) ? [$o['element']] : []);
                if ($elements === []) {
                    throw new \InvalidArgumentException('Chybí "prvky" (pole prvků) nebo "prvek".');
                }

                return self::insert($root, $elements, $o);

            case 'presun':
                [$without, $detached] = self::detach($root, $id);
                if ($detached === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }
                if (isset($o['do']) && self::find($detached, (string) $o['do'])) {
                    throw new \InvalidArgumentException('Prvek nejde přesunout do sebe sama.');
                }

                return self::insert($without, [$detached], $o);
        }
        throw new \InvalidArgumentException('Neznámá operace (op): uprav | nahrad | smaz | vloz | presun.');
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
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                $node['deti'][$i] = $change($p);
                $found = true;

                return $node;
            }
            $node['deti'][$i] = self::changeInNode($p, $id, $change, $found);
            if ($found) {
                return $node;
            }
        }

        return $node;
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} [tree without the element, the removed element] */
    private static function detach(array $node, string $id): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                array_splice($node['deti'], $i, 1);

                return [$node, $p];
            }
            [$new, $detached] = self::detach($p, $id);
            if ($detached !== null) {
                $node['deti'][$i] = $new;

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
        foreach ($node['deti'] ?? [] as $p) {
            if (is_array($p) && self::find($p, $id)) {
                return true;
            }
        }

        return false;
    }

    /** Inserts elements into the parent „do“ (null = root) at a position, after the element „za“ or before the element „pred“; unspecified = at the end. */
    private static function insert(array $root, array $elements, array $o): array
    {
        $sibling = is_string($o['za'] ?? null) ? $o['za'] : (is_string($o['pred'] ?? null) ? $o['pred'] : null);
        if ($sibling !== null) {
            $done = false;
            $root = self::insertBeside($root, $sibling, $elements, isset($o['za']), $done);
            if (!$done) {
                throw new \InvalidArgumentException('Prvek „' . $sibling . '“ (za/pred) ve stavbě není.');
            }

            return $root;
        }
        $parent = isset($o['do']) && $o['do'] !== null && $o['do'] !== '' ? (string) $o['do'] : null;
        $position = isset($o['position']) && is_numeric($o['position']) ? (int) $o['position'] : null;
        if ($parent === null) {
            array_splice($root['deti'], $position ?? count($root['deti']), 0, $elements);

            return $root;
        }

        return self::change($root, $parent, function (array $p) use ($elements, $position): array {
            $p['deti'] = array_values(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            array_splice($p['deti'], $position ?? count($p['deti']), 0, $elements);

            return $p;
        });
    }

    private static function insertBeside(array $node, string $sibling, array $elements, bool $after, bool &$done): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $sibling) {
                array_splice($node['deti'], $after ? $i + 1 : $i, 0, $elements);
                $done = true;

                return $node;
            }
            $node['deti'][$i] = self::insertBeside($p, $sibling, $elements, $after, $done);
            if ($done) {
                return $node;
            }
        }

        return $node;
    }
}
