<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Sign-in to the administration and permissions.
 *
 * Roles: an author writes their own news items (can publish them only with the "permission to publish"), an editor manages
 * content and publishes everyone's news items, an administrator also users and settings. Access to modules is
 * set individually for authors and editors; an author can have a supervising editor.
 */
final class Auth
{
    public const int AUTHOR = 0;
    public const int EDITOR = 1;
    public const int ADMIN = 2;

    public const array TYPES = [self::AUTHOR => 'author', self::EDITOR => 'editor', self::ADMIN => 'administrator'];

    /** After this many wrong passwords or codes in a row the account is locked for 15 minutes (it unlocks itself again). */
    private const int MAX_ERRORS = 10;

    /**
     * Cookie of a browser that completed a sign-in to an account before: while that account is locked, the browser may still sign in
     * (password and code). Without it, anyone could keep any account locked from one address and shut its owner out. Value: a random
     * token, its sha256 in user_devices; it ends with a new password, a change of two-factor sign-in or passkeys, and after
     * KNOWN_DEVICE_DAYS. Wrong guesses from it still count, and after KNOWN_DEVICE_FAILURES of them it no longer opens a locked account.
     */
    public const string KNOWN_DEVICE_COOKIE = 'tl_device';
    public const int KNOWN_DEVICE_DAYS = 365;
    private const int KNOWN_DEVICE_FAILURES = 5;

    /** A sign-in ends after this many seconds without a request (3.3.3, N60); an open admin page keeps it alive (image/admin.js). */
    public const int IDLE_LIMIT = 8 * 3600;

    /** A sign-in ends this many seconds after it started, however active it is (3.3.3, N60) – the keep-alive never extends it. */
    public const int SESSION_LIMIT = 24 * 3600;

    /**
     * "Stay signed in": the cookie that brings a new session once the old one has ended (idle, absolute limit, closed browser).
     * Its sha256 is in user_remember_tokens; it lasts REMEMBER_DAYS from the sign-in and ends with a sign-out or a new password.
     */
    public const string REMEMBER_COOKIE = 'tl_remember';

    public const int REMEMBER_DAYS = 30;

    /**
     * The one answer to a failed password step (3.3.3, N51): a wrong name, a wrong password, a temporarily locked account
     * and a blocked one all get it, so the answer never confirms a password. It still tells a locked-out owner what to do.
     */
    public const string SIGN_IN_FAILED = 'Wrong user name or password, or the account is temporarily locked after a series of failed attempts. Try again in 15 minutes or reset your password.';

    /**
     * Verified when there is no account to check against, or the account is locked or blocked: the response time then does
     * not reveal which case it was (a hash of a random password nobody knows – not a secret).
     */
    // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash
    private const string DUMMY_HASH = '$2y$12$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $user = false;

    /** @var list<string>|null */
    private ?array $modules = null;

    public function __construct(private readonly Db $db, private readonly Session $session)
    {
    }

    /**
     * The password step of the sign-in. $address is what the per-address limit counts by – Antispam::visitorKey(): the
     * visitor's address behind the proxy, an IPv6 address by its /64 (3.3.3, N54). $password is taken as typed (N61).
     * $login is the user name or the e-mail address of the account; $remember sets the "stay signed in" cookie once the
     * sign-in is complete (after the second step, if the account has one).
     *
     * @return string|null error text, null = signed in (or waiting for the second step)
     */
    public function login(string $login, string $password, string $address, bool $remember = false): ?string
    {
        // Slowing down password guessing: at most 10 attempts from one address per 15 minutes
        $attempts = (int) $this->db->value(
            "SELECT COUNT(*) FROM {ip_checks} WHERE type = 'login' AND ip = ? AND checked_at > NOW() - INTERVAL 15 MINUTE",
            [Antispam::hash($address)],
        );
        if ($attempts >= 10) {
            return t('Too many sign-in attempts. Try again in 15 minutes.');
        }

        $user = $this->account($login);
        // A locked or blocked account is never checked against its own password (3.3.3, N51): a different answer to the
        // right password would confirm it, and the lock would shut out only the real owner. The dummy hash is verified
        // instead – for a nonexistent user too – so the response time is the same in every case.
        $closed = $user !== null && (!empty($user['blocked']) || $this->lockedFor($user));
        $typed = self::matchingPassword($password, $user !== null && !$closed ? (string) $user['password'] : self::DUMMY_HASH);

        if ($typed === null || $user === null || $closed) {
            $this->db->insert('ip_checks', ['ip' => Antispam::hash($address), 'type' => 'login', 'checked_at' => date('Y-m-d H:i:s')]);
            if ($user !== null && !$closed) {
                // after 10 errors in a row the account is locked for 15 minutes - not permanently, and a known device still gets in (lockedFor)
                $this->countError($user);
                $this->countDeviceFailure($user);
            }

            return t(self::SIGN_IN_FAILED);
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password' => password_hash($typed, PASSWORD_DEFAULT)], ['user_id' => $user['user_id']]);
        }
        // the failure counter is reset only by a completed sign-in (recordSignIn): with two-factor sign-in a correct password
        // must not wipe the wrong codes counted so far, or the per-account lock would never be reached (3.3.2, N7)

        $this->session->regenerate();
        if ($user['totp_secret'] !== '') {
            // the password matches, but the account has two-factor sign-in: only the code from the app completes the sign-in
            $this->session->set('pending_user', ['user_id' => (int) $user['user_id'], 'time' => time(), 'remember' => $remember]);

            return null;
        }
        $this->recordSignIn((int) $user['user_id']);
        $this->startSignIn((int) $user['user_id'], (string) $this->db->value('SELECT password FROM {users} WHERE user_id = ?', [$user['user_id']]), $remember);

        return null;
    }

    /**
     * The account a sign-in name points to: the one with that user name, or else the one with that e-mail address. An address
     * that several accounts share signs none of them in – the name tells them apart.
     *
     * @return array<string, mixed>|null
     */
    private function account(string $login): ?array
    {
        $user = $this->db->one('SELECT * FROM {users} WHERE username = ?', [$login]);
        if ($user !== null || !str_contains($login, '@')) {
            return $user;
        }
        $matches = $this->db->all('SELECT * FROM {users} WHERE email = ? LIMIT 2', [$login]);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The password as typed when it matches the hash, null when it does not (3.3.3, N61: sign-in no longer trims it, as
     * none of the places that set a password do). A password saved trimmed before 3.3.3 still opens when typed with the
     * spaces; the second check runs for the real and the dummy hash alike, so the timing stays equal.
     */
    public static function matchingPassword(string $password, string $hash): ?string
    {
        if (password_verify($password, $hash)) {
            return $password;
        }

        return trim($password) !== $password && password_verify(trim($password), $hash) ? trim($password) : null;
    }

    /** @param array<string, mixed> $user */
    private static function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();
    }

    /** The account is locked and this browser has not signed in to it before (KNOWN_DEVICE_COOKIE). @param array<string, mixed> $user */
    private function lockedFor(array $user): bool
    {
        return self::isLocked($user) && !$this->knownDevice($user);
    }

    /** The sha256 of the known-device cookie of this request when it has the right shape, '' otherwise. */
    private static function deviceHash(): string
    {
        $cookie = $_COOKIE[self::KNOWN_DEVICE_COOKIE] ?? '';

        return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1 ? hash('sha256', $cookie) : '';
    }

    /** This browser has a live known-device row of the account with fewer than KNOWN_DEVICE_FAILURES failures. @param array<string, mixed> $user */
    private function knownDevice(array $user): bool
    {
        $hash = self::deviceHash();

        return $hash !== '' && (int) $this->db->value(
            'SELECT COUNT(*) FROM {user_devices} WHERE token_hash = ? AND user_id = ? AND failures < ? AND created_at > NOW() - INTERVAL ' . self::KNOWN_DEVICE_DAYS . ' DAY',
            [$hash, $user['user_id'], self::KNOWN_DEVICE_FAILURES],
        ) > 0;
    }

    /** A wrong password or code made with this browser's known-device cookie (of this account) is recorded on it. @param array<string, mixed> $user */
    private function countDeviceFailure(array $user): void
    {
        if (self::deviceHash() !== '') {
            $this->db->run('UPDATE {user_devices} SET failures = failures + 1 WHERE token_hash = ? AND user_id = ?', [self::deviceHash(), $user['user_id']]);
        }
    }

    /** A new known-device token for this browser in place of its old one; the cookie lasts KNOWN_DEVICE_DAYS. */
    private function issueDevice(int $idu): void
    {
        $token = bin2hex(random_bytes(32));
        if (self::deviceHash() !== '') {
            $this->db->delete('user_devices', ['token_hash' => self::deviceHash()]);
        }
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM {user_devices} WHERE created_at < NOW() - INTERVAL ' . self::KNOWN_DEVICE_DAYS . ' DAY');
        }
        $this->db->insert('user_devices', ['user_id' => $idu, 'token_hash' => hash('sha256', $token), 'failures' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $this->session->cookie(self::KNOWN_DEVICE_COOKIE, $token, time() + self::KNOWN_DEVICE_DAYS * 86400);
        $_COOKIE[self::KNOWN_DEVICE_COOKIE] = $token;
    }

    /** One more wrong password, code or passkey for the account; the tenth in a row locks it for 15 minutes. @param array<string, mixed> $user */
    private function countError(array $user): void
    {
        $errorCount = (int) $user['failed_logins'] + 1;
        $this->db->update('users', $errorCount >= self::MAX_ERRORS
            ? ['failed_logins' => 0, 'locked_until' => date('Y-m-d H:i:s', time() + 900)]
            : ['failed_logins' => $errorCount], ['user_id' => $user['user_id']]);
    }

    /**
     * A completed sign-in in the session: the account, the password fingerprint, and since 3.3.3 (N60) when it started and
     * when it was last used – user() ends it after IDLE_LIMIT without a request, or SESSION_LIMIT after it started.
     */
    private function startSignIn(int $idu, string $passwordHash, bool $remember = false): void
    {
        $this->session->set('user_id', $idu);
        $this->session->set('fingerprint', self::passwordHash($passwordHash));
        $this->session->set('login_at', time());
        $this->session->set('last_seen', time());
        $this->user = false;
        $this->issueDevice($idu);
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + self::REMEMBER_DAYS * 86400;
            $this->db->insert('user_remember_tokens', ['user_id' => $idu, 'token_hash' => hash('sha256', $token), 'fingerprint' => self::passwordHash($passwordHash),
                'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', $expires)]);
            if (random_int(1, 50) === 1) {
                $this->db->run('DELETE FROM {user_remember_tokens} WHERE expires_at < NOW()');
            }
            $this->session->cookie(self::REMEMBER_COOKIE, $token, $expires);
        }
    }

    /** The "stay signed in" cookie of this request when it has the right shape, '' otherwise. */
    private static function rememberCookie(): string
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';

        return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1 ? $cookie : '';
    }

    /**
     * A new session from the "stay signed in" cookie, when the old one has ended. The token stays the same until it expires
     * (no rotation: two requests racing with one cookie would otherwise sign each other out); a blocked account, a changed
     * password or an expired token ends it and drops the cookie. No second step is asked: it was passed when the cookie was set.
     *
     * @return array<string, mixed>|null
     */
    private function restoreSignIn(): ?array
    {
        $cookie = self::rememberCookie();
        if ($cookie === '') {
            return null;
        }
        $token = $this->db->one('SELECT * FROM {user_remember_tokens} WHERE token_hash = ? AND expires_at > NOW()', [hash('sha256', $cookie)]);
        $user = $token === null ? null : $this->db->one('SELECT * FROM {users} WHERE user_id = ? AND blocked = FALSE', [$token['user_id']]);
        if ($user === null || !hash_equals(self::passwordHash((string) $user['password']), (string) $token['fingerprint'])) {
            $this->forget();

            return null;
        }
        $this->session->regenerate();
        $this->startSignIn((int) $user['user_id'], (string) $user['password']);
        $this->db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['user_id' => $user['user_id']]);

        return $user;
    }

    /** The sha256 of this browser's "stay signed in" cookie, '' without one. */
    private static function rememberHash(): string
    {
        $cookie = self::rememberCookie();

        return $cookie === '' ? '' : hash('sha256', $cookie);
    }

    /** How many other browsers of the account still have a valid "stay signed in" token. */
    public function rememberedElsewhere(int $idu): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {user_remember_tokens} WHERE user_id = ? AND token_hash <> ? AND expires_at > NOW()', [$idu, self::rememberHash()]);
    }

    /**
     * Ends the "stay signed in" tokens of the account on every other browser: when two-factor sign-in or a passkey changes
     * (a lost phone or device must not leave a remembered sign-in behind), or when the owner asks for it in My account.
     * This browser keeps its own; for another person's account (an administrator resetting it) that ends all of them.
     */
    public function forgetOtherDevices(int $idu): void
    {
        $this->db->run('DELETE FROM {user_remember_tokens} WHERE user_id = ? AND token_hash <> ?', [$idu, self::rememberHash()]);
        $this->db->run('DELETE FROM {user_devices} WHERE user_id = ? AND token_hash <> ?', [$idu, self::deviceHash()]);
    }

    /** Ends the "stay signed in" token of this browser and drops its cookie. */
    private function forget(): void
    {
        if (self::rememberHash() !== '') {
            $this->db->delete('user_remember_tokens', ['token_hash' => self::rememberHash()]);
        }
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $this->session->cookie(self::REMEMBER_COOKIE, '', time() - 86400);
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        }
    }

    /** Is a sign-in that started at $loginAt and was last used at $lastSeen still valid at $now? (3.3.3, N60) */
    public static function sessionValid(int $loginAt, int $lastSeen, int $now): bool
    {
        return $now - $lastSeen < self::IDLE_LIMIT && $now - $loginAt < self::SESSION_LIMIT;
    }

    /**
     * Password hash stored in the session: after a password change all other sign-ins of the same account stop being valid
     * (a stolen session, a forgotten computer). By itself it reveals nothing - it is a shortened hash of an already hashed password.
     */
    public static function passwordHash(string $hash): string
    {
        return substr(hash('sha256', 'talea-session|' . $hash), 0, 24);
    }

    /** After changing one's own password: this sign-in stays valid (with its "stay signed in" cookie), the others do not. */
    public function refreshAfterPasswordChange(string $newHash): void
    {
        $this->session->regenerate();
        $this->session->set('fingerprint', self::passwordHash($newHash));
        if (self::rememberHash() !== '') {
            $this->db->run('UPDATE {user_remember_tokens} SET fingerprint = ? WHERE token_hash = ? AND user_id = ?', [self::passwordHash($newHash), self::rememberHash(), $this->id()]);
        }
        // the known browsers of the account end with the old password; this one gets a new token
        $this->db->delete('user_devices', ['user_id' => $this->id()]);
        $this->issueDevice((int) $this->id());
        $this->user = false;
    }

    /** The password was entered correctly and a code from the authenticator app is awaited (at most 5 minutes). */
    public function isAwaitingCode(): bool
    {
        $pending = $this->session->get('pending_user');

        return is_array($pending) && time() - (int) $pending['time'] < 300;
    }

    /** Second step of the sign-in: a code from the app, or a one-time backup code. @return string|null error text */
    public function verifyCode(string $code, string $ip): ?string
    {
        if (!$this->isAwaitingCode()) {
            return t('The sign-in has expired, please start again.');
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {ip_checks} WHERE type = 'login' AND ip = ? AND checked_at > NOW() - INTERVAL 15 MINUTE", [Antispam::hash($ip)]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $user = $this->db->one('SELECT * FROM {users} WHERE user_id = ? AND blocked = FALSE', [(int) $this->session->get('pending_user')['user_id']]);
        if ($user !== null && $this->lockedFor($user)) {
            $this->session->remove('pending_user');

            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }
        $backupCodes = $user === null ? null : Totp::useBackupCode($user['totp_backup_codes'], $code);
        $step = $user === null ? null : Totp::step($user['totp_secret'], $code);
        if ($step !== null && !$this->claimCodeStep((int) $user['user_id'], $step)) {
            $step = null; // a code of a step this account already signed in with never passes again
        }
        if ($user === null || ($step === null && $backupCodes === null)) {
            $this->db->insert('ip_checks', ['ip' => Antispam::hash($ip), 'type' => 'login', 'checked_at' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // wrong codes are counted per account, not only per IP address: whoever knows the password must not try codes from many addresses
                $this->countError($user);
                $this->countDeviceFailure($user);
            }

            return t('The code is not correct.');
        }
        $this->recordSignIn((int) $user['user_id']);
        if ($backupCodes !== null) {
            $this->db->update('users', ['totp_backup_codes' => $backupCodes], ['user_id' => $user['user_id']]);
        }
        $remember = !empty($this->session->get('pending_user')['remember']);
        $this->session->remove('pending_user');
        $this->session->regenerate();
        $this->startSignIn((int) $user['user_id'], (string) $user['password'], $remember);

        return null;
    }

    /**
     * Claims the step of a code from the app: true when the account has not signed in with this step or a later one. The
     * check and the record are one UPDATE, so two sign-ins with the same code at the same moment cannot both pass.
     */
    private function claimCodeStep(int $userId, int $step): bool
    {
        return $this->db->run('UPDATE {users} SET totp_last_step = ? WHERE user_id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $userId, $step])->rowCount() === 1;
    }

    /** Does the account waiting for the second step have registered passkeys? */
    public function isAwaitingKey(): bool
    {
        return $this->isAwaitingCode() && $this->accountKeys((int) $this->session->get('pending_user')['user_id']) !== [];
    }

    /** @return list<array<string, mixed>> passkeys of the account */
    public function accountKeys(int $idu): array
    {
        return $this->db->all('SELECT * FROM {user_passkeys} WHERE user_id = ? ORDER BY passkey_id', [$idu]);
    }

    /**
     * Sign-in with a passkey, part 1: a challenge for the device. After the password it lists the account's keys; on the
     * first step it lists none, so the device offers the keys it keeps for the site and nothing tells which accounts exist.
     *
     * @return array<string, mixed>|null options for navigator.credentials.get(), null = nothing to wait for
     */
    public function keyChallenge(string $siteUrl): ?array
    {
        $pending = $this->isAwaitingCode();
        if ($pending && !$this->isAwaitingKey()) {
            return null;
        }
        $challenge = Passkey::challenge();
        $this->session->set('passkey_challenge', $challenge);
        $keys = $pending ? array_map(static fn (array $k): string => (string) $k['credential_id'], $this->accountKeys((int) $this->session->get('pending_user')['user_id'])) : [];

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), $keys, $pending ? 'preferred' : 'required');
    }

    /**
     * Sign-in with a passkey, part 2: verifying the signature. After the password it is the second step and must be a key of
     * that account; on the first step the key alone signs in, so the device must have checked its owner (PIN, fingerprint).
     * A failure counts the same as a wrong code. $remember is the tick of the first step (after the password it came then).
     *
     * @param array<string, mixed> $response
     * @return string|null error text, null = signed in
     */
    public function verifyKey(array $response, string $siteUrl, string $ip, bool $remember = false): ?string
    {
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {ip_checks} WHERE type = 'login' AND ip = ? AND checked_at > NOW() - INTERVAL 15 MINUTE", [Antispam::hash($ip)]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $challenge = (string) $this->session->get('passkey_challenge', '');
        $this->session->remove('passkey_challenge'); // the challenge is valid for one attempt
        $pending = $this->isAwaitingCode() ? $this->session->get('pending_user') : null;
        $key = $this->db->one('SELECT * FROM {user_passkeys} WHERE credential_hash = ?' . ($pending !== null ? ' AND user_id = ?' : ''),
            array_merge([hash('sha256', Passkey::fromB64((string) ($response['id'] ?? '')))], $pending !== null ? [(int) $pending['user_id']] : []));
        $userId = $pending !== null ? (int) $pending['user_id'] : (int) ($key['user_id'] ?? 0);
        $user = $userId === 0 ? null : $this->db->one('SELECT * FROM {users} WHERE user_id = ? AND blocked = FALSE', [$userId]);
        // no lock check: a passkey assertion cannot be guessed, so it opens a locked account (as first step or as second factor);
        // everything else about it is still verified below, and wrong ones still count
        try {
            if ($key === null || $user === null) {
                throw new \RuntimeException($pending !== null ? 'This key does not belong to the account.' : 'This passkey is not registered on this site.');
            }
            $counter = Passkey::verifySignIn($response, $challenge, Passkey::origin($siteUrl), Passkey::rpId($siteUrl), (string) $key['public_key'], (int) $key['sign_count'], $pending === null);
        } catch (\RuntimeException $e) {
            $this->db->insert('ip_checks', ['ip' => Antispam::hash($ip), 'type' => 'login', 'checked_at' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                $this->countError($user);
                $this->countDeviceFailure($user);
            }

            return t($e->getMessage());
        }
        $this->db->update('user_passkeys', ['sign_count' => $counter, 'used_at' => date('Y-m-d H:i:s')], ['passkey_id' => $key['passkey_id']]);
        $this->recordSignIn((int) $user['user_id']);
        $this->session->remove('pending_user');
        $this->session->regenerate();
        $this->startSignIn((int) $user['user_id'], (string) $user['password'], $pending !== null ? !empty($pending['remember']) : $remember);

        return null;
    }

    /**
     * A completed sign-in (password alone, or password and the code or passkey): the last sign-in is what the check of
     * unused accounts counts from (Core\SecurityHygiene), so a password alone without the second step does not count.
     */
    private function recordSignIn(int $idu): void
    {
        $this->db->update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], ['user_id' => $idu]);
    }

    /**
     * The sign-in of an administrator who came from another site of the owner with a handoff token (Core\Sites::claimHandoff): the
     * second site trusts the first one's sign-in, so no password and no second step is asked. A blocked account or one that is not an
     * administrator is refused. A new session id, as for every sign-in.
     */
    public function signInFromHandoff(int $userId): bool
    {
        $user = $this->db->one('SELECT user_id, password FROM {users} WHERE user_id = ? AND blocked = FALSE AND admin = ?', [$userId, self::ADMIN]);
        if ($user === null) {
            return false;
        }
        $this->session->regenerate();
        $this->recordSignIn($userId);
        $this->startSignIn($userId, (string) $user['password']);

        return true;
    }

    public function logout(): void
    {
        $this->forget();
        $this->session->destroy();
        $this->user = null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            $this->user = $this->sessionUser() ?? $this->restoreSignIn();
        }

        return $this->user;
    }

    /** The account signed in by the PHP session, null when there is none or it has ended. @return array<string, mixed>|null */
    private function sessionUser(): ?array
    {
        // without a session cookie there is nobody who could be signed in - and no session is started for the check (the site stays cacheable)
        if (!isset($_COOKIE[Session::COOKIE])) {
            return null;
        }
        $id = $this->session->get('user_id');
        $user = is_int($id) ? $this->db->one('SELECT * FROM {users} WHERE user_id = ? AND blocked = FALSE', [$id]) : null;
        if ($user === null) {
            return null;
        }
        $hash = $this->session->get('fingerprint');
        if ($hash === null) {
            $this->session->set('fingerprint', self::passwordHash((string) $user['password'])); // a sign-in from before this check existed
        } elseif (!hash_equals(self::passwordHash((string) $user['password']), (string) $hash)) {
            $this->session->remove('user_id'); // the password has changed since the sign-in

            return null;
        }
        // 3.3.3 (N60): the idle and the absolute limit are checked here, not left to the session garbage collector;
        // tokens (MCP, OAuth) never come this way – signInAs() sets the user without a session
        $now = time();
        $loginAt = $this->session->get('login_at');
        $lastSeen = $this->session->get('last_seen');
        if (!is_int($loginAt) || !is_int($lastSeen)) {
            $this->session->set('login_at', $now); // a sign-in from before 3.3.3: its limits start now
        } elseif (!self::sessionValid($loginAt, $lastSeen, $now)) {
            foreach (['user_id', 'fingerprint', 'login_at', 'last_seen'] as $key) {
                $this->session->remove($key);
            }

            return null;
        }
        $this->session->set('last_seen', $now);

        return $user;
    }

    /** Sign-in without a session - for requests authenticated by a token (MCP). */
    public function signInAs(array $user): void
    {
        $this->user = $user;
        $this->modules = null;
    }

    /** @var array{name: string, access: string, tool_set: string}|null the Claude connection of this request (MCP, 2.2) */
    private ?array $connection = null;

    /**
     * The request comes through a Claude connection: its name goes to the change log, and a connection limited to drafts
     * or to reading never publishes, whatever the user's role (canPublish).
     */
    public function useConnection(string $name, string $access, string $toolSet = 'all'): void
    {
        $this->connection = ['name' => $name, 'access' => isset(\Talea\Mcp\Catalog::CONNECTION_ACCESS[$access]) ? $access : 'read', 'tool_set' => \Talea\Mcp\Catalog::toolSet($toolSet)];
    }

    /** @return array{name: string, access: string, tool_set: string}|null */
    public function connection(): ?array
    {
        return $this->connection;
    }

    public function id(): int
    {
        return (int) ($this->user()['user_id'] ?? 0);
    }

    public function isAdmin(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::ADMIN;
    }

    public function isEditor(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::EDITOR;
    }

    /** The site requires two-factor sign-in and this user does not have it yet (can only go to "My account" to turn it on). */
    public function isMissingRequired2fa(Settings $siteSettings): bool
    {
        $required = $siteSettings->get('require_2fa');
        $user = $this->user();

        return $user !== null && ($required === 'everyone' || ($required === 'admins' && $this->isAdmin())) && (string) ($user['totp_secret'] ?? '') === '';
    }

    public function canPublish(): bool
    {
        return ($this->isAdmin() || $this->isEditor()) && ($this->connection['access'] ?? 'full') === 'full';
    }

    /**
     * A Claude connection limited to drafts (2.2): besides drafts it may save what a person still has to apply (3.2) – a
     * hidden collection item, a proposed exception to the opening hours, a triage suggestion, a notebook note.
     */
    public function draftsOnly(): bool
    {
        return ($this->connection['access'] ?? 'full') === 'drafts';
    }

    /**
     * Can insert or change code that runs on the site (the Custom HTML element, head code): an administrator, and through a Claude
     * connection only one with full access – a connection limited to drafts must not reach the administrator's browser through a preview.
     */
    public function canWriteCode(): bool
    {
        return $this->isAdmin() && !Demo::active() && ($this->connection['access'] ?? 'full') === 'full';
    }

    /** Does the signed-in user have access to the module? Admin always; others according to tl_user_permissions. */
    public function hasModule(string $ident, bool $forEveryone = false): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->isAdmin() || $forEveryone) {
            return true;
        }
        $this->modules ??= array_column(
            $this->db->all('SELECT module FROM {user_permissions} WHERE user_id = ?', [$this->id()]),
            'module',
        );

        return in_array($ident, $this->modules, true);
    }

    /**
     * Can the signed-in user edit this news item? The same rules as in the administration: the News module, an author only their own,
     * and a published news item only someone who can publish.
     *
     * @param array<string, mixed> $newsItem row of tl_news
     */
    public function canEditArticle(array $newsItem): bool
    {
        if (!$this->hasModule('news')) {
            return false;
        }
        $authors = $this->managedAuthors();

        return ($authors === null || in_array((int) $newsItem['author_id'], $authors, true)) && (empty($newsItem['visible']) || $this->canPublish());
    }

    /**
     * Part of the WHERE condition (starts with „ AND“, or is empty) that limits the news list to what the signed-in user can see:
     * an author only their own news items, an editor and an administrator all of them.
     */
    public function articleScope(string $alias = ''): string
    {
        $authors = $this->managedAuthors();

        return $authors === null ? '' : ' AND ' . $alias . 'author_id IN (' . implode(',', array_map(intval(...), $authors)) . ')';
    }

    /*
     * What a list may show: list_pages, list_news, list_collection_items and the translation overview (Core\Translations, admin
     * and translation_status) take their conditions from here, so the rules cannot drift apart. Each is part of a WHERE
     * condition: it starts with " AND", or is empty.
     */

    /** Pages: without the Pages section only the published ones – the user can link to those, drafts stay unseen. */
    public function pageListScope(string $alias = ''): string
    {
        return $this->hasModule('pages') ? '' : ' AND ' . $alias . 'visible = TRUE';
    }

    /** News: an author only their own (articleScope), and without the News section only what visitors see. */
    public function newsListScope(string $alias = ''): string
    {
        return $this->articleScope($alias) . ($this->hasModule('news') ? '' : ' AND ' . $alias . 'visible = TRUE AND ' . $alias . 'published_at <= NOW()');
    }

    /** Collection items: without the Collections section only the items shown on the site. */
    public function collectionItemListScope(string $alias = ''): string
    {
        return $this->hasModule('collections') ? '' : ' AND ' . $alias . 'visible = TRUE';
    }

    /**
     * IDs of authors whose news items the user can manage: an author only themselves, an editor and an administrator all (null = no limit).
     *
     * @return list<int>|null
     */
    public function managedAuthors(): ?array
    {
        return $this->isAdmin() || $this->isEditor() ? null : [$this->id()];
    }
}
