<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\DesignSystem;

/**
 * Look drafts (1.7): the design system, the shared classes and the menus change the whole site at once, so a change goes
 * to one draft of the look first – from Site appearance, the builder, the menu editor and Claude alike. The draft is
 * previewed on the whole site (a signed link, or an administrator in the builder), then published in one step or thrown
 * away. Publishing keeps the previous look as a version (the last 20), which comes back into the draft with one click.
 *
 * The draft is one JSON in the settings (look_draft): {design_system: {...}, classes: {name: {styl, css} | null = delete},
 * menus: {"location|language": items | null = automatic}}. Components and site parts have their own drafts in their builds.
 */
final class Look
{
    public const int VERSIONS = 20;

    /** Design system settings in words for the summary (colours come from DesignSystem::COLORS). */
    private const array DS_LABELS = ['font_heading' => 'Heading font', 'font_body' => 'Text font', 'base_min' => 'Base font size', 'base_max' => 'Base font size',
        'ratio_min' => 'Type scale', 'ratio_max' => 'Type scale', 'width' => 'Content width', 'text_width' => 'Text width', 'radius' => 'Corner radius',
        'custom_fonts' => 'Custom fonts', 'typography' => 'Typography styles'];

    /** The draft look applies to this request (a preview or the builder of an administrator). */
    private static ?Settings $active = null;

    /** Makes the rest of the request render with the draft look. */
    public static function activate(Settings $s): void
    {
        self::$active = $s;
    }

    public static function isActive(): bool
    {
        return self::$active !== null;
    }

    /** @return array{design_system?: array<string, mixed>, classes?: array<string, ?array>, menus?: array<string, ?array>} */
    public static function draft(Settings $s): array
    {
        $draft = json_decode($s->get('look_draft'), true);

        return is_array($draft) ? $draft : [];
    }

    public static function hasDraft(Settings $s): bool
    {
        return self::draft($s) !== [];
    }

    /** The draft design system while the draft look is active (DesignSystem::load), otherwise null. */
    public static function activeDesignSystem(): ?array
    {
        return self::$active !== null ? (self::draft(self::$active)['design_system'] ?? null) : null;
    }

    /** Draft items of a menu while the draft look is active: [whether the draft has the menu, its items or null = automatic]. */
    public static function activeMenu(string $location, string $language): array
    {
        $menus = self::$active !== null ? (self::draft(self::$active)['menus'] ?? []) : [];

        return array_key_exists($location . '|' . $language, $menus) ? [true, $menus[$location . '|' . $language]] : [false, null];
    }

    /* ---------- writing to the draft ---------- */

    public static function setDesignSystem(Settings $s, array $ds): void
    {
        self::write($s, fn (array $d): array => ['design_system' => DesignSystem::sanitize($ds)] + $d);
    }

    /**
     * A class into the draft; null = delete it on publishing. A class the site does not have yet goes live at once – it
     * changes nothing that is published, and new pages need it.
     *
     * @param array{styl: array<string, mixed>, css: string}|null $class
     * @param bool $draftOnly even a new class waits in the draft (a kit from the fleet console is reviewed as a whole, 2.16)
     * @return bool whether it went to the draft
     */
    public static function setClass(Settings $s, string $name, ?array $class, bool $draftOnly = false): bool
    {
        $db = $s->db();
        if (!$draftOnly && $class !== null && $db->value('SELECT 1 FROM {classes} WHERE name = ?', [$name]) === null) {
            $db->run('INSERT INTO {classes} (name, style, css, updated_at) VALUES (?, ?, ?, NOW())', [$name, (string) json_encode($class['style'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE), (string) $class['css']]);
            $d = self::draft($s);
            if (isset($d['classes']) && array_key_exists($name, $d['classes'])) {
                unset($d['classes'][$name]);
                $s->set('look_draft', $d === ['classes' => []] || $d === [] ? '' : (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            \Talea\Front\Cache::clear();

            return false;
        }
        self::write($s, function (array $d) use ($name, $class): array {
            $d['classes'][$name] = $class === null ? null : ['style' => $class['style'] ?: new \stdClass(), 'css' => (string) $class['css']];

            return $d;
        });

        return true;
    }

    /** A menu into the draft; null = the automatic menu. */
    public static function setMenu(Settings $s, string $location, string $language, ?array $items): void
    {
        self::write($s, function (array $d) use ($location, $language, $items): array {
            $d['menus'][$location . '|' . $language] = $items === null ? null : Menu::sanitize($items);

            return $d;
        });
    }

    /** A renamed class keeps its draft under the new name. */
    public static function renameClass(Settings $s, string $old, string $new): void
    {
        $d = self::draft($s);
        if (isset($d['classes']) && array_key_exists($old, $d['classes'])) {
            $d['classes'][$new] = $d['classes'][$old];
            unset($d['classes'][$old]);
            $s->set('look_draft', (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private static function write(Settings $s, callable $change): void
    {
        $s->set('look_draft', (string) json_encode($change(self::draft($s)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The header and footer templates a look (Builder\Looks) put into the drafts of the site parts: type => template key. */
    public static function setParts(Settings $s, array $parts): void
    {
        self::write($s, function (array $d) use ($parts): array {
            unset($d['parts']);

            return $parts === [] ? $d : $d + ['parts' => $parts];
        });
    }

    /** Throws the part drafts a look made away; a part that exists only because of the look goes too. */
    public static function discardParts(Db $db, array $parts): void
    {
        foreach (array_keys($parts) as $type) {
            $row = \Talea\Builder\SiteParts::row($db, (string) $type, '');
            if ($row === null) {
                continue;
            }
            $row['build'] === null ? $db->delete('site_parts', ['type' => $type, 'language' => '', 'variant' => ''])
                : $db->update('site_parts', ['build_draft' => null], ['type' => $type, 'language' => '', 'variant' => '']);
        }
    }

    public static function discard(Settings $s): void
    {
        self::discardParts($s->db(), self::draft($s)['parts'] ?? []);
        $s->set('look_draft', '');
    }

    /* ---------- reading with the draft (editors) ---------- */

    /** The design system as editors should show it: the draft when there is one. */
    public static function designSystem(Settings $s): array
    {
        $draft = self::draft($s)['design_system'] ?? null;

        return is_array($draft) ? DesignSystem::sanitize($draft + DesignSystem::DEFAULTS) : DesignSystem::load($s);
    }

    /**
     * Shared classes, with the draft applied when asked: name => [styl, css, draft (changed in the draft)].
     *
     * @return array<string, array{styl: array<string, mixed>, css: string, draft: bool}>
     */
    public static function classes(Db $db, Settings $s, bool $withDraft): array
    {
        $classes = [];
        foreach ($db->all('SELECT name, style, css FROM {classes} ORDER BY name') as $r) {
            $classes[$r['name']] = ['style' => json_decode((string) $r['style'], true) ?: [], 'css' => (string) $r['css'], 'draft' => false];
        }
        if ($withDraft) {
            foreach (self::draft($s)['classes'] ?? [] as $name => $class) {
                if ($class === null) {
                    unset($classes[$name]);
                } else {
                    $classes[$name] = ['style' => (array) ($class['style'] ?? []), 'css' => (string) ($class['css'] ?? ''), 'draft' => true];
                }
            }
            ksort($classes);
        }

        return $classes;
    }

    /** Classes of the given names for the page CSS: the draft ones while the draft look is active. */
    public static function classesForCss(Db $db, array $names): array
    {
        if (self::$active === null) {
            return $db->all('SELECT name, style, css FROM {classes} WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY name', $names);
        }
        $rows = [];
        foreach (self::classes($db, self::$active, true) as $name => $class) {
            if (in_array($name, $names, true)) {
                $rows[] = ['name' => $name, 'style' => (string) json_encode($class['style']), 'css' => $class['css']];
            }
        }

        return $rows;
    }

    /** Menu items as editors should show them: the draft when there is one. @return array{0: bool, 1: ?array} [from the draft, items] */
    public static function menuForEditing(Db $db, Settings $s, string $location, string $language): array
    {
        $menus = self::draft($s)['menus'] ?? [];
        if (array_key_exists($location . '|' . $language, $menus)) {
            return [true, $menus[$location . '|' . $language]];
        }

        return [false, Menu::load($db, $location, $language)];
    }

    /* ---------- publishing and versions ---------- */

    /**
     * What the draft changes, in words, for the admin bar, Claude and the change log (colours and fonts with before → after).
     *
     * @return list<string>
     */
    public static function summary(Db $db, Settings $s): array
    {
        $draft = self::draft($s);
        $lines = [];
        if (isset($draft['design_system'])) {
            $before = DesignSystem::load($s);
            $after = DesignSystem::sanitize($draft['design_system'] + DesignSystem::DEFAULTS);
            $changes = [];
            foreach (['colors', 'colors_dark'] as $group) {
                foreach ((array) $after[$group] as $k => $v) {
                    if (($before[$group][$k] ?? null) !== $v) {
                        $label = t(DesignSystem::COLORS[$k] ?? $k);
                        $changes[] = ($group === 'colors_dark' ? t('%s (dark mode)', $label) : $label) . ' ' . ($before[$group][$k] ?? '–') . ' → ' . $v;
                    }
                }
            }
            foreach ($after as $k => $v) {
                if (!in_array($k, ['colors', 'colors_dark'], true) && ($before[$k] ?? null) != $v) {
                    $changes[] = t(self::DS_LABELS[$k] ?? $k) . (is_scalar($v) && is_scalar($before[$k] ?? null) ? ' ' . $before[$k] . ' → ' . $v : '');
                }
            }
            $changes = array_values(array_unique($changes));
            $lines[] = t('Design system: %s', $changes === [] ? t('no change') : implode(', ', array_slice($changes, 0, 8)) . (count($changes) > 8 ? ' …' : ''));
        }
        if (($draft['parts'] ?? []) !== []) {
            $lines[] = t('Header and footer: %s', implode(', ', array_map(fn (string $type, string $key): string => t(\Talea\Builder\SiteParts::TYPES[$type][0] ?? $type) . ' – ' . t(\Talea\Builder\PartTemplates::LIST[$type][$key][0] ?? $key),
                array_keys($draft['parts']), $draft['parts'])));
        }
        if (($draft['classes'] ?? []) !== []) {
            $existing = array_column($db->all('SELECT name FROM {classes}'), 'name');
            $lines[] = t('Classes: %s', implode(', ', array_map(fn (string $name): string => $name . ' (' . ($draft['classes'][$name] === null ? t('deleted')
                : (in_array($name, $existing, true) ? t('changed') : t('new'))) . ')', array_keys($draft['classes']))));
        }
        if (($draft['menus'] ?? []) !== []) {
            $lines[] = t('Menus: %s', implode(', ', array_map(function (string $key) use ($draft): string {
                [$location, $language] = explode('|', $key) + ['', ''];

                return t(Menu::LOCATIONS[$location] ?? $location) . ($language !== '' ? ' (' . strtoupper($language) . ')' : '') . ($draft['menus'][$key] === null ? ' – ' . t('automatic') : '');
            }, array_keys($draft['menus']))));
        }

        return $lines;
    }

    /** The published look (to keep as a version before publishing). */
    private static function snapshot(Db $db, Settings $s): array
    {
        $menus = [];
        foreach ($db->all('SELECT location, language, items FROM {menus}') as $r) {
            $menus[$r['location'] . '|' . $r['language']] = json_decode((string) $r['items'], true) ?: [];
        }

        return ['design_system' => DesignSystem::load($s), 'classes' => array_map(fn (array $c): array => ['style' => $c['style'] ?: new \stdClass(), 'css' => $c['css']],
            self::classes($db, $s, false)), 'menus' => $menus];
    }

    /**
     * Publishes the draft look: the published one is kept as a version first. Returns the summary of what changed.
     *
     * @return list<string>
     */
    public static function publish(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $draft = self::draft($s);
        if ($draft === []) {
            return [];
        }
        $summary = self::summary($db, $s);
        Events::record($db, 'look.published', 'info', mb_substr(t('The look was published: %s', implode(', ', $summary)), 0, 255), ['username' => $app->auth()->id() ?: null]);
        $db->insert('look_versions', ['data' => (string) json_encode(self::snapshot($db, $s), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => mb_substr(implode(' · ', $summary), 0, 500), 'author' => $app->auth()->user()['user_id'] ?? null, 'created' => date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {look_versions} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {look_versions} ORDER BY id DESC LIMIT ' . self::VERSIONS . ') keep)');
        if (isset($draft['design_system'])) {
            $s->set('design_system', (string) json_encode(DesignSystem::sanitize($draft['design_system'] + DesignSystem::DEFAULTS), JSON_UNESCAPED_SLASHES));
        }
        foreach ($draft['classes'] ?? [] as $name => $class) {
            if ($class === null) {
                $db->delete('classes', ['name' => $name]);
            } else {
                $db->upsert('classes', ['name' => $name, 'style' => (string) json_encode($class['style'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE), 'css' => (string) $class['css'], 'updated_at' => date('Y-m-d H:i:s')], ['name']);
            }
        }
        foreach ($draft['menus'] ?? [] as $key => $items) {
            [$location, $language] = explode('|', $key) + ['', ''];
            Menu::save($db, $location, $language, $items);
        }
        foreach (array_keys($draft['parts'] ?? []) as $type) {
            $row = \Talea\Builder\SiteParts::row($db, (string) $type, '');
            if ($row !== null && $row['build_draft'] !== null) {
                \Talea\Builder\Publisher::part($app, $row); // the editor may have adjusted the draft since the look was applied
            }
        }
        $s->set('look_draft', '');
        \Talea\Front\Cache::clear();
        \Talea\Admin\ChangeLog::write($app, 'appearance', 'publish look', mb_substr(implode(' · ', $summary), 0, 255));

        return $summary;
    }

    /** @return list<array{id: int, summary: string, created: string, author: ?string}> newest first */
    public static function versions(Db $db): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'summary' => (string) $r['summary'], 'created' => (string) $r['created'], 'author' => $r['author_name']],
            $db->all('SELECT v.id, v.summary, v.created, u.name AS author_name FROM {look_versions} v LEFT JOIN {users} u ON u.user_id = v.author ORDER BY v.id DESC LIMIT ' . self::VERSIONS));
    }

    /** A kept version back into the draft (the site changes only after publishing): classes and menus it did not have go away. */
    public static function restoreVersion(App $app, int $id): void
    {
        $db = $app->db();
        $s = $app->settings();
        $version = json_decode((string) $db->value('SELECT data FROM {look_versions} WHERE id = ?', [$id]), true);
        if (!is_array($version)) {
            throw new \InvalidArgumentException('The look version does not exist.');
        }
        $classes = [];
        foreach (array_keys(self::classes($db, $s, false)) as $name) {
            $classes[$name] = null;
        }
        foreach ((array) ($version['classes'] ?? []) as $name => $class) {
            $classes[$name] = ['style' => $class['style'] ?? new \stdClass(), 'css' => (string) ($class['css'] ?? '')];
        }
        $menus = [];
        foreach ($db->all('SELECT location, language FROM {menus}') as $r) {
            $menus[$r['location'] . '|' . $r['language']] = null;
        }
        $menus = array_merge($menus, (array) ($version['menus'] ?? []));
        $s->set('look_draft', (string) json_encode(['design_system' => $version['design_system'] ?? DesignSystem::DEFAULTS, 'classes' => $classes ?: new \stdClass(),
            'menus' => $menus ?: new \stdClass()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
