<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\Cache;

/**
 * Moving a site from one owner to another (docs/specs/multisite.md, "Transfer between owners"; operators only, needs TALEA_OWNERS). Tables are not
 * renamed – they hold foreign keys to the source owner's users and media paths with the owner's folder – so the target owner gets a NEW site that
 * cron fills with the cross-owner clone in transfer mode (OwnerClone::copy: everything but the queues, people found again by e-mail, secrets and
 * site address included, signing keys made new). In the same cron step the host names move, the new site takes over the public id and the state of
 * the old one, and the old one goes to the trash. report() is the dry run; start() needs its hash back and refuses when a fresh report differs.
 */
final class SiteTransfer
{
    /** Settings that hold a secret of the business (they move with it; listed in the report so the operator knows). */
    private const array SECRETS = ['smtp_password', 'ai_key', 'backup_password', 'captcha_secret', 'webhook_secret', 'tasks_token', 'health_token', 'screen_secret', 'site_key_secret'];

    /**
     * What names a person of the source owner: table => column => what a person with no counterpart in the target becomes (NULL, or 0 where the
     * column cannot be empty – such a row is a draft of nobody, deleted by OwnerClone::copy, or an author the screens show as unknown).
     */
    private const array USER_COLUMNS = ['news' => ['author_id' => 'NULL'], 'news_revisions' => ['user_id' => 'NULL'], 'page_revisions' => ['user_id' => 'NULL'], 'build_revisions' => ['user_id' => 'NULL'],
        'draft_comments' => ['resolved_by' => 'NULL'], 'booking_staff' => ['user_id' => 'NULL'], 'fleet_kits' => ['created_by' => 'NULL'], 'change_log' => ['user_id' => 'NULL'],
        'enquiries' => ['assigned_to' => 'NULL'], 'news_drafts' => ['user_id' => '0'], 'requests' => ['author_id' => '0'], 'look_versions' => ['author' => 'NULL'], 'newsletters' => ['author' => 'NULL']];

    /**
     * The site, its owner's connection and the target owner for the public ids an operator names; a string is the refusal.
     *
     * @return array{owner: Db, site: array<string, mixed>, target: array<string, mixed>}|string
     */
    public static function find(Db $db, string $sitePublicId, string $ownerPublicId): array|string
    {
        if (!Owners::enabled() || !Owners::isFirst($db)) {
            return 'Only the operators of the installation can move a site to another owner.';
        }
        $target = $ownerPublicId !== '' ? Owners::byPublicId($db, $ownerPublicId) : null;
        if ($target === null) {
            return 'Choose the owner that gets the site.';
        }
        foreach (Owners::all($db) as $o) {
            if ($o['ready_at'] === null) {
                continue;
            }
            $owner = $db->forOwner((string) $o['prefix']);
            if (($site = $owner->byPublicId('sites', $sitePublicId)) !== null) {
                return ['owner' => $owner, 'site' => $site, 'target' => $target];
            }
        }

        return 'Choose a site to move.';
    }

    /**
     * The dry run: what moves and what does not, what the operator has to know, what refuses the move, and a hash of all of it.
     *
     * @param Db $ownerDb a connection of the source owner
     * @param array<string, mixed> $site its row of {sites}
     * @param array<string, mixed> $targetOwner the row of {owners}
     * @return array<string, mixed>
     */
    public static function report(Db $ownerDb, array $site, array $targetOwner): array
    {
        $source = $ownerDb->forSite((string) $site['prefix']);
        $targetDb = $ownerDb->forOwner((string) $targetOwner['prefix']);
        $siteId = (int) $site['site_id'];
        $ready = $site['ready_at'] !== null && $site['status'] !== 'trashed';
        $n = fn (string $table, string $where = ''): int => $ready && $source->tableExists($table) ? (int) $source->value('SELECT COUNT(*) FROM {' . $table . '}' . $where) : 0;
        $people = $ready ? self::people($source, $ownerDb, $targetDb) : ['map' => [], 'mapped' => [], 'unmapped' => [], 'ambiguous' => []];
        $lose = !$ready ? [] : array_map(fn (array $u): string => (string) ($u['name'] !== '' ? $u['name'] : $u['username']),
            $ownerDb->all('SELECT u.name, u.username FROM {user_sites} us JOIN {users} u ON u.user_id = us.user_id WHERE us.site_id = ? AND u.sites_limited = TRUE AND u.admin <> ? ORDER BY u.user_id', [$siteId, Auth::ADMIN]));
        $secrets = !$ready ? [] : array_values(array_filter(self::SECRETS, fn (string $name): bool => (string) $source->value('SELECT value FROM {settings} WHERE name = ?', [$name]) !== ''));
        $sessions = $ready && $ownerDb->tableExists('agent_journal') ? (int) $ownerDb->value('SELECT COUNT(DISTINCT session_id) FROM {agent_journal} WHERE site_prefix = ?', [(string) $site['prefix']]) : 0;
        $queued = $n('mail', ' WHERE sent_at IS NULL');
        $child = $site['look_parent_id'] !== null;
        $parent = (int) $ownerDb->value('SELECT COUNT(*) FROM {sites} WHERE look_parent_id = ?', [$siteId]) > 0;
        $shared = ['components' => (int) $ownerDb->value('SELECT COUNT(*) FROM {components} WHERE site_id IS NULL'), 'facts' => (int) $ownerDb->value('SELECT COUNT(*) FROM {facts} WHERE site_id IS NULL')];

        $blockers = [];
        if ($site['prefix'] === $ownerDb->ownerPrefix) {
            $blockers[] = 'The first site of an owner cannot be moved.';
        }
        if (!$ready) {
            $blockers[] = 'A site in the trash or one that is not set up yet cannot be moved.';
        }
        if ($targetOwner['prefix'] === $ownerDb->ownerPrefix) {
            $blockers[] = 'The site already belongs to this owner.';
        }
        if ($targetOwner['ready_at'] === null || $targetOwner['status'] === 'archived') {
            $blockers[] = 'The owner that gets the site is not set up or is archived.';
        }
        if ($parent) {
            $blockers[] = 'This site is the parent of a look family. Let its child sites leave the family first.';
        }
        if ($ready && $targetOwner['ready_at'] !== null && array_intersect(array_column($ownerDb->all("SELECT host FROM {site_domains} WHERE site_id = ? AND kind = 'domain'", [$siteId]), 'host'), array_column($targetDb->all("SELECT host FROM {site_domains} WHERE kind = 'domain'"), 'host')) !== []) {
            $blockers[] = 'A host name of this site is already used in the new owner.';
        }
        if ($site['transfer_to'] !== null) {
            $blockers[] = 'A transfer of this site is already under way.';
        }
        $warnings = [];
        if ($child) {
            $warnings[] = 'This site takes its look from a parent site. It leaves the family and keeps its current copy.';
        }
        if ($shared['components'] + $shared['facts'] > 0) {
            $warnings[] = 'Components and facts the owner shares between its sites become the moved site\'s own copies; nothing stays linked.';
        }
        if ($lose !== []) {
            $warnings[] = 'People who are limited to this site lose access to it; nobody is given access in the new owner.';
        }
        if ($people['unmapped'] !== [] || $people['ambiguous'] !== []) {
            $warnings[] = 'People with no account, or with several accounts, of the same e-mail in the new owner are cleared from authors and revisions (the change log keeps their names).';
        }
        if ($sessions > 0) {
            $warnings[] = 'Claude sessions on this site can no longer be undone after the move.';
        }
        if ($queued > 0) {
            $warnings[] = 'E-mails waiting to be sent are not moved.';
        }

        $report = [
            'site' => ['id' => (string) $site['public_id'], 'name' => (string) $site['name'], 'from' => Owners::nameOf($ownerDb, $ownerDb->ownerPrefix), 'to' => Owners::nameOf($ownerDb, (string) $targetOwner['prefix']), 'to_id' => (string) $targetOwner['public_id']],
            'counts' => ['pages' => $n('pages'), 'news' => $n('news'), 'collection_items' => $n('collection_items'), 'enquiries' => $n('enquiries'), 'members' => $n('members'), 'bookings' => $n('bookings'), 'subscribers' => $n('subscribers')],
            'media' => $ready ? self::media($ownerDb, $source, $siteId) : ['files' => 0, 'bytes' => 0],
            'domains' => array_map(fn (array $d): array => ['host' => (string) $d['host'], 'verified' => $d['verified_at'] !== null], $ownerDb->all("SELECT host, verified_at FROM {site_domains} WHERE site_id = ? AND kind = 'domain' ORDER BY domain_id", [$siteId])),
            'secrets' => $secrets,
            'connections' => $ready && $source->tableExists('connectors') ? (int) $source->value('SELECT COUNT(*) FROM {connectors}') : 0,
            'shared' => $shared,
            'people' => ['mapped' => $people['mapped'], 'unmapped' => $people['unmapped'], 'ambiguous' => $people['ambiguous']],
            'lose_access' => $lose,
            'claude_sessions' => $sessions,
            'queued_mail' => $queued,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
        $report['hash'] = hash('sha256', (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $report;
    }

    /**
     * Starts a transfer: the report is made again and its hash compared with the one the operator saw; then the new site is registered in the target
     * owner (paused, a transfer) and the old one is marked, which is all this request writes – cron copies. The limit of sites does not count a transfer.
     * Returns the refusal, null = queued.
     *
     * @param array<string, mixed> $operator the signed-in user of owner 1
     */
    public static function start(Db $db, array $operator, string $sitePublicId, string $ownerPublicId, string $hash): ?string
    {
        if (!Owners::isOperator($db, $operator) || !Owners::operatorValid($db, (int) $operator['user_id'])) {
            return 'Only the operators of the installation can move a site to another owner.';
        }
        $found = self::find($db, $sitePublicId, $ownerPublicId);
        if (is_string($found)) {
            return $found;
        }
        $report = self::report($found['owner'], $found['site'], $found['target']);
        if ($report['blockers'] !== []) {
            return $report['blockers'][0];
        }
        if (!hash_equals($report['hash'], $hash)) {
            return 'The site has changed since the report was made. Read the new report and confirm again.';
        }
        $site = $found['site'];
        $targetDb = $db->forOwner((string) $found['target']['prefix']);
        $by = mb_substr((string) ($operator['name'] !== '' ? $operator['name'] : $operator['username']), 0, 100);
        // ponytail: no lock between the report and these writes; a second start in the same instant is stopped by the blocker the first one sets
        $queue = function () use ($found, $site, $targetDb, $by): void {
            $prefix = Sites::add($targetDb, (string) $site['name'], Sites::freeSlug($targetDb, (string) $site['slug']), (int) $site['site_id'], true, $found['owner']->ownerPrefix);
            $targetDb->update('sites', ['transfer_by' => $by], ['prefix' => $prefix]);
            $found['owner']->update('sites', ['transfer_to' => $prefix, 'transfer_error' => ''], ['site_id' => (int) $site['site_id']]);
        };
        $db->pdo()->inTransaction() ? $queue() : $db->transaction($queue);
        Sites::forgetCurrent();

        return null;
    }

    /**
     * Cron (Sites::provision for the new site of a transfer): copies, moves the host names, swaps the public ids, trashes the old site. A failure
     * undoes it all – the new site's tables and row go, the old site is released and carries the error – and is thrown again for the error report.
     *
     * @param array<string, mixed> $site the new site's row in the target owner's {sites}
     */
    public static function provision(Db $db, array $site, Db $new, Db $source, Db $sourceOwnerDb): void
    {
        $lock = 'transfer-' . $site['prefix'];
        if (!$db->lock($lock)) {
            throw new \RuntimeException('Another process is moving this site right now.');
        }
        try {
            // the row may have been finished, or cancelled, since cron read it: only a target still waiting is worked on
            $site = $db->one('SELECT * FROM {sites} WHERE site_id = ?', [(int) $site['site_id']]);
            if ($site === null || $site['ready_at'] !== null || $site['transfer_by'] === null) {
                return;
            }
            $old = (array) $sourceOwnerDb->one('SELECT * FROM {sites} WHERE site_id = ?', [(int) $site['source_site_id']]);
            $made = ['ids' => [], 'files' => []];
            try {
                if (($old['transfer_to'] ?? null) !== $site['prefix']) {
                    throw new \RuntimeException('The transfer of this site was cancelled.');
                }
                self::copy($db, $site, $new, $source, $sourceOwnerDb, $made);
                self::finish($db, $site, $old, $sourceOwnerDb);
            } catch (\Throwable $e) {
                // finish() is one transaction: a target that is ready is complete and is never touched here
                if ($db->value('SELECT ready_at FROM {sites} WHERE site_id = ?', [(int) $site['site_id']]) === null) {
                    self::discard($db, $site, $made);
                }
                if (($old['transfer_to'] ?? null) === $site['prefix']) {
                    $sourceOwnerDb->update('sites', ['transfer_to' => null, 'transfer_error' => mb_substr($e->getMessage(), 0, 500)], ['site_id' => (int) $old['site_id']]);
                }
                throw $e;
            }
        } finally {
            $db->unlock($lock);
        }
    }

    /**
     * Removes a new site that was never finished: its tables and row, the host names and shared rows made for it, and the media rows and files its copy
     * made in the owner (a file an earlier copy had put there stays).
     *
     * @param array<string, mixed> $site the target's row
     * @param array{ids: list<int>, files: list<string>} $made
     */
    private static function discard(Db $db, array $site, array $made = ['ids' => [], 'files' => []]): void
    {
        try {
            Sites::dropTables($db, (string) $site['prefix']);
        } catch (\Throwable $drop) {
            ErrorReport::capture($drop); // the row goes either way; the tables are left for a person (the original error is the one thrown)
        }
        foreach ($made['ids'] as $id) {
            $db->delete('media', ['media_id' => $id]);
        }
        foreach ($made['files'] as $file) {
            @unlink($file);
        }
        foreach (['components', 'facts', 'site_domains', 'sites'] as $table) {
            $db->delete($table, ['site_id' => (int) $site['site_id']]);
        }
        Sites::forgetCurrent();
    }

    /**
     * An operator gives a queued or stuck move up: the new site is removed and the old one answers again. Refused while cron is copying.
     * Returns the refusal, null = cancelled.
     *
     * @param array<string, mixed> $operator
     */
    public static function cancel(Db $db, array $operator, string $sitePublicId): ?string
    {
        if (!Owners::isOperator($db, $operator) || !Owners::operatorValid($db, (int) $operator['user_id'])) {
            return 'Only the operators of the installation can move a site to another owner.';
        }
        foreach (Owners::all($db) as $o) {
            $owner = $o['ready_at'] === null ? null : $db->forOwner((string) $o['prefix']);
            $site = $owner?->byPublicId('sites', $sitePublicId);
            if ($owner === null || $site === null) {
                continue;
            }
            if ($site['transfer_to'] === null) {
                return 'No move of this site is under way.';
            }
            $prefix = (string) $site['transfer_to'];
            $lock = 'transfer-' . $prefix;
            if (!$db->lock($lock)) {
                return 'The site is being copied right now. Try again in a few minutes.';
            }
            try {
                $targetOwner = Owners::ownerOf($db->installPrefix, $prefix);
                $target = $targetOwner === null ? null : $db->forOwner($targetOwner)->one('SELECT * FROM {sites} WHERE prefix = ? AND ready_at IS NULL AND transfer_by IS NOT NULL', [$prefix]);
                if ($target !== null) {
                    self::discard($db->forOwner((string) $targetOwner), $target);
                }
                $owner->update('sites', ['transfer_to' => null, 'transfer_error' => ''], ['site_id' => (int) $site['site_id']]);
            } finally {
                $db->unlock($lock);
            }

            return null;
        }

        return 'Choose a site to move.';
    }

    /** The tables, the people, the media, the settings: the new site is a faithful copy that signs with keys of its own. */
    private static function copy(Db $db, array $site, Db $new, Db $source, Db $sourceOwnerDb, array &$made): void
    {
        $people = self::people($source, $sourceOwnerDb, $db);
        $mapped = [];
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $else) {
                $mapped[$table][$column] = ['map' => $people['map'], 'else' => $else];
            }
        }
        OwnerClone::copy($db, $new, $source, $sourceOwnerDb, $site, (int) $site['source_site_id'], $mapped, $made);
        // the signing key (links, previews, form signatures) is made again on first use; the connections' credentials are sealed with it, so they follow
        $key = (string) $new->value("SELECT value FROM {settings} WHERE name = 'secret_key'");
        if ($key !== '') {
            $fresh = bin2hex(random_bytes(32));
            if ($new->tableExists('connectors')) {
                Connectors::rekey($new, $key, $fresh);
            }
            $new->update('settings', ['value' => $fresh], ['name' => 'secret_key']);
        }
        // the new site is the business's, not a member of a look family any more (the old one may have been a child; its parent stays behind)
        $db->update('sites', ['look_parent_id' => null], ['site_id' => (int) $site['site_id']]);
    }

    /** One transaction (or the caller's): host names, public ids, state, the trash, the logs. Then the page caches of both. */
    private static function finish(Db $db, array $site, array $old, Db $sourceOwnerDb): void
    {
        $install = $db->forOwner($db->installPrefix);
        $run = function () use ($db, $site, $old, $sourceOwnerDb, $install): void {
            // a host name belongs to one site: the rows move with their verification state, the old site keeps none (its preview address stays with it)
            foreach ($sourceOwnerDb->all("SELECT * FROM {site_domains} WHERE site_id = ? AND kind = 'domain' ORDER BY domain_id", [(int) $old['site_id']]) as $d) {
                $db->insert('site_domains', ['site_id' => (int) $site['site_id'], 'host' => $d['host'], 'is_primary' => (bool) $d['is_primary'], 'redirect_to_primary' => (bool) $d['redirect_to_primary'],
                    'verified_at' => $d['verified_at'], 'token' => $d['token'], 'kind' => 'domain', 'created_at' => $d['created_at'] ?? date('Y-m-d H:i:s')]);
                $sourceOwnerDb->delete('site_domains', ['domain_id' => (int) $d['domain_id']]);
            }
            Domains::addPreview($db, (int) $site['site_id'], (string) $site['slug']);
            // the public id stays with the live site; the old row gets a new one first, so no two rows ever carry it
            $publicId = (string) $old['public_id'];
            $sourceOwnerDb->update('sites', ['public_id' => Uuid::v4()], ['site_id' => (int) $old['site_id']]);
            $db->update('sites', ['public_id' => $publicId, 'status' => $old['status'] === 'trashed' ? 'paused' : (string) $old['status'], 'redirect_to_site_id' => null,
                'card_summary' => $old['card_summary'], 'card_image' => $old['card_image'], 'show_in_hub' => (bool) $old['show_in_hub'], 'ready_at' => date('Y-m-d H:i:s'), 'transfer_by' => null], ['site_id' => (int) $site['site_id']]);
            $sourceOwnerDb->update('sites', ['transfer_to' => null, 'name' => mb_substr((string) $old['name'] . ' (transferred to ' . Owners::nameOf($db, $db->ownerPrefix) . ')', 0, 120)], ['site_id' => (int) $old['site_id']]);
            Sites::trash($sourceOwnerDb, (int) $old['site_id']);
            $by = (string) $site['transfer_by'];
            $now = date('Y-m-d H:i:s');
            $from = Owners::nameOf($db, $sourceOwnerDb->ownerPrefix);
            $first = $db->ownerPrefix === $db->installPrefix;
            $db->forSite((string) $site['prefix'])->insert('change_log', ['created_at' => $now, 'user_id' => null, 'user_name' => $by, 'via' => '', 'module' => 'sites', 'action' => 'moved from owner',
                'description' => mb_substr($first ? 'Moved here from ' . $from : 'Moved here by the installation\'s operator', 0, 255)]);
            $install->forSite($install->ownerPrefix)->insert('change_log', ['created_at' => $now, 'user_id' => null, 'user_name' => $by, 'via' => '', 'module' => 'sites', 'action' => 'transfer',
                'description' => mb_substr((string) $old['name'] . ': ' . $from . ' → ' . Owners::nameOf($db, $db->ownerPrefix), 0, 255)]);
        };
        $db->pdo()->inTransaction() ? $run() : $db->transaction($run);
        Sites::forgetCurrent();
        $before = Cache::site();
        foreach ([$site['prefix'], $old['prefix']] as $prefix) {
            Cache::useSite((string) $prefix === $db->installPrefix ? null : (string) $prefix);
            Cache::clear(); // the cache folder of each site: the host names and the state changed
        }
        Cache::useSite($before);
    }

    /**
     * The people the site's rows name (the columns of USER_COLUMNS), in the source owner's users, and the ones with the same e-mail in the target
     * owner's: map = source user number => target user number; mapped and unmapped list name and e-mail for the report. An operator's row (no
     * e-mail) is never found again; neither is a row of the target that stands for an operator.
     *
     * A person is found again only when exactly one account of the target has that e-mail; with several they are listed as ambiguous and cleared.
     *
     * @return array{map: array<int, int>, mapped: list<array{name: string, email: string}>, unmapped: list<array{name: string, email: string}>, ambiguous: list<array{name: string, email: string}>}
     */
    private static function people(Db $source, Db $sourceOwner, Db $targetOwner): array
    {
        $ids = [];
        foreach (self::USER_COLUMNS as $table => $columns) {
            if (!$source->tableExists($table)) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                if (!in_array($column, $source->columns($table), true)) {
                    continue;
                }
                foreach ($source->all('SELECT DISTINCT ' . $column . ' AS id FROM {' . $table . '} WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> 0') as $r) {
                    $ids[(int) $r['id']] = true;
                }
            }
        }
        ksort($ids);
        $map = $mapped = $unmapped = $ambiguous = [];
        foreach (array_keys($ids) as $id) {
            $user = $sourceOwner->one('SELECT name, username, email FROM {users} WHERE user_id = ?', [$id]);
            if ($user === null) {
                continue; // a number with no account any more: cleared like an unknown person, not worth a line
            }
            $email = trim((string) $user['email']);
            $line = ['name' => (string) ($user['name'] !== '' ? $user['name'] : $user['username']), 'email' => $email];
            $found = $email === '' ? [] : $targetOwner->all('SELECT user_id FROM {users} WHERE LOWER(email) = ? AND operator_user_id IS NULL', [mb_strtolower($email)]);
            if (count($found) === 1) {
                $map[$id] = (int) $found[0]['user_id'];
                $mapped[] = $line;
            } elseif ($found === []) {
                $unmapped[] = $line;
            } else {
                $ambiguous[] = $line;
            }
        }

        return ['map' => $map, 'mapped' => $mapped, 'unmapped' => $unmapped, 'ambiguous' => $ambiguous];
    }

    /**
     * The files and bytes of the media the site's content uses (what OwnerClone::copyMedia would copy, with the variants of each file).
     *
     * @return array{files: int, bytes: int}
     */
    private static function media(Db $owner, Db $source, int $siteId): array
    {
        $found = OwnerClone::usedPaths($owner, $source, $siteId);
        $usage = [...($source->tableExists('media_usage') ? array_map('intval', array_column($source->all('SELECT DISTINCT media_id FROM {media_usage}'), 'media_id')) : []), ...OwnerClone::attachmentIds($source)];
        $files = $bytes = 0;
        foreach ($owner->all('SELECT media_id, image_path, thumb_path FROM {media} WHERE staged_at IS NULL ORDER BY media_id') as $row) {
            if (!isset($found[$row['image_path']]) && !isset($found[$row['thumb_path']]) && !in_array((int) $row['media_id'], $usage, true)) {
                continue;
            }
            foreach (MediaStaging::files((string) $row['image_path'], false) as $file) {
                $files++;
                $bytes += (int) filesize($file);
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
