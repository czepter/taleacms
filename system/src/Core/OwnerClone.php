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
    public const array CONTENT = [
        'pages' => ['image', 'text', 'build', 'build_draft'],
        'news' => ['image', 'intro', 'text', 'faq'],
        'news_revisions' => ['intro', 'text'],
        'page_revisions' => ['text'],
        'news_drafts' => ['data'],
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

    /**
     * What points at a user of the source owner and means nothing in the target: table => columns copied as NULL. This happens inside the
     * INSERT … SELECT, because the foreign keys to the target's own users would refuse a number it does not have (PostgreSQL checks at once).
     */
    private const array USER_COLUMNS = ['news' => ['author_id'], 'news_revisions' => ['user_id'], 'page_revisions' => ['user_id'], 'build_revisions' => ['user_id'], 'draft_comments' => ['resolved_by'],
        'booking_staff' => ['user_id'], 'fleet_kits' => ['created_by'], 'look_versions' => ['author'], 'newsletters' => ['author']];

    /** Is the owner prefix a set-up owner of this install (never trust a prefix read from a row)? */
    public static function isKnownOwner(Db $db, string $prefix): bool
    {
        return in_array($prefix, Owners::readyPrefixes($db), true);
    }

    /**
     * Fills the new site $new (migrated, in the owner of $db) from $source (a site of $sourceOwnerDb's owner). Called by Sites::provision.
     *
     * $userMap non-null is a TRANSFER (Core\SiteTransfer): the site moves with its visitors' data and logs (Sites::TRANSFER_SKIP is all that stays behind), the
     * user columns follow the people with the same e-mail instead of being cleared, and no clone rows are written (the caller logs the move).
     *
     * @param array<string, mixed> $site the row of the new site in {sites}
     * @param array<string, array<string, array{map: array<int, int>, else: string}>>|null $userMap
     * @param array{ids: list<int>, files: list<string>} $made the media rows and files this run made in the target owner (the caller removes them when it fails)
     * @param-out array{ids: list<int>, files: list<string>} $made
     */
    public static function copy(Db $db, Db $new, Db $source, Db $sourceOwnerDb, array $site, int $sourceSiteId, ?array $userMap = null, array &$made = ['ids' => [], 'files' => []]): void
    {
        // news_drafts is keyed by (user, news item): a transfer copies it by hand below, for the people it found again
        Sites::copyTables($source, $new, self::USER_COLUMNS, $userMap === null ? null : [...Sites::TRANSFER_SKIP, 'news_drafts'], $userMap ?? []);
        self::copyComponentsAndFacts($db, $new, $sourceOwnerDb, $sourceSiteId, (int) $site['site_id']);
        if ($new->tableExists('news_drafts')) {
            $new->run('DELETE FROM {news_drafts}'); // a draft belongs to a user of the source owner
            foreach ($userMap === null ? [] : $source->all('SELECT * FROM {news_drafts} ORDER BY saved_at') as $draft) {
                if (isset($userMap['news_drafts']['user_id']['map'][(int) $draft['user_id']])) {
                    $new->insertIgnore('news_drafts', ['user_id' => $userMap['news_drafts']['user_id']['map'][(int) $draft['user_id']]] + $draft); // two people with one e-mail: the earlier draft stays
                }
            }
        }
        $ownerId = Owners::publicIdOf($db, $db->ownerPrefix);
        $map = self::copyMedia($db, $new, $source, $sourceOwnerDb, $ownerId, $made);
        self::rewrite($new, $map, $ownerId, self::CONTENT);
        self::rewrite($db, $map, $ownerId, ['components' => ['build', 'build_draft']], ' WHERE site_id = ' . (int) $site['site_id']);
        $name = fn (Db $d, int $id): string => (string) ($d->value('SELECT name FROM {sites} WHERE site_id = ?', [$id]) ?: 'main');
        if ($userMap !== null) {
            return;
        }
        $from = Owners::nameOf($db, $sourceOwnerDb->ownerPrefix) . ' / ' . $name($sourceOwnerDb, $sourceSiteId);
        $to = Owners::nameOf($db, $db->ownerPrefix) . ' / ' . (string) $site['name'];
        // the other client's name stays out of a client's log: only owner 1's log (the operator's own) names the other side
        self::log($new, 'clone_in', $db->ownerPrefix === $db->installPrefix ? 'Copied from ' . $from : 'Created as a copy by the installation\'s operator');
        self::log($source, 'clone_out', $sourceOwnerDb->ownerPrefix === $db->installPrefix ? 'Copied to ' . $to : 'Copied by the installation\'s operator');
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
     * @param array{ids: list<int>, files: list<string>} $made
     * @param-out array{ids: list<int>, files: list<string>} $made
     * @return array<string, string> media path without variant => its path in the target owner's folder
     */
    private static function copyMedia(Db $db, Db $new, Db $source, Db $sourceOwnerDb, string $targetOwnerId, array &$made): array
    {
        $found = self::usedPaths($db, $new, self::siteIdOf($db, $new));
        $usage = $new->tableExists('media_usage') ? array_map('intval', array_column($new->all('SELECT DISTINCT media_id FROM {media_usage}'), 'media_id')) : [];
        $usage = [...$usage, ...self::attachmentIds($new)];
        $map = [];
        $ids = [];
        $targetDir = MediaFolder::dirOf($targetOwnerId);
        foreach ($sourceOwnerDb->all('SELECT * FROM {media} WHERE staged_at IS NULL ORDER BY media_id') as $row) {
            $paths = array_values(array_filter([(string) $row['image_path'], (string) $row['thumb_path']]));
            if (!isset($found[$row['image_path']]) && !isset($found[$row['thumb_path']]) && !in_array((int) $row['media_id'], $usage, true)) {
                continue;
            }
            // a different file already under that name in the target's folder is never reused: the copy gets a stem of its own
            $oldStem = (string) preg_replace('#^media/' . MediaFolder::OWNER . '#', '', MediaStaging::stem((string) $row['image_path']));
            $newStem = $oldStem;
            while (self::collides((string) $row['image_path'], $targetDir, $oldStem, $newStem)) {
                $newStem = (string) preg_replace('/(-[0-9a-f]{6})?$/D', '-' . bin2hex(random_bytes(3)), $oldStem, 1);
            }
            $newPath = self::place((string) $row['image_path'], $targetDir, $oldStem, $newStem);
            $newThumb = (string) $row['thumb_path'] === '' ? '' : ((string) $row['thumb_path'] === (string) $row['image_path'] ? $newPath : self::place((string) $row['thumb_path'], $targetDir, $oldStem, $newStem));
            array_push($made['files'], ...self::copyFiles((string) $row['image_path'], $targetDir, $oldStem, $newStem));
            $existing = $db->value('SELECT media_id FROM {media} WHERE image_path = ?', [$newPath]);
            if ($existing === null) {
                $made['ids'][] = $ids[(int) $row['media_id']] = $db->insert('media', ['image_path' => $newPath, 'thumb_path' => $newThumb, 'staged_at' => null] + array_diff_key($row, ['media_id' => 1, 'public_id' => 1, 'owner_id' => 1, 'folder_id' => 1]));
            } else {
                $ids[(int) $row['media_id']] = (int) $existing;
            }
            foreach ($paths as $path) {
                $canonical = (string) preg_replace('/-(?:1200|nahled)(\.[A-Za-z0-9]+)$/', '$1', $path); // the thumbnail's key is the original's
                $map[$canonical] = self::place($canonical, $targetDir, $oldStem, $newStem);
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

        if ($new->tableExists('requests')) { // the files a request carries are listed by number: the copies' numbers, and none for a file that was not copied
            foreach ($new->all('SELECT id, attachments FROM {requests}') as $r) {
                $list = array_values(array_filter(array_map(fn (mixed $m): int => $ids[(int) $m] ?? 0, (array) json_decode((string) $r['attachments'], true))));
                $new->update('requests', ['attachments' => (string) json_encode($list)], ['id' => (int) $r['id']]);
            }
        }

        return $map;
    }

    /**
     * The media numbers the requests of a site carry (their attachments, a JSON list).
     *
     * @return list<int>
     */
    public static function attachmentIds(Db $site): array
    {
        $ids = [];
        if ($site->tableExists('requests')) {
            foreach ($site->all("SELECT attachments FROM {requests} WHERE attachments <> '[]'") as $r) {
                foreach ((array) json_decode((string) $r['attachments'], true) as $m) {
                    $ids[] = (int) $m;
                }
            }
        }

        return $ids;
    }

    /**
     * The media paths the content of a site names (its tables, its builds in the shared components table): path => true. Also what the report of a
     * transfer measures on the source.
     *
     * @return array<string, true>
     */
    public static function usedPaths(Db $owner, Db $site, int $siteId): array
    {
        $found = [];
        foreach (self::CONTENT as $table => $columns) {
            if (!$site->tableExists($table)) {
                continue;
            }
            foreach ($site->all('SELECT ' . implode(', ', $columns) . ' FROM {' . $table . '}') as $row) {
                foreach (MediaHygiene::paths(implode(' ', array_map('strval', $row))) as $path) {
                    $found[$path] = true;
                }
            }
        }
        foreach ($owner->all('SELECT build, build_draft FROM {components} WHERE site_id = ?', [$siteId]) as $row) {
            foreach (MediaHygiene::paths(implode(' ', array_map('strval', $row))) as $path) {
                $found[$path] = true;
            }
        }

        return $found;
    }

    /** The number of the new site (its row is the one with its prefix). */
    private static function siteIdOf(Db $db, Db $new): int
    {
        return (int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', [$new->prefix]);
    }

    /** "media/2026/10/a-ab12cd.jpg" or "media/<owner>/2026/10/a-ab12cd.jpg" => the same file in the target folder, its stem changed from $oldStem to $newStem (both without the media/ folder). */
    private static function place(string $path, string $targetDir, string $oldStem, string $newStem): string
    {
        $rel = (string) preg_replace('#^media/' . MediaFolder::OWNER . '#', '', $path);
        if ($newStem !== $oldStem && str_starts_with($rel, $oldStem)) {
            $rel = $newStem . substr($rel, strlen($oldStem));
        }

        return $targetDir . '/' . $rel;
    }

    /** Is there a DIFFERENT file at the main path of the copy in the target folder (an earlier copy of the same file is the same, and is reused)? */
    private static function collides(string $imagePath, string $targetDir, string $oldStem, string $newStem): bool
    {
        $target = TALEA_ROOT . '/' . self::place($imagePath, $targetDir, $oldStem, $newStem);
        $source = TALEA_ROOT . '/' . $imagePath;

        return is_file($target) && (!is_file($source) || md5_file($target) !== md5_file($source));
    }

    /** Copies the file and its variants (-1200, -nahled, .webp, .avif) into the target folder under the new stem; an existing file is left. Returns the files it made. @return list<string> */
    private static function copyFiles(string $oldPath, string $targetDir, string $oldStem, string $newStem): array
    {
        $copied = [];
        $folder = TALEA_ROOT . '/' . $targetDir . '/' . dirname($newStem);
        $from = MediaStaging::files($oldPath, false);
        if ($from !== [] && !is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create the folder ' . $targetDir . '/' . dirname($newStem));
        }
        $oldName = basename($oldStem);
        foreach ($from as $file) {
            $target = $folder . '/' . basename($newStem) . substr(basename($file), strlen($oldName));
            if (!is_file($target)) {
                if (!@copy($file, $target)) {
                    throw new \RuntimeException('Cannot copy ' . substr($file, strlen(TALEA_ROOT) + 1));
                }
                $copied[] = $target;
            }
        }

        return $copied;
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
                if (!isset($map[$known])) {
                    return $m[0];
                }
                // the copy may have a stem of its own (a different file was already there): the variant and the extension of the match stay
                $stem = fn (string $path): string => (string) preg_replace('/\.[A-Za-z0-9]+$/', '', (string) preg_replace('#^media/' . MediaFolder::OWNER . '#', '', $path));
                $file = str_replace('\\/', '/', $m[2]);
                $old = $stem($known);
                $file = str_starts_with($file, $old) ? $stem($map[$known]) . substr($file, strlen($old)) : $file;

                return 'media' . $m[1] . ($targetOwnerId !== '' ? $targetOwnerId . $m[1] : '') . str_replace('/', $m[1], $file);
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
