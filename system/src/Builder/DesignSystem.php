<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Settings;
use Talea\Front\SiteIdentity;

/**
 * The site's design system: a few decisions (colors, fonts, base size and scale ratio, width, corner radius) from which the
 * CSS custom properties (tokens) for the layout and the builder are computed. Typography and spacing are fluid (clamp between a phone's
 * and a large monitor's width), color shades are mixed in the browser (color-mix in OKLCH) – so the site needs only a handful of numbers and nothing is duplicated.
 *
 * Stored in the „design_system“ setting (JSON). A missing key = the default value; the primary color and fonts are also taken from the older site Identity.
 */
final class DesignSystem
{
    /** Colors the site chooses; the other shades are computed from them. */
    public const array COLORS = ['primary' => 'Primary', 'secondary' => 'Secondary', 'text' => 'Text', 'background' => 'Background', 'surface' => 'Surface (cards, footer)'];

    /** Color tokens to choose from in the builder (key => description). */
    public const array COLOR_TOKENS = [
        'primary' => 'Primary', 'primary-soft' => 'Primary – soft', 'on-primary' => 'Text on primary', 'secondary' => 'Secondary',
        'text' => 'Text', 'muted' => 'Muted text', 'background' => 'Background', 'surface' => 'Surface', 'line' => 'Line', 'white' => 'White', 'black' => 'Black',
    ];

    public const array SPACES = ['2xs' => 0.25, 'xs' => 0.5, 's' => 0.75, 'm' => 1, 'l' => 1.5, 'xl' => 2.5, '2xl' => 4, '3xl' => 6];
    public const array STEPS = ['-1', '0', '1', '2', '3', '4', '5'];
    public const array RADII = ['0' => '0', 's' => '0.375rem', 'm' => '0.75rem', 'l' => '1.25rem', 'full' => '999px'];
    public const array SHADOWS = [
        's' => '0 1px 2px rgb(0 0 0 / 0.06), 0 1px 3px rgb(0 0 0 / 0.1)',
        'm' => '0 4px 12px rgb(0 0 0 / 0.08), 0 2px 4px rgb(0 0 0 / 0.06)',
        'l' => '0 18px 40px rgb(0 0 0 / 0.12), 0 6px 12px rgb(0 0 0 / 0.06)',
    ];

    /**
     * Typography styles: a named combination of size, weight, line height and font. An element gets the style with one choice
     * ("Section heading", "Lead") and a change in Appearance shows on the whole site. key => [name, step, weight, line height, heading font]
     */
    public const array TYPOGRAPHY = [
        'title' => ['Main title', '5', 800, 1.1, true],
        'section-heading' => ['Section heading', '4', 700, 1.15, true],
        'subheading' => ['Subheading', '2', 600, 1.3, true],
        'lead' => ['Lead', '1', 400, 1.55, false],
        'text' => ['Body text', '0', 400, 1.6, false],
        'small' => ['Small text', '-1', 400, 1.5, false],
        'eyebrow' => ['Eyebrow', '-1', 600, 1.3, false],
    ];

    /** Font weights offered for typography styles. */
    public const array FONT_WEIGHTS = [300 => 'thin', 400 => 'normal', 500 => 'medium', 600 => 'semibold', 700 => 'bold', 800 => 'extra bold'];

    /**
     * Order of the cascade layers for the whole site: tokens, shared elements (image/web.css), layout, base of builder elements, classes, element styles.
     * A later layer wins regardless of specificity – nothing has to be overridden with selectors or !important.
     */
    public const string LAYERS = '@layer tokens, shared, template, builder, classes, elements;';

    /** Fluid scales stretch between these viewport widths (rem). */
    private const float VIEWPORT_MIN = 22.5;
    private const float VIEWPORT_MAX = 80;

    public const array DEFAULTS = [
        'colors' => ['primary' => '#2b5be3', 'secondary' => '#0f766e', 'text' => '#16181d', 'background' => '#ffffff', 'surface' => '#f5f6f8'],
        'colors_dark' => ['text' => '#eceef2', 'background' => '#121418', 'surface' => '#1b1e24'],
        'font_heading' => 'modern', 'font_body' => 'modern',
        'base_min' => 1.0, 'base_max' => 1.125, 'ratio_min' => 1.2, 'ratio_max' => 1.25,
        'width' => 72, 'text_width' => 44, 'radius' => 'm',
    ];

    /** Typographic scale ratios (step n = base × ratio^n): the larger, the more the headings differ from the text. */
    public const array RATIOS = ['1.125' => 'subtle (1.125)', '1.2' => 'calm (1.2)', '1.25' => 'balanced (1.25)', '1.333' => 'strong (1.333)', '1.414' => 'dramatic (1.414)', '1.5' => 'poster (1.5)'];

    /**
     * Presets: the whole appearance of the site in one click, then it can be fine-tuned. Keys not given have the default value.
     * key => [name, description, values]
     */
    public const array PRESETS = [
        'business' => ['Business', 'Blue, sans-serif type, modest rounding', [
            'colors' => ['primary' => '#2b5be3', 'secondary' => '#0f766e', 'text' => '#16181d', 'background' => '#ffffff', 'surface' => '#f5f6f8'],
            'font_heading' => 'modern', 'font_body' => 'modern', 'ratio_min' => 1.2, 'ratio_max' => 1.25, 'radius' => 'm',
        ]],
        'crafts' => ['Craftsmanship', 'Warm earthy colours, serif headings', [
            'colors' => ['primary' => '#9a3412', 'secondary' => '#3f6212', 'text' => '#1c1917', 'background' => '#fffbf5', 'surface' => '#f5ede1'],
            'font_heading' => 'classic', 'font_body' => 'modern', 'ratio_min' => 1.2, 'ratio_max' => 1.333, 'radius' => 's',
        ]],
        'friendly' => ['Friendly', 'Fresh green, rounded type and corners', [
            'colors' => ['primary' => '#047857', 'secondary' => '#7c3aed', 'text' => '#132a22', 'background' => '#ffffff', 'surface' => '#effaf5'],
            'font_heading' => 'rounded', 'font_body' => 'modern', 'ratio_min' => 1.2, 'ratio_max' => 1.25, 'radius' => 'l',
        ]],
        'elegant' => ['Elegant', 'Dark tones, large serif headings, sharp edges', [
            'colors' => ['primary' => '#1e293b', 'secondary' => '#a16207', 'text' => '#0f172a', 'background' => '#fcfcfa', 'surface' => '#f1f0ea'],
            'font_heading' => 'elegant', 'font_body' => 'book', 'ratio_min' => 1.25, 'ratio_max' => 1.414, 'radius' => '0',
        ]],
        'tech' => ['Technology', 'Purple, bold grotesque, high contrast', [
            'colors' => ['primary' => '#6d28d9', 'secondary' => '#0e7490', 'text' => '#0b0b12', 'background' => '#ffffff', 'surface' => '#f4f3fb'],
            'font_heading' => 'grotesque', 'font_body' => 'modern', 'ratio_min' => 1.25, 'ratio_max' => 1.414, 'radius' => 'm',
        ]],
    ];

    /** A preset as a complete design system. @return array<string, mixed>|null */
    public static function preset(string $key): ?array
    {
        return isset(self::PRESETS[$key]) ? self::sanitize(self::PRESETS[$key][2] + self::DEFAULTS) : null;
    }

    /**
     * Legibility of color pairs by WCAG 2.2 AA (text 4.5 : 1). General pairs that really meet on the site.
     *
     * @return list<array{description: string, ratio: float, ok: bool}>
     */
    public static function contrasts(array $ds): array
    {
        $b = $ds['colors'];
        $pairs = [
            ['Text on background', $b['text'], $b['background']],
            ['Text on surface', $b['text'], $b['surface']],
            ['Link (primary colour) on background', $b['primary'], $b['background']],
            ['Button text on primary colour', self::contrastColor($b['primary']), $b['primary']],
            ['Secondary colour on background', $b['secondary'], $b['background']],
        ];

        return array_map(fn (array $d): array => ['description' => $d[0], 'ratio' => $p = self::contrast($d[1], $d[2]), 'ok' => $p >= 4.5], $pairs);
    }

    /** @return array<string, mixed> the stored value completed with the defaults */
    public static function load(Settings $siteSettings): array
    {
        // a preview of the draft look (Core\Look) renders with the draft design system
        $stored = \Talea\Core\Look::activeDesignSystem() ?? json_decode($siteSettings->get('design_system'), true);
        $ds = is_array($stored) ? $stored + self::DEFAULTS : self::DEFAULTS;
        $ds['colors'] = (is_array($stored['colors'] ?? null) ? $stored['colors'] : []) + self::DEFAULTS['colors'];
        $ds['colors_dark'] = (is_array($stored['colors_dark'] ?? null) ? $stored['colors_dark'] : []) + self::DEFAULTS['colors_dark'];

        return self::sanitize($ds);
    }

    /**
     * Values from the form or from AI: only known keys in the right shape and range, otherwise the defaults.
     *
     * @param array<string, mixed> $ds
     * @return array<string, mixed>
     */
    public static function sanitize(array $ds): array
    {
        $color = fn (mixed $v, string $defaults): string => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $defaults;
        $number = fn (mixed $v, float $min, float $max, float $defaults): float => is_numeric($v) ? round(max($min, min($max, (float) $v)), 3) : $defaults;
        $v = self::DEFAULTS;
        $clean = [
            'colors' => [], 'colors_dark' => [],
            'custom_fonts' => self::customFonts($ds['custom_fonts'] ?? []),
            'font_heading' => isset(SiteIdentity::TITLE_FONTS[$ds['font_heading'] ?? '']) && $ds['font_heading'] !== 'default' ? $ds['font_heading'] : $v['font_heading'],
            'font_body' => isset(SiteIdentity::TEXT_FONTS[$ds['font_body'] ?? '']) && $ds['font_body'] !== 'default' ? $ds['font_body'] : $v['font_body'],
            'base_min' => $number($ds['base_min'] ?? null, 0.8, 1.5, $v['base_min']),
            'base_max' => $number($ds['base_max'] ?? null, 0.8, 1.6, $v['base_max']),
            'ratio_min' => $number($ds['ratio_min'] ?? null, 1.05, 1.5, $v['ratio_min']),
            'ratio_max' => $number($ds['ratio_max'] ?? null, 1.05, 1.62, $v['ratio_max']),
            'width' => $number($ds['width'] ?? null, 40, 120, $v['width']),
            'text_width' => $number($ds['text_width'] ?? null, 28, 60, $v['text_width']),
            'radius' => isset(self::RADII[$ds['radius'] ?? '']) ? $ds['radius'] : $v['radius'],
            'typography' => [],
        ];
        // typography styles: only what differs from the default is saved (step and weight)
        foreach (self::TYPOGRAPHY as $key => [, $step, $weight]) {
            $t = is_array($ds['typography'][$key] ?? null) ? $ds['typography'][$key] : [];
            $change = [];
            if (in_array((string) ($t['step'] ?? ''), self::STEPS, true) && (string) $t['step'] !== $step) {
                $change['step'] = (string) $t['step'];
            }
            if (isset(self::FONT_WEIGHTS[(int) ($t['weight'] ?? 0)]) && (int) $t['weight'] !== $weight) {
                $change['weight'] = (int) $t['weight'];
            }
            if ($change !== []) {
                $clean['typography'][$key] = $change;
            }
        }
        // a custom font (custom-1…3) can be selected only when it is uploaded
        foreach (['font_heading', 'font_body'] as $key) {
            if (preg_match('/^custom-([1-3])$/', (string) ($ds[$key] ?? ''), $m) && isset($clean['custom_fonts'][(int) $m[1] - 1])) {
                $clean[$key] = $ds[$key];
            }
        }
        foreach (self::COLORS as $key => $_) {
            $clean['colors'][$key] = $color($ds['colors'][$key] ?? null, $v['colors'][$key]);
        }
        foreach ($v['colors_dark'] as $key => $defaults) {
            $clean['colors_dark'][$key] = $color($ds['colors_dark'][$key] ?? null, $defaults);
        }

        return $clean;
    }

    /**
     * The site's custom fonts (WOFF2 files from Media, hosted on the site's own server – no third-party servers or consent).
     *
     * @return list<array{name: string, file: string, bold: string}>
     */
    private static function customFonts(mixed $fonts): array
    {
        $file = fn (mixed $v): string => is_string($v) && preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,200}\.woff2?)$#', trim($v), $m) && !str_contains($m[1], '..') ? $m[1] : '';
        $result = [];
        foreach (array_slice(is_array($fonts) ? $fonts : [], 0, 3) as $p) {
            $name = is_array($p) ? trim((string) preg_replace('/[^\p{L}\p{N} -]/u', '', (string) ($p['name'] ?? ''))) : '';
            if ($name !== '' && ($s = $file($p['file'] ?? null)) !== '') {
                $result[] = ['name' => mb_substr($name, 0, 40), 'file' => $s, 'bold' => $file($p['bold'] ?? null)];
            }
        }

        return $result;
    }

    /** The font-family value for the chosen font (custom ones too); the fallback is a system font of the same character. */
    public static function fontFamily(array $ds, string $key, bool $forHeadings): string
    {
        if (preg_match('/^custom-([1-3])$/', $key, $m) && isset($ds['custom_fonts'][(int) $m[1] - 1])) {
            return '"' . $ds['custom_fonts'][(int) $m[1] - 1]['name'] . '", system-ui, -apple-system, "Segoe UI", sans-serif';
        }

        return ($forHeadings ? SiteIdentity::TITLE_FONTS : SiteIdentity::TEXT_FONTS)[$key][2] ?? 'system-ui, sans-serif';
    }

    /**
     * Preload tags for the font files that render text above the fold (2.8): the body face and the heading face, only when
     * they are the site's own WOFF2 files (the bundled choices are system fonts – nothing to download). Headings are bold
     * (TYPOGRAPHY), so a heading font with a separate bold file preloads that file; every @font-face has font-display: swap,
     * so text shows in the fallback font until the file arrives. Nothing else is preloaded – an unused weight would only
     * compete for bandwidth.
     */
    public static function fontPreloads(array $ds, string $base = ''): string
    {
        $files = [];
        foreach (['font_body' => false, 'font_heading' => true] as $key => $forHeadings) {
            if (preg_match('/^custom-([1-3])$/', (string) ($ds[$key] ?? ''), $m) && isset($ds['custom_fonts'][(int) $m[1] - 1])) {
                $font = $ds['custom_fonts'][(int) $m[1] - 1];
                $file = $forHeadings && $font['bold'] !== '' ? $font['bold'] : $font['file'];
                if (str_ends_with($file, '.woff2')) {
                    $files[$file] = true;
                }
            }
        }

        return implode("\n", array_map(fn (string $file): string => '<link rel="preload" href="' . e($base . '/' . $file) . '" as="font" type="font/woff2" crossorigin>', array_keys($files)));
    }

    /** Tokens as CSS custom properties in the first cascade layer; the layout and the builder only use them. $base = installation folder (for the font files). */
    public static function css(array $ds, string $base = ''): string
    {
        $fonts = '';
        foreach ($ds['custom_fonts'] ?? [] as $p) {
            // one file = the regular weight (or a variable font with all weights), the second one, if any, bold
            $fonts .= '@font-face { font-family: "' . $p['name'] . '"; src: url("' . $base . '/' . $p['file'] . '") format("woff2"); font-weight: ' . ($p['bold'] !== '' ? '400' : '100 900') . '; font-display: swap; }' . "\n";
            if ($p['bold'] !== '') {
                $fonts .= '@font-face { font-family: "' . $p['name'] . '"; src: url("' . $base . '/' . $p['bold'] . '") format("woff2"); font-weight: 600 900; font-display: swap; }' . "\n";
            }
        }
        $b = $ds['colors'];
        $p = [
            '--tl-color-primary' => $b['primary'], '--tl-color-secondary' => $b['secondary'], '--tl-color-text' => $b['text'],
            '--tl-color-background' => $b['background'], '--tl-color-surface' => $b['surface'],
            '--tl-color-on-primary' => self::contrastColor($b['primary']),
            '--tl-color-white' => '#ffffff', '--tl-color-black' => '#000000',
            // text of the light and dark mode, fixed – for surfaces that do not change with the mode (white and black background)
            '--tl-color-text-light' => $b['text'], '--tl-color-text-dark' => $ds['colors_dark']['text'],
            '--tl-color-muted' => 'color-mix(in oklch, var(--tl-color-text) 64%, var(--tl-color-background))',
            '--tl-color-line' => 'color-mix(in oklch, var(--tl-color-text) 14%, var(--tl-color-background))',
            '--tl-color-primary-soft' => 'color-mix(in oklch, var(--tl-color-primary) 12%, var(--tl-color-background))',
            '--tl-accent' => 'var(--tl-color-primary)', // older name from the site Identity
            '--tl-font-body' => self::fontFamily($ds, $ds['font_body'], false),
            '--tl-font-heading' => self::fontFamily($ds, $ds['font_heading'], true),
            '--tl-width' => $ds['width'] . 'rem', '--tl-text-width' => $ds['text_width'] . 'rem',
            '--tl-radius' => 'var(--tl-radius-' . $ds['radius'] . ')',
        ];
        // typographic scale: step n = base × ratio^n, a smaller base and ratio on a phone, larger on a large monitor
        foreach (self::STEPS as $n) {
            $p['--tl-step-' . $n] = self::clamp($ds['base_min'] * $ds['ratio_min'] ** (int) $n, $ds['base_max'] * $ds['ratio_max'] ** (int) $n);
        }
        foreach (self::SPACES as $key => $multiplier) {
            $p['--tl-space-' . $key] = self::clamp($ds['base_min'] * $multiplier, $ds['base_max'] * $multiplier * ($multiplier >= 2 ? 1.25 : 1));
        }
        foreach (self::RADII as $key => $value) {
            $p['--tl-radius-' . $key] = $value;
        }
        foreach (self::SHADOWS as $key => $value) {
            $p['--tl-shadow-' . $key] = $value;
        }
        foreach (self::TYPOGRAPHY as $key => [, $step, $weight, $lineHeight, $forHeadings]) {
            $t = ($ds['typography'] ?? [])[$key] ?? [];
            $p['--tl-type-' . $key] = ($t['weight'] ?? $weight) . ' var(--tl-step-' . ($t['step'] ?? $step) . ')/' . $lineHeight . ' var(--tl-font-' . ($forHeadings ? 'heading' : 'body') . ')';
        }
        $rows = array_map(fn (string $k, string $h): string => "\t{$k}: {$h};", array_keys($p), $p);
        // the dark overrides replace the colour properties (surfaces and text follow the mode)
        $dark = array_map(fn (string $k, string $h): string => "\t\t--tl-color-" . $k . ": {$h};", array_keys($ds['colors_dark']), $ds['colors_dark']);

        // dark colors: by the device (unless the visitor chose „light“) and always when the site or the visitor chooses dark mode
        return self::LAYERS . "\n" . $fonts . "@layer tokens {\n:root {\n" . implode("\n", $rows) . "\n}\n"
            . "@media (prefers-color-scheme: dark) {\n\t:root[data-dark]:not([data-theme=\"light\"]) {\n" . implode("\n", $dark) . "\n\t}\n}\n"
            . ":root[data-dark][data-theme=\"dark\"] {\n" . implode("\n", $dark) . "\n}\n"
            . "}\n";
    }

    /**
     * Design tokens in the W3C Design Tokens format (DTCG, https://tr.designtokens.org/format/) for Figma, Tokens Studio and other tools.
     * Talea's complete design system is also in $extensions, so that nothing is lost when importing back.
     *
     * @param array<string, mixed> $ds
     * @return array<string, mixed>
     */
    public static function toDtcg(array $ds): array
    {
        $colors = fn (array $b): array => array_map(fn (string $hex): array => ['$type' => 'color', '$value' => $hex], $b);
        $font = fn (string $key, bool $forHeadings): array => ['$type' => 'fontFamily', '$value' => array_map(fn (string $x): string => trim($x, " \"'"), explode(',', self::fontFamily($ds, $key, $forHeadings)))];
        $steps = [];
        foreach (self::STEPS as $n) {
            $steps[$n] = ['$type' => 'dimension', '$value' => ['value' => round($ds['base_max'] * $ds['ratio_max'] ** (int) $n, 3), 'unit' => 'rem'], '$description' => 'monitor; on a phone ' . round($ds['base_min'] * $ds['ratio_min'] ** (int) $n, 3) . ' rem'];
        }
        $typography = [];
        foreach (self::TYPOGRAPHY as $key => [$name, $step, $weight, $lineHeight, $forHeadings]) {
            $t = ($ds['typography'] ?? [])[$key] ?? [];
            $typography[$key] = ['$type' => 'typography', '$description' => $name, '$value' => [
                'fontFamily' => '{font.' . ($forHeadings ? 'heading' : 'body') . '}', 'fontSize' => '{size.' . ($t['step'] ?? $step) . '}',
                'fontWeight' => $t['weight'] ?? $weight, 'lineHeight' => $lineHeight, 'letterSpacing' => ['value' => $key === 'eyebrow' ? 0.08 : 0, 'unit' => 'rem'],
            ]];
        }

        return [
            'color' => $colors($ds['colors']),
            'color-dark' => $colors($ds['colors_dark']),
            'font' => ['heading' => $font($ds['font_heading'], true), 'body' => $font($ds['font_body'], false)],
            'size' => $steps,
            'typography' => $typography,
            'space' => array_map(fn (float $n): array => ['$type' => 'dimension', '$value' => ['value' => round($ds['base_max'] * $n, 3), 'unit' => 'rem']], self::SPACES),
            'radius' => ['$type' => 'dimension', '$value' => ['value' => (float) (self::RADII[$ds['radius']] === '999px' ? 999 : (float) self::RADII[$ds['radius']]), 'unit' => self::RADII[$ds['radius']] === '999px' ? 'px' : 'rem']],
            'width' => ['content' => ['$type' => 'dimension', '$value' => ['value' => $ds['width'], 'unit' => 'rem']], 'text' => ['$type' => 'dimension', '$value' => ['value' => $ds['text_width'], 'unit' => 'rem']]],
            '$extensions' => ['cz.talea' => ['design_system' => $ds]],
        ];
    }

    /**
     * Design system from DTCG tokens: from a Talea export the whole of it (extension cz.talea), from another tool at least the colors –
     * by our keys and by common English names (primary, secondary, text, background, surface). The rest stays as it is.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $ds the current design system
     * @return array<string, mixed>|null null = the file contains nothing usable
     */
    public static function fromDtcg(array $tokens, array $ds): ?array
    {
        if (is_array($tokens['$extensions']['cz.talea']['design_system'] ?? null)) {
            return self::sanitize($tokens['$extensions']['cz.talea']['design_system'] + $ds);
        }
        $names = ['primary' => ['primary', 'primary', 'brand', 'accent'], 'secondary' => ['secondary', 'secondary'], 'text' => ['text', 'foreground', 'on-background'],
            'background' => ['background', 'background', 'bg'], 'surface' => ['surface', 'surface', 'muted']];
        $found = [];
        $walk = function (array $group, string $path) use (&$walk, &$found): void {
            foreach ($group as $key => $value) {
                if (!is_array($value) || str_starts_with((string) $key, '$')) {
                    continue;
                }
                if (isset($value['$value']) && is_string($value['$value']) && preg_match('/^#[0-9a-f]{6}$/i', $value['$value'])) {
                    $found[strtolower($path . '.' . $key)] = strtolower($value['$value']);
                } else {
                    $walk($value, $path . '.' . $key);
                }
            }
        };
        $walk($tokens, '');
        $change = false;
        foreach ($names as $ourKey => $candidates) {
            foreach ($found as $path => $hex) {
                if (!str_contains($path, 'dark') && in_array(substr($path, strrpos($path, '.') + 1), $candidates, true)) {
                    $ds['colors'][$ourKey] = $hex;
                    $change = true;
                    break;
                }
            }
        }

        return $change ? self::sanitize($ds) : null;
    }

    /** A fluid value in rem between VIEWPORT_MIN and VIEWPORT_MAX. */
    public static function clamp(float $min, float $max): string
    {
        if (abs($max - $min) < 0.001) {
            return self::rem($min);
        }
        $slope = ($max - $min) / (self::VIEWPORT_MAX - self::VIEWPORT_MIN);
        $offset = $min - $slope * self::VIEWPORT_MIN;
        [$lower, $upper] = $min < $max ? [$min, $max] : [$max, $min];

        return 'clamp(' . self::rem($lower) . ', ' . self::rem($offset) . ' + ' . round($slope * 100, 4) . 'vw, ' . self::rem($upper) . ')';
    }

    private static function rem(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') . 'rem';
    }

    /** White or near black – whichever has the better contrast on the given color (WCAG relative luminance). */
    public static function contrastColor(string $hex): string
    {
        return self::contrast($hex, '#ffffff') >= self::contrast($hex, '#111111') ? '#ffffff' : '#111111';
    }

    /** Contrast ratio of two colors by WCAG 2.2 (1–21). */
    public static function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $channels = array_map(fn (string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
            $linear = array_map(fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        [$lighter, $darker] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }
}
