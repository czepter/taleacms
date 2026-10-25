<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Language;
use Talea\Front\SiteIdentity;

/**
 * Which writing systems the heading and text fonts have letters for (#159). A language version in Arabic, Hebrew, Cyrillic or
 * Greek whose font has no letters of that script shows them in whatever font the visitor's device falls back to: readable, but
 * not the chosen design. Appearance and the site audit warn and suggest fonts that have them.
 *
 * A library font says what it covers in the "scripts" of its font.json; a system font stack by the fonts it names first. A font
 * uploaded to Media is not checked: Talea cannot read the letters of a WOFF2 file.
 */
final class FontScripts
{
    /** A language (its base code) => the script it is written in; languages written in Latin are not listed. */
    public const array LANGUAGES = [
        'ar' => 'arabic', 'fa' => 'arabic', 'ur' => 'arabic', 'he' => 'hebrew', 'yi' => 'hebrew',
        'bg' => 'cyrillic', 'mk' => 'cyrillic', 'ru' => 'cyrillic', 'uk' => 'cyrillic', 'el' => 'greek',
    ];

    /** Script => its name in a message. */
    public const array NAMES = ['arabic' => 'Arabic', 'hebrew' => 'Hebrew', 'cyrillic' => 'Cyrillic', 'greek' => 'Greek'];

    /**
     * System font stacks (Front\SiteIdentity) => the scripts their first fonts have besides Latin: the device's system font has
     * every script; Georgia, Helvetica and Arial, Charter and Cambria and the monospace fonts have Cyrillic and Greek; Bodoni and
     * Didot and the rounded faces only Latin.
     */
    private const array STACKS = [
        'modern' => ['arabic', 'hebrew', 'cyrillic', 'greek'],
        'grotesque' => ['cyrillic', 'greek'], 'classic' => ['cyrillic', 'greek'], 'serif' => ['cyrillic', 'greek'], 'book' => ['cyrillic', 'greek'], 'typewriter' => ['cyrillic', 'greek'],
        'elegant' => [], 'rounded' => [],
    ];

    /**
     * For each script of the site's languages that the heading or the text font lacks, one row.
     *
     * @param array<string, mixed> $ds the design system (DesignSystem::load)
     * @param list<string> $languages the default and the additional languages
     * @return list<array{key: string, font: string, script: string, languages: list<string>, suggestions: list<string>}> key = font_heading | font_body;
     *         suggestions = names of library fonts and system fonts that have the script
     */
    public static function problems(array $ds, array $languages): array
    {
        $byScript = [];
        foreach (array_unique($languages) as $code) {
            if (($script = self::LANGUAGES[Language::base($code)] ?? null) !== null) {
                $byScript[$script][] = $code;
            }
        }
        $out = [];
        foreach (['font_heading' => SiteIdentity::TITLE_FONTS, 'font_body' => SiteIdentity::TEXT_FONTS] as $key => $stacks) {
            $choice = (string) ($ds[$key] ?? '');
            $library = DesignSystem::libraryFont($choice);
            $covered = $library !== null ? $library['scripts'] : (self::STACKS[$choice] ?? null);
            if ($covered === null) {
                continue; // a font from Media, or a stack this list does not know
            }
            foreach ($byScript as $script => $codes) {
                if (!in_array($script, $covered, true)) {
                    $out[] = ['key' => $key, 'font' => $library !== null ? $library['name'] : t($stacks[$choice][0]), 'script' => $script, 'languages' => $codes, 'suggestions' => self::suggestions($script, $stacks)];
                }
            }
        }

        return $out;
    }

    /** @param array{key: string, font: string, script: string, languages: list<string>, suggestions: list<string>} $problem */
    public static function message(array $problem): string
    {
        $versions = implode(', ', array_map(fn (string $code): string => Language::AVAILABLE[$code][0] ?? $code, $problem['languages']));
        $script = t(self::NAMES[$problem['script']]);
        $text = $problem['key'] === 'font_heading'
            ? t('The heading font %s has no %s letters: the %s version shows them in a fallback font of the visitor’s device.', $problem['font'], $script, $versions)
            : t('The text font %s has no %s letters: the %s version shows them in a fallback font of the visitor’s device.', $problem['font'], $script, $versions);

        return $text . ' ' . t('Fonts that have them: %s.', implode(', ', $problem['suggestions']));
    }

    /**
     * Library fonts with the script, then the system fonts offered for the role that have it.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $stacks
     * @return list<string>
     */
    private static function suggestions(string $script, array $stacks): array
    {
        $names = [];
        foreach (DesignSystem::libraryFonts() as $font) {
            if (in_array($script, $font['scripts'], true)) {
                $names[] = $font['name'];
            }
        }
        foreach (self::STACKS as $key => $scripts) {
            if (isset($stacks[$key]) && in_array($script, $scripts, true)) {
                $names[] = t($stacks[$key][0]);
            }
        }

        return $names;
    }
}
