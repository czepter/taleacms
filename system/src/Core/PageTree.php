<?php

declare(strict_types=1);

namespace Talea\Core;

/** The pages overview and the SEO list show pages as a tree: each page below its parent, up to five levels. */
final class PageTree
{
    /**
     * @param list<array<string, mixed>> $pages rows of tl_pages
     * @return list<array<string, mixed>> the rows in tree order, each with its level
     */
    public static function sorted(array $pages): array
    {
        $byParent = [];
        foreach ($pages as $s) {
            $byParent[(int) ($s['parent_id'] ?? 0)][] = $s;
        }
        $ids = array_column($pages, 'page_id');
        $result = [];
        $add = function (int $parent, int $level) use (&$add, &$result, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $s) {
                $result[] = $s + ['level' => $level];
                if ($level < 4) {
                    $add((int) $s['page_id'], $level + 1);
                }
            }
        };
        $add(0, 0);
        // pages whose parent is in the trash or does not exist are shown at the top level
        foreach ($byParent as $parent => $children) {
            if ($parent !== 0 && !in_array($parent, array_map('intval', $ids), true)) {
                foreach ($children as $s) {
                    $result[] = $s + ['level' => 0];
                }
            }
        }

        return $result;
    }
}
