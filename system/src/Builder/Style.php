<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * Style of an element or class: {"base": {...}, "tablet": {...}, "mobile": {...}, "hover": {...}}.
 * The properties are curated (PROPERTIES) and prefer design system tokens (spacing “l”, color “primary”, step “2”);
 * a free value works too, but only in a safe form. Editing one breakpoint never touches another.
 */
final class Style
{
    /** Breakpoints and states: key => media query or pseudo-class (empty = base). */
    public const array STATUSES = [
        'base' => '',
        'tablet' => '@media (max-width: 1023px)',
        'mobile' => '@media (max-width: 767px)',
        'hover' => ':hover',     // also applies to keyboard focus (:focus-visible) – whoever does not use a mouse sees the same
        'active' => ':active',  // press (button, card link)
        // state on a smaller screen: hover and press can be fine-tuned separately for tablet and mobile
        'hover_tablet' => '@media (max-width: 1023px)',
        'hover_mobile' => '@media (max-width: 767px)',
        'active_tablet' => '@media (max-width: 1023px)',
        'active_mobile' => '@media (max-width: 767px)',
    ];

    /** Where a state inherits a value it does not have itself (the editor shows it in grey): the nearest first. */
    public const array INHERITANCE = [
        'base' => [], 'tablet' => ['base'], 'mobile' => ['tablet', 'base'],
        'hover' => ['base'], 'hover_tablet' => ['hover', 'tablet', 'base'], 'hover_mobile' => ['hover_tablet', 'hover', 'mobile', 'tablet', 'base'],
        'active' => ['hover', 'base'], 'active_tablet' => ['active', 'hover_tablet', 'hover', 'tablet', 'base'],
        'active_mobile' => ['active_tablet', 'active', 'hover_mobile', 'hover_tablet', 'hover', 'mobile', 'tablet', 'base'],
    ];

    /**
     * key => [CSS property, type, group, label, enumeration options]
     * Types: column_line | row_line | space | length | color | step | radius | shadow | border | choice | number | image | columns | rows | areas | area | text
     */
    public const array PROPERTIES = [
        // layout
        'display' => ['display', 'choice', 'layout', 'Display', ['block' => 'block', 'flex' => 'flex (row / column)', 'grid' => 'grid', 'none' => 'hide']],
        'direction' => ['flex-direction', 'choice', 'layout', 'Direction', ['row' => 'side by side', 'column' => 'stacked', 'row-reverse' => 'side by side, reversed', 'column-reverse' => 'stacked, reversed']],
        'wrap' => ['flex-wrap', 'choice', 'layout', 'Wrapping', ['wrap' => 'wrap', 'nowrap' => 'no wrap']],
        'columns' => ['grid-template-columns', 'columns', 'layout', 'Grid columns', null],
        'rows' => ['grid-template-rows', 'rows', 'layout', 'Grid rows', null],
        'areas' => ['grid-template-areas', 'areas', 'layout', 'Grid areas', null],
        'area' => ['grid-area', 'area', 'layout', 'Grid area (name)', null],
        'column_span' => ['grid-column', 'choice', 'layout', 'Span columns (in grid)', ['span 2' => '2 columns', 'span 3' => '3 columns', 'span 4' => '4 columns', '1 / -1' => 'full width']],
        'row_span' => ['grid-row', 'choice', 'layout', 'Span rows (in grid)', ['span 2' => '2 rows', 'span 3' => '3 rows', 'span 4' => '4 rows']],
        'gap' => ['gap', 'space', 'layout', 'Gap between elements', null],
        'align_items' => ['align-items', 'choice', 'layout', 'Alignment (cross axis)', ['start' => 'start', 'center' => 'centre', 'end' => 'end', 'stretch' => 'stretch', 'baseline' => 'baseline']],
        'justify_content' => ['justify-content', 'choice', 'layout', 'Distribution (main axis)', ['start' => 'start', 'center' => 'centre', 'end' => 'end', 'space-between' => 'space between', 'space-around' => 'evenly']],
        'align_self' => ['align-self', 'choice', 'layout', 'Self alignment', ['start' => 'start', 'center' => 'centre', 'end' => 'end', 'stretch' => 'stretch']],
        'order' => ['order', 'number', 'layout', 'Order', null],
        // placement inside a Compose section (Build::sanitize drops these anywhere else): grid lines 1–13 / 1–40 and a layer from a small scale
        'grid_column_start' => ['grid-column-start', 'column_line', 'layout', 'Compose: first column line (1–13)', null],
        'grid_column_end' => ['grid-column-end', 'column_line', 'layout', 'Compose: last column line (1–13)', null],
        'grid_row_start' => ['grid-row-start', 'row_line', 'layout', 'Compose: first row line (1–40)', null],
        'grid_row_end' => ['grid-row-end', 'row_line', 'layout', 'Compose: last row line (1–40)', null],
        'layer' => ['z-index', 'choice', 'layout', 'Compose: layer', ['below' => 'below', 'base' => 'base', 'above' => 'above', 'top' => 'top']],
        'flex' => ['flex', 'choice', 'layout', 'Flex growth', ['1 1 0%' => 'fill the space', '0 0 auto' => 'by content']],
        // dimensions
        'width' => ['width', 'length', 'dimensions', 'Width', null],
        'max_width' => ['max-width', 'length', 'dimensions', 'Max width', null],
        'height' => ['height', 'length', 'dimensions', 'Height', null],
        'min_height' => ['min-height', 'length', 'dimensions', 'Min height', null],
        'aspect_ratio' => ['aspect-ratio', 'choice', 'dimensions', 'Aspect ratio', ['1' => '1 : 1', '4/3' => '4 : 3', '3/2' => '3 : 2', '16/9' => '16 : 9', '21/9' => '21 : 9', '3/4' => '3 : 4']],
        'object_fit' => ['object-fit', 'choice', 'dimensions', 'Image fit', ['cover' => 'cover (crop)', 'contain' => 'whole image']],
        'center' => ['margin-inline', 'choice', 'dimensions', 'Centre', ['auto' => 'yes']],
        // spacing
        'padding_y' => ['padding-block', 'space', 'spacing', 'Padding top and bottom', null],
        'padding_x' => ['padding-inline', 'space', 'spacing', 'Padding left and right', null],
        'margin_top' => ['margin-block-start', 'space', 'spacing', 'Margin top', null],
        'margin_bottom' => ['margin-block-end', 'space', 'spacing', 'Margin bottom', null],
        'margin_left' => ['margin-inline-start', 'space', 'spacing', 'Outer margin left', null],
        'margin_right' => ['margin-inline-end', 'space', 'spacing', 'Outer margin right', null],
        // typography: first the named style from Appearance, the individual properties below it fine-tune it
        'text_style' => ['font', 'choice', 'typography', 'Typography style', [
            'title' => 'Main title', 'section-heading' => 'Section heading', 'subheading' => 'Subheading', 'lead' => 'Lead',
            'text' => 'Body text', 'small' => 'Small text', 'eyebrow' => 'Eyebrow',
        ]],
        'font_size' => ['font-size', 'step', 'typography', 'Font size', null],
        'font_weight' => ['font-weight', 'choice', 'typography', 'Font weight', ['300' => 'thin', '400' => 'normal', '500' => 'medium', '600' => 'semibold', '700' => 'bold', '800' => 'extra bold']],
        'font' => ['font-family', 'choice', 'typography', 'Font', ['var(--tl-font-body)' => 'body', 'var(--tl-font-heading)' => 'headings']],
        'text_align' => ['text-align', 'choice', 'typography', 'Text alignment', ['start' => 'left', 'center' => 'centre', 'end' => 'right']],
        'line_height' => ['line-height', 'choice', 'typography', 'Line height', ['1.1' => 'tight', '1.3' => 'smaller', '1.6' => 'normal', '1.8' => 'loose']],
        'text_transform' => ['text-transform', 'choice', 'typography', 'Capitals', ['uppercase' => 'UPPERCASE', 'none' => 'normal']],
        'letter_spacing' => ['letter-spacing', 'choice', 'typography', 'Letter spacing', ['-0.02em' => 'tighter', '0' => 'normal', '0.06em' => 'wider', '0.12em' => 'wide']],
        'line_length' => ['max-width', 'choice', 'typography', 'Line length', ['var(--tl-text-width)' => 'comfortable for reading', '20ch' => 'short (headline)', '60ch' => '60 characters']],
        'color' => ['color', 'color', 'typography', 'Text colour', null],
        // background and border
        'background' => ['background-color', 'color', 'background', 'Background colour', null],
        'background_image' => ['background-image', 'image', 'background', 'Background image', null],
        'gradient' => ['background-image', 'choice', 'background', 'Gradient', [
            'linear-gradient(135deg, var(--tl-color-primary), var(--tl-color-secondary))' => 'primary → secondary',
            'linear-gradient(180deg, var(--tl-color-primary-soft), var(--tl-color-background))' => 'soft from the top',
            'linear-gradient(180deg, var(--tl-color-background), var(--tl-color-surface))' => 'background → surface',
            'radial-gradient(circle at 25% 15%, var(--tl-color-primary-soft), transparent 60%)' => 'glow in the corner',
            'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))' => 'darken at the bottom (over a photo)',
        ]],
        'background_attachment' => ['background-attachment', 'choice', 'background', 'Background image on scroll', ['fixed' => 'stays fixed (parallax)', 'scroll' => 'scrolls with content']],
        'overlay' => ['--tl-overlay', 'color', 'background', 'Image overlay (colour)', null],
        'border' => ['border', 'border', 'background', 'Border', ['none' => 'none', '1px solid var(--tl-color-line)' => 'thin', '2px solid currentColor' => 'strong', '2px solid var(--tl-color-primary)' => 'in the primary colour']],
        'border_color' => ['border-color', 'color', 'background', 'Border colour', null],
        'border_top' => ['border-block-start', 'choice', 'background', 'Top line', ['none' => 'none', '1px solid var(--tl-color-line)' => 'thin', '2px solid var(--tl-color-primary)' => 'in the primary colour']],
        'border_bottom' => ['border-block-end', 'choice', 'background', 'Bottom line', ['none' => 'none', '1px solid var(--tl-color-line)' => 'thin', '2px solid var(--tl-color-primary)' => 'in the primary colour']],
        'radius' => ['border-radius', 'radius', 'background', 'Corner radius', null],
        'shadow' => ['box-shadow', 'shadow', 'background', 'Shadow', null],
        'opacity' => ['opacity', 'choice', 'background', 'Opacity', ['1' => 'none', '0.8' => '80 %', '0.6' => '60 %', '0.4' => '40 %']],
        'overflow' => ['overflow', 'choice', 'background', 'Overflow', ['hidden' => 'clip', 'visible' => 'keep']],
        'position' => ['position', 'choice', 'advanced', 'Placement', ['relative' => 'normal (anchor for nested)', 'sticky' => 'sticky on scroll', 'absolute' => 'free within parent', 'fixed' => 'fixed in window']],
        'top' => ['top', 'space', 'advanced', 'From top', null],
        'bottom' => ['bottom', 'space', 'advanced', 'From bottom', null],
        'left' => ['left', 'space', 'advanced', 'From left', null],
        'right' => ['right', 'space', 'advanced', 'From right', null],
        'translate' => ['translate', 'choice', 'advanced', 'Offset', ['0 -4px' => 'lift', '0 -0.5rem' => 'lift more', '0 4px' => 'lower', '-50% -50%' => 'centre (with free positioning)']],
        'scale' => ['scale', 'choice', 'advanced', 'Scale', ['0.95' => '95 %', '1' => '100 %', '1.03' => '103 %', '1.05' => '105 %', '1.1' => '110 %']],
        'rotate' => ['rotate', 'choice', 'advanced', 'Rotation', ['-3deg' => '−3°', '3deg' => '3°', '-90deg' => '−90°', '90deg' => '90°', '180deg' => '180°']],
        'transition' => ['transition', 'choice', 'advanced', 'Smooth change (on hover)', ['all 0.2s ease' => 'fast', 'all 0.4s ease' => 'slower', 'none' => 'none']],
        'z_index' => ['z-index', 'number', 'advanced', 'Layer (above other content)', null],
        // reveal on scroll: an animation driven by page scrolling (CSS scroll-driven), without JavaScript; where the browser cannot do it, the element is visible right away
        'animation' => ['animation', 'choice', 'advanced', 'Reveal on scroll', ['tl-appear' => 'fade', 'tl-slide-in' => 'slide up', 'tl-zoom' => 'zoom in',
            'tl-from-left' => 'slide in from the left', 'tl-from-right' => 'slide in from the right', 'tl-blur' => 'from a blur', 'none' => 'none']],
        // motion while the element crosses the window (2.7): parallax, a slight rotation or growing into view – scroll-driven CSS as well
        'scroll_motion' => ['animation', 'choice', 'advanced', 'Motion while scrolling', ['tl-parallax' => 'parallax (slower than the page)', 'tl-parallax-strong' => 'stronger parallax',
            'tl-rotation' => 'slight rotation', 'tl-grow' => 'grows into view', 'none' => 'none']],
        // a ready-made hover effect (2.7): the change and its smooth transition in one choice; the hover state still fine-tunes it
        'hover_effect' => ['transition', 'choice', 'advanced', 'Effect on hover', ['lift' => 'lift with a shadow', 'grow' => 'grow slightly', 'nudge' => 'nudge to the side',
            'fade' => 'fade a little', 'none' => 'none']],
    ];

    /**
     * Keyframes of the scroll animations: name => [keyframes, animation-range]. Build::css adds only those a page uses.
     */
    public const array KEYFRAMES = [
        'tl-appear' => ['from { opacity: 0; }', 'entry 0% cover 28%'],
        'tl-slide-in' => ['from { opacity: 0; translate: 0 2.5rem; }', 'entry 0% cover 28%'],
        'tl-zoom' => ['from { opacity: 0; scale: 0.92; }', 'entry 0% cover 28%'],
        'tl-from-left' => ['from { opacity: 0; translate: -3rem 0; }', 'entry 0% cover 28%'],
        'tl-from-right' => ['from { opacity: 0; translate: 3rem 0; }', 'entry 0% cover 28%'],
        'tl-blur' => ['from { opacity: 0; filter: blur(12px); }', 'entry 0% cover 28%'],
        'tl-parallax' => ['from { translate: 0 3rem; } to { translate: 0 -3rem; }', 'cover 0% cover 100%'],
        'tl-parallax-strong' => ['from { translate: 0 7rem; } to { translate: 0 -7rem; }', 'cover 0% cover 100%'],
        'tl-rotation' => ['from { rotate: -4deg; } to { rotate: 4deg; }', 'cover 0% cover 100%'],
        'tl-grow' => ['from { scale: 0.85; } to { scale: 1; }', 'entry 0% cover 45%'],
    ];

    /** Hover effects: name => declarations on hover (and on keyboard focus). */
    private const array HOVER_EFFECTS = [
        'lift' => 'translate: 0 -4px; box-shadow: var(--tl-shadow-l, 0 12px 28px rgb(0 0 0 / 0.14));',
        'grow' => 'scale: 1.03;',
        'nudge' => 'translate: 4px 0;',
        'fade' => 'opacity: 0.82;',
    ];

    /** Properties that only mean something on a direct child of a Compose section; Build::sanitize drops them elsewhere. */
    public const array COMPOSE_ONLY = ['grid_column_start', 'grid_column_end', 'grid_row_start', 'grid_row_end', 'layer'];

    /** The layer scale of a Compose section: name => z-index. */
    public const array LAYERS = ['below' => -1, 'base' => 0, 'above' => 1, 'top' => 2];

    public const array GROUPS = ['layout' => 'Layout', 'dimensions' => 'Size', 'spacing' => 'Spacing', 'typography' => 'Typography', 'background' => 'Background and border', 'advanced' => 'Advanced'];

    /** Safe form of a free value: numbers with units, keywords, calc/min/max/clamp, var(--tl-…). Never ; { } < > \ or url(). */
    private const string FREE_VALUE_PATTERN = '/^(?!.*(?:url|expression|javascript|@import))[-a-z0-9 .,%()#+*\/]{1,80}$/i';
    private const string LENGTH_PATTERN = '/^(auto|0|-?\d{1,5}(\.\d{1,4})?(px|rem|em|%|vw|vh|svh|dvh|ch|fr)|(min|max|clamp|calc)\([-a-z0-9 .,%+*\/()]{1,70}\)|var\(--tl-[a-z0-9-]{1,40}\)|fit-content|min-content|max-content)$/i';

    /**
     * Sanitizes a style: it knows only the states from STATUSES and the properties from PROPERTIES; an invalid value is discarded and written to $errors.
     *
     * @param array<string, string> $errors path => error text (for the editor's and MCP's message)
     * @return array<string, array<string, string>>
     */
    public static function sanitize(mixed $style, string $path = '', array &$errors = []): array
    {
        $clean = [];
        foreach (is_array($style) ? $style : [] as $state => $properties) {
            if (!isset(self::STATUSES[$state]) || !is_array($properties)) {
                $errors[$path . '.' . $state] = 'Unknown breakpoint or state (allowed: ' . implode(', ', array_keys(self::STATUSES)) . ').';
                continue;
            }
            foreach ($properties as $key => $value) {
                if (!isset(self::PROPERTIES[$key])) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Unknown style property.';
                    continue;
                }
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value === '') {
                    continue;
                }
                if (self::value($key, $value) === null) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Invalid value “' . mb_substr($value, 0, 40) . '”.';
                    continue;
                }
                $clean[$state][$key] = $value;
            }
        }

        return $clean;
    }

    /** CSS value for a stored property value; null = invalid. */
    public static function value(string $key, string $value): ?string
    {
        [, $type, , , $options] = self::PROPERTIES[$key];

        return match ($type) {
            'choice' => isset($options[$value]) ? ($key === 'layer' ? (string) self::LAYERS[$value] : $value) : null,
            'space' => isset(DesignSystem::SPACES[$value]) ? 'var(--tl-space-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'length' => preg_match(self::LENGTH_PATTERN, $value) ? $value : null,
            'step' => in_array($value, DesignSystem::STEPS, true) ? 'var(--tl-step-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'color' => self::color($value),
            'radius' => isset(DesignSystem::RADII[$value]) ? 'var(--tl-radius-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'shadow' => isset(DesignSystem::SHADOWS[$value]) ? 'var(--tl-shadow-' . $value . ')' : ($value === 'none' ? 'none' : self::shadow($value)),
            'border' => isset($options[$value]) ? $value : self::border($value),
            'rows' => preg_match('/^([1-9]|1[0-2])$/', $value) ? 'repeat(' . $value . ', auto)' : (preg_match('/^((\d{1,2}(\.\d)?fr|auto|min-content|max-content|\d{1,4}(px|rem))\s?){1,8}$/', $value) ? trim($value) : null),
            'areas' => self::areas($value),
            'area' => preg_match('/^[a-z][a-z0-9-]{0,20}$/', $value) ? $value : null,
            'column_line' => preg_match('/^([1-9]|1[0-3])$/', $value) ? $value : null,
            'row_line' => preg_match('/^([1-9]|[1-3]\d|40)$/', $value) ? $value : null,
            'number' => preg_match('/^-?\d{1,3}$/', $value) ? $value : null,
            'columns' => self::columns($value),
            'image' => preg_match('#^(https://[^\s"\'()<>\\\\]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $value) ? $value : null,
            default => preg_match(self::FREE_VALUE_PATTERN, $value) ? $value : null,
        };
    }

    /**
     * A CSS declaration as style properties (converting <style> from HTML to class states – breakpoints and hover). Tokens are returned
     * as keys ("var(--tl-space-l)" → "l"), the padding/margin shorthands are expanded. What has no counterpart in the style returns null.
     *
     * @return array<string, string>|null style key => value
     */
    public static function fromCss(string $property, string $value): ?array
    {
        $property = strtolower(trim($property));
        $value = trim((string) preg_replace('/\s*!important$/i', '', trim($value)));
        // common notations the builder knows under a logical name: margin-top → margin-block-start, flex-start → start
        $property = ['margin-top' => 'margin-block-start', 'margin-bottom' => 'margin-block-end', 'margin-left' => 'margin-inline-start', 'margin-right' => 'margin-inline-end'][$property] ?? $property;
        // the background shorthand with only a color (background: #EFECE5) is the background color
        if ($property === 'background' && preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^()]*\)|var\(--tl-color-[a-z0-9-]+\)|[a-z]+)$/i', $value)) {
            $property = 'background-color';
        }
        if (in_array($property, ['align-items', 'align-self', 'justify-content'], true)) {
            $value = ['flex-start' => 'start', 'flex-end' => 'end'][$value] ?? $value;
        }
        if ($property === 'text-align') {
            $value = ['left' => 'start', 'right' => 'end'][$value] ?? $value;
        }
        $token = static fn (string $h): string => (string) preg_replace_callback('/var\(--tl-(space|step|radius|shadow|color)-([a-z0-9-]{1,20})\)/',
            static fn (array $m): string => match ($m[1]) {
                'space' => isset(DesignSystem::SPACES[$m[2]]) ? $m[2] : $m[0],
                'step' => in_array($m[2], DesignSystem::STEPS, true) ? $m[2] : $m[0],
                'radius' => isset(DesignSystem::RADII[$m[2]]) ? (string) $m[2] : $m[0],
                'shadow' => isset(DesignSystem::SHADOWS[$m[2]]) ? $m[2] : $m[0],
                default => isset(DesignSystem::COLOR_TOKENS[$m[2]]) ? $m[2] : $m[0],
            }, $h);
        $pairs = static function (string $h): ?array {
            $parts = preg_split('/\s+/', trim($h)) ?: [];

            return match (count($parts)) {
                1 => [$parts[0], $parts[0]],
                2 => [$parts[0], $parts[1]],
                3 => $parts[0] === $parts[2] ? [$parts[0], $parts[1]] : null,
                4 => $parts[0] === $parts[2] && $parts[1] === $parts[3] ? [$parts[0], $parts[1]] : null,
                default => null,
            };
        };
        if (in_array($property, ['padding', 'margin'], true)) {
            $args = $pairs($token($value));
            if ($args === null) {
                return null;
            }
            $keys = $property === 'padding' ? ['padding_y', 'padding_x'] : null;
            if ($keys === null) {
                $result = [];
                foreach (['margin_top' => $args[0], 'margin_bottom' => $args[0], 'margin_left' => $args[1], 'margin_right' => $args[1]] as $k => $h) {
                    if ($h === 'auto' && in_array($k, ['margin_left', 'margin_right'], true)) {
                        $result['center'] = 'auto';
                        continue;
                    }
                    if (self::value($k, $h) === null) {
                        return null;
                    }
                    $result[$k] = $h;
                }

                return $result;
            }

            return self::value($keys[0], $args[0]) !== null && self::value($keys[1], $args[1]) !== null ? [$keys[0] => $args[0], $keys[1] => $args[1]] : null;
        }
        $value = $token($value);
        if ($property === 'transform') {
            // shift and enlargement (hover effect) have their own properties translate and scale in the style
            $value = match (true) {
                (bool) preg_match('/^translateY\(([^()]+)\)$/', $value, $m) => '0 ' . trim($m[1]),
                (bool) preg_match('/^translate\(([^(),]+),\s*([^(),]+)\)$/', $value, $m) => trim($m[1]) . ' ' . trim($m[2]),
                (bool) preg_match('/^scale\(([\d.]+)\)$/', $value, $m) => $m[1],
                default => '',
            };
            $property = str_contains($value, ' ') ? 'translate' : 'scale';
        }
        if ($property === 'grid-template-columns') {
            if (preg_match('/^repeat\(\s*([1-9]|1[0-2])\s*,\s*(minmax\(0,\s*1fr\)|1fr)\s*\)$/', $value, $m)) {
                $value = $m[1];
            } elseif (preg_match('/^repeat\(\s*auto-(fit|fill)\s*,\s*minmax\(\s*(?:min\(100%,\s*)?(\d{1,3}(?:\.\d{1,2})?(?:rem|px|ch))\)?\s*,\s*1fr\s*\)\s*\)$/', $value, $m)) {
                $value = 'auto:' . $m[2];
            } elseif (preg_match('/^1fr$/', $value)) {
                $value = '1';
            }
        }
        foreach (self::PROPERTIES as $key => [$css]) {
            if ($css === $property && self::value($key, $value) !== null) {
                return [$key => $value];
            }
        }

        return null;
    }

    /** Color: a design system token, or a safely written custom color. */
    public static function color(string $value): ?string
    {
        if (isset(DesignSystem::COLOR_TOKENS[$value])) {
            return 'var(--tl-color-' . $value . ')';
        }

        return preg_match('/^(#[0-9a-f]{3,8}|transparent|currentColor|(rgba?|hsla?|oklch|oklab|lab|lch|hwb)\([0-9., %\/+-]{3,60}\)|var\(--tl-color-[a-z-]{1,30}\))$/i', $value) ? $value : null;
    }

    /**
     * Custom shadow from the shadow editor: up to three layers "[inset] x y [blur] [spread] color" (the color also as a token, e.g. "0 8px 24px primarni").
     */
    private static function shadow(string $value): ?string
    {
        $layers = preg_split('/,(?![^(]*\))/', $value) ?: [];
        if (count($layers) > 3) {
            return null;
        }
        $css = [];
        foreach ($layers as $layer) {
            if (!preg_match('/^\s*(inset\s+)?((?:-?\d{1,3}(?:\.\d{1,2})?(?:px|rem|em)?\s+){2,4})(\S.*?)\s*$/i', $layer, $m) || ($color = self::color($m[3])) === null) {
                return null;
            }
            $css[] = $m[1] . trim($m[2]) . ' ' . $color;
        }

        return $css === [] ? null : implode(', ', $css);
    }

    /** Custom border: "2px dashed primarni" – width, line style and color (token or custom). */
    private static function border(string $value): ?string
    {
        if (!preg_match('/^(\d{1,2}(?:\.\d)?px)\s+(solid|dashed|dotted|double)\s+(\S+)$/', $value, $m) || ($color = self::color($m[3])) === null) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ' ' . $color;
    }

    /** Grid areas: rows separated by "/", area names in a row (a dot = an empty cell); all rows of the same length. */
    private static function areas(string $value): ?string
    {
        $rows = array_map(fn (string $r): array => preg_split('/\s+/', trim($r)) ?: [], explode('/', $value));
        if (count($rows) > 8 || count(array_unique(array_map('count', $rows))) !== 1 || count($rows[0]) > 12) {
            return null;
        }
        foreach ($rows as $row) {
            foreach ($row as $name) {
                if (!preg_match('/^([a-z][a-z0-9-]{0,20}|\.)$/', $name)) {
                    return null;
                }
            }
        }

        return implode(' ', array_map(fn (array $r): string => '"' . implode(' ', $r) . '"', $rows));
    }

    /** Grid columns: "3" = three equal ones, "auto:16rem" = as many as fit at min. 16rem, "2fr 1fr" = a custom ratio. */
    private static function columns(string $value): ?string
    {
        if (preg_match('/^([1-9]|1[0-2])$/', $value)) {
            return 'repeat(' . $value . ', minmax(0, 1fr))';
        }
        if (preg_match('/^auto:(\d{1,3}(\.\d{1,2})?)(rem|px|ch)$/', $value, $m)) {
            return 'repeat(auto-fit, minmax(min(100%, ' . $m[1] . $m[3] . '), 1fr))';
        }

        return preg_match('/^((\d{1,2}(\.\d)?fr|auto|\d{1,4}(px|rem))\s?){1,6}$/', $value) ? trim($value) : null;
    }

    /**
     * CSS for a selector from the style: base, :hover, then breakpoints (from larger to smaller, so that the smaller wins).
     *
     * @param array<string, array<string, string>> $style
     */
    public static function css(string $selector, array $style, string $customCss = '', string $base = ''): string
    {
        $css = '';
        $declarations = function (array $properties) use ($base): string {
            $rows = [];
            $image = null;
            $animations = [];
            if (isset($properties['text_style'])) {
                // the typography style first: a size or weight set separately fine-tunes it (the later declaration wins)
                $properties = ['text_style' => $properties['text_style']] + $properties;
            }
            foreach ($properties as $key => $value) {
                $css = self::value($key, (string) $value);
                if ($css === null) {
                    continue;
                }
                [$property, $type] = self::PROPERTIES[$key];
                if ($key === 'text_style') {
                    $rows[] = 'font: var(--tl-type-' . $css . ')';
                    if ($css === 'eyebrow') {
                        array_push($rows, 'text-transform: uppercase', 'letter-spacing: 0.08em');
                    }
                    continue;
                }
                if ($key === 'animation' || $key === 'scroll_motion') {
                    if (isset(self::KEYFRAMES[$css])) {
                        $animations[] = $css; // a reveal and a motion run together: one animation list
                    }
                    continue;
                }
                if ($key === 'hover_effect') {
                    continue; // added by css() with its own hover rule
                }
                if ($type === 'image') {
                    $image = $css;
                    continue;
                }
                $rows[] = $property . ': ' . $css;
                if ($key === 'background' && ($value === 'white' || $value === 'black') && !isset($properties['color'])) {
                    // white and black do not change in dark mode: the text and derived shades inside adapt to them (otherwise light text on white)
                    $text = $value === 'white' ? 'var(--tl-color-text-light)' : 'var(--tl-color-text-dark)';
                    $surface = $value === 'white' ? '#ffffff' : '#000000';
                    array_push($rows, '--tl-color-text: ' . $text, 'color: ' . $text,
                        '--tl-color-muted: color-mix(in oklch, ' . $text . ' 64%, ' . $surface . ')', '--tl-color-line: color-mix(in oklch, ' . $text . ' 14%, ' . $surface . ')');
                }
            }
            if ($image !== null) {
                // the site's media always from the installation root – a relative url() would be looked up elsewhere on /en/… or /kolekce/polozka
                if (!str_starts_with($image, 'https://') && !str_starts_with($image, '/')) {
                    $image = $base . '/' . $image;
                }
                // the background image always covers the area; an optional overlay (--tl-overlay) goes over it for text legibility
                $rows[] = 'background-image: linear-gradient(var(--tl-overlay, transparent), var(--tl-overlay, transparent)), url("' . $image . '")';
                $rows[] = 'background-size: cover';
                $rows[] = 'background-position: center';
            }

            if ($animations !== []) {
                $rows[] = 'animation: ' . implode(', ', array_map(fn (string $a): string => $a . ' linear both', $animations));
                $rows[] = 'animation-timeline: ' . implode(', ', array_fill(0, count($animations), 'view()'));
                $rows[] = 'animation-range: ' . implode(', ', array_map(fn (string $a): string => self::KEYFRAMES[$a][1], $animations));
            }

            return $rows === [] ? '' : implode('; ', $rows) . ';';
        };
        $base = $declarations($style['base'] ?? []) . ($customCss !== '' ? ' ' . $customCss : '');
        if (trim($base) !== '') {
            $css .= $selector . ' { ' . trim($base) . " }\n";
        }
        $effect = self::HOVER_EFFECTS[$style['base']['hover_effect'] ?? ''] ?? null;
        if ($effect !== null) {
            // the effect first, so that the element's own hover state wins; motion only for those who did not turn it off
            $css .= '@media (prefers-reduced-motion: no-preference) { ' . $selector . ' { transition: translate 0.2s ease, scale 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease; } }' . "\n"
                . $selector . ':is(:hover, :focus-visible) { ' . $effect . " }\n";
        }
        if (($hover = $declarations($style['hover'] ?? [])) !== '') {
            $css .= $selector . ':is(:hover, :focus-visible) { ' . $hover . " }\n";
        }
        if (($active = $declarations($style['active'] ?? [])) !== '') {
            $css .= $selector . ':active { ' . $active . " }\n";
        }
        foreach (['tablet', 'mobile'] as $state) {
            $block = '';
            if (($d = $declarations($style[$state] ?? [])) !== '') {
                $block .= $selector . ' { ' . $d . ' } ';
            }
            if (($d = $declarations($style['hover_' . $state] ?? [])) !== '') {
                $block .= $selector . ':is(:hover, :focus-visible) { ' . $d . ' } ';
            }
            if (($d = $declarations($style['active_' . $state] ?? [])) !== '') {
                $block .= $selector . ':active { ' . $d . ' } ';
            }
            if ($block !== '') {
                $css .= self::STATUSES[$state] . ' { ' . trim($block) . " }\n";
            }
        }

        return $css;
    }

    /**
     * Custom CSS of a class (entered by the administrator, or created by converting HTML from AI): only "property: value;" declarations
     * without blocks, url(), imports and script constructs. Disallowed lines are discarded.
     */
    public static function customCss(string $css, array &$discarded = []): string
    {
        $output = [];
        foreach (preg_split('/;(?![^(]*\))/', str_replace(["\r", "\n"], ' ', $css)) ?: [] as $declarations) {
            $declarations = trim($declarations);
            if ($declarations === '') {
                continue;
            }
            // forbidden: loading external resources (url, image-set, image, src), comments and unclosed quotes – they would break the CSS of the rest of the page
            if (preg_match('/^(--tl-[a-z0-9-]{1,40}|-?[a-z][a-z-]{1,40})\s*:\s*([^;{}<>\\\\@]{1,200})$/i', $declarations, $m)
                && !preg_match('/url\s*\(|image-set|image\s*\(|src\s*\(|cross-fade|element\s*\(|expression|javascript|behavior|-moz-binding|\/\*|\*\//i', $m[2])
                && substr_count($m[2], '"') % 2 === 0 && substr_count($m[2], "'") % 2 === 0) {
                $output[] = strtolower($m[1]) . ': ' . trim($m[2]) . ';';
            } else {
                $discarded[] = $declarations;
            }
        }

        return implode(' ', $output);
    }
}
