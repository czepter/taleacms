<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Typed schema.org structured data (HF-11): the vocabulary in system/data/schemaorg.json, the only gate for what an editor or the AI
 * may put into a page (sanitize) and the conversion to a JSON-LD node (render). Nothing here talks to the network.
 *
 * A stored node is {type, fields}: fields hold plain values by property name; a nested thing is {type, fields} again, a property
 * that may repeat is a list. Anything the vocabulary does not know is dropped with a note, never emitted.
 */
final class StructuredData
{
    public const int MAX_DEPTH = 4;
    public const int MAX_LIST = 50;
    public const int MAX_TEXT = 500;
    public const int MAX_LONG_TEXT = 5000;

    /** @var array<string, mixed>|null */
    private static ?array $vocabulary = null;

    /** @return array<string, mixed> type => [label, description, extends, properties] */
    public static function vocabulary(): array
    {
        if (self::$vocabulary === null) {
            $data = json_decode((string) @file_get_contents(KALETA_SYSTEM . '/data/schemaorg.json'), true);
            self::$vocabulary = is_array($data['types'] ?? null) ? $data['types'] : [];
        }

        return self::$vocabulary;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::vocabulary());
    }

    /**
     * A compact description for the AI and the editor: per type its label and the properties with type and required/recommended flags.
     *
     * @return array<string, mixed>
     */
    public static function compact(?string $only = null): array
    {
        $out = [];
        foreach (self::vocabulary() as $type => $t) {
            if ($only !== null && $only !== $type) {
                continue;
            }
            $props = [];
            foreach ($t['properties'] as $name => $p) {
                $props[$name] = $p['type'] . ($p['type'] === 'thing' ? '(' . implode('|', $p['of'] ?? []) . ')' : '') . ($p['type'] === 'enum' ? '(' . implode('|', $p['options']) . ')' : '')
                    . (!empty($p['multiple']) ? '[]' : '') . ($p['required'] ? ' required' : ($p['recommended'] ? ' recommended' : ''));
            }
            $out[$type] = ['label' => $t['label'], 'description' => $t['description'], 'properties' => $props];
        }

        return $out;
    }

    /**
     * @param mixed $node {type, fields}
     * @return array{0: ?array{type: string, fields: array<string, mixed>}, 1: list<string>} the cleaned node (null when the type is unknown) and the notes about what was dropped
     */
    public static function sanitize(mixed $node, int $depth = 0): array
    {
        $notes = [];
        if (!is_array($node) || !is_string($node['type'] ?? null) || !isset(self::vocabulary()[$node['type']])) {
            return [null, ['unknown structured-data type']];
        }
        $type = $node['type'];
        $definition = self::vocabulary()[$type]['properties'];
        $fields = [];
        foreach ((array) ($node['fields'] ?? []) as $name => $value) {
            if (!is_string($name) || !isset($definition[$name])) {
                $notes[] = "$type: property " . (is_string($name) ? $name : '?') . ' is not known and was dropped';
                continue;
            }
            $p = $definition[$name];
            $values = !empty($p['multiple']) && is_array($value) && array_is_list($value) ? array_slice($value, 0, self::MAX_LIST) : [$value];
            $clean = [];
            foreach ($values as $v) {
                $c = self::value($p, $v, $depth, $notes, "$type.$name");
                if ($c !== null) {
                    $clean[] = $c;
                }
            }
            if ($clean !== []) {
                $fields[$name] = !empty($p['multiple']) ? $clean : $clean[0];
            }
        }

        return [['type' => $type, 'fields' => $fields], $notes];
    }

    /**
     * @param array<string, mixed> $p property definition
     * @param list<string> $notes
     */
    private static function value(array $p, mixed $v, int $depth, array &$notes, string $where): mixed
    {
        $drop = static function () use (&$notes, $where): null {
            $notes[] = "$where: the value is not valid and was dropped";

            return null;
        };
        switch ($p['type']) {
            case 'text':
                return is_scalar($v) && trim(strip_tags((string) $v)) !== '' ? mb_substr(trim(strip_tags((string) $v)), 0, self::MAX_TEXT) : null;
            case 'long_text':
                return is_scalar($v) && trim(strip_tags((string) $v)) !== '' ? mb_substr(trim(strip_tags((string) $v)), 0, self::MAX_LONG_TEXT) : null;
            case 'url':
            case 'image':
                return self::url($v) ?? ($v === '' || $v === null ? null : $drop());
            case 'date':
            case 'datetime':
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', trim($v)) === 1 ? trim($v) : ($v === '' || $v === null ? null : $drop());
            case 'number':
                return is_numeric($v) ? $v + 0 : ($v === '' || $v === null ? null : $drop());
            case 'integer':
                return is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) ? (int) $v : ($v === '' || $v === null ? null : $drop());
            case 'boolean':
                return is_bool($v) ? $v : (in_array($v, ['1', 1, 'true', 'yes'], true) ? true : (in_array($v, ['0', 0, 'false', 'no'], true) ? false : ($v === '' || $v === null ? null : $drop())));
            case 'duration':
                return is_string($v) && preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', trim($v)) === 1 ? trim($v) : ($v === '' || $v === null ? null : $drop());
            case 'enum':
                return is_string($v) && in_array($v, $p['options'], true) ? $v : ($v === '' || $v === null ? null : $drop());
            case 'thing':
                if ($depth >= self::MAX_DEPTH) {
                    return $drop();
                }
                if (!is_array($v)) {
                    return $v === '' || $v === null ? null : $drop();
                }
                $v['type'] ??= ($p['of'][0] ?? null);
                if (!in_array($v['type'], $p['of'] ?? [], true)) {
                    return $drop();
                }
                [$clean, $sub] = self::sanitize($v, $depth + 1);
                array_push($notes, ...$sub);

                return $clean !== null && $clean['fields'] !== [] ? $clean : null;
        }

        return null;
    }

    /** http(s) address or a path of this site; never javascript:, data: and the like. */
    private static function url(mixed $v): ?string
    {
        if (!is_string($v) || ($v = trim($v)) === '' || strlen($v) > 500) {
            return null;
        }
        if (str_starts_with($v, '/') && !str_starts_with($v, '//')) {
            return preg_match('#^/[^\s<>"\'\\\\]*$#', $v) === 1 ? $v : null;
        }

        return preg_match('#^https?://#i', $v) === 1 && filter_var($v, FILTER_VALIDATE_URL) !== false && !preg_match('/[<>"\'\s]/', $v) ? $v : null;
    }

    /**
     * The JSON-LD node of a cleaned node. $absolute turns a site path into an address (Seo passes the origin); enum values become schema.org addresses.
     *
     * @param array{type: string, fields: array<string, mixed>} $node
     * @return array<string, mixed>
     */
    public static function render(array $node, callable $absolute): array
    {
        $definition = self::vocabulary()[$node['type']]['properties'] ?? [];
        $out = ['@type' => $node['type']];
        foreach ($node['fields'] as $name => $value) {
            $p = $definition[$name] ?? null;
            if ($p === null) {
                continue;
            }
            $render = fn (mixed $v): mixed => match ($p['type']) {
                'url' => $absolute($v),
                'image' => ['@type' => 'ImageObject', 'url' => $absolute($v)],
                'enum' => 'https://schema.org/' . $v,
                'thing' => self::render($v, $absolute),
                default => $v,
            };
            $out[$name] = !empty($p['multiple']) ? array_map($render, (array) $value) : $render($value);
        }

        return $out;
    }

    /**
     * What is still missing for rich results.
     *
     * @param array{type: string, fields: array<string, mixed>} $node
     * @return array{required: list<string>, recommended: list<string>}
     */
    public static function missing(array $node): array
    {
        $out = ['required' => [], 'recommended' => []];
        foreach (self::vocabulary()[$node['type']]['properties'] ?? [] as $name => $p) {
            if (isset($node['fields'][$name])) {
                continue;
            }
            if ($p['required']) {
                $out['required'][] = $name;
            } elseif ($p['recommended']) {
                $out['recommended'][] = $name;
            }
        }

        return $out;
    }
}
