<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Settings;
use Kaleta\Front\SiteIdentity;

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
    public const array COLORS = ['primarni' => 'Primary', 'sekundarni' => 'Secondary', 'text' => 'Text', 'pozadi' => 'Pozadí', 'plocha' => 'Surface (cards, footer)'];

    /** Color tokens to choose from in the builder (key => description). */
    public const array COLOR_TOKENS = [
        'primarni' => 'Primary', 'primarni-jemna' => 'Primary – soft', 'na-primarni' => 'Text on primary', 'sekundarni' => 'Secondary',
        'text' => 'Text', 'tlumeny' => 'Muted text', 'pozadi' => 'Pozadí', 'plocha' => 'Surface', 'linka' => 'Linka', 'bila' => 'White', 'cerna' => 'Black',
    ];

    public const array SPACES = ['2xs' => 0.25, 'xs' => 0.5, 's' => 0.75, 'm' => 1, 'l' => 1.5, 'xl' => 2.5, '2xl' => 4, '3xl' => 6];
    public const array STEPS = ['-1', '0', '1', '2', '3', '4', '5'];
    public const array RADII = ['0' => '0', 's' => '0.375rem', 'm' => '0.75rem', 'l' => '1.25rem', 'plne' => '999px'];
    public const array SHADOWS = [
        's' => '0 1px 2px rgb(0 0 0 / 0.06), 0 1px 3px rgb(0 0 0 / 0.1)',
        'm' => '0 4px 12px rgb(0 0 0 / 0.08), 0 2px 4px rgb(0 0 0 / 0.06)',
        'l' => '0 18px 40px rgb(0 0 0 / 0.12), 0 6px 12px rgb(0 0 0 / 0.06)',
    ];

    /**
     * Typography styles: a named combination of size, weight, line height and font. An element gets the style with one choice
     * („Nadpis sekce“, „Perex“) and a change in Appearance shows on the whole site. key => [name, step, weight, line height, heading font]
     */
    public const array TYPOGRAPHY = [
        'title' => ['Main title', '5', 800, 1.1, true],
        'nadpis-sekce' => ['Section heading', '4', 700, 1.15, true],
        'podnadpis' => ['Podnadpis', '2', 600, 1.3, true],
        'perex' => ['Lead', '1', 400, 1.55, false],
        'text' => ['Body text', '0', 400, 1.6, false],
        'drobny' => ['Small text', '-1', 400, 1.5, false],
        'nadtitulek' => ['Eyebrow', '-1', 600, 1.3, false],
    ];

    /** Font weights offered for typography styles. */
    public const array FONT_WEIGHTS = [300 => 'tenké', 400 => 'normální', 500 => 'střední', 600 => 'polotučné', 700 => 'tučné', 800 => 'extra bold'];

    /**
     * Order of the cascade layers for the whole site: tokens, shared elements (image/web.css), layout, base of builder elements, classes, element styles.
     * A later layer wins regardless of specificity – nothing has to be overridden with selectors or !important.
     */
    public const string LAYERS = '@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;';

    /** Fluid scales stretch between these viewport widths (rem). */
    private const float VIEWPORT_MIN = 22.5;
    private const float VIEWPORT_MAX = 80;

    public const array DEFAULTS = [
        'barvy' => ['primarni' => '#2b5be3', 'sekundarni' => '#0f766e', 'text' => '#16181d', 'pozadi' => '#ffffff', 'plocha' => '#f5f6f8'],
        'barvy_tmave' => ['text' => '#eceef2', 'pozadi' => '#121418', 'plocha' => '#1b1e24'],
        'pismo_titulky' => 'moderni', 'pismo_text' => 'moderni',
        'zaklad_min' => 1.0, 'zaklad_max' => 1.125, 'pomer_min' => 1.2, 'pomer_max' => 1.25,
        'sirka' => 72, 'sirka_textu' => 44, 'zaobleni' => 'm',
    ];

    /** Typographic scale ratios (step n = base × ratio^n): the larger, the more the headings differ from the text. */
    public const array RATIOS = ['1.125' => 'subtle (1.125)', '1.2' => 'calm (1.2)', '1.25' => 'balanced (1.25)', '1.333' => 'strong (1.333)', '1.414' => 'dramatic (1.414)', '1.5' => 'poster (1.5)'];

    /**
     * Presets: the whole appearance of the site in one click, then it can be fine-tuned. Keys not given have the default value.
     * key => [name, description, values]
     */
    public const array PRESETS = [
        'firemni' => ['Business', 'Blue, sans-serif type, modest rounding', [
            'barvy' => ['primarni' => '#2b5be3', 'sekundarni' => '#0f766e', 'text' => '#16181d', 'pozadi' => '#ffffff', 'plocha' => '#f5f6f8'],
            'pismo_titulky' => 'moderni', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.25, 'zaobleni' => 'm',
        ]],
        'remeslo' => ['Řemeslo', 'Warm earthy colours, serif headings', [
            'barvy' => ['primarni' => '#9a3412', 'sekundarni' => '#3f6212', 'text' => '#1c1917', 'pozadi' => '#fffbf5', 'plocha' => '#f5ede1'],
            'pismo_titulky' => 'klasicke', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.333, 'zaobleni' => 's',
        ]],
        'pratelsky' => ['Friendly', 'Fresh green, rounded type and corners', [
            'barvy' => ['primarni' => '#047857', 'sekundarni' => '#7c3aed', 'text' => '#132a22', 'pozadi' => '#ffffff', 'plocha' => '#effaf5'],
            'pismo_titulky' => 'zaoblene', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.25, 'zaobleni' => 'l',
        ]],
        'elegantni' => ['Elegant', 'Dark tones, large serif headings, sharp edges', [
            'barvy' => ['primarni' => '#1e293b', 'sekundarni' => '#a16207', 'text' => '#0f172a', 'pozadi' => '#fcfcfa', 'plocha' => '#f1f0ea'],
            'pismo_titulky' => 'elegantni', 'pismo_text' => 'knizni', 'pomer_min' => 1.25, 'pomer_max' => 1.414, 'zaobleni' => '0',
        ]],
        'technologie' => ['Technology', 'Purple, bold grotesque, high contrast', [
            'barvy' => ['primarni' => '#6d28d9', 'sekundarni' => '#0e7490', 'text' => '#0b0b12', 'pozadi' => '#ffffff', 'plocha' => '#f4f3fb'],
            'pismo_titulky' => 'grotesk', 'pismo_text' => 'moderni', 'pomer_min' => 1.25, 'pomer_max' => 1.414, 'zaobleni' => 'm',
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
     * @return list<array{popis: string, pomer: float, ok: bool}>
     */
    public static function contrasts(array $ds): array
    {
        $b = $ds['barvy'];
        $pairs = [
            ['Text on background', $b['text'], $b['pozadi']],
            ['Text on surface', $b['text'], $b['plocha']],
            ['Link (primary colour) on background', $b['primarni'], $b['pozadi']],
            ['Button text on primary colour', self::contrastColor($b['primarni']), $b['primarni']],
            ['Secondary colour on background', $b['sekundarni'], $b['pozadi']],
        ];

        return array_map(fn (array $d): array => ['popis' => $d[0], 'pomer' => $p = self::contrast($d[1], $d[2]), 'ok' => $p >= 4.5], $pairs);
    }

    /** @return array<string, mixed> the stored value completed with the defaults (and with the color and fonts from the older site Identity) */
    public static function load(Settings $siteSettings): array
    {
        // a preview of the draft look (Core\Look) renders with the draft design system
        $stored = \Kaleta\Core\Look::activeDesignSystem() ?? json_decode($siteSettings->get('design_system'), true);
        $ds = is_array($stored) ? $stored + self::DEFAULTS : self::DEFAULTS;
        $ds['barvy'] = (is_array($stored['barvy'] ?? null) ? $stored['barvy'] : []) + self::DEFAULTS['barvy'];
        $ds['barvy_tmave'] = (is_array($stored['barvy_tmave'] ?? null) ? $stored['barvy_tmave'] : []) + self::DEFAULTS['barvy_tmave'];
        if (!isset($stored['barvy']['primarni']) && preg_match('/^#[0-9a-f]{6}$/i', $siteSettings->get('brand_accent'))) {
            $ds['barvy']['primarni'] = strtolower($siteSettings->get('brand_accent'));
        }
        foreach (['pismo_titulky' => 'brand_heading_font', 'pismo_text' => 'brand_text_font'] as $key => $old) {
            if (!isset($stored[$key]) && $siteSettings->get($old) !== '' && $siteSettings->get($old) !== 'vychozi') {
                $ds[$key] = $siteSettings->get($old);
            }
        }

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
            'barvy' => [], 'barvy_tmave' => [],
            'vlastni_pisma' => self::customFonts($ds['vlastni_pisma'] ?? []),
            'pismo_titulky' => isset(SiteIdentity::TITLE_FONTS[$ds['pismo_titulky'] ?? '']) && $ds['pismo_titulky'] !== 'vychozi' ? $ds['pismo_titulky'] : $v['pismo_titulky'],
            'pismo_text' => isset(SiteIdentity::TEXT_FONTS[$ds['pismo_text'] ?? '']) && $ds['pismo_text'] !== 'vychozi' ? $ds['pismo_text'] : $v['pismo_text'],
            'zaklad_min' => $number($ds['zaklad_min'] ?? null, 0.8, 1.5, $v['zaklad_min']),
            'zaklad_max' => $number($ds['zaklad_max'] ?? null, 0.8, 1.6, $v['zaklad_max']),
            'pomer_min' => $number($ds['pomer_min'] ?? null, 1.05, 1.5, $v['pomer_min']),
            'pomer_max' => $number($ds['pomer_max'] ?? null, 1.05, 1.62, $v['pomer_max']),
            'sirka' => $number($ds['sirka'] ?? null, 40, 120, $v['sirka']),
            'sirka_textu' => $number($ds['sirka_textu'] ?? null, 28, 60, $v['sirka_textu']),
            'zaobleni' => isset(self::RADII[$ds['zaobleni'] ?? '']) ? $ds['zaobleni'] : $v['zaobleni'],
            'typografie' => [],
        ];
        // typography styles: only what differs from the default is saved (step and weight)
        foreach (self::TYPOGRAPHY as $key => [, $step, $weight]) {
            $t = is_array($ds['typografie'][$key] ?? null) ? $ds['typografie'][$key] : [];
            $change = [];
            if (in_array((string) ($t['krok'] ?? ''), self::STEPS, true) && (string) $t['krok'] !== $step) {
                $change['krok'] = (string) $t['krok'];
            }
            if (isset(self::FONT_WEIGHTS[(int) ($t['tloustka'] ?? 0)]) && (int) $t['tloustka'] !== $weight) {
                $change['tloustka'] = (int) $t['tloustka'];
            }
            if ($change !== []) {
                $clean['typografie'][$key] = $change;
            }
        }
        // a custom font (vlastni-1…3) can be selected only when it is uploaded
        foreach (['pismo_titulky', 'pismo_text'] as $key) {
            if (preg_match('/^vlastni-([1-3])$/', (string) ($ds[$key] ?? ''), $m) && isset($clean['vlastni_pisma'][(int) $m[1] - 1])) {
                $clean[$key] = $ds[$key];
            }
        }
        foreach (self::COLORS as $key => $_) {
            $clean['barvy'][$key] = $color($ds['barvy'][$key] ?? null, $v['barvy'][$key]);
        }
        foreach ($v['barvy_tmave'] as $key => $defaults) {
            $clean['barvy_tmave'][$key] = $color($ds['barvy_tmave'][$key] ?? null, $defaults);
        }

        return $clean;
    }

    /**
     * The site's custom fonts (WOFF2 files from Media, hosted on the site's own server – no third-party servers or consent).
     *
     * @return list<array{nazev: string, soubor: string, tucny: string}>
     */
    private static function customFonts(mixed $fonts): array
    {
        $file = fn (mixed $v): string => is_string($v) && preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,200}\.woff2?)$#', trim($v), $m) && !str_contains($m[1], '..') ? $m[1] : '';
        $result = [];
        foreach (array_slice(is_array($fonts) ? $fonts : [], 0, 3) as $p) {
            $name = is_array($p) ? trim((string) preg_replace('/[^\p{L}\p{N} -]/u', '', (string) ($p['nazev'] ?? ''))) : '';
            if ($name !== '' && ($s = $file($p['soubor'] ?? null)) !== '') {
                $result[] = ['nazev' => mb_substr($name, 0, 40), 'soubor' => $s, 'tucny' => $file($p['tucny'] ?? null)];
            }
        }

        return $result;
    }

    /** The font-family value for the chosen font (custom ones too); the fallback is a system font of the same character. */
    public static function fontFamily(array $ds, string $key, bool $forHeadings): string
    {
        if (preg_match('/^vlastni-([1-3])$/', $key, $m) && isset($ds['vlastni_pisma'][(int) $m[1] - 1])) {
            return '"' . $ds['vlastni_pisma'][(int) $m[1] - 1]['nazev'] . '", system-ui, -apple-system, "Segoe UI", sans-serif';
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
        foreach (['pismo_text' => false, 'pismo_titulky' => true] as $key => $forHeadings) {
            if (preg_match('/^vlastni-([1-3])$/', (string) ($ds[$key] ?? ''), $m) && isset($ds['vlastni_pisma'][(int) $m[1] - 1])) {
                $font = $ds['vlastni_pisma'][(int) $m[1] - 1];
                $file = $forHeadings && $font['tucny'] !== '' ? $font['tucny'] : $font['soubor'];
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
        foreach ($ds['vlastni_pisma'] ?? [] as $p) {
            // one file = the regular weight (or a variable font with all weights), the second one, if any, bold
            $fonts .= '@font-face { font-family: "' . $p['nazev'] . '"; src: url("' . $base . '/' . $p['soubor'] . '") format("woff2"); font-weight: ' . ($p['tucny'] !== '' ? '400' : '100 900') . '; font-display: swap; }' . "\n";
            if ($p['tucny'] !== '') {
                $fonts .= '@font-face { font-family: "' . $p['nazev'] . '"; src: url("' . $base . '/' . $p['tucny'] . '") format("woff2"); font-weight: 600 900; font-display: swap; }' . "\n";
            }
        }
        $b = $ds['barvy'];
        $p = [
            '--ka-barva-primarni' => $b['primarni'], '--ka-barva-sekundarni' => $b['sekundarni'], '--ka-barva-text' => $b['text'],
            '--ka-barva-pozadi' => $b['pozadi'], '--ka-barva-plocha' => $b['plocha'],
            '--ka-barva-na-primarni' => self::contrastColor($b['primarni']),
            '--ka-barva-bila' => '#ffffff', '--ka-barva-cerna' => '#000000',
            // text of the light and dark mode, fixed – for surfaces that do not change with the mode (white and black background)
            '--ka-barva-text-svetle' => $b['text'], '--ka-barva-text-tmave' => $ds['barvy_tmave']['text'],
            '--ka-barva-tlumeny' => 'color-mix(in oklch, var(--ka-barva-text) 64%, var(--ka-barva-pozadi))',
            '--ka-barva-linka' => 'color-mix(in oklch, var(--ka-barva-text) 14%, var(--ka-barva-pozadi))',
            '--ka-barva-primarni-jemna' => 'color-mix(in oklch, var(--ka-barva-primarni) 12%, var(--ka-barva-pozadi))',
            '--ka-akcent' => 'var(--ka-barva-primarni)', // older name from the site Identity
            '--ka-pismo-text' => self::fontFamily($ds, $ds['pismo_text'], false),
            '--ka-pismo-titulky' => self::fontFamily($ds, $ds['pismo_titulky'], true),
            '--ka-sirka' => $ds['sirka'] . 'rem', '--ka-sirka-textu' => $ds['sirka_textu'] . 'rem',
            '--ka-zaobleni' => 'var(--ka-zaobleni-' . $ds['zaobleni'] . ')',
        ];
        // typographic scale: step n = base × ratio^n, a smaller base and ratio on a phone, larger on a large monitor
        foreach (self::STEPS as $n) {
            $p['--ka-krok-' . $n] = self::clamp($ds['zaklad_min'] * $ds['pomer_min'] ** (int) $n, $ds['zaklad_max'] * $ds['pomer_max'] ** (int) $n);
        }
        foreach (self::SPACES as $key => $multiplier) {
            $p['--ka-mezera-' . $key] = self::clamp($ds['zaklad_min'] * $multiplier, $ds['zaklad_max'] * $multiplier * ($multiplier >= 2 ? 1.25 : 1));
        }
        foreach (self::RADII as $key => $value) {
            $p['--ka-zaobleni-' . $key] = $value;
        }
        foreach (self::SHADOWS as $key => $value) {
            $p['--ka-stin-' . $key] = $value;
        }
        foreach (self::TYPOGRAPHY as $key => [, $step, $weight, $lineHeight, $forHeadings]) {
            $t = ($ds['typografie'] ?? [])[$key] ?? [];
            $p['--ka-typ-' . $key] = ($t['tloustka'] ?? $weight) . ' var(--ka-krok-' . ($t['krok'] ?? $step) . ')/' . $lineHeight . ' var(--ka-pismo-' . ($forHeadings ? 'titulky' : 'text') . ')';
        }
        $rows = array_map(fn (string $k, string $h): string => "\t{$k}: {$h};", array_keys($p), $p);
        $dark = array_map(fn (string $k, string $h): string => "\t\t--ka-barva-{$k}: {$h};", array_keys($ds['barvy_tmave']), $ds['barvy_tmave']);
        // English names (2.1) read the stored tokens again on every styled element, so they follow a token overridden in a
        // class or an element style (a dark section sets --ka-barva-text; var(--ka-color-text) inside it follows)
        $aliases = array_map(fn (string $en, string $cs): string => "\t{$en}: var({$cs});", array_keys(self::englishTokens()), self::englishTokens());

        // dark colors: by the device (unless the visitor chose „svetly“) and always when the site or the visitor chooses dark mode
        return self::LAYERS . "\n" . $fonts . "@layer tokeny {\n:root {\n" . implode("\n", $rows) . "\n}\n"
            . "@media (prefers-color-scheme: dark) {\n\t:root[data-tmavy]:not([data-tema=\"svetly\"]) {\n" . implode("\n", $dark) . "\n\t}\n}\n"
            . ":root[data-tmavy][data-tema=\"tmavy\"] {\n" . implode("\n", $dark) . "\n}\n"
            . ":where(:root, [class], [id], [style]) {\n" . implode("\n", $aliases) . "\n}\n}\n";
    }

    /**
     * English names of the design tokens (2.1): --ka-color-primary for --ka-barva-primarni and so on. They are read-only
     * aliases – to restyle a section, override the stored (Czech) token, and the English name follows.
     *
     * @return array<string, string> English custom property => stored custom property
     */
    public static function englishTokens(): array
    {
        $map = [];
        foreach (['primarni' => 'primary', 'sekundarni' => 'secondary', 'text' => 'text', 'pozadi' => 'background', 'plocha' => 'surface',
            'na-primarni' => 'on-primary', 'bila' => 'white', 'cerna' => 'black', 'text-svetle' => 'text-light', 'text-tmave' => 'text-dark',
            'tlumeny' => 'muted', 'linka' => 'line', 'primarni-jemna' => 'primary-soft'] as $cs => $en) {
            $map['--ka-color-' . $en] = '--ka-barva-' . $cs;
        }
        $map += ['--ka-font-body' => '--ka-pismo-text', '--ka-font-heading' => '--ka-pismo-titulky',
            '--ka-width' => '--ka-sirka', '--ka-text-width' => '--ka-sirka-textu', '--ka-radius' => '--ka-zaobleni'];
        foreach (self::RADII as $key => $_) {
            $map['--ka-radius-' . ($key === 'plne' ? 'full' : $key)] = '--ka-zaobleni-' . $key;
        }
        foreach (self::STEPS as $n) {
            $map['--ka-step-' . $n] = '--ka-krok-' . $n;
        }
        foreach (self::SPACES as $key => $_) {
            $map['--ka-space-' . $key] = '--ka-mezera-' . $key;
        }
        foreach (self::SHADOWS as $key => $_) {
            $map['--ka-shadow-' . $key] = '--ka-stin-' . $key;
        }
        foreach (['title' => 'title', 'nadpis-sekce' => 'section-heading', 'podnadpis' => 'subheading', 'perex' => 'lead', 'text' => 'body',
            'drobny' => 'small', 'nadtitulek' => 'eyebrow'] as $cs => $en) {
            $map['--ka-type-' . $en] = '--ka-typ-' . $cs;
        }

        return $map;
    }

    /**
     * Design tokens in the W3C Design Tokens format (DTCG, https://tr.designtokens.org/format/) for Figma, Tokens Studio and other tools.
     * Kaleta's complete design system is also in $extensions, so that nothing is lost when importing back.
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
            $steps[$n] = ['$type' => 'dimension', '$value' => ['value' => round($ds['zaklad_max'] * $ds['pomer_max'] ** (int) $n, 3), 'unit' => 'rem'], '$description' => 'monitor; na telefonu ' . round($ds['zaklad_min'] * $ds['pomer_min'] ** (int) $n, 3) . ' rem'];
        }
        $typography = [];
        foreach (self::TYPOGRAPHY as $key => [$name, $step, $weight, $lineHeight, $forHeadings]) {
            $t = ($ds['typografie'] ?? [])[$key] ?? [];
            $typography[$key] = ['$type' => 'typography', '$description' => $name, '$value' => [
                'fontFamily' => '{pismo.' . ($forHeadings ? 'titulky' : 'text') . '}', 'fontSize' => '{velikost.' . ($t['krok'] ?? $step) . '}',
                'fontWeight' => $t['tloustka'] ?? $weight, 'lineHeight' => $lineHeight, 'letterSpacing' => ['value' => $key === 'nadtitulek' ? 0.08 : 0, 'unit' => 'rem'],
            ]];
        }

        return [
            'color' => $colors($ds['barvy']),
            'barva-tmava' => $colors($ds['barvy_tmave']),
            'pismo' => ['titulky' => $font($ds['pismo_titulky'], true), 'text' => $font($ds['pismo_text'], false)],
            'velikost' => $steps,
            'typografie' => $typography,
            'mezera' => array_map(fn (float $n): array => ['$type' => 'dimension', '$value' => ['value' => round($ds['zaklad_max'] * $n, 3), 'unit' => 'rem']], self::SPACES),
            'zaobleni' => ['$type' => 'dimension', '$value' => ['value' => (float) (self::RADII[$ds['zaobleni']] === '999px' ? 999 : (float) self::RADII[$ds['zaobleni']]), 'unit' => self::RADII[$ds['zaobleni']] === '999px' ? 'px' : 'rem']],
            'sirka' => ['obsah' => ['$type' => 'dimension', '$value' => ['value' => $ds['sirka'], 'unit' => 'rem']], 'text' => ['$type' => 'dimension', '$value' => ['value' => $ds['sirka_textu'], 'unit' => 'rem']]],
            '$extensions' => ['cz.kaleta' => ['design_system' => $ds]],
        ];
    }

    /**
     * Design system from DTCG tokens: from a Kaleta export the whole of it (extension cz.kaleta), from another tool at least the colors –
     * by our keys and by common English names (primary, secondary, text, background, surface). The rest stays as it is.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $ds the current design system
     * @return array<string, mixed>|null null = the file contains nothing usable
     */
    public static function fromDtcg(array $tokens, array $ds): ?array
    {
        if (is_array($tokens['$extensions']['cz.kaleta']['design_system'] ?? null)) {
            return self::sanitize($tokens['$extensions']['cz.kaleta']['design_system'] + $ds);
        }
        $names = ['primarni' => ['primarni', 'primary', 'brand', 'accent'], 'sekundarni' => ['sekundarni', 'secondary'], 'text' => ['text', 'foreground', 'on-background'],
            'pozadi' => ['pozadi', 'background', 'bg'], 'plocha' => ['plocha', 'surface', 'muted']];
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
                if (!str_contains($path, 'tmav') && !str_contains($path, 'dark') && in_array(substr($path, strrpos($path, '.') + 1), $candidates, true)) {
                    $ds['barvy'][$ourKey] = $hex;
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
