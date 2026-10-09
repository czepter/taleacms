<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Style of an element or class: {"zaklad": {...}, "tablet": {...}, "mobil": {...}, "hover": {...}}.
 * The properties are curated (PROPERTIES) and prefer design system tokens (spacing „l“, color „primarni“, step „2“);
 * a free value works too, but only in a safe form. Editing one breakpoint never touches another.
 */
final class Style
{
    /** Breakpoints and states: key => media query or pseudo-class (empty = base). */
    public const array STATUSES = [
        'zaklad' => '',
        'tablet' => '@media (max-width: 1023px)',
        'mobil' => '@media (max-width: 767px)',
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
        'zaklad' => [], 'tablet' => ['zaklad'], 'mobil' => ['tablet', 'zaklad'],
        'hover' => ['zaklad'], 'hover_tablet' => ['hover', 'tablet', 'zaklad'], 'hover_mobile' => ['hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
        'active' => ['hover', 'zaklad'], 'active_tablet' => ['active', 'hover_tablet', 'hover', 'tablet', 'zaklad'],
        'active_mobile' => ['active_tablet', 'active', 'hover_mobile', 'hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
    ];

    /**
     * key => [CSS property, type, group, label, enumeration options]
     * Types: mezera | delka | barva | krok | zaobleni | stin | ramecek | vyber | cislo | obrazek | sloupce | radky | oblasti | oblast | text
     */
    public const array PROPERTIES = [
        // layout
        'zobrazeni' => ['display', 'vyber', 'rozlozeni', 'Display', ['block' => 'blok', 'flex' => 'flex (row / column)', 'grid' => 'mřížka', 'none' => 'skrýt']],
        'direction' => ['flex-direction', 'vyber', 'rozlozeni', 'Direction', ['row' => 'side by side', 'column' => 'stacked', 'row-reverse' => 'side by side, reversed', 'column-reverse' => 'stacked, reversed']],
        'wrap' => ['flex-wrap', 'vyber', 'rozlozeni', 'Wrapping', ['wrap' => 'zalamovat', 'nowrap' => 'nezalamovat']],
        'columns' => ['grid-template-columns', 'columns', 'rozlozeni', 'Grid columns', null],
        'radky' => ['grid-template-rows', 'radky', 'rozlozeni', 'Grid rows', null],
        'areas' => ['grid-template-areas', 'areas', 'rozlozeni', 'Grid areas', null],
        'area' => ['grid-area', 'area', 'rozlozeni', 'Grid area (name)', null],
        'column_span' => ['grid-column', 'vyber', 'rozlozeni', 'Span columns (in grid)', ['span 2' => '2 columns', 'span 3' => '3 columns', 'span 4' => '4 columns', '1 / -1' => 'full width']],
        'row_span' => ['grid-row', 'vyber', 'rozlozeni', 'Span rows (in grid)', ['span 2' => '2 rows', 'span 3' => '3 rows', 'span 4' => '4 rows']],
        'mezera' => ['gap', 'mezera', 'rozlozeni', 'Gap between elements', null],
        'align_items' => ['align-items', 'vyber', 'rozlozeni', 'Alignment (cross axis)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout', 'baseline' => 'účaří']],
        'justify_content' => ['justify-content', 'vyber', 'rozlozeni', 'Distribution (main axis)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'space-between' => 'space between', 'space-around' => 'rovnoměrně']],
        'align_self' => ['align-self', 'vyber', 'rozlozeni', 'Self alignment', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout']],
        'poradi' => ['order', 'number', 'rozlozeni', 'Pořadí', null],
        'rust' => ['flex', 'vyber', 'rozlozeni', 'Flex growth', ['1 1 0%' => 'fill the space', '0 0 auto' => 'by content']],
        // dimensions
        'width' => ['width', 'length', 'rozmery', 'Width', null],
        'max_width' => ['max-width', 'length', 'rozmery', 'Max width', null],
        'height' => ['height', 'length', 'rozmery', 'Height', null],
        'min_height' => ['min-height', 'length', 'rozmery', 'Min height', null],
        'aspect_ratio' => ['aspect-ratio', 'vyber', 'rozmery', 'Aspect ratio', ['1' => '1 : 1', '4/3' => '4 : 3', '3/2' => '3 : 2', '16/9' => '16 : 9', '21/9' => '21 : 9', '3/4' => '3 : 4']],
        'object_fit' => ['object-fit', 'vyber', 'rozmery', 'Image fit', ['cover' => 'cover (crop)', 'contain' => 'whole image']],
        'center' => ['margin-inline', 'vyber', 'rozmery', 'Centre', ['auto' => 'ano']],
        // spacing
        'padding_y' => ['padding-block', 'mezera', 'mezery', 'Padding top and bottom', null],
        'padding_x' => ['padding-inline', 'mezera', 'mezery', 'Padding left and right', null],
        'margin_top' => ['margin-block-start', 'mezera', 'mezery', 'Margin top', null],
        'margin_bottom' => ['margin-block-end', 'mezera', 'mezery', 'Margin bottom', null],
        'margin_left' => ['margin-inline-start', 'mezera', 'mezery', 'Outer margin left', null],
        'margin_right' => ['margin-inline-end', 'mezera', 'mezery', 'Outer margin right', null],
        // typography: first the named style from Appearance, the individual properties below it fine-tune it
        'text_style' => ['font', 'vyber', 'typografie', 'Typography style', [
            'title' => 'Main title', 'section-heading' => 'Section heading', 'subheading' => 'Podnadpis', 'lead' => 'Lead',
            'text' => 'Body text', 'small' => 'Small text', 'eyebrow' => 'Eyebrow',
        ]],
        'font_size' => ['font-size', 'step', 'typografie', 'Font size', null],
        'font_weight' => ['font-weight', 'vyber', 'typografie', 'Font weight', ['300' => 'tenké', '400' => 'normální', '500' => 'střední', '600' => 'polotučné', '700' => 'tučné', '800' => 'extra bold']],
        'font' => ['font-family', 'vyber', 'typografie', 'Font', ['var(--ka-pismo-text)' => 'textové', 'var(--ka-pismo-titulky)' => 'titulkové']],
        'text_align' => ['text-align', 'vyber', 'typografie', 'Text alignment', ['start' => 'vlevo', 'center' => 'na střed', 'end' => 'vpravo']],
        'line_height' => ['line-height', 'vyber', 'typografie', 'Line height', ['1.1' => 'těsné', '1.3' => 'menší', '1.6' => 'běžné', '1.8' => 'volné']],
        'text_transform' => ['text-transform', 'vyber', 'typografie', 'Capitals', ['uppercase' => 'UPPERCASE', 'none' => 'normální']],
        'letter_spacing' => ['letter-spacing', 'vyber', 'typografie', 'Letter spacing', ['-0.02em' => 'užší', '0' => 'normální', '0.06em' => 'širší', '0.12em' => 'široký']],
        'line_length' => ['max-width', 'vyber', 'typografie', 'Line length', ['var(--ka-sirka-textu)' => 'comfortable for reading', '20ch' => 'short (headline)', '60ch' => '60 characters']],
        'color' => ['color', 'color', 'typografie', 'Text colour', null],
        // background and border
        'background' => ['background-color', 'color', 'background', 'Background colour', null],
        'background_image' => ['background-image', 'image', 'background', 'Background image', null],
        'gradient' => ['background-image', 'vyber', 'background', 'Gradient', [
            'linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))' => 'primary → secondary',
            'linear-gradient(180deg, var(--ka-barva-primarni-jemna), var(--ka-barva-pozadi))' => 'soft from the top',
            'linear-gradient(180deg, var(--ka-barva-pozadi), var(--ka-barva-plocha))' => 'background → surface',
            'radial-gradient(circle at 25% 15%, var(--ka-barva-primarni-jemna), transparent 60%)' => 'glow in the corner',
            'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))' => 'darken at the bottom (over a photo)',
        ]],
        'background_attachment' => ['background-attachment', 'vyber', 'background', 'Background image on scroll', ['fixed' => 'stays fixed (parallax)', 'scroll' => 'scrolls with content']],
        'overlay' => ['--ka-prekryv', 'color', 'background', 'Image overlay (colour)', null],
        'border' => ['border', 'border', 'background', 'Border', ['none' => 'žádný', '1px solid var(--ka-barva-linka)' => 'tenký', '2px solid currentColor' => 'výrazný', '2px solid var(--ka-barva-primarni)' => 'in the primary colour']],
        'border_color' => ['border-color', 'color', 'background', 'Border colour', null],
        'border_top' => ['border-block-start', 'vyber', 'background', 'Top line', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'in the primary colour']],
        'border_bottom' => ['border-block-end', 'vyber', 'background', 'Bottom line', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'in the primary colour']],
        'radius' => ['border-radius', 'radius', 'background', 'Corner radius', null],
        'shadow' => ['box-shadow', 'shadow', 'background', 'Shadow', null],
        'opacity' => ['opacity', 'vyber', 'background', 'Opacity', ['1' => 'žádná', '0.8' => '80 %', '0.6' => '60 %', '0.4' => '40 %']],
        'overflow' => ['overflow', 'vyber', 'background', 'Overflow', ['hidden' => 'oříznout', 'visible' => 'nechat']],
        'position' => ['position', 'vyber', 'pokrocile', 'Placement', ['relative' => 'normal (anchor for nested)', 'sticky' => 'sticky on scroll', 'absolute' => 'free within parent', 'fixed' => 'fixed in window']],
        'top' => ['top', 'mezera', 'pokrocile', 'From top', null],
        'bottom' => ['bottom', 'mezera', 'pokrocile', 'From bottom', null],
        'left' => ['left', 'mezera', 'pokrocile', 'From left', null],
        'right' => ['right', 'mezera', 'pokrocile', 'From right', null],
        'translate' => ['translate', 'vyber', 'pokrocile', 'Offset', ['0 -4px' => 'nadzvednout', '0 -0.5rem' => 'lift more', '0 4px' => 'snížit', '-50% -50%' => 'centre (with free positioning)']],
        'scale' => ['scale', 'vyber', 'pokrocile', 'Scale', ['0.95' => '95 %', '1' => '100 %', '1.03' => '103 %', '1.05' => '105 %', '1.1' => '110 %']],
        'rotate' => ['rotate', 'vyber', 'pokrocile', 'Rotation', ['-3deg' => '−3°', '3deg' => '3°', '-90deg' => '−90°', '90deg' => '90°', '180deg' => '180°']],
        'transition' => ['transition', 'vyber', 'pokrocile', 'Smooth change (on hover)', ['all 0.2s ease' => 'rychlá', 'all 0.4s ease' => 'pomalejší', 'none' => 'žádná']],
        'z_index' => ['z-index', 'number', 'pokrocile', 'Layer (above other content)', null],
        // reveal on scroll: an animation driven by page scrolling (CSS scroll-driven), without JavaScript; where the browser cannot do it, the element is visible right away
        'animation' => ['animation', 'vyber', 'pokrocile', 'Reveal on scroll', ['ka-objevit' => 'prolnutí', 'ka-vyjet' => 'slide up', 'ka-priblizit' => 'přiblížení',
            'ka-zleva' => 'slide in from the left', 'ka-zprava' => 'slide in from the right', 'ka-rozostreni' => 'from a blur', 'none' => 'žádné']],
        // motion while the element crosses the window (2.7): parallax, a slight rotation or growing into view – scroll-driven CSS as well
        'scroll_motion' => ['animation', 'vyber', 'pokrocile', 'Motion while scrolling', ['ka-paralaxa' => 'parallax (slower than the page)', 'ka-paralaxa-silna' => 'stronger parallax',
            'ka-natoceni' => 'slight rotation', 'ka-rust' => 'grows into view', 'none' => 'žádný']],
        // a ready-made hover effect (2.7): the change and its smooth transition in one choice; the hover state still fine-tunes it
        'hover_effect' => ['transition', 'vyber', 'pokrocile', 'Effect on hover', ['zvednout' => 'lift with a shadow', 'zvetsit' => 'grow slightly', 'posunout' => 'nudge to the side',
            'zesvetlit' => 'fade a little', 'none' => 'žádný']],
    ];

    /**
     * Keyframes of the scroll animations: name => [keyframes, animation-range]. Build::css adds only those a page uses.
     */
    public const array KEYFRAMES = [
        'ka-objevit' => ['from { opacity: 0; }', 'entry 0% cover 28%'],
        'ka-vyjet' => ['from { opacity: 0; translate: 0 2.5rem; }', 'entry 0% cover 28%'],
        'ka-priblizit' => ['from { opacity: 0; scale: 0.92; }', 'entry 0% cover 28%'],
        'ka-zleva' => ['from { opacity: 0; translate: -3rem 0; }', 'entry 0% cover 28%'],
        'ka-zprava' => ['from { opacity: 0; translate: 3rem 0; }', 'entry 0% cover 28%'],
        'ka-rozostreni' => ['from { opacity: 0; filter: blur(12px); }', 'entry 0% cover 28%'],
        'ka-paralaxa' => ['from { translate: 0 3rem; } to { translate: 0 -3rem; }', 'cover 0% cover 100%'],
        'ka-paralaxa-silna' => ['from { translate: 0 7rem; } to { translate: 0 -7rem; }', 'cover 0% cover 100%'],
        'ka-natoceni' => ['from { rotate: -4deg; } to { rotate: 4deg; }', 'cover 0% cover 100%'],
        'ka-rust' => ['from { scale: 0.85; } to { scale: 1; }', 'entry 0% cover 45%'],
    ];

    /** Hover effects: name => declarations on hover (and on keyboard focus). */
    private const array HOVER_EFFECTS = [
        'zvednout' => 'translate: 0 -4px; box-shadow: var(--ka-stin-l, 0 12px 28px rgb(0 0 0 / 0.14));',
        'zvetsit' => 'scale: 1.03;',
        'posunout' => 'translate: 4px 0;',
        'zesvetlit' => 'opacity: 0.82;',
    ];

    public const array GROUPS = ['rozlozeni' => 'Rozložení', 'rozmery' => 'Rozměry', 'mezery' => 'Spacing', 'typografie' => 'Typography', 'background' => 'Background and border', 'pokrocile' => 'Pokročilé'];

    /** Safe form of a free value: numbers with units, keywords, calc/min/max/clamp, var(--ka-…). Never ; { } < > \ or url(). */
    private const string FREE_VALUE_PATTERN = '/^(?!.*(?:url|expression|javascript|@import))[-a-z0-9 .,%()#+*\/]{1,80}$/i';
    private const string LENGTH_PATTERN = '/^(auto|0|-?\d{1,5}(\.\d{1,4})?(px|rem|em|%|vw|vh|svh|dvh|ch|fr)|(min|max|clamp|calc)\([-a-z0-9 .,%+*\/()]{1,70}\)|var\(--ka-[a-z0-9-]{1,40}\)|fit-content|min-content|max-content)$/i';

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
                $errors[$path . '.' . $state] = 'Neznámý breakpoint nebo stav (povolené: ' . implode(', ', array_keys(self::STATUSES)) . ').';
                continue;
            }
            foreach ($properties as $key => $value) {
                if (!isset(self::PROPERTIES[$key])) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Neznámá vlastnost stylu.';
                    continue;
                }
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value === '') {
                    continue;
                }
                if (self::value($key, $value) === null) {
                    $errors[$path . '.' . $state . '.' . $key] = 'Neplatná hodnota „' . mb_substr($value, 0, 40) . '“.';
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
            'vyber' => isset($options[$value]) ? $value : null,
            'mezera' => isset(DesignSystem::SPACES[$value]) ? 'var(--ka-mezera-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'length' => preg_match(self::LENGTH_PATTERN, $value) ? $value : null,
            'step' => in_array($value, DesignSystem::STEPS, true) ? 'var(--ka-krok-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'color' => self::color($value),
            'radius' => isset(DesignSystem::RADII[$value]) ? 'var(--ka-zaobleni-' . $value . ')' : (preg_match(self::LENGTH_PATTERN, $value) ? $value : null),
            'shadow' => isset(DesignSystem::SHADOWS[$value]) ? 'var(--ka-stin-' . $value . ')' : ($value === 'none' ? 'none' : self::shadow($value)),
            'border' => isset($options[$value]) ? $value : self::border($value),
            'radky' => preg_match('/^([1-9]|1[0-2])$/', $value) ? 'repeat(' . $value . ', auto)' : (preg_match('/^((\d{1,2}(\.\d)?fr|auto|min-content|max-content|\d{1,4}(px|rem))\s?){1,8}$/', $value) ? trim($value) : null),
            'areas' => self::areas($value),
            'area' => preg_match('/^[a-z][a-z0-9-]{0,20}$/', $value) ? $value : null,
            'number' => preg_match('/^-?\d{1,3}$/', $value) ? $value : null,
            'columns' => self::columns($value),
            'image' => preg_match('#^(https://[^\s"\'()<>\\\\]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $value) ? $value : null,
            default => preg_match(self::FREE_VALUE_PATTERN, $value) ? $value : null,
        };
    }

    /**
     * A CSS declaration as style properties (converting <style> from HTML to class states – breakpoints and hover). Tokens are returned
     * as keys ("var(--ka-mezera-l)" → "l"), the padding/margin shorthands are expanded. What has no counterpart in the style returns null.
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
        if ($property === 'background' && preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^()]*\)|var\(--ka-barva-[a-z0-9-]+\)|[a-z]+)$/i', $value)) {
            $property = 'background-color';
        }
        if (in_array($property, ['align-items', 'align-self', 'justify-content'], true)) {
            $value = ['flex-start' => 'start', 'flex-end' => 'end'][$value] ?? $value;
        }
        if ($property === 'text-align') {
            $value = ['left' => 'start', 'right' => 'end'][$value] ?? $value;
        }
        $token = static fn (string $h): string => (string) preg_replace_callback('/var\(--ka-(mezera|krok|zaobleni|stin|barva)-([a-z0-9-]{1,20})\)/',
            static fn (array $m): string => match ($m[1]) {
                'mezera' => isset(DesignSystem::SPACES[$m[2]]) ? $m[2] : $m[0],
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
                    if ($h === 'auto' && str_starts_with($k, 'okraj_v')) {
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
            return 'var(--ka-barva-' . $value . ')';
        }

        return preg_match('/^(#[0-9a-f]{3,8}|transparent|currentColor|(rgba?|hsla?|oklch|oklab|lab|lch|hwb)\([0-9., %\/+-]{3,60}\)|var\(--ka-barva-[a-z-]{1,30}\))$/i', $value) ? $value : null;
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
                    $rows[] = 'font: var(--ka-typ-' . $css . ')';
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
                    $text = $value === 'white' ? 'var(--ka-barva-text-svetle)' : 'var(--ka-barva-text-tmave)';
                    $surface = $value === 'white' ? '#ffffff' : '#000000';
                    array_push($rows, '--ka-barva-text: ' . $text, 'color: ' . $text,
                        '--ka-barva-tlumeny: color-mix(in oklch, ' . $text . ' 64%, ' . $surface . ')', '--ka-barva-linka: color-mix(in oklch, ' . $text . ' 14%, ' . $surface . ')');
                }
            }
            if ($image !== null) {
                // the site's media always from the installation root – a relative url() would be looked up elsewhere on /en/… or /kolekce/polozka
                if (!str_starts_with($image, 'https://') && !str_starts_with($image, '/')) {
                    $image = $base . '/' . $image;
                }
                // the background image always covers the area; an optional overlay (--ka-prekryv) goes over it for text legibility
                $rows[] = 'background-image: linear-gradient(var(--ka-prekryv, transparent), var(--ka-prekryv, transparent)), url("' . $image . '")';
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
        $base = $declarations($style['zaklad'] ?? []) . ($customCss !== '' ? ' ' . $customCss : '');
        if (trim($base) !== '') {
            $css .= $selector . ' { ' . trim($base) . " }\n";
        }
        $effect = self::HOVER_EFFECTS[$style['zaklad']['hover_effect'] ?? ''] ?? null;
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
        foreach (['tablet', 'mobil'] as $state) {
            $block = '';
            if (($d = $declarations($style[$state] ?? [])) !== '') {
                $block .= $selector . ' { ' . $d . ' } ';
            }
            if (($d = $declarations($style['hover_' . $state] ?? [])) !== '') {
                $block .= $selector . ':is(:hover, :focus-visible) { ' . $d . ' } ';
            }
            if (($d = $declarations($style['aktivni_' . $state] ?? [])) !== '') {
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
            if (preg_match('/^(--ka-[a-z0-9-]{1,40}|-?[a-z][a-z-]{1,40})\s*:\s*([^;{}<>\\\\@]{1,200})$/i', $declarations, $m)
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
