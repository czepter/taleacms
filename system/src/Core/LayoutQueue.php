<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Imported pages whose layout is not rebuilt yet: an import (Core\WebImport, Core\WpImport, Import\Batch) marks every page it creates
 * (pages.layout_pending), the Pages list has a tab for them and MCP lists them (list_layout_queue, layout_queue). Ticking a page off
 * changes nothing but the marker – never the content, the draft or the time of the last change.
 */
final class LayoutQueue
{
    public const int PAGE_SIZE = 50;

    public static function count(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE layout_pending = TRUE AND deleted_at IS NULL');
    }

    /** @return array{pages: list<array{page_id: int, public_id: string, title: string, slug: string, language: string, visible: bool, old_address: string}>, total: int} */
    public static function page(Db $db, int $offset = 0, int $limit = self::PAGE_SIZE): array
    {
        $rows = $db->all(
            'SELECT page_id, public_id, title, slug, language, visible, import_source FROM {pages} WHERE layout_pending = TRUE AND deleted_at IS NULL ORDER BY language, sort_order, page_id LIMIT ? OFFSET ?',
            [max(1, $limit), max(0, $offset)],
        );

        return [
            'pages' => array_map(fn (array $r): array => [
                'page_id' => (int) $r['page_id'], 'public_id' => (string) $r['public_id'], 'title' => (string) $r['title'], 'slug' => (string) $r['slug'],
                'language' => (string) $r['language'], 'visible' => (bool) $r['visible'], 'old_address' => (string) ($r['import_source'] ?? ''),
            ], $rows),
            'total' => self::count($db),
        ];
    }

    /**
     * Ticks pages off ("Layout done").
     *
     * @param list<int> $pageIds
     * @return int how many pages were still waiting
     */
    public static function done(Db $db, array $pageIds): int
    {
        $done = 0;
        foreach ($pageIds as $id) {
            $done += $db->run('UPDATE {pages} SET layout_pending = FALSE WHERE page_id = ? AND layout_pending = TRUE', [(int) $id])->rowCount();
        }

        return $done;
    }
}
