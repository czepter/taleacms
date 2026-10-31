<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * What the builder offers, described for the editor, MCP (builder_schema) and the contracts: element types with their fields,
 * style properties, states and design-system tokens. Elements of switched-off extensions are left out.
 */
final class ElementSchema
{
    /**
     * Schema description for the editor and for language models (MCP builder_schema): element types with fields, style properties and tokens.
     *
     * @return array<string, mixed>
     */
    /** @param list<string>|null $extensions enabled extensions (null = all) – elements of disabled ones are not offered */
    public static function schema(bool $admin = true, string $language = 'en', bool $parts = false, ?array $extensions = null): array
    {
        // the default content of new elements is in the page language, the editor translates field labels into the admin language
        return \Talea\Core\Language::runWith($language, fn (): array => self::buildSchema($admin, $parts, $extensions));
    }

    /**
     * builder_schema (MCP): the schema in brief for the language model – each element and style property on one line (enumerations
     * have the default value marked with an asterisk, items list their fields in square brackets) – or, for the chosen element
     * types (or all of them with $full), their full definitions with labels, default style and default children.
     *
     * @param array<string, mixed> $schema output of schema()
     * @param list<string> $only element types for full definitions
     * @return array<string, mixed>
     */
    public static function overview(array $schema, array $only = [], bool $full = false): array
    {
        $admin = fn (string $text): string => \Talea\Core\Language::runWith('en', fn (): string => t($text), 'admin-');
        $definitions = [];
        $lines = [];
        foreach ($schema['elements'] as $p) {
            if ($full || in_array($p['type'], $only, true)) {
                $definitions[] = ['type' => $p['type'], 'name' => $admin($p['name']), 'description' => $admin($p['description']), 'container' => $p['container'], 'tags' => $p['tags'],
                    'fields' => self::fieldDefinitions((array) $p['properties'], $admin), 'default_style' => (array) $p['default_style'] ?: new \stdClass(), 'default_children' => $p['default_children']];
            }
            $style = [];
            foreach ((array) $p['default_style'] as $state => $properties) {
                foreach ((array) $properties as $k => $h) {
                    $style[] = ($state === 'base' ? '' : $state . '.') . $k . '=' . (is_string($h) ? $h : json_encode($h));
                }
            }
            $fields = self::fieldLine((array) $p['properties']);
            $lines[$p['type']] = $admin($p['name']) . ' – ' . $admin($p['description']) . ($p['container'] ? ' [CONTAINER]' : '') . ' | tags: ' . implode(',', $p['tags'])
                . ($fields !== '' ? ' | content: ' . $fields : '')
                . ($style !== [] ? ' | style of a new element in the builder: ' . implode(', ', $style) . ' (write it in the JSON yourself, otherwise the element has none)' : '');
        }
        if ($only !== [] && !$full) {
            return ['elements' => $definitions];
        }
        $style = [];
        foreach ($schema['style'] as $key => $v) {
            $style[$key] = $v['css'] . ': ' . (isset($v['options']) ? implode('|', array_map('strval', array_keys($v['options']))) : $v['type']);
        }
        $tokens = $schema['tokens'];
        $colors = array_keys($tokens['colors']);

        return [
            'version' => $schema['version'], 'elements' => $full ? $definitions : $lines, 'style' => $style, 'states' => $schema['states'],
            'tokens' => ['colors' => $colors, 'spaces' => $tokens['spaces'], 'steps' => $tokens['steps'], 'radii' => array_map('strval', $tokens['radii']), 'shadows' => $tokens['shadows']],
            'value_types' => [
                'space' => 'token ' . implode('|', $tokens['spaces']) . ' or a length (1.5rem)', 'step' => 'font size: token ' . implode('|', $tokens['steps']) . ' or a length',
                'color' => 'token (' . implode('|', $colors) . ') or #hex', 'radius' => 'token ' . implode('|', array_map('strval', $tokens['radii'])) . ' or a length',
                'shadow' => 'token s|m|l, none or “x y blur colour”', 'border' => 'an option or “2px solid colour”', 'length' => 'px, rem, %, vw, fr, auto, min()/max()/clamp()/calc()',
                'columns' => 'a number 1–12, “auto:16rem” (as many as fit) or “2fr 1fr”', 'rows' => 'a number or “auto 1fr”', 'areas' => 'rows separated by /, e.g. “a a / b c”',
                'focal' => 'a point in the picture, “x% y%” with whole numbers 0–100 (e.g. “62% 38%”); focal_point on an image, background_focal where the element has a background image or video',
                'column_line' => 'a grid line 1–13 (Compose section children)', 'row_line' => 'a grid line 1–40 (Compose section children)',
            ],
            'node' => '{"id":"(optional, keep it when editing)","type":"…","tag":"(one of the tags; the first is the default)","content":{…},"style":{"base":{…},"tablet":{…},"mobile":{…},"hover":{…}},"classes":["…"],"anchor":"id-for-links","children":[…]}; leave out empty fields and default values',
            'conditions' => 'optional "conditions" of a node: {"signed_in":"yes|no","from":"YYYY-MM-DD","to":"YYYY-MM-DD","languages":["","de"] ("" = the default language),"url_parameter":{"name":"utm_campaign","value":"jaro"}} – the element is rendered only when all of them hold',
            'rules' => $schema['rules'],
        ];
    }

    /** Full field definitions of an element (builder_schema with element types). @param array<string, array<string, mixed>> $properties */
    private static function fieldDefinitions(array $properties, callable $admin): array
    {
        $out = [];
        foreach ($properties as $key => $v) {
            $field = ['type' => $v['type'], 'label' => $admin((string) ($v['label'] ?? ''))];
            if (array_key_exists('default', $v)) {
                $field['default'] = $v['default'];
            }
            if (isset($v['options']) && is_array($v['options'])) {
                $field['options'] = array_map('strval', array_is_list($v['options']) ? $v['options'] : array_keys($v['options']));
            }
            if (isset($v['fields']) && is_array($v['fields'])) {
                $item = [];
                foreach ($v['fields'] as $k => $f) {
                    $item[$k] = ['type' => $f['type'], 'label' => $admin((string) ($f['label'] ?? ''))]
                        + (isset($f['options']) && is_array($f['options']) ? ['options' => array_map('strval', array_keys($f['options']))] : []);
                }
                $field['item_fields'] = $item;
            }
            $out[$key] = $field;
        }

        return $out;
    }

    /** Fields of an element on one line: key:type(options, * = default) or key:items[fields]. @param array<string, array<string, mixed>> $properties */
    private static function fieldLine(array $properties): string
    {
        $parts = [];
        foreach ($properties as $key => $v) {
            $description = $key . ':' . $v['type'];
            if (isset($v['options']) && is_array($v['options'])) {
                $description .= '(' . implode('|', array_map(fn (string|int $m): string => (string) $m . ((string) $m === (string) ($v['default'] ?? '') ? '*' : ''),
                    array_is_list($v['options']) ? $v['options'] : array_keys($v['options']))) . ')';
            } elseif (isset($v['fields']) && is_array($v['fields'])) {
                $item = [];
                foreach ($v['fields'] as $k => $f) {
                    $item[] = $k . ':' . $f['type'] . (isset($f['options']) && is_array($f['options']) ? '(' . implode('|', array_map('strval', array_keys($f['options']))) . ')' : '');
                }
                $description .= '[' . implode('; ', $item) . ']';
            } elseif (in_array($v['type'], ['boolean', 'number'], true) && isset($v['default'])) {
                $description .= '=' . var_export($v['default'], true);
            }
            $parts[] = $description;
        }

        return implode('; ', $parts);
    }

    /** @param list<string>|null $extensions */
    public static function disabledTypes(?array $extensions): array
    {
        $types = [];
        foreach (Build::ELEMENTS as $className) {
            if (($extensions !== null && $className::EXTENSION !== '' && !in_array($className::EXTENSION, $extensions, true)) || ($className::MULTISITE && !\Talea\Core\Sites::enabled())) {
                $types[] = $className::TYPE;
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    private static function buildSchema(bool $admin, bool $parts, ?array $extensions): array
    {
        $elements = [];
        $disabled = self::disabledTypes($extensions);
        foreach (Build::ELEMENTS as $className) {
            if (($className::ADMIN_ONLY && !$admin) || ($className::PARTS_ONLY && !$parts) || in_array($className::TYPE, $disabled, true)) {
                continue;
            }
            $elements[] = [
                'type' => $className::TYPE, 'name' => $className::NAME, 'description' => $className::DESCRIPTION, 'icon' => $className::ICON, 'group' => $className::GROUP,
                'container' => $className::CONTAINER, 'tags' => $className::HTML_TAGS, 'properties' => $className::properties(), 'default_style' => $className::defaultStyle() ?: new \stdClass(),
                'default_children' => $className::defaultChildren(),
            ] + ($className::OFFERED ? [] : ['offered' => false]);
        }
        $style = [];
        foreach (Style::PROPERTIES as $key => [$css, $type, $group, $labelText, $options]) {
            $style[$key] = ['css' => $css, 'type' => $type, 'group' => $group, 'label' => $labelText] + ($options !== null ? ['options' => $options] : []);
        }

        return [
            'version' => Build::VERSION, 'elements' => $elements, 'style' => $style, 'style_groups' => Style::GROUPS, 'states' => array_keys(Style::STATUSES),
            'tokens' => ['colors' => DesignSystem::COLOR_TOKENS, 'spaces' => array_keys(DesignSystem::SPACES), 'steps' => DesignSystem::STEPS,
                'radii' => array_keys(DesignSystem::RADII), 'shadows' => array_keys(DesignSystem::SHADOWS)],
            'rules' => [
                'One element = one HTML tag; a section has at most one inner wrapper. Build page content from sections.',
                'Take style from tokens (space “l”, colour “primary”, step “2”); a free value only when no token fits.',
                'Style has the states base, tablet (up to 1023 px), mobile (up to 767 px), hover; editing one state leaves the others.',
                'A repeated look (cards, labels) belongs in a class, not in the style of every element.',
                'Grid columns: a number (“3”), “auto:16rem” (as many as fit) or a ratio (“2fr 1fr”).',
                'Free placement: a Section with content.layout “compose” is a fixed 12-column grid (row unit = space “l”). Its direct children get grid_column_start/grid_column_end (lines 1–13), grid_row_start/grid_row_end (lines 1–40) and layer (below|base|above|top); only there may elements overlap. Tablet and phone stack the children in source order (content.stack_from “phone” keeps the grid on a tablet; the tablet state then places the children). Placement properties anywhere else are dropped.',
            ],
        ];
    }
}
