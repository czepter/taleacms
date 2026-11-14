<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The starter pages of an installation (Install\Starter): Home, About us, Services and Contact are recorded in the setting `starter_pages`
 * as `{page public id: fingerprint}`. A page is "untouched" while its fingerprint still matches, nobody saved it (updated_at stays NULL), it
 * has no draft and no translation – an import may then replace it instead of giving the imported page a number (/contact-2). The privacy
 * policy skeleton is a legal template and is never recorded. The home page is never replaced by an import.
 */
final class StarterPages
{
    public const string SETTING = 'starter_pages';

    /**
     * What the fingerprint covers. Not `build`: the Forms list rewrites the published build of the starter Contact page (form → form_embed)
     * without a person touching it; `text` (the build as text) still shows a changed content and `updated_at` every save.
     */
    private const array COLUMNS = ['title', 'slug', 'text', 'description', 'seo_title', 'image', 'visible', 'in_menu', 'language', 'parent_id'];

    /** @param array<string, mixed> $row a pages row */
    public static function fingerprint(array $row): string
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? '';
            $values[] = match ($column) {
                'visible', 'in_menu' => (int) $value,
                'parent_id' => $value === null || $value === '' ? null : (int) $value,
                default => (string) $value,
            };
        }

        return sha1((string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The value of the setting for these pages.
     *
     * @param list<int> $pageIds
     */
    public static function record(Db $db, array $pageIds): string
    {
        $recorded = [];
        foreach ($pageIds as $id) {
            if (($row = $db->one('SELECT * FROM {pages} WHERE page_id = ?', [$id])) !== null) {
                $recorded[(string) $row['public_id']] = self::fingerprint($row);
            }
        }

        return (string) json_encode($recorded, JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string>|null public id => fingerprint; null = this site recorded no starter pages */
    public static function recorded(Settings $settings): ?array
    {
        $value = $settings->get(self::SETTING);
        if ($value === '') {
            return null;
        }
        $data = json_decode($value, true);

        return is_array($data) ? array_map('strval', array_filter($data, 'is_string')) : null;
    }

    /**
     * Every recorded starter page that is not in the trash, with whether nobody has touched it since the installation.
     *
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool, untouched: bool}> by page id
     */
    private static function all(Db $db, Settings $settings): array
    {
        $recorded = self::recorded($settings);
        if ($recorded === null || $recorded === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($recorded), '?'));
        $rows = $db->all(
            "SELECT p.*, CASE WHEN EXISTS (SELECT 1 FROM {pages} t WHERE t.translation_of = p.page_id AND t.deleted_at IS NULL) THEN 1 ELSE 0 END AS translated FROM {pages} p WHERE p.public_id IN ({$marks}) AND p.deleted_at IS NULL",
            array_map('strval', array_keys($recorded)),
        );
        $home = $settings->int('home_page');
        $out = [];
        foreach ($rows as $row) {
            $untouched = $row['build_draft'] === null && $row['updated_at'] === null && !(int) $row['translated']
                && hash_equals($recorded[(string) $row['public_id']], self::fingerprint($row));
            $out[(int) $row['page_id']] = [
                'page_id' => (int) $row['page_id'], 'public_id' => (string) $row['public_id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug'],
                'language' => (string) $row['language'], 'visible' => (bool) $row['visible'], 'home' => (int) $row['page_id'] === $home, 'untouched' => $untouched,
            ];
        }

        return $out;
    }

    /**
     * The recorded pages nobody has touched since the installation: the fingerprint matches, nobody saved the page (updated_at is
     * still NULL), it has no draft and no translation.
     *
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool}> by page id
     */
    public static function untouched(Db $db, Settings $settings): array
    {
        return self::strip(array_filter(self::all($db, $settings), fn (array $p): bool => $p['untouched']));
    }

    /**
     * Recorded pages that still exist (not in the trash) but were changed: they stay when an import wants their address.
     *
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool}> by page id
     */
    public static function edited(Db $db, Settings $settings): array
    {
        return self::strip(array_filter(self::all($db, $settings), fn (array $p): bool => !$p['untouched']));
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool}>
     */
    private static function strip(array $pages): array
    {
        return array_map(function (array $p): array {
            unset($p['untouched']);

            return $p;
        }, $pages);
    }

    /**
     * What the pages an import is about to create would meet: one row per starter page that sits at the address of a target.
     *
     * @param list<array{old: string, slug: string, language: string}> $targets
     * @param list<int>|null $keep ids of the starter pages that stay; null = the default (every untouched one is replaced)
     * @return list<array{old: string, page_id: int, public_id: string, title: string, address: string, published: bool, action: string}> action: replace | keep | edited | home
     */
    public static function choices(Db $db, Settings $settings, array $targets, ?array $keep): array
    {
        $byAddress = [];
        foreach (self::all($db, $settings) as $page) {
            $byAddress[$page['language'] . "\n" . $page['slug']] = $page;
        }
        $out = [];
        $seen = [];
        foreach ($targets as $target) {
            $page = $byAddress[$target['language'] . "\n" . $target['slug']] ?? null;
            if ($page === null || isset($seen[$page['page_id']])) {
                continue;
            }
            $seen[$page['page_id']] = true;
            $out[] = ['old' => $target['old'], 'page_id' => $page['page_id'], 'public_id' => $page['public_id'], 'title' => $page['title'], 'address' => '/' . $page['slug'], 'published' => $page['visible'],
                'action' => self::action($page, $keep)];
        }

        return $out;
    }

    /** @param array{page_id: int, home: bool, untouched: bool} $page */
    private static function action(array $page, ?array $keep): string
    {
        return match (true) {
            $page['home'] => 'home',
            !$page['untouched'] => 'edited',
            $keep !== null && in_array($page['page_id'], $keep, true) => 'keep',
            default => 'replace',
        };
    }

    /**
     * The importers' hook before they choose a slug: when a starter page sits at the address, an untouched one that is not the home
     * page and not asked to stay goes to the trash (its address is free for the imported page); otherwise it stays. Both are logged.
     *
     * @param list<int>|null $keep ids of the starter pages that stay
     * @param array{replaced?: list<array<string, string>>, kept?: list<array<string, string>>, renamed?: list<array<string, string>>} $log
     * @return bool a starter page still holds the address (the imported page gets a number)
     */
    public static function makeRoom(Db $db, Settings $settings, string $slug, string $language, ?array $keep, array &$log, string $old): bool
    {
        $page = null;
        foreach (self::all($db, $settings) as $candidate) {
            if ($candidate['slug'] === $slug && $candidate['language'] === $language) {
                $page = $candidate;
                break;
            }
        }
        if ($page === null) {
            return false;
        }
        $entry = ['old' => $old, 'title' => $page['title'], 'address' => '/' . $page['slug']];
        $action = self::action($page, $keep);
        if ($action === 'replace' && self::retire($db, $settings, $page['page_id']) !== null) {
            if (count($log['replaced'] ?? []) < WpImport::MAX_LISTED) {
                $log['replaced'][] = $entry;
            }

            return false;
        }
        if (count($log['kept'] ?? []) < WpImport::MAX_LISTED) {
            $log['kept'][] = $entry + ['why' => in_array($action, ['keep', 'replace'], true) ? 'kept' : $action];
        }

        return true;
    }

    /**
     * Moves an untouched starter page to the trash (30 days) under a free address, so the import can take the original one. The home page
     * and every page that is not untouched stay.
     *
     * @return string|null the address the page had (now free); null = the page stays
     */
    public static function retire(Db $db, Settings $settings, int $pageId): ?string
    {
        $page = self::untouched($db, $settings)[$pageId] ?? null;
        if ($page === null || $page['home']) {
            return null;
        }
        $language = $page['language'];
        $parked = Slug::makeUnique($page['slug'] . '-starter', fn (string $x): bool => Slug::taken($db, 'pages', $x, $language), 120);
        $db->run('UPDATE {pages} SET deleted_at = NOW(), visible = FALSE, slug = ? WHERE page_id = ?', [$parked, $pageId]);
        Menu::setPage($db, $pageId, $language, false);
        \Talea\Front\Cache::clear();

        return $page['slug'];
    }

    /** Moves every untouched starter page except the home page to the trash; returns how many. */
    public static function trashAll(Db $db, Settings $settings): int
    {
        $count = 0;
        foreach (self::untouched($db, $settings) as $page) {
            if (!$page['home'] && self::retire($db, $settings, $page['page_id']) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
