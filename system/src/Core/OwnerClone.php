<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Copying a site of ANOTHER owner (docs/specs/multisite.md, "Several owners", stage B): the operators' tool for starting a client's site
 * from a site that lives in a different owner. Copy semantics, nothing is linked: the source's tables are copied like in a clone
 * within one owner (Sites::copyTables), its components and facts – its own and the ones it shared – become LOCAL rows of the new site in
 * the target owner, the media files the copied content uses are copied into the target owner's media folder with new media rows and the
 * paths in the copied content are rewritten, and what points at the source owner's users (authors, revisions, drafts) is cleared.
 * Both sites get a change-log row. Plain DML, redone from the start by a run that failed half-way; ready_at is set by the caller.
 */
final class OwnerClone
{
    /** Columns of the copied site tables, and of the target's components, that may name a media file: table => columns. */
    private const array CONTENT = [
        'pages' => ['image', 'text', 'build', 'build_draft'],
        'news' => ['image', 'intro', 'text', 'faq'],
        'tags' => ['description', 'image'],
        'categories' => ['description'],
        'site_parts' => ['build', 'build_draft'],
        'popups' => ['build', 'build_draft'],
        'collections' => ['build', 'build_draft'],
        'collection_templates' => ['build', 'build_draft'],
        'collection_items' => ['data', 'image'],
        'build_revisions' => ['build'],
        'sections' => ['element'],
        'classes' => ['style', 'css'],
        'menus' => ['items'],
        'settings' => ['value'],
        'newsletters' => ['intro', 'button_url', 'html'],
        'experiments' => ['previous_build'],
    ];

    /** What points at a user of the source owner and means nothing in the target: table => column to clear. */
    private const array USER_COLUMNS = ['news' => 'author_id', 'news_revisions' => 'user_id', 'page_revisions' => 'user_id', 'build_revisions' => 'user_id', 'booking_staff' => 'user_id', 'fleet_kits' => 'created_by'];

    /** Is the owner prefix a set-up owner of this install (never trust a prefix read from a row)? */
    public static function isKnownOwner(Db $db, string $prefix): bool
    {
        return in_array($prefix, Owners::readyPrefixes($db), true);
    }

    /**
     * Fills the new site $new (migrated, in the owner of $db) from $source (a site of $sourceOwnerDb's owner). Called by Sites::provision.
     *
     * @param array<string, mixed> $site the row of the new site in {sites}
     */
    public static function copy(Db $db, Db $new, Db $source, Db $sourceOwnerDb, array $site, int $sourceSiteId): void
    {
        Sites::copyTables($source, $new);
        self::copyComponentsAndFacts($db, $new, $sourceOwnerDb, $sourceSiteId, (int) $site['site_id']);
        foreach (self::USER_COLUMNS as $table => $column) {
            if ($new->tableExists($table)) {
                $new->run('UPDATE {' . $table . '} SET ' . $column . ' = NULL');
            }
        }
        if ($new->tableExists('news_drafts')) {
            $new->run('DELETE FROM {news_drafts}'); // a draft belongs to a user of the source owner
        }
        $ownerId = Owners::publicIdOf($db, $db->ownerPrefix);
        $map = self::copyMedia($db, $new, $source, $sourceOwnerDb, $ownerId);
        self::rewrite($new, $map, $ownerId, self::CONTENT);
        self::rewrite($db, $map, $ownerId, ['components' => ['build', 'build_draft']], ' WHERE site_id = ' . (int) $site['site_id']);
        $name = fn (Db $d, int $id): string => (string) ($d->value('SELECT name FROM {sites} WHERE site_id = ?', [$id]) ?: 'main');
        $from = Owners::nameOf($db, $sourceOwnerDb->ownerPrefix) . ' / ' . $name($sourceOwnerDb, $sourceSiteId);
        $to = Owners::nameOf($db, $db->ownerPrefix) . ' / ' . (string) $site['name'];
        self::log($new, 'clone_in', 'Copied from ' . $from);
        self::log($source, 'clone_out', 'Copied to ' . $to);
    }

    /** One row of the change log of a site (no person: cron sets a site up). */
    private static function log(Db $site, string $action, string $description): void
    {
        try {
            $site->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => null, 'user_name' => 'Operator', 'via' => '', 'module' => 'sites', 'action' => $action, 'description' => mb_substr($description, 0, 255)]);
        } catch (\Throwable) {
            // the log must not stop the copy
        }
    }

    /**
     * The source site's components and facts – its own and those shared with every site of its owner – as LOCAL rows of the new site, with
     * the component numbers in the copied builds pointed at the copies (Sites::copyOwnRows does the pointing, as for a clone in one owner).
     */
    private static function copyComponentsAndFacts(Db $db, Db $new, Db $sourceOwnerDb, int $sourceSiteId, int $siteId): void
    {
        Sites::copyOwnRows($db, $new, $sourceSiteId, $siteId, $sourceOwnerDb);
    }

    /**
     * The media rows and files the copied content uses (its paths, and the media_usage rows of its news) copied to the target owner's folder.
     * A file already there (an earlier copy of the same source) and its row are reused. Returns the paths that changed: old => new.
     *
     * @return array<string, string> media path without variant => its path in the target owner's folder
     */
    private static function copyMedia(Db $db, Db $new, Db $source, Db $sourceOwnerDb, string $targetOwnerId): array
    {
        $found = [];
        foreach (self::CONTENT as $table => $columns) {
            if (!$new->tableExists($table)) {
                continue;
            }
            foreach ($new->all('SELECT ' . implode(', ', $columns) . ' FROM {' . $table . '}') as $row) {
                foreach (MediaHygiene::paths(implode(' ', array_map('strval', $row))) as $path) {
                    $found[$path] = true;
                }
            }
        }
        foreach ($db->all('SELECT build, build_draft FROM {components} WHERE site_id = ?', [self::siteIdOf($db, $new)]) as $row) {
            foreach (MediaHygiene::paths(implode(' ', array_map('strval', $row))) as $path) {
                $found[$path] = true;
            }
        }
        $usage = $new->tableExists('media_usage') ? array_map('intval', array_column($new->all('SELECT DISTINCT media_id FROM {media_usage}'), 'media_id')) : [];
        $map = [];
        $ids = [];
        $targetDir = MediaFolder::dirOf($targetOwnerId);
        foreach ($sourceOwnerDb->all('SELECT * FROM {media} WHERE staged_at IS NULL ORDER BY media_id') as $row) {
            $paths = array_values(array_filter([(string) $row['image_path'], (string) $row['thumb_path']]));
            if (!isset($found[$row['image_path']]) && !isset($found[$row['thumb_path']]) && !in_array((int) $row['media_id'], $usage, true)) {
                continue;
            }
            $newPath = self::place((string) $row['image_path'], $targetDir);
            $newThumb = (string) $row['thumb_path'] === '' ? '' : ((string) $row['thumb_path'] === (string) $row['image_path'] ? $newPath : self::place((string) $row['thumb_path'], $targetDir));
            self::copyFiles((string) $row['image_path'], $newPath);
            $existing = $db->value('SELECT media_id FROM {media} WHERE image_path = ?', [$newPath]);
            $ids[(int) $row['media_id']] = $existing !== null ? (int) $existing : $db->insert('media', ['image_path' => $newPath, 'thumb_path' => $newThumb, 'staged_at' => null] + array_diff_key($row, ['media_id' => 1, 'public_id' => 1, 'owner_id' => 1, 'folder_id' => 1]));
            foreach ($paths as $path) {
                $map[(string) preg_replace('/-(?:1200|nahled)(\.[A-Za-z0-9]+)$/', '$1', $path)] = self::place($path, $targetDir);
            }
        }
        if ($new->tableExists('media_usage')) {
            $rows = $new->all('SELECT media_id, news_id FROM {media_usage}');
            $new->run('DELETE FROM {media_usage}');
            foreach ($rows as $r) {
                if (isset($ids[(int) $r['media_id']])) {
                    $new->insertIgnore('media_usage', ['media_id' => $ids[(int) $r['media_id']], 'news_id' => (int) $r['news_id']]);
                }
            }
        }

        return $map;
    }

    /** The number of the new site (its row is the one with its prefix). */
    private static function siteIdOf(Db $db, Db $new): int
    {
        return (int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', [$new->prefix]);
    }

    /** "media/2026/10/a.jpg" or "media/<owner>/2026/10/a.jpg" => the same file name in the target folder. */
    private static function place(string $path, string $targetDir): string
    {
        return $targetDir . '/' . preg_replace('#^media/' . MediaFolder::OWNER . '#', '', $path);
    }

    /** Copies the file and its variants (-1200, -nahled, .webp, .avif) to the new path's folder; an existing file is left. */
    private static function copyFiles(string $oldPath, string $newPath): void
    {
        $from = MediaStaging::files($oldPath, false);
        $folder = TALEA_ROOT . '/' . dirname($newPath);
        if ($from !== [] && !is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create the folder ' . dirname($newPath));
        }
        foreach ($from as $file) {
            $target = $folder . '/' . basename($file);
            if (!is_file($target) && !@copy($file, $target)) {
                throw new \RuntimeException('Cannot copy ' . substr($file, strlen(TALEA_ROOT) + 1));
            }
        }
    }

    /**
     * Puts the target owner's folder into every media path of the content that was copied (also in JSON with escaped slashes, as a variant,
     * with or without the site address in front); a path that was not copied stays as it is.
     *
     * @param array<string, string> $map from copyMedia()
     * @param array<string, list<string>> $places table => columns
     */
    private static function rewrite(Db $d, array $map, string $targetOwnerId, array $places, string $where = ''): void
    {
        if ($map === []) {
            return;
        }
        $pattern = '#media((?:\\\\)?/)(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?:\\\\)?/)?(\d{4}(?:\\\\)?/\d{2}(?:\\\\)?/[A-Za-z0-9._-]+)#';
        $change = function (string $text) use ($pattern, $map, $targetOwnerId): string {
            return (string) preg_replace_callback($pattern, function (array $m) use ($map, $targetOwnerId): string {
                $known = MediaHygiene::paths($m[0])[0] ?? '';

                return isset($map[$known]) ? 'media' . $m[1] . ($targetOwnerId !== '' ? $targetOwnerId . $m[1] : '') . $m[2] : $m[0];
            }, $text);
        };
        foreach ($places as $table => $columns) {
            if (!$d->tableExists($table)) {
                continue;
            }
            $columns = array_values(array_intersect($columns, $d->columns($table)));
            if ($columns === []) {
                continue;
            }
            $key = $d->primaryKey($table);
            $like = implode(' OR ', array_map(fn (string $c): string => $d->dialect()->quote($c) . " LIKE '%media%'", $columns));
            $sql = 'SELECT ' . implode(', ', array_map($d->dialect()->quote(...), array_merge($key, $columns))) . ' FROM {' . $table . '} WHERE (' . $like . ')'
                . ($where !== '' ? ' AND ' . substr($where, strlen(' WHERE ')) : '');
            foreach ($d->all($sql) as $row) {
                $update = [];
                foreach ($columns as $column) {
                    if (is_string($row[$column] ?? null) && ($changed = $change($row[$column])) !== $row[$column]) {
                        $update[$column] = $changed;
                    }
                }
                if ($update !== []) {
                    $d->update($table, $update, array_intersect_key($row, array_flip($key)));
                }
            }
        }
    }
}
