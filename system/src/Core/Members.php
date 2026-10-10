<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Member login (HF-33): visitor accounts that sign in with an e-mailed link (no passwords), groups, and the gating of pages,
 * collection items and news by group. The pages of the member area are Front\MemberArea; the admin screen is Admin\Modules\Members.
 *
 *  - A sign-in link carries a random token (256 bits). Only its sha256 is stored; the link works once (claimed atomically) and
 *    for LINK_MINUTES (an invitation for INVITE_DAYS). Opening the link only shows a button – a mail scanner that fetches
 *    links must not use it up; the sign-in happens on the POST.
 *  - The member session is its own: a cookie (tl_member) with a random value, its sha256 in tl_member_sessions. It is never a PHP
 *    session and never shared with the administration (Core\Auth), and it is created only by a completed sign-in – an anonymous
 *    visitor gets no cookie, so the page cache (Front\Cache) keeps working for them. Removing a member or a group, or signing
 *    out, takes effect on the next request: access is read from the database every time.
 *  - Gated content (tl_content_groups: no row = public) is never in the page cache, the sitemap, llms.txt, feeds, the site
 *    search, lists or Markdown outputs: the queries leave it out with notGated(), and the pages that show it answer
 *    "Cache-Control: private, no-store" and noindex (Front\Kernel).
 *  - Limits (counted without the database, Antispam::tally): link requests per address and per e-mail, wrong links per address.
 *    Whether an address belongs to a member is never revealed: the answer to a request is always the same.
 */
final class Members
{
    public const string COOKIE = 'tl_member';

    public const int SESSION_DAYS = 30;

    public const int LINK_MINUTES = 15;

    public const int INVITE_DAYS = 3;

    /** Link requests per visitor address (its network) per WINDOW seconds. */
    public const int ADDRESS_ATTEMPTS = 10;

    /** Mails to one e-mail address per hour: more requests are answered the same way, but nothing is sent. */
    public const int EMAIL_ATTEMPTS = 3;

    /** Wrong or used links per visitor address per WINDOW seconds. */
    public const int VERIFY_ATTEMPTS = 10;

    public const int WINDOW = 900;

    /** Content that can be gated: type => the admin section whose users may always read it. */
    public const array TYPES = ['page' => 'pages', 'item' => 'collections', 'news' => 'news'];

    /** @var array<string, mixed>|null the signed-in member of this request */
    private static ?array $current = null;

    private static bool $resolved = false;

    public static function enabled(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, 'members');
    }

    public static function openSignup(Settings $settings): bool
    {
        return $settings->get('member_signup') === 'open';
    }

    /** SQL condition: the content row is public (no group). $idColumn is the row's key, e.g. c.news_id. */
    public static function notGated(string $type, string $idColumn): string
    {
        if (!isset(self::TYPES[$type]) || !preg_match('/^[a-z_.]+$/', $idColumn)) {
            throw new \InvalidArgumentException('Unknown content type or column.');
        }

        return "NOT EXISTS (SELECT 1 FROM {content_groups} cg WHERE cg.content_type = '" . $type . "' AND cg.content_id = " . $idColumn . ')';
    }

    /* ---------- the signed-in member ---------- */

    /** The member of this request, null for everybody else. Reads nothing when there is no cookie, never starts a PHP session. @return array<string, mixed>|null */
    public static function current(App $app): ?array
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1) {
            return null;
        }

        return self::$current = $app->db()->one(
            'SELECT m.* FROM {member_sessions} s JOIN {members} m ON m.member_id = s.member_id WHERE s.token_hash = ? AND s.expires_at > NOW() AND m.confirmed_at IS NOT NULL',
            [hash('sha256', $cookie)],
        );
    }

    /** Starts a session for the member and sets the cookie. */
    public static function startSession(App $app, int $memberId): void
    {
        $token = bin2hex(random_bytes(32));
        $db = $app->db();
        $db->insert('member_sessions', ['member_id' => $memberId, 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400)]);
        $db->update('members', ['last_login_at' => date('Y-m-d H:i:s')], ['member_id' => $memberId]);
        if (random_int(1, 50) === 1) {
            $db->run('DELETE FROM {member_sessions} WHERE expires_at < NOW()');
            $db->run('DELETE FROM {member_tokens} WHERE expires_at < ' . $db->dialect()->now() . ' - ' . $db->dialect()->interval(1, 'DAY'));
        }
        self::cookie($app, $token, time() + self::SESSION_DAYS * 86400);
        self::$resolved = false;
        self::$current = null;
    }

    public static function signOut(App $app): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1) {
            $app->db()->delete('member_sessions', ['token_hash' => hash('sha256', $cookie)]);
        }
        self::cookie($app, '', time() - 86400);
        unset($_COOKIE[self::COOKIE]);
        self::$resolved = false;
        self::$current = null;
    }

    /** The token of the signed-in form (sign-out), bound to the session; there is no PHP session to keep it in. */
    public static function csrf(App $app): string
    {
        $cookie = is_string($_COOKIE[self::COOKIE] ?? null) ? (string) $_COOKIE[self::COOKIE] : '';

        return hash_hmac('sha256', 'member-csrf|' . $cookie, (new Antispam($app->db(), $app->settings()))->key());
    }

    public static function csrfValid(App $app, string $token): bool
    {
        return isset($_COOKIE[self::COOKIE]) && hash_equals(self::csrf($app), $token);
    }

    private static function cookie(App $app, string $value, int $expires): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => $app->request->basePath() . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $app->request->isHttps()]);
        }
    }

    /* ---------- sign-in links ---------- */

    /**
     * A new link token for the member; returns the token (only its hash is stored).
     */
    public static function issueToken(Db $db, int $memberId, int $seconds): string
    {
        $db->run('DELETE FROM {member_tokens} WHERE member_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())', [$memberId]);
        $token = bin2hex(random_bytes(32));
        $db->insert('member_tokens', ['member_id' => $memberId, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + $seconds), 'created_at' => date('Y-m-d H:i:s')]);

        return $token;
    }

    /** Does the link still work? Does not use it up (the page that asks for the click). */
    public static function linkWorks(Db $db, string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1
            && $db->value('SELECT 1 FROM {member_tokens} WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]) !== null;
    }

    /**
     * Uses the link up: once, atomically. Confirms the address (the first link that works is the confirmation). Returns the member, or null for a link that is
     * unknown, expired or already used. @return array<string, mixed>|null
     */
    public static function useLink(Db $db, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $db->one('SELECT token_id, member_id FROM {member_tokens} WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]);
        if ($row === null) {
            return null;
        }
        // the claim: of two requests with the same link only one changes the row
        if ($db->run('UPDATE {member_tokens} SET used_at = ? WHERE token_id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), (int) $row['token_id']])->rowCount() !== 1) {
            return null;
        }
        $db->run('UPDATE {members} SET confirmed_at = ? WHERE member_id = ? AND confirmed_at IS NULL', [date('Y-m-d H:i:s'), (int) $row['member_id']]);

        return $db->one('SELECT * FROM {members} WHERE member_id = ?', [(int) $row['member_id']]);
    }

    /** The visitor's network key for the limits. */
    public static function visitorKey(App $app): string
    {
        $ip = Antispam::visitorIp($app->request->serverValues(), $app->settings()->get('trusted_proxy'));

        return Antispam::network($ip !== '' ? $ip : 'unknown');
    }

    /** Counts a link request of this visitor; false = over the limit (the visitor is told so). */
    public static function countRequest(App $app): bool
    {
        return Antispam::tally(self::visitorKey($app), 'member-link', self::WINDOW) <= self::ADDRESS_ATTEMPTS;
    }

    /** Counts a link that did not work; false = over the limit. */
    public static function countWrongLink(App $app): bool
    {
        return Antispam::tally(self::visitorKey($app), 'member-verify', self::WINDOW) <= self::VERIFY_ATTEMPTS;
    }

    /** Is a link still expected for this visitor – the check before a token is looked up, without counting. */
    public static function overWrongLinkLimit(App $app): bool
    {
        return Antispam::tally(self::visitorKey($app), 'member-verify', self::WINDOW, false) > self::VERIFY_ATTEMPTS;
    }

    /**
     * The visitor asked for a link: mails it to a member (or, with open sign-up, to a new address after creating the account). Says nothing about
     * whether the address is known. The mail is queued and sent after the response (Mail::later), so the time of the answer tells nothing either.
     *
     * @param string $return internal path to come back to after the sign-in ('' = the member page)
     */
    public static function requestLink(App $app, string $email, string $return): void
    {
        $email = PersonalData::normalise($email);
        if ($email === null || mb_strlen($email) > 190 || Antispam::tally($email, 'member-link-email', 3600) > self::EMAIL_ATTEMPTS) {
            return;
        }
        $db = $app->db();
        $member = $db->one('SELECT * FROM {members} WHERE email = ?', [$email]);
        if ($member === null) {
            if (!self::openSignup($app->settings())) {
                return;
            }
            $db->insert('members', ['email' => $email, 'created_at' => date('Y-m-d H:i:s')]);
            $member = $db->one('SELECT * FROM {members} WHERE email = ?', [$email]);
        }
        if ($member === null) {
            return;
        }
        $link = self::link($app, self::issueToken($db, (int) $member['member_id'], self::LINK_MINUTES * 60), $return);
        $site = $app->settings()->get('site_name');
        Mail::later($app->settings(), $email, t('Your sign-in link for %s', $site),
            t('Hello,') . "\n\n" . t('use this link to sign in to %s (valid for %d minutes, works once):', $site, self::LINK_MINUTES) . "\n" . $link . "\n\n"
            . t('If you did not ask for it, ignore this e-mail – nobody can sign in without the link.') . "\n");
    }

    /**
     * Invites a person (administration): creates the member when needed, adds the groups and mails an invitation link.
     *
     * @param list<int> $groupIds
     * @return array{0: ?array<string, mixed>, 1: string} the member and an error text ('' = invited)
     */
    public static function invite(App $app, string $email, string $name, array $groupIds): array
    {
        $normalised = PersonalData::normalise($email);
        if ($normalised === null || mb_strlen($normalised) > 190) {
            return [null, 'Enter a valid e-mail address.'];
        }
        $db = $app->db();
        $member = $db->one('SELECT * FROM {members} WHERE email = ?', [$normalised]);
        if ($member === null) {
            $db->insert('members', ['email' => $normalised, 'name' => mb_substr(trim($name), 0, 100), 'created_at' => date('Y-m-d H:i:s')]);
            $member = $db->one('SELECT * FROM {members} WHERE email = ?', [$normalised]);
        } elseif ($member['name'] === '' && trim($name) !== '') {
            $db->update('members', ['name' => mb_substr(trim($name), 0, 100)], ['member_id' => (int) $member['member_id']]);
        }
        if ($member === null) {
            return [null, 'The member could not be saved.'];
        }
        foreach ($groupIds as $groupId) {
            $db->insertIgnore('member_group_links', ['member_id' => (int) $member['member_id'], 'group_id' => $groupId]);
        }
        $link = self::link($app, self::issueToken($db, (int) $member['member_id'], self::INVITE_DAYS * 86400), '');
        $site = $app->settings()->get('site_name');
        Language::runWith(Language::defaults($app->settings()), fn (): bool => Mail::send($app->settings(), $normalised, t('Your access to %s', $site),
            t('Hello,') . "\n\n" . t('you have been given access to the members area of %s.', $site) . "\n\n"
            . t('Sign in at this address (valid for %d days, works once; later you ask for a new link on the same page):', self::INVITE_DAYS) . "\n" . $link . "\n"));

        return [$member, ''];
    }

    /** The address in the mail: the site address, the link page, the token and where to go after. */
    private static function link(App $app, string $token, string $return): string
    {
        $query = 'token=' . $token . (self::safeReturn($return) !== '' ? '&return=' . rawurlencode(self::safeReturn($return)) : '');

        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('member/verify?' . $query);
    }

    /** An internal path ("/clients/offer") or ''. Nothing that could lead to another site. */
    public static function safeReturn(string $path): string
    {
        return preg_match('~^/(?!/)[A-Za-z0-9._/%-]{0,200}$~', $path) === 1 && !str_contains($path, '..') ? $path : '';
    }

    /* ---------- groups and gating ---------- */

    /** @return list<int> group ids of the member */
    public static function groupIds(Db $db, int $memberId): array
    {
        return array_map('intval', array_column($db->all('SELECT group_id FROM {member_group_links} WHERE member_id = ?', [$memberId]), 'group_id'));
    }

    /** @return list<int> the groups that may read the content; empty = public */
    public static function contentGroupIds(Db $db, string $type, int $id): array
    {
        return array_map('intval', array_column($db->all('SELECT group_id FROM {content_groups} WHERE content_type = ? AND content_id = ?', [$type, $id]), 'group_id'));
    }

    /** @return list<string> public ids of the groups that may read the content */
    public static function contentGroupPublicIds(Db $db, string $type, int $id): array
    {
        return array_column($db->all('SELECT g.public_id FROM {content_groups} c JOIN {member_groups} g ON g.group_id = c.group_id WHERE c.content_type = ? AND c.content_id = ? ORDER BY g.name', [$type, $id]), 'public_id');
    }

    /**
     * Sets the groups of a page, an item or a news item (public ids of groups; [] = public). Unknown ids are ignored. Clears the page cache.
     *
     * @param list<string> $groupPublicIds
     */
    public static function setContentGroups(Db $db, string $type, int $id, array $groupPublicIds): void
    {
        $groupIds = [];
        foreach (array_unique($groupPublicIds) as $publicId) {
            $groupId = $db->internalId('member_groups', $publicId);
            if ($groupId > 0) {
                $groupIds[] = $groupId;
            }
        }
        self::setContentGroupIds($db, $type, $id, $groupIds);
    }

    /** @param list<int> $groupIds */
    public static function setContentGroupIds(Db $db, string $type, int $id, array $groupIds): void
    {
        if (!isset(self::TYPES[$type]) || $id <= 0) {
            throw new \InvalidArgumentException('Unknown content.');
        }
        $groupIds = array_values(array_unique($groupIds));
        $db->transaction(function () use ($db, $type, $id, $groupIds): void {
            $db->delete('content_groups', ['content_type' => $type, 'content_id' => $id]);
            foreach ($groupIds as $groupId) {
                $db->insert('content_groups', ['content_type' => $type, 'content_id' => $id, 'group_id' => $groupId]);
            }
        });
        \Talea\Front\Cache::clear();
    }

    /** A copy of restricted content stays restricted (a duplicate that is published later must not become public). */
    public static function copyGroups(Db $db, string $type, int $from, int $to): void
    {
        foreach (self::contentGroupIds($db, $type, $from) as $groupId) {
            $db->insertIgnore('content_groups', ['content_type' => $type, 'content_id' => $to, 'group_id' => $groupId]);
        }
    }

    /** Saves the groups chosen in a content form (the fieldset of views/admin/members/gating.php); a form without it changes nothing. */
    public static function saveFromForm(App $app, string $type, int $id): void
    {
        if ($app->request->post('member_groups_present') === '1') {
            self::setContentGroups($app->db(), $type, $id, $app->request->postList('member_groups'));
        }
    }

    /**
     * What this request may do with the content: 'public' (no group), 'allowed' (a member of one of its groups, or an administration user
     * who may edit this kind of content), 'login' (nobody is signed in), 'denied' (signed in, but in none of its groups).
     */
    public static function access(App $app, string $type, int $id): string
    {
        $groups = self::contentGroupIds($app->db(), $type, $id);
        if ($groups === []) {
            return 'public';
        }
        if ($app->auth()->hasModule(self::TYPES[$type])) {
            return 'allowed';
        }
        $member = self::current($app);
        if ($member === null) {
            return 'login';
        }

        return array_intersect($groups, self::groupIds($app->db(), (int) $member['member_id'])) !== [] ? 'allowed' : 'denied';
    }

    /** Is the content gated at all? One query; the lists and outputs use notGated() instead. */
    public static function isGated(Db $db, string $type, int $id): bool
    {
        return $db->value('SELECT 1 FROM {content_groups} WHERE content_type = ? AND content_id = ?', [$type, $id]) !== null;
    }

    /**
     * Creates a group, or renames the group with this id. @return array{0: ?array<string, mixed>, 1: string} the group and an error text ('' = saved)
     */
    public static function saveGroup(Db $db, string $name, int $id = 0): array
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            return [null, 'Enter the group name.'];
        }
        if ($db->value('SELECT 1 FROM {member_groups} WHERE name = ? AND group_id <> ?', [$name, $id]) !== null) {
            return [null, 'A group with this name already exists.'];
        }
        if ($id > 0) {
            $db->update('member_groups', ['name' => $name], ['group_id' => $id]);
        } else {
            $id = $db->insert('member_groups', ['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
        }

        return [$db->one('SELECT * FROM {member_groups} WHERE group_id = ?', [$id]), ''];
    }

    /**
     * Deletes a group; its members stay. A group that still restricts content is refused: with it gone the content would be public, so the
     * restriction must be changed on purpose first. @return string an error text, '' = deleted
     */
    public static function deleteGroup(Db $db, int $id): string
    {
        if ($db->value('SELECT 1 FROM {content_groups} WHERE group_id = ?', [$id]) !== null) {
            return 'This group still restricts pages, items or news. Remove the restriction first – otherwise they would become public.';
        }
        $db->delete('member_groups', ['group_id' => $id]); // the memberships go with it (foreign key)

        return '';
    }

    /** Groups with their member counts, for the administration. @return list<array<string, mixed>> */
    public static function groups(Db $db): array
    {
        return $db->all('SELECT g.*, (SELECT COUNT(*) FROM {member_group_links} l WHERE l.group_id = g.group_id) AS members, (SELECT COUNT(*) FROM {content_groups} c WHERE c.group_id = g.group_id) AS contents FROM {member_groups} g ORDER BY g.name');
    }
}
