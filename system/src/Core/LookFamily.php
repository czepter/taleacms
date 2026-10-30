<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\Cache;

/**
 * Look families (docs/specs/multisite.md, "Look families"; only with TALEA_MULTISITE): a business with several locations keeps one
 * parent site whose look is copied into its child sites. A child names its parent in `sites.look_parent_id` (owner table; one level,
 * the same owner). What is copied, whenever the parent publishes: the design system and the dark mode setting, the classes (merged by
 * name: the parent's rows overwrite same-named ones, the child keeps its own extra classes), and the header and footer parts of every
 * language and variant the parent has. Menus, wrappers, pages and legal pages stay the child's own. The child cannot change what
 * the parent hands down (the lock: refusal() and its siblings, asked by the admin and by MCP).
 */
final class LookFamily
{
    /** The site parts a family shares. */
    private const array PARTS = ['header', 'footer'];

    private static bool $paused = false;

    /** Look::publish pushes once at its end, not for every part it publishes. */
    public static function pause(bool $paused): void
    {
        self::$paused = $paused;
    }

    /** @return array{site_id: int, public_id: string, prefix: string, name: string}|null the parent of the site $db works on; null = none, or multisite is off */
    public static function parent(Db $db): ?array
    {
        if (!Sites::enabled()) {
            return null;
        }
        $row = $db->one("SELECT p.site_id, p.public_id, p.prefix, p.name, p.slug FROM {sites} s JOIN {sites} p ON p.site_id = s.look_parent_id WHERE s.site_id = ? AND p.status <> 'trashed'", [Sites::currentId($db)]);

        return $row === null ? null : ['site_id' => (int) $row['site_id'], 'public_id' => (string) $row['public_id'], 'prefix' => (string) $row['prefix'], 'name' => $row['name'] !== '' ? (string) $row['name'] : (string) $row['slug']];
    }

    /** How many sites take their look from the one $db works on. */
    public static function childCount(Db $db): int
    {
        return Sites::enabled() ? (int) $db->value('SELECT COUNT(*) FROM {sites} WHERE look_parent_id = ?', [Sites::currentId($db)]) : 0;
    }

    /** The refusal for a child (null = not in a family): the look is changed on the parent. $translate: false gives the English text (MCP). */
    public static function refusal(Db $db, bool $translate = true): ?string
    {
        $parent = self::parent($db);
        if ($parent === null) {
            return null;
        }

        return $translate ? t('This site takes its look from %s. Change it there or leave the family.', $parent['name'])
            : sprintf('This site takes its look from %s. Change it there or leave the family.', $parent['name']);
    }

    /** The refusal for a header or footer (the shared site parts), null for the other parts or a site outside a family. */
    public static function partRefusal(Db $db, string $type, bool $translate = true): ?string
    {
        return in_array($type, self::PARTS, true) ? self::refusal($db, $translate) : null;
    }

    /** The refusal for a class the parent hands down (its name is read-only in the child), null for the child's own classes. */
    public static function classRefusal(Db $db, string $name, bool $translate = true): ?string
    {
        $parent = self::parent($db);

        return $parent !== null && $db->forSite($parent['prefix'])->value('SELECT 1 FROM {classes} WHERE name = ?', [$name]) !== null ? self::refusal($db, $translate) : null;
    }

    /**
     * The refusal for publishing a look draft: a child may publish a draft of its own menus and classes, and the rollback to one of its
     * earlier looks, but not a design system, header or footer of its own, nor a class of the parent.
     */
    public static function draftRefusal(Db $db, Settings $s, bool $translate = true): ?string
    {
        $parent = self::parent($db);
        $draft = Look::draft($s);
        if ($parent === null || !empty($draft['rollback'])) {
            return null;
        }
        foreach (array_keys((array) ($draft['classes'] ?? [])) as $name) {
            if (self::classRefusal($db, (string) $name, $translate) !== null) {
                return self::refusal($db, $translate);
            }
        }

        return isset($draft['design_system']) || ($draft['parts'] ?? []) !== [] ? self::refusal($db, $translate) : null;
    }

    /** Whether a site has work in progress that joining a family would throw away: a look draft or a header or footer draft. */
    public static function hasDraft(Db $site): bool
    {
        return Look::hasDraft(new Settings($site))
            || $site->value("SELECT 1 FROM {site_parts} WHERE type IN ('header', 'footer') AND build_draft IS NOT NULL AND (build IS NULL OR build_draft <> build)") !== null;
    }

    /**
     * Checks a site as a parent of $childId (null = a site that is not made yet). Returns [row of the parent, error].
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public static function checkParent(Db $owner, string $parentPublicId, ?int $childId): array
    {
        $parent = $owner->byPublicId('sites', $parentPublicId);
        if ($parent === null || $parent['status'] === 'trashed' || $parent['ready_at'] === null || (int) $parent['site_id'] === $childId) {
            return [null, 'Choose a site that is set up as the parent.'];
        }
        if ($parent['look_parent_id'] !== null) {
            return [null, 'A site that takes its look from another site cannot be a parent.'];
        }
        if ($childId !== null && $owner->value('SELECT 1 FROM {sites} WHERE look_parent_id = ?', [$childId]) !== null) {
            return [null, 'This site is the parent of a look family: its children have to leave first.'];
        }

        return [$parent, null];
    }

    /**
     * Puts a site into the family of $parent (or, with null, takes it out and keeps its current copy). A site that is set up gets the parent's published
     * look at once; its pending look draft is thrown away, which needs $discardDraft. Returns an error text, null = done.
     *
     * @param array<string, mixed> $site the row of {sites}
     * @param array<string, mixed>|null $parent
     */
    public static function set(Db $owner, array $site, ?array $parent, bool $discardDraft, ?int $author): ?string
    {
        if ($parent === null) {
            $owner->update('sites', ['look_parent_id' => null], ['site_id' => (int) $site['site_id']]);

            return null;
        }
        if ($site['ready_at'] === null) {
            $owner->update('sites', ['look_parent_id' => (int) $parent['site_id']], ['site_id' => (int) $site['site_id']]);

            return null; // set up later by cron: Sites::provision() pushes
        }
        $child = $owner->forSite((string) $site['prefix']);
        if (self::hasDraft($child)) {
            if (!$discardDraft) {
                return 'This site has an unpublished look. Joining throws it away.';
            }
            Look::discard(new Settings($child));
            $child->run("UPDATE {site_parts} SET build_draft = NULL WHERE type IN ('header', 'footer') AND build IS NOT NULL");
            $child->run("DELETE FROM {site_parts} WHERE type IN ('header', 'footer') AND build IS NULL");
        }
        $owner->update('sites', ['look_parent_id' => (int) $parent['site_id']], ['site_id' => (int) $site['site_id']]);
        $source = $owner->forSite((string) $parent['prefix']);
        self::copy($source, new Settings($source), [$site], $author, true);

        return null;
    }

    /** A parent that is trashed or archived (or gone) lets its children go: each keeps its current copy and gets a line in its own change log. */
    public static function detachChildren(Db $owner, int $parentId, string $why): void
    {
        foreach ($owner->all('SELECT site_id, prefix, ready_at FROM {sites} WHERE look_parent_id = ?', [$parentId]) as $child) {
            $owner->update('sites', ['look_parent_id' => null], ['site_id' => (int) $child['site_id']]);
            if ($child['ready_at'] === null) {
                continue;
            }
            try {
                $owner->forSite((string) $child['prefix'])->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => null, 'user_name' => '', 'via' => '',
                    'module' => 'sites', 'action' => 'leave look family', 'description' => mb_substr($why, 0, 255)]);
            } catch (\Throwable) {
                // like ChangeLog: the log must not break what it records
            }
        }
    }

    /** The parent has published: copy its published look into every child that is set up. $version: keep each child's earlier look as a version first. */
    public static function push(Db $db, Settings $s, ?int $author, bool $version = true): void
    {
        if (self::$paused || !Sites::enabled()) {
            return;
        }
        $children = $db->all("SELECT * FROM {sites} WHERE look_parent_id = ? AND ready_at IS NOT NULL AND status <> 'trashed' ORDER BY site_id", [Sites::currentId($db)]);
        if ($children !== []) {
            self::copy($db, $s, $children, $author, $version);
        }
    }

    /** A child that has just been set up (Sites::provision) takes the parent's look. */
    public static function pushTo(Db $owner, array $site): void
    {
        $parent = $site['look_parent_id'] === null ? null : $owner->one("SELECT * FROM {sites} WHERE site_id = ? AND ready_at IS NOT NULL AND status <> 'trashed'", [(int) $site['look_parent_id']]);
        if ($parent !== null) {
            $source = $owner->forSite((string) $parent['prefix']);
            self::copy($source, new Settings($source), [$site], null, false);
        }
    }

    /** @return array<int, string> page number => "language|address" of a site's pages */
    private static function addresses(Db $site): array
    {
        $out = [];
        foreach ($site->all('SELECT page_id, language, slug FROM {pages}') as $p) {
            $out[(int) $p['page_id']] = $p['language'] . '|' . $p['slug'];
        }

        return $out;
    }

    /**
     * Copies the parent's published look into the given child sites. One writer per parent at a time (Db::lock): two publishes that overlap
     * would otherwise leave the children with half of each.
     *
     * @param list<array<string, mixed>> $children rows of {sites}
     */
    private static function copy(Db $parentDb, Settings $parent, array $children, ?int $author, bool $version): void
    {
        $lock = 'look-family-' . $parentDb->prefix;
        $held = $parentDb->lock($lock, 10); // ponytail: a lock that is not granted in 10 s is ignored – the copy is idempotent, a stale child is worse than two writers
        try {
            $design = $parent->get('design_system');
            $dark = $parent->get('dark_mode');
            $classes = $parentDb->all('SELECT name, style, css FROM {classes}');
            $parts = $parentDb->all("SELECT * FROM {site_parts} WHERE type IN ('header', 'footer') AND build IS NOT NULL");
            $parentPages = [];
            if (array_filter($parts, fn (array $p): bool => $p['variant'] !== '') !== []) {
                $parentPages = self::addresses($parentDb);
            }
            $now = date('Y-m-d H:i:s');
            $from = t('From %s', (string) $parentDb->value('SELECT name FROM {sites} WHERE prefix = ?', [$parentDb->prefix]));
            foreach ($children as $site) {
                $child = $parentDb->forSite((string) $site['prefix']);
                $childSettings = new Settings($child);
                if ($version) {
                    Look::keepVersion($child, $childSettings, [$from], $author);
                }
                $childSettings->set('design_system', $design);
                $childSettings->set('dark_mode', $dark);
                foreach ($classes as $class) {
                    $child->upsert('classes', ['name' => $class['name'], 'style' => $class['style'], 'css' => $class['css'], 'updated_at' => $now], ['name']);
                }
                $childPages = $parentPages === [] ? [] : array_flip(self::addresses($child));
                foreach ($parts as $part) {
                    // the pages of a variant are page numbers of the parent: the child's page of the same address and language stands in
                    $pages = $part['variant'] === '' ? $part['pages'] : (string) json_encode(array_values(array_filter(array_map(
                        fn (mixed $id): ?int => isset($parentPages[(int) $id], $childPages[$parentPages[(int) $id]]) ? (int) $childPages[$parentPages[(int) $id]] : null,
                        json_decode((string) $part['pages'], true) ?: []))));
                    $child->upsert('site_parts', ['type' => $part['type'], 'language' => $part['language'], 'variant' => $part['variant'], 'name' => $part['name'], 'pages' => $pages,
                        'build' => $part['build'], 'build_draft' => null, 'updated_at' => $now], ['type', 'language', 'variant']);
                }
                $before = Cache::site();
                Cache::useSite((string) $site['prefix'] === $parentDb->installPrefix ? null : (string) $site['prefix']);
                Cache::clear();
                Cache::useSite($before);
            }
        } finally {
            if ($held) {
                $parentDb->unlock($lock);
            }
        }
    }
}
