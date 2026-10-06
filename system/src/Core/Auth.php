<?php

declare(strict_types=1);

namespace Kaleta\Core;

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

    public const array TYPES = [self::AUTHOR => 'autor', self::EDITOR => 'editor', self::ADMIN => 'správce'];

    /** After this many wrong passwords or codes in a row the account is locked for 15 minutes (it unlocks itself again). */
    private const int MAX_ERRORS = 10;

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $user = false;

    /** @var list<string>|null */
    private ?array $modules = null;

    public function __construct(private readonly Db $db, private readonly Session $session)
    {
    }

    /** @return string|null error text, null = signed in */
    public function login(string $login, string $password, string $ip): ?string
    {
        // Slowing down password guessing: at most 10 attempts from one IP per 15 minutes
        $attempts = (int) $this->db->value(
            "SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE",
            [Antispam::hash($ip)],
        );
        if ($attempts >= 10) {
            return t('Too many sign-in attempts. Try again in 15 minutes.');
        }

        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE user = ?', [$login]);
        // The hash is verified even for a nonexistent user, so that the response time does not reveal that the account does not exist
        // (a hash of a random password nobody knows – not a secret)
        // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash
        $hash = $user['password'] ?? '$2y$12$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';
        $ok = password_verify($password, $hash) && $user !== null;

        if (!$ok) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // after 10 errors in a row the account is locked for 15 minutes - not permanently, otherwise anyone could lock the site administrator out
                $errorCount = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $errorCount >= self::MAX_ERRORS
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $errorCount], ['idu' => $user['idu']]);
            }

            return t('Wrong user name or password.');
        }
        if ($user['blokovat']) {
            return t('The account is blocked. Contact an administrator.');
        }
        if ($user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $this->db->update('uzivatele', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['idu' => $user['idu']]);
        }
        // the failure counter is reset only by a completed sign-in (recordSignIn): with two-factor sign-in a correct password
        // must not wipe the wrong codes counted so far, or the per-account lock would never be reached (3.3.2, N7)

        $this->session->regenerate();
        if ($user['totp_tajemstvi'] !== '') {
            // the password matches, but the account has two-factor sign-in: only the code from the app completes the sign-in
            $this->session->set('idu_ceka', ['idu' => (int) $user['idu'], 'cas' => time()]);

            return null;
        }
        $this->recordSignIn((int) $user['idu']);
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::passwordHash((string) $this->db->value('SELECT password FROM {uzivatele} WHERE idu = ?', [$user['idu']])));
        $this->user = false;

        return null;
    }

    /**
     * Password hash stored in the session: after a password change all other sign-ins of the same account stop being valid
     * (a stolen session, a forgotten computer). By itself it reveals nothing - it is a shortened hash of an already hashed password.
     */
    public static function passwordHash(string $hash): string
    {
        return substr(hash('sha256', 'kaleta-session|' . $hash), 0, 24);
    }

    /** After changing one's own password: this sign-in stays valid, the others do not. */
    public function refreshAfterPasswordChange(string $newHash): void
    {
        $this->session->regenerate();
        $this->session->set('otisk', self::passwordHash($newHash));
        $this->user = false;
    }

    /** The password was entered correctly and a code from the authenticator app is awaited (at most 5 minutes). */
    public function isAwaitingCode(): bool
    {
        $pending = $this->session->get('idu_ceka');

        return is_array($pending) && time() - (int) $pending['cas'] < 300;
    }

    /** Second step of the sign-in: a code from the app, or a one-time backup code. @return string|null error text */
    public function verifyCode(string $code, string $ip): ?string
    {
        if (!$this->isAwaitingCode()) {
            return t('The sign-in has expired, please start again.');
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [Antispam::hash($ip)]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }
        $backupCodes = $user === null ? null : Totp::useBackupCode($user['totp_zalozni'], $code);
        if ($user === null || (!Totp::verify($user['totp_tajemstvi'], $code) && $backupCodes === null)) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                // wrong codes are counted per account, not only per IP address: whoever knows the password must not try codes from many addresses
                $errorCount = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $errorCount >= self::MAX_ERRORS
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $errorCount], ['idu' => $user['idu']]);
            }

            return t('The code is not correct.');
        }
        $this->recordSignIn((int) $user['idu']);
        if ($backupCodes !== null) {
            $this->db->update('uzivatele', ['totp_zalozni' => $backupCodes], ['idu' => $user['idu']]);
        }
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::passwordHash((string) $user['password']));
        $this->user = false;

        return null;
    }

    /** Does the account waiting for the second step have registered passkeys? */
    public function isAwaitingKey(): bool
    {
        return $this->isAwaitingCode() && $this->accountKeys((int) $this->session->get('idu_ceka')['idu']) !== [];
    }

    /** @return list<array<string, mixed>> passkeys of the account */
    public function accountKeys(int $idu): array
    {
        return $this->db->all('SELECT * FROM {uzivatele_klice} WHERE idu = ? ORDER BY idk', [$idu]);
    }

    /**
     * Second step of the sign-in with a passkey, part 1: a challenge for the device. Valid only for the account that has just entered the correct password.
     *
     * @return array<string, mixed>|null options for navigator.credentials.get(), null = nothing to wait for
     */
    public function keyChallenge(string $siteUrl): ?array
    {
        if (!$this->isAwaitingKey()) {
            return null;
        }
        $challenge = Passkey::challenge();
        $this->session->set('klic_vyzva', $challenge);

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), array_map(static fn (array $k): string => (string) $k['id_klice'], $this->accountKeys((int) $this->session->get('idu_ceka')['idu'])));
    }

    /**
     * Second step of the sign-in with a passkey, part 2: verifying the signature. A failure counts the same as a wrong code.
     *
     * @param array<string, mixed> $response
     * @return string|null error text, null = signed in
     */
    public function verifyKey(array $response, string $siteUrl, string $ip): ?string
    {
        if (!$this->isAwaitingCode()) {
            return t('The sign-in has expired, please start again.');
        }
        $attempts = (int) $this->db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [Antispam::hash($ip)]);
        if ($attempts >= 10) {
            return t('Too many attempts. Try again in 15 minutes.');
        }
        $challenge = (string) $this->session->get('klic_vyzva', '');
        $this->session->remove('klic_vyzva'); // the challenge is valid for one attempt
        $user = $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $this->session->get('idu_ceka')['idu']]);
        if ($user !== null && $user['zamceno_do'] !== null && strtotime($user['zamceno_do']) > time()) {
            $this->session->remove('idu_ceka');

            return t('The account is temporarily locked after a series of failed attempts. Try again in 15 minutes.');
        }
        $key = $user === null ? null : $this->db->one('SELECT * FROM {uzivatele_klice} WHERE idu = ? AND otisk_id = ?', [$user['idu'], hash('sha256', Passkey::fromB64((string) ($response['id'] ?? '')))]);
        try {
            if ($key === null) {
                throw new \RuntimeException('This key does not belong to the account.');
            }
            $counter = Passkey::verifySignIn($response, $challenge, Passkey::origin($siteUrl), Passkey::rpId($siteUrl), (string) $key['verejny'], (int) $key['pocitadlo']);
        } catch (\RuntimeException $e) {
            $this->db->insert('kontrola_ip', ['ip_adresa' => Antispam::hash($ip), 'typ' => 'login', 'cas' => date('Y-m-d H:i:s')]);
            if ($user !== null) {
                $errorCount = (int) $user['pocet_chyb'] + 1;
                $this->db->update('uzivatele', $errorCount >= self::MAX_ERRORS
                    ? ['pocet_chyb' => 0, 'zamceno_do' => date('Y-m-d H:i:s', time() + 900)]
                    : ['pocet_chyb' => $errorCount], ['idu' => $user['idu']]);
            }

            return t($e->getMessage());
        }
        $this->db->update('uzivatele_klice', ['pocitadlo' => $counter, 'pouzito' => date('Y-m-d H:i:s')], ['idk' => $key['idk']]);
        $this->recordSignIn((int) $user['idu']);
        $this->session->remove('idu_ceka');
        $this->session->regenerate();
        $this->session->set('idu', (int) $user['idu']);
        $this->session->set('otisk', self::passwordHash((string) $user['password']));
        $this->user = false;

        return null;
    }

    /**
     * A completed sign-in (password alone, or password and the code or passkey): the last sign-in is what the check of
     * unused accounts counts from (Core\SecurityHygiene), so a password alone without the second step does not count.
     */
    private function recordSignIn(int $idu): void
    {
        $this->db->update('uzivatele', ['pocet_chyb' => 0, 'posledni_login' => date('Y-m-d H:i:s')], ['idu' => $idu]);
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            // without a session cookie there is nobody who could be signed in - and no session is started for the check (the site stays cacheable)
            if (!isset($_COOKIE['kaleta'])) {
                return $this->user = null;
            }
            $id = $this->session->get('idu');
            $this->user = is_int($id)
                ? $this->db->one('SELECT * FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$id])
                : null;
            if ($this->user !== null) {
                $hash = $this->session->get('otisk');
                if ($hash === null) {
                    $this->session->set('otisk', self::passwordHash((string) $this->user['password'])); // a sign-in from before this check existed
                } elseif (!hash_equals(self::passwordHash((string) $this->user['password']), (string) $hash)) {
                    $this->session->remove('idu'); // the password has changed since the sign-in
                    $this->user = null;
                }
            }
        }

        return $this->user;
    }

    /** Sign-in without a session - for requests authenticated by a token (MCP). */
    public function signInAs(array $user): void
    {
        $this->user = $user;
        $this->modules = null;
    }

    /** @var array{name: string, access: string}|null the Claude connection of this request (MCP, 2.2) */
    private ?array $connection = null;

    /**
     * The request comes through a Claude connection: its name goes to the change log, and a connection limited to drafts
     * or to reading never publishes, whatever the user's role (canPublish).
     */
    public function useConnection(string $name, string $access): void
    {
        $this->connection = ['name' => $name, 'access' => isset(\Kaleta\Mcp\Catalog::CONNECTION_ACCESS[$access]) ? $access : 'read'];
    }

    /** @return array{name: string, access: string}|null */
    public function connection(): ?array
    {
        return $this->connection;
    }

    public function id(): int
    {
        return (int) ($this->user()['idu'] ?? 0);
    }

    public function isAdmin(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::ADMIN;
    }

    public function isEditor(): bool
    {
        return (int) ($this->user()['admin'] ?? -1) === self::EDITOR;
    }

    /** The site requires two-factor sign-in and this user does not have it yet (can only go to "Můj účet" (My account) to turn it on). */
    public function isMissingRequired2fa(Settings $siteSettings): bool
    {
        $required = $siteSettings->get('require_2fa');
        $user = $this->user();

        return $user !== null && ($required === 'vsichni' || ($required === 'spravci' && $this->isAdmin())) && (string) ($user['totp_tajemstvi'] ?? '') === '';
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
        return $this->isAdmin() && ($this->connection['access'] ?? 'full') === 'full';
    }

    /** Does the signed-in user have access to the module? Admin always; others according to ka_uzivatele_prava. */
    public function hasModule(string $ident, bool $forEveryone = false): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->isAdmin() || $forEveryone) {
            return true;
        }
        $this->modules ??= array_column(
            $this->db->all('SELECT ident_modulu FROM {uzivatele_prava} WHERE fk_id_user = ?', [$this->id()]),
            'ident_modulu',
        );

        return in_array($ident, $this->modules, true);
    }

    /**
     * Can the signed-in user edit this news item? The same rules as in the administration: the News module, an author only their own,
     * and a published news item only someone who can publish.
     *
     * @param array<string, mixed> $newsItem row of ka_novinky
     */
    public function canEditArticle(array $newsItem): bool
    {
        if (!$this->hasModule('news')) {
            return false;
        }
        $authors = $this->managedAuthors();

        return ($authors === null || in_array((int) $newsItem['autor'], $authors, true)) && (empty($newsItem['visible']) || $this->canPublish());
    }

    /**
     * Part of the WHERE condition (starts with „ AND“, or is empty) that limits the news list to what the signed-in user can see:
     * an author only their own news items, an editor and an administrator all of them.
     */
    public function articleScope(string $alias = ''): string
    {
        $authors = $this->managedAuthors();

        return $authors === null ? '' : ' AND ' . $alias . 'autor IN (' . implode(',', array_map(intval(...), $authors)) . ')';
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
