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
 */
final class Looks
{
    /** @return array<string, array{key: string, name: string, description: string, source: string, design_system: array<string, mixed>, header: string, footer: string, sections: list<string>}> */
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
            $looks[$key] = ['key' => $key, 'name' => (string) $l['name'], 'description' => (string) $l['description'], 'source' => (string) ($l['source'] ?? ''),
                'design_system' => DesignSystem::sanitize($l['design_system'] + DesignSystem::DEFAULTS), 'header' => $l['header'], 'footer' => $l['footer'],
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
     * Applies a look as a draft look: the design system goes to the look draft, the header and the footer template to the drafts
     * of the parts (marked in the look draft, so publishing and discarding the look handle them). Nothing public changes.
     * Pages, news, menus, classes and settings stay as they are.
     */
    public static function apply(App $app, string $key): bool
    {
        $look = self::get($key);
        if ($look === null) {
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
        Look::setDesignSystem($s, $look['design_system']);
        Look::setParts($s, $parts);

        return true;
    }
}
