<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;
use Talea\Core\Look;

/**
 * The looks gallery (#24): finished looks that ship in the release as data files (system/looks/<key>.json) – design tokens
 * (colours with a dark mode, library fonts, scale, radius, width) plus a header and a footer template (PartTemplates) and a
 * list of recommended ready sections (Library). A look is applied as a draft look (Core\Look): previewed on the whole site,
 * then published or discarded, and the previous look stays as a version. No PHP themes, no third-party assets: every file
 * records how it was built (`source`) and carries `"assets": "none-third-party"`.
 *
 * Onboarding (#46): every look lists the `topics` it fits, best first – a chosen topic re-ranks the gallery, it never hides a look –
 * and offers its own colour `palettes` (primary and secondary in light and dark mode) and font pairs (`fonts`) to try on before it is
 * applied. Variant 0 is the look as it is.
 */
final class Looks
{
    /** Topics of the onboarding's first question (key => English name; the order of the list). */
    public const array TOPICS = ['photography' => 'Photography', 'design' => 'Design', 'education' => 'Education', 'consulting' => 'Consulting', 'art' => 'Art',
        'health' => 'Health and wellness', 'marketing' => 'Marketing', 'tech' => 'Technology', 'construction' => 'Construction and crafts', 'clothing' => 'Clothing and fashion',
        'music' => 'Music', 'real-estate' => 'Real estate', 'beauty' => 'Beauty', 'travel' => 'Travel', 'weddings' => 'Weddings', 'restaurant' => 'Restaurant and café', 'other' => 'Other'];

    /** @return array<string, array{key: string, name: string, description: string, source: string, topics: list<string>, design_system: array<string, mixed>, palettes: list<array{colors: array<string, string>, colors_dark: array<string, string>}>, fonts: list<array{font_heading: string, font_body: string}>, header: string, footer: string, sections: list<string>}> */
    public static function all(): array
    {
        static $looks = null;
        if ($looks !== null) {
            return $looks;
        }
        $looks = [];
        foreach (glob(TALEA_SYSTEM . '/looks/*.json') ?: [] as $file) {
            $l = json_decode((string) file_get_contents($file), true);
            $key = basename($file, '.json');
            if (!is_array($l) || ($l['key'] ?? '') !== $key || !preg_match('/^[a-z0-9-]+$/', $key) || ($l['assets'] ?? '') !== 'none-third-party' || !is_array($l['design_system'] ?? null)
                || !isset(PartTemplates::LIST['header'][$l['header'] ?? ''], PartTemplates::LIST['footer'][$l['footer'] ?? ''])) {
                continue;
            }
            $ds = DesignSystem::sanitize($l['design_system'] + DesignSystem::DEFAULTS);
            $palettes = [];
            foreach ((array) ($l['palettes'] ?? []) as $p) {
                $v = DesignSystem::sanitize(['colors' => (array) ($p['colors'] ?? []) + $ds['colors'], 'colors_dark' => (array) ($p['colors_dark'] ?? []) + $ds['colors_dark']] + $ds);
                $palettes[] = ['colors' => $v['colors'], 'colors_dark' => $v['colors_dark']];
            }
            $fonts = [];
            foreach ((array) ($l['fonts'] ?? []) as $f) {
                $v = DesignSystem::sanitize(['font_heading' => (string) ($f['font_heading'] ?? ''), 'font_body' => (string) ($f['font_body'] ?? '')] + $ds);
                $fonts[] = ['font_heading' => $v['font_heading'], 'font_body' => $v['font_body']];
            }
            $looks[$key] = ['key' => $key, 'name' => (string) $l['name'], 'description' => (string) $l['description'], 'source' => (string) ($l['source'] ?? ''),
                'topics' => array_values(array_filter((array) ($l['topics'] ?? []), fn (mixed $t): bool => is_string($t) && isset(self::TOPICS[$t]))),
                'design_system' => $ds, 'palettes' => [['colors' => $ds['colors'], 'colors_dark' => $ds['colors_dark']], ...$palettes],
                'fonts' => [['font_heading' => $ds['font_heading'], 'font_body' => $ds['font_body']], ...$fonts], 'header' => $l['header'], 'footer' => $l['footer'],
                'sections' => array_values(array_filter((array) ($l['sections'] ?? []), fn (mixed $k): bool => is_string($k) && Library::section($k) !== null))];
        }
        ksort($looks);

        return $looks;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * All looks, the best fits of a topic first: a look's rank is the topic's place in its `topics`, a look without the topic comes
     * last; ties keep the gallery order. An unknown topic changes nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ranked(string $topic): array
    {
        $looks = self::all();
        if (!isset(self::TOPICS[$topic])) {
            return $looks;
        }
        $rank = fn (array $l): int => ($i = array_search($topic, $l['topics'], true)) === false ? PHP_INT_MAX : $i;
        uasort($looks, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $looks;
    }

    /** The topic key of what the owner typed or chose: a key or a topic's name in English or the admin language; '' when none fits. */
    public static function topic(string $input): string
    {
        $input = mb_strtolower(trim($input));
        foreach (self::TOPICS as $key => $name) {
            if ($input === $key || $input === mb_strtolower($name) || $input === mb_strtolower(t($name))) {
                return $key;
            }
        }

        return '';
    }

    /**
     * The design system of a look with one of its palettes and font pairs (0 = the look's own); null for an unknown look or variant.
     *
     * @return array<string, mixed>|null
     */
    public static function variant(string $key, int $palette = 0, int $fonts = 0): ?array
    {
        $look = self::get($key);
        if ($look === null || !isset($look['palettes'][$palette], $look['fonts'][$fonts])) {
            return null;
        }

        return array_replace($look['design_system'], $look['palettes'][$palette], $look['fonts'][$fonts]);
    }

    /**
     * Applies a look as a draft look: the design system goes to the look draft, the header and the footer template to the drafts
     * of the parts (marked in the look draft, so publishing and discarding the look handle them). Nothing public changes.
     * Pages, news, menus, classes and settings stay as they are.
     */
    public static function apply(App $app, string $key, int $palette = 0, int $fonts = 0): bool
    {
        if (($refusal = \Talea\Core\LookFamily::refusal($app->db())) !== null) {
            throw new \DomainException($refusal); // a look family's child takes its look from the parent
        }
        $look = self::get($key);
        $ds = self::variant($key, $palette, $fonts);
        if ($look === null || $ds === null) {
            return false;
        }
        $s = $app->settings();
        $db = $app->db();
        // an earlier look that waits in the draft is replaced as a whole: its part drafts go first
        Look::discardParts($db, Look::draft($s)['parts'] ?? []);
        $draft = Look::draft($s);
        unset($draft['parts']);
        $s->set('look_draft', $draft === [] ? '' : (string) json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $content = \Talea\Core\Language::ofContent($s, '');
        $extensions = \Talea\Core\Extensions::enabled($s);
        $parts = [];
        foreach (['header', 'footer'] as $type) {
            if (SiteParts::applyTemplate($db, $type, '', '', $look[$type], $content, $extensions)) {
                $parts[$type] = $look[$type];
            }
        }
        Look::setDesignSystem($s, $ds);
        Look::setParts($s, $parts);

        return true;
    }
}
