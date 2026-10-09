<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;

/**
 * Publishing a build draft (page, site part, collection, component or popup) – from the editor and from MCP. The previous published version goes to the history
 * (ka_stavba_revize, the last 20 for each target), the site cache is cleared.
 */
final class Publisher
{
    public const int VERSIONS_KEPT = 20;

    /** Page: the content without the layout is saved into the text – search, llms.txt, the API and the return to text draw on it. */
    public static function page(App $app, array $page): void
    {
        $new = $page['build_draft'] ?? $page['build'];
        self::version($app, ['page_id' => $page['page_id']], $page['build'], $new, $page['updated_at'] ?? null);
        $text = Build::asText(Build::fromJson($new) ?? []);
        $app->db()->update('pages', ['build' => $new, 'build_draft' => null, 'updated_at' => date('Y-m-d H:i:s')] + ($text !== '' ? ['text' => $text] : []), ['page_id' => $page['page_id']]);
        \Kaleta\Front\Cache::clear();
    }

    public static function part(App $app, array $row): void
    {
        $new = $row['build_draft'] ?? $row['build'];
        $variant = (string) ($row['variant'] ?? '');
        self::version($app, ['part' => SiteParts::versionKey($row['type'], $row['language'], $variant)], $row['build'], $new, $row['updated_at'] ?? null);
        $app->db()->update('site_parts', ['build' => $new, 'build_draft' => null, 'updated_at' => date('Y-m-d H:i:s')], ['type' => $row['type'], 'language' => $row['language'], 'variant' => $variant]);
        \Kaleta\Front\Cache::clear();
    }

    /**
     * Item template of a collection in the language from Collections::inLanguage (versions under the key „kolekce:<idk>“,
     * for another language „kolekce:<idk>:<jazyk>“).
     */
    public static function collection(App $app, array $collection): void
    {
        $new = $collection['build_draft'] ?? $collection['build'];
        self::version($app, ['part' => Collections::templateKey($collection)], $collection['build'], $new, $collection['updated_at'] ?? null);
        Collections::writeTemplate($app->db(), $collection, ['build' => $new, 'build_draft' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        \Kaleta\Front\Cache::clear();
    }

    /** Popup (versions under the key „popup:<idpp>“). */
    public static function popup(App $app, array $popup): void
    {
        $new = $popup['build_draft'] ?? $popup['build'];
        self::version($app, ['part' => 'popup:' . (int) $popup['popup_id']], $popup['build'], $new, $popup['updated_at'] ?? null);
        $app->db()->update('popups', ['build' => $new, 'build_draft' => null, 'updated_at' => date('Y-m-d H:i:s')], ['popup_id' => $popup['popup_id']]);
        \Kaleta\Front\Cache::clear();
    }

    /** Component (versions under the key „komponenta:<idm>“) – the change shows on all pages where it is used. */
    public static function component(App $app, array $component): void
    {
        $new = $component['build_draft'] ?? $component['build'];
        self::version($app, ['part' => 'komponenta:' . (int) $component['component_id']], $component['build'], $new, $component['updated_at'] ?? null);
        $app->db()->update('components', ['build' => $new, 'build_draft' => null, 'updated_at' => date('Y-m-d H:i:s')], ['component_id' => $component['component_id']]);
        \Kaleta\Front\Cache::clear();
    }

    /** Saves the previous published version to the history. @param array{page_id?: int|string, part?: string} $target */
    public static function version(App $app, array $target, ?string $old, ?string $newVersion, ?string $date): void
    {
        // 2.8: every publishing is an event (who: a user id and whether it came through a Claude connection)
        \Kaleta\Core\Events::record($app->db(), 'build.published', 'info', t('Published: %s', isset($target['page_id']) ? 'page ' . (int) $target['page_id'] : (string) ($target['part'] ?? '')),
            $target + ['username' => $app->auth()->id() ?: null, 'claude' => $app->auth()->connection() !== null]);
        if ($old === null || $old === $newVersion) {
            return;
        }
        $db = $app->db();
        $db->insert('build_revisions', $target + ['created_at' => $date ?? date('Y-m-d H:i:s'), 'user_id' => $app->auth()->id() ?: null, 'build' => $old]);
        [$whereParts, $value] = self::whereClause($target);
        $boundary = $db->value('SELECT revision_id FROM {build_revisions} WHERE ' . $whereParts . ' ORDER BY revision_id DESC LIMIT 1 OFFSET ' . self::VERSIONS_KEPT, [$value]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {build_revisions} WHERE ' . $whereParts . ' AND revision_id <= ?', [$value, $boundary]);
        }
    }

    /** @param array{page_id?: int|string, part?: string} $target @return list<array<string, mixed>> */
    public static function listAll(Db $db, array $target): array
    {
        [$whereParts, $value] = self::whereClause($target);

        return $db->all("SELECT r.revision_id, r.created_at, IF(u.name = '' OR u.name IS NULL, u.username, u.name) AS user_id FROM {build_revisions} r LEFT JOIN {users} u ON u.user_id = r.user_id WHERE r." . $whereParts . ' ORDER BY r.revision_id DESC', [$value]);
    }

    /** @param array{page_id?: int|string, part?: string} $target */
    public static function load(Db $db, array $target, int $idr): ?string
    {
        [$whereParts, $value] = self::whereClause($target);
        $build = $db->value('SELECT build FROM {build_revisions} WHERE revision_id = ? AND ' . $whereParts, [$idr, $value]);

        return $build === null ? null : (string) $build;
    }

    /** @return array{0: string, 1: int|string} */
    private static function whereClause(array $target): array
    {
        return isset($target['page_id']) ? ['page_id = ?', (int) $target['page_id']] : ['part = ?', (string) $target['part']];
    }
}
