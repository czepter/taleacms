<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The owners of the install (several owners, docs/specs/multisite.md "Several owners"): one row per owner in the install table {owners}.
 * An owner is a group of table prefixes: owner 1 is the install's own prefix ("tl_", which is also its site 1), a further owner gets
 * the install stem plus letters ("tlb_", "tlc_" … "tlba_"), and its further sites that prefix with a number ("tlb2_"). Letters only in
 * an owner stem and digits only in a site number keep every `LIKE 'tlb\_%'` unambiguous: no prefix is the start of another one.
 * With TALEA_OWNERS off there is only owner 1 and nothing here is consulted.
 */
final class Owners
{
    public const array KINDS = ['person', 'organisation'];
    public const array STATUSES = ['live', 'paused', 'archived'];

    /** Several owners are switched on by the environment (TALEA_OWNERS) and need multisite. */
    public static function enabled(): bool
    {
        return Sites::enabled() && Config::flag('OWNERS', false);
    }

    /**
     * Table prefix of the owner numbered $n: the install prefix for owner 1, then its stem plus b, c … z, ba, bb … and an underscore
     * (n = 2 gives "tlb_"). The install prefix of an install with several owners is letters only ("tl_", "wp_").
     */
    public static function prefixFor(string $installPrefix, int $n): string
    {
        if ($n <= 1) {
            return $installPrefix;
        }
        if (preg_match('/^[a-z]+_$/', $installPrefix) !== 1) {
            throw new \RuntimeException('Further owners need a table prefix of letters ending in "_" (now "' . $installPrefix . '").');
        }
        $letters = '';
        for ($i = $n - 1; $i > 0; $i = intdiv($i, 26)) {
            $letters = chr(97 + $i % 26) . $letters;
        }

        return substr($installPrefix, 0, -1) . $letters . '_';
    }

    /**
     * The owner prefix a table prefix belongs to ("tlb2_" and "tlb_" belong to "tlb_", "tl3_" to "tl_"), or null when the prefix has not
     * the shape of this install's prefixes (stem, letters, digits, underscore).
     */
    public static function ownerOf(string $installPrefix, string $prefix): ?string
    {
        $stem = substr($installPrefix, 0, -1);
        if (preg_match('/^' . preg_quote($stem, '/') . '([a-z]*)[0-9]*_$/D', $prefix, $m) !== 1) {
            return null;
        }

        return $m[1] === '' ? $installPrefix : $stem . $m[1] . '_';
    }

    /**
     * Every owner, owner 1 first. Before the table exists (an install that has not run the migration yet) only owner 1.
     *
     * @return list<array<string, mixed>> the rows of {owners}
     */
    public static function all(Db $db): array
    {
        if (!$db->tableExists('owners')) {
            return [['owner_id' => 1, 'public_id' => '', 'name' => '', 'kind' => 'organisation', 'prefix' => $db->installPrefix, 'status' => 'live', 'ready_at' => '', 'created_at' => '']];
        }

        return $db->all('SELECT * FROM {owners} ORDER BY owner_id');
    }

    /** @return list<string> owner prefixes, owner 1's (the install's) first */
    public static function prefixes(Db $db): array
    {
        return array_map('strval', array_column(self::all($db), 'prefix'));
    }

    /** Owners that are set up (owner 1 always); what routing reads. @return list<string> */
    public static function readyPrefixes(Db $db): array
    {
        return array_map('strval', array_column(array_filter(self::all($db), fn (array $o): bool => $o['ready_at'] !== null), 'prefix'));
    }

    /** Sets an owner's state (live, paused, archived); owner 1 is the install itself and always live. Returns an error text, null = saved. */
    public static function setStatus(Db $db, string $prefix, string $status): ?string
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 'Choose a state for the owner.';
        }
        if ($prefix === $db->installPrefix) {
            return 'The first owner cannot be paused or archived.';
        }
        if ($db->update('owners', ['status' => $status], ['prefix' => $prefix]) === 0 && $db->value('SELECT 1 FROM {owners} WHERE prefix = ?', [$prefix]) === null) {
            return 'The owner does not exist.';
        }

        return null;
    }

    /** Is this connection owner 1's, the one whose files (media/, storage/backups/, the logs) are the install's own? */
    public static function isFirst(Db $db): bool
    {
        return $db->ownerPrefix === $db->installPrefix;
    }

    /**
     * Why a feature that works on the files shared by the whole install (backups, exports, the log, the media archive) is not
     * available to this owner yet, or null. A further owner's files are not separated from the first one's until owners get their own folders.
     */
    public static function sharedFilesRefusal(Db $db): ?string
    {
        return self::isFirst($db) ? null : 'This is not available to this owner yet: backups, exports and the log are kept for the whole install.';
    }

    /**
     * Every owner's prefix and its further sites' prefixes in order (owner, its sites, next owner …), each with a connection on its
     * tables: what the migrations and the backup walk.
     *
     * @return list<Db>
     */
    public static function connections(Db $db): array
    {
        $out = [];
        foreach (self::prefixes($db) as $owner) {
            $ownerDb = $db->forOwner($owner);
            foreach (Sites::prefixes($ownerDb) as $prefix) {
                $out[] = $ownerDb->forSite($prefix);
            }
        }

        return $out;
    }

    /** @return list<string> every table prefix of the install: each owner's, then its sites' */
    public static function allPrefixes(Db $db): array
    {
        return array_map(fn (Db $c): string => $c->prefix, self::connections($db));
    }

    /** Why one more owner may not be made (TALEA_MAX_OWNERS, counting every owner row), or null. */
    public static function limitRefusal(Db $db): ?string
    {
        $max = Sites::limit('MAX_OWNERS');

        return $max > 0 && count(self::all($db)) >= $max ? t('The limit of %d owners for this installation is reached. Raise TALEA_MAX_OWNERS or remove an owner first.', $max) : null;
    }

    /**
     * Registers a further owner (not set up yet) and returns its prefix. Its tables come from the next Migrator::migrate(), its first
     * site from the migration; provisionPending() (cron) sets ready_at. A number whose prefix already has tables is skipped.
     */
    public static function add(Db $db, string $name, string $kind = 'organisation', string $adminEmail = ''): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('The kind of an owner is person or organisation.');
        }
        $n = (int) $db->value('SELECT MAX(owner_id) FROM {owners}') + 1;
        while ($db->dialect()->dumpTables($db->pdo(), $prefix = self::prefixFor($db->installPrefix, $n)) !== []) {
            $n++;
        }
        $db->insert('owners', ['name' => trim(mb_substr($name, 0, 120)), 'kind' => $kind, 'prefix' => $prefix, 'status' => 'live', 'admin_email' => mb_substr(trim($adminEmail), 0, 190), 'created_at' => date('Y-m-d H:i:s')]);

        return $prefix;
    }

    /**
     * Cron (owner 1's run): migrates the database so the new owners get their tables, makes sure each has the row of its site 1 and marks
     * it set up. Nothing else is copied: a new owner starts empty. One failing owner is reported and retried on the next run.
     *
     * @param array<string, mixed> $config the db part of config.php
     * @return list<string> one line per owner
     */
    public static function provisionPending(Db $db, array $config): array
    {
        $pending = $db->all('SELECT * FROM {owners} WHERE ready_at IS NULL ORDER BY owner_id');
        if ($pending === []) {
            return [];
        }
        Migrator::migrate($config);
        $lines = [];
        foreach ($pending as $owner) {
            try {
                $ownerDb = $db->forOwner((string) $owner['prefix']);
                if (!$ownerDb->tableExists('sites')) {
                    throw new \RuntimeException('The tables of owner ' . $owner['prefix'] . ' do not exist yet: migrate first.');
                }
                if ($ownerDb->value('SELECT 1 FROM {sites} WHERE site_id = 1') === null) {
                    $ownerDb->insert('sites', ['site_id' => 1, 'slug' => 'main', 'prefix' => $ownerDb->prefix, 'status' => 'live', 'ready_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
                }
                $db->update('owners', ['ready_at' => date('Y-m-d H:i:s')], ['owner_id' => (int) $owner['owner_id']]);
                $lines[] = 'owner ' . $owner['prefix'] . ': ready';
            } catch (\Throwable $e) {
                ErrorReport::capture($e, 'error', ['tags' => ['job' => 'owners_provision']]);
                $lines[] = 'owner ' . $owner['prefix'] . ': error: ' . $e->getMessage();
            }
        }

        return $lines;
    }

    /**
     * Does any owner of the install use this host? A host name is unique in the whole install, not per owner: routing reads every
     * owner's domains (and so does adding one). Without several owners only this owner's domains exist.
     */
    public static function hostTaken(Db $db, string $host): bool
    {
        $owners = self::enabled() ? self::readyPrefixes($db) : [$db->ownerPrefix];
        $sql = implode(' UNION ALL ', array_map(fn (string $p): string => 'SELECT 1 FROM ' . $db->dialect()->quote($db->forOwner($p)->table('site_domains')) . ' WHERE host = ?', $owners));
        $stmt = $db->pdo()->prepare($sql);
        $stmt->execute(array_fill(0, count($owners), $host));

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null the owner with this public id */
    public static function byPublicId(Db $db, string $publicId): ?array
    {
        return $db->byPublicId('owners', $publicId);
    }

    /** The public id of the owner with this prefix ('' for owner 1: its files sit directly in media/). */
    public static function publicIdOf(Db $db, string $prefix): string
    {
        return $prefix === $db->installPrefix ? '' : (string) $db->value('SELECT public_id FROM {owners} WHERE prefix = ?', [$prefix]);
    }

    /** The name of the owner with this prefix, or its prefix while it has none. */
    public static function nameOf(Db $db, string $prefix): string
    {
        $name = (string) $db->value('SELECT name FROM {owners} WHERE prefix = ?', [$prefix]);

        return $name !== '' ? $name : $prefix;
    }

    /* ---------- operators: administrators of owner 1, working in the other owners ---------- */

    /** Is this user of owner 1 still an unblocked administrator, i.e. may the row that stands for them in another owner still be used? */
    public static function operatorValid(Db $db, int $operatorUserId): bool
    {
        return $db->forOwner($db->installPrefix)->value('SELECT 1 FROM {users} WHERE user_id = ? AND admin = ? AND blocked = FALSE', [$operatorUserId, Auth::ADMIN]) !== null;
    }

    /**
     * The row of {users} in the owner $target that stands for the operator (an administrator of owner 1), made on first use. It is a
     * full administrator of that owner, flagged with `operator_user_id`, and cannot sign in or be reached any other way than the handoff:
     * its password is random and thrown away, it has no e-mail (no reset link), and the client's Users module, Account and password
     * reset all refuse it. A blocked row is released again (the operator was valid when this is called).
     *
     * @param array<string, mixed> $operator the operator's row in owner 1's {users}
     */
    public static function operatorRow(Db $target, array $operator): int
    {
        $name = mb_substr('Operator: ' . ((string) $operator['name'] !== '' ? $operator['name'] : $operator['username']), 0, 100);
        $id = $target->value('SELECT user_id FROM {users} WHERE operator_user_id = ?', [(int) $operator['user_id']]);
        if ($id !== null) {
            $target->update('users', ['name' => $name, 'admin' => Auth::ADMIN, 'role' => null, 'blocked' => 0, 'auto_blocked_at' => null], ['user_id' => (int) $id]);

            return (int) $id;
        }
        $username = 'operator-' . (int) $operator['user_id'];
        while ($target->value('SELECT 1 FROM {users} WHERE username = ?', [$username]) !== null) {
            $username = 'operator-' . bin2hex(random_bytes(3)); // a client user already took the name
        }

        return $target->insert('users', ['username' => $username, 'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'name' => $name, 'email' => '', 'admin' => Auth::ADMIN,
            'language' => '', 'confirmed_at' => date('Y-m-d H:i:s'), 'operator_user_id' => (int) $operator['user_id']]);
    }

    /** Is this signed-in user an operator: an unblocked administrator of owner 1, or the row that stands for one in another owner? @param array<string, mixed>|null $user */
    public static function isOperator(Db $db, ?array $user): bool
    {
        if ($user === null || !self::enabled() || (int) ($user['admin'] ?? -1) !== Auth::ADMIN) {
            return false;
        }
        $operator = $user['operator_user_id'] ?? null;

        return $operator !== null ? self::operatorValid($db, (int) $operator) : (self::isFirst($db) && empty($user['blocked']));
    }

    /**
     * The sites of the OTHER set-up owners that an operator may copy (cross-owner clone): set up, not in the trash.
     *
     * @return list<array{owner: string, owner_name: string, site: array<string, mixed>}>
     */
    public static function foreignSites(Db $db): array
    {
        $out = [];
        foreach (self::all($db) as $o) {
            if ($o['prefix'] === $db->ownerPrefix || $o['ready_at'] === null) {
                continue;
            }
            foreach ($db->forOwner((string) $o['prefix'])->all("SELECT * FROM {sites} WHERE ready_at IS NOT NULL AND status <> 'trashed' ORDER BY site_id") as $site) {
                $out[] = ['owner' => (string) $o['prefix'], 'owner_name' => (string) ($o['name'] !== '' ? $o['name'] : $o['prefix']), 'site' => $site];
            }
        }

        return $out;
    }

    /** A site of another set-up owner by its public id (site ids are UUIDs, unique across owners), or null. @return array{owner: string, owner_name: string, site: array<string, mixed>}|null */
    public static function findSite(Db $db, string $publicId): ?array
    {
        foreach (self::foreignSites($db) as $f) {
            if ($f['site']['public_id'] === $publicId) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Removes the rows that stand for an operator from every further owner (they take their tokens and sessions with them). Only owner 1
     * has operators; called when one is deleted, blocked or no longer an administrator. Returns how many rows went.
     */
    public static function revokeOperator(Db $db, int $operatorUserId): int
    {
        if (!self::isFirst($db) || !self::enabled()) {
            return 0;
        }
        $removed = 0;
        foreach (self::readyPrefixes($db) as $prefix) {
            if ($prefix !== $db->installPrefix) {
                $removed += $db->forOwner($prefix)->delete('users', ['operator_user_id' => $operatorUserId]);
            }
        }

        return $removed;
    }

    /**
     * The one-time token that signs an operator into a site of another owner: written in the TARGET owner's {handoff_tokens}, for that
     * site and the operator's row there, valid for Sites::HANDOFF_SECONDS (UTC) and used up by the first claim. Only an unblocked
     * administrator of owner 1 gets one, and only for a live owner that is set up. Returns [token, primary host] or an error text.
     *
     * @param array<string, mixed> $operator the signed-in user of owner 1
     * @return array{0: string, 1: string}|string
     */
    public static function handoff(Db $db, array $operator, string $ownerPublicId, string $sitePublicId): array|string
    {
        if (!self::enabled() || !self::isFirst($db) || (int) ($operator['admin'] ?? -1) !== Auth::ADMIN || !self::operatorValid($db, (int) $operator['user_id'])) {
            return 'Only the operators of the installation can open another owner\'s site.';
        }
        $owner = self::byPublicId($db, $ownerPublicId);
        if ($owner === null || $owner['ready_at'] === null || $owner['status'] !== 'live' || $owner['prefix'] === $db->installPrefix) {
            return 'This owner cannot be opened now.';
        }
        $target = $db->forOwner((string) $owner['prefix']);
        $site = $target->byPublicId('sites', $sitePublicId);
        $host = $site === null ? null : Sites::primaryHost($target, (int) $site['site_id']);
        if ($site === null || $site['status'] === 'trashed' || $site['ready_at'] === null) {
            return 'This site cannot be opened now.';
        }
        if ($host === null) {
            return 'Add a host name to this site first.';
        }

        $row = self::operatorRow($target, $operator);
        try {
            // the client's change log shows who came in (the name of the row reads "Operator: …")
            $target->forSite((string) $site['prefix'])->insert('change_log', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => $row, 'user_name' => mb_substr('Operator: ' . ((string) $operator['name'] !== '' ? $operator['name'] : $operator['username']), 0, 100), 'via' => '', 'module' => 'owners', 'action' => 'operator_opened', 'description' => 'Opened by an operator of the installation']);
        } catch (\Throwable) {
            // the log must not stop the sign-in
        }

        return [Sites::handoffToken($target, $row, (int) $site['site_id']), $host];
    }

    /**
     * Invites the first administrator an operator named: a user in the owner's {users} (made here, with a password nobody knows) and the
     * e-mail with the link to set one, valid for 3 days like every invitation. The link points at the owner's first site, so the owner
     * needs a host name; the mail goes out through the install's own mail setup. Returns an error text, null = sent.
     *
     * @param array<string, mixed> $owner the row of {owners}
     */
    public static function invite(Db $db, array $owner, Settings $mailSettings, Request $request): ?string
    {
        $email = (string) $owner['admin_email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter the e-mail address of the first administrator.';
        }
        $target = $db->forOwner((string) $owner['prefix']);
        $host = $owner['ready_at'] === null ? null : Sites::primaryHost($target, 1);
        if ($host === null) {
            return 'The owner needs to be set up and have a host name before the invitation can be sent.';
        }
        $user = $target->one('SELECT * FROM {users} WHERE email = ? AND operator_user_id IS NULL', [$email]);
        if ($user === null) {
            $base = substr((string) preg_replace('/[^a-zA-Z0-9._-]/', '', strstr($email, '@', true) ?: 'admin'), 0, 30) ?: 'admin';
            for ($username = $base, $n = 2; $target->value('SELECT 1 FROM {users} WHERE username = ?', [$username]) !== null; $n++) {
                $username = $base . $n;
            }
            $id = $target->insert('users', ['username' => $username, 'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'name' => '', 'email' => $email, 'admin' => Auth::ADMIN, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
            $user = (array) $target->one('SELECT * FROM {users} WHERE user_id = ?', [$id]);
        }
        $token = bin2hex(random_bytes(32));
        $target->update('users', ['reset_token_hash' => hash('sha256', $token), 'reset_sent_at' => date('Y-m-d H:i:s', time() + 71 * 3600)], ['user_id' => (int) $user['user_id']]);
        $link = Sites::originOn($request, $host) . $request->basePath() . '/' . \Talea\Admin\Kernel::path('action=password&token=' . $token);
        $name = (string) ($owner['name'] !== '' ? $owner['name'] : $owner['prefix']);
        Mail::send($mailSettings, $email, t('Invitation to the administration') . ' – ' . $name,
            t('Hello,') . "\n\n" . t('you have been given access to the administration of the website %s. Your username is %s.', $name, (string) $user['username'])
            . "\n\n" . t('Set your password at this address (valid for 3 days, single use):') . "\n" . $link);

        return null;
    }
}
