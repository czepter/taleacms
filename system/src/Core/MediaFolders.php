<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The tree of Media folders (media_folders.parent_id, docs/specs/media-library.md). Not to be confused with MediaFolder, the
 * owner's directory. The helpers that take $all (the result of all()) are pure.
 */
final class MediaFolders
{
    /**
     * Every folder with the files directly in it, the images in it without a description and the date of its newest file.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(Db $db): array
    {
        $rows = $db->all(
            'SELECT f.folder_id, f.public_id, f.name, f.parent_id, COUNT(m.media_id) AS count,
                    SUM(CASE WHEN m.thumb_path <> \'\' AND m.name = \'\' THEN 1 ELSE 0 END) AS missing, MAX(m.created_at) AS newest
             FROM {media_folders} f LEFT JOIN {media} m ON m.folder_id = f.folder_id
             GROUP BY f.folder_id, f.public_id, f.name, f.parent_id ORDER BY f.name',
        );
        foreach ($rows as &$row) {
            $row['folder_id'] = (int) $row['folder_id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $row['count'] = (int) $row['count'];
            $row['missing'] = (int) $row['missing'];
        }

        return $rows;
    }

    /**
     * The three newest thumbnails of the files directly in each of the folders, in one query (the mosaic of a folder card).
     *
     * @param list<int> $ids
     * @return array<int, list<string>> folder_id => thumb paths, newest first
     */
    public static function thumbnails(Db $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $db->all(
            'SELECT folder_id, thumb_path FROM (SELECT folder_id, thumb_path, ROW_NUMBER() OVER (PARTITION BY folder_id ORDER BY media_id DESC) AS place
                 FROM {media} WHERE thumb_path <> \'\' AND staged_at IS NULL AND folder_id IN (' . implode(',', array_map(intval(...), $ids)) . ')) newest WHERE place <= 3 ORDER BY folder_id, place',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['folder_id']][] = (string) $row['thumb_path'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<array<string, mixed>> the folders directly under $parentId (null = top level), in the order of $all
     */
    public static function children(array $all, ?int $parentId): array
    {
        return array_values(array_filter($all, fn (array $f): bool => $f['parent_id'] === $parentId));
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<array<string, mixed>> the breadcrumb: the top folder first, the folder itself last; [] for an unknown id
     */
    public static function path(array $all, int $id): array
    {
        $byId = array_column($all, null, 'folder_id');
        $path = [];
        for ($seen = []; isset($byId[$id]) && !isset($seen[$id]); $id = (int) $byId[$id]['parent_id']) { // $seen: a broken cycle ends the walk
            $seen[$id] = true;
            array_unshift($path, $byId[$id]);
            if ($byId[$id]['parent_id'] === null) {
                break;
            }
        }

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<int> every folder below $id, at any depth ($id itself not included)
     */
    public static function descendants(array $all, int $id): array
    {
        $found = [];
        $queue = [$id];
        while ($queue !== []) {
            foreach (self::children($all, array_shift($queue)) as $child) {
                if (!in_array($child['folder_id'], $found, true)) {
                    $found[] = $child['folder_id'];
                    $queue[] = $child['folder_id'];
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<array<string, mixed>> depth first, each folder with its `depth` (0 = top level), for indented lists and the menu
     */
    public static function tree(array $all, ?int $parentId = null, int $depth = 0): array
    {
        $out = [];
        foreach (self::children($all, $parentId) as $folder) {
            $out[] = $folder + ['depth' => $depth];
            array_push($out, ...self::tree($all, $folder['folder_id'], $depth + 1));
        }

        return $out;
    }

    /**
     * Moves a folder (and renames it in the same step when $name is given: a refused move leaves the name as it was). The check and
     * the update run in one transaction under a lock, so two moves at once cannot close a cycle between them.
     *
     * @param int|null $parentId null = top level
     * @throws \DomainException the target is the folder itself, one of its subfolders, or does not exist
     */
    public static function move(Db $db, int $id, ?int $parentId, ?string $name = null): void
    {
        $lock = 'media-folders-' . $db->prefix;
        $held = $db->lock($lock, 10); // ponytail: a lock not granted in 10 s is ignored – the transaction below still keeps one move consistent
        try {
            $db->transaction(function (Db $db) use ($id, $parentId, $name): void {
                $all = self::all($db);
                if (!in_array($id, array_column($all, 'folder_id'), true)) {
                    throw new \DomainException('The folder does not exist.');
                }
                if ($parentId !== null) {
                    if (!in_array($parentId, array_column($all, 'folder_id'), true)) {
                        throw new \DomainException('The target folder does not exist.');
                    }
                    if ($parentId === $id || in_array($parentId, self::descendants($all, $id), true)) {
                        throw new \DomainException('A folder cannot be moved into itself or one of its subfolders.');
                    }
                }
                $db->update('media_folders', ['parent_id' => $parentId] + ($name !== null ? ['name' => $name] : []), ['folder_id' => $id]);
            });
        } finally {
            if ($held) {
                $db->unlock($lock);
            }
        }
    }

    /** Deletes the folder; its files and subfolders move to its parent (the top level when it has none). */
    public static function delete(Db $db, int $id): void
    {
        $db->transaction(function (Db $db) use ($id): void {
            $parent = $db->value('SELECT parent_id FROM {media_folders} WHERE folder_id = ?', [$id]);
            $parent = $parent === null ? null : (int) $parent;
            $db->update('media', ['folder_id' => $parent], ['folder_id' => $id]);
            $db->update('media_folders', ['parent_id' => $parent], ['parent_id' => $id]);
            $db->delete('media_folders', ['folder_id' => $id]);
        });
    }
}
