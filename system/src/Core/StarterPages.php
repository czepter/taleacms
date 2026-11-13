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
     * The recorded pages nobody has touched since the installation.
     *
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool}> by page id
     */
    public static function untouched(Db $db, Settings $settings): array
    {
        $recorded = self::recorded($settings);
        if ($recorded === null || $recorded === []) {
            return [];
        }
        $ids = array_keys($recorded);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $db->all(
            "SELECT p.* FROM {pages} p WHERE p.public_id IN ({$marks}) AND p.deleted_at IS NULL AND p.build_draft IS NULL AND p.updated_at IS NULL"
            . ' AND NOT EXISTS (SELECT 1 FROM {pages} t WHERE t.translation_of = p.page_id AND t.deleted_at IS NULL)',
            array_map('strval', $ids),
        );
        $home = $settings->int('home_page');
        $out = [];
        foreach ($rows as $row) {
            if (!hash_equals($recorded[(string) $row['public_id']], self::fingerprint($row))) {
                continue;
            }
            $out[(int) $row['page_id']] = [
                'page_id' => (int) $row['page_id'],
                'public_id' => (string) $row['public_id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'language' => (string) $row['language'],
                'visible' => (bool) $row['visible'],
                'home' => (int) $row['page_id'] === $home,
            ];
        }

        return $out;
    }

    /**
     * Recorded pages that still exist (not in the trash) but were changed: they stay when an import wants their address.
     *
     * @return array<int, array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, home: bool}> by page id
     */
    public static function edited(Db $db, Settings $settings): array
    {
        $recorded = self::recorded($settings);
        if ($recorded === null || $recorded === []) {
            return [];
        }
        $untouched = self::untouched($db, $settings);
        $marks = implode(', ', array_fill(0, count($recorded), '?'));
        $home = $settings->int('home_page');
        $out = [];
        foreach ($db->all("SELECT page_id, public_id, title, slug, language, visible FROM {pages} WHERE public_id IN ({$marks}) AND deleted_at IS NULL", array_map('strval', array_keys($recorded))) as $row) {
            if (!isset($untouched[(int) $row['page_id']])) {
                $out[(int) $row['page_id']] = [
                    'page_id' => (int) $row['page_id'],
                    'public_id' => (string) $row['public_id'],
                    'title' => (string) $row['title'],
                    'slug' => (string) $row['slug'],
                    'language' => (string) $row['language'],
                    'visible' => (bool) $row['visible'],
                    'home' => (int) $row['page_id'] === $home,
                ];
            }
        }

        return $out;
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
