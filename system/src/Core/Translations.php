<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Translation overview (2.14): every page, news item and collection item in the default language against the site's
 * other languages – the translation is present, missing, or older than the original (the original changed after the
 * translation was last saved). Pages and news items are linked by preklad_z, collection items by the same address in
 * the same collection (the switcher and hreflang pair them the same way).
 *
 * Read-only: the admin screen Pages → Translations and the MCP tool translation_status both draw on matrix().
 */
final class Translations
{
    public const string PRESENT = 'present';
    public const string MISSING = 'missing';
    public const string OUTDATED = 'outdated';

    /**
     * The status of one translation against its original: the dates are the rows' zmeneno (or datum when never changed).
     *
     * @param array<string, mixed>|null $translation
     */
    public static function status(?array $translation, ?string $originalChanged): string
    {
        if ($translation === null) {
            return self::MISSING;
        }
        $translated = $translation['updated_at'] ?? $translation['edited_at'] ?? $translation['published_at'] ?? $translation['created_at'] ?? null;
        if ($originalChanged !== null && $translated !== null && strtotime((string) $translated) < strtotime($originalChanged)) {
            return self::OUTDATED;
        }

        return self::PRESENT;
    }

    /**
     * @return array{languages: list<string>, rows: list<array{type: string, id: int, title: string, changed: ?string, collection?: array{idk: int, nazev: string, seo_link: string},
     *     translations: array<string, array{status: string, id: ?int}>}>} languages = the site's additional languages ([] = a single-language site)
     */
    public static function matrix(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $languages = Language::additional($s);
        if ($languages === []) {
            return ['languages' => [], 'rows' => []];
        }
        $rows = [];
        $cells = function (array $byLanguage, ?string $changed) use ($languages): array {
            $out = [];
            foreach ($languages as $code) {
                $translation = $byLanguage[$code] ?? null;
                $out[$code] = ['status' => self::status($translation, $changed), 'id' => $translation === null ? null : (int) $translation['id']];
            }

            return $out;
        };

        $pageTranslations = [];
        foreach ($db->all("SELECT page_id AS id, translation_of, language, updated_at FROM {pages} WHERE deleted_at IS NULL AND language <> '' AND translation_of IS NOT NULL") as $t) {
            $pageTranslations[(int) $t['translation_of']][$t['language']] = $t;
        }
        foreach ($db->all("SELECT page_id, title, updated_at FROM {pages} WHERE deleted_at IS NULL AND language = '' ORDER BY sort_order, title") as $p) {
            $rows[] = ['type' => 'page', 'id' => (int) $p['page_id'], 'title' => $p['title'], 'changed' => $p['updated_at'], 'translations' => $cells($pageTranslations[(int) $p['page_id']] ?? [], $p['updated_at'])];
        }

        if (Extensions::isEnabled($s, 'news')) {
            $newsTranslations = [];
            foreach ($db->all("SELECT news_id AS id, translation_of, language, edited_at, published_at FROM {news} WHERE deleted_at IS NULL AND language <> '' AND translation_of IS NOT NULL") as $t) {
                $newsTranslations[(int) $t['translation_of']][$t['language']] = $t;
            }
            foreach ($db->all("SELECT news_id, title, edited_at, published_at FROM {news} WHERE deleted_at IS NULL AND language = '' ORDER BY published_at DESC LIMIT 500") as $c) {
                $changed = $c['edited_at'] ?? $c['published_at'];
                $rows[] = ['type' => 'news', 'id' => (int) $c['news_id'], 'title' => $c['title'], 'changed' => $changed, 'translations' => $cells($newsTranslations[(int) $c['news_id']] ?? [], $changed)];
            }
        }

        $itemTranslations = [];
        foreach ($db->all("SELECT item_id AS id, collection_id, slug, language, updated_at, created_at FROM {collection_items} WHERE deleted_at IS NULL AND language <> ''") as $t) {
            $itemTranslations[(int) $t['collection_id']][$t['slug']][$t['language']] = $t;
        }
        foreach ($db->all("SELECT p.item_id, p.collection_id, p.name, p.slug, p.updated_at, p.created_at, k.name AS collection, k.slug AS collection_slug FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id
            WHERE p.deleted_at IS NULL AND p.language = '' ORDER BY k.name, p.sort_order, p.name") as $p) {
            $changed = $p['updated_at'] ?? $p['created_at'];
            $rows[] = ['type' => 'collection_item', 'id' => (int) $p['item_id'], 'title' => $p['name'], 'changed' => $changed,
                'collection' => ['collection_id' => (int) $p['collection_id'], 'name' => $p['collection'], 'slug' => $p['collection_slug']],
                'translations' => $cells($itemTranslations[(int) $p['collection_id']][$p['slug']] ?? [], $changed)];
        }

        return ['languages' => $languages, 'rows' => $rows];
    }
}
