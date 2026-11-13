<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The proof of identity of a signed-in person (#332), a part of Auth: the password asked again, the proof recorded in the session by
 * a credential sign-in or a passkey confirmation, and the two checks that sensitive steps use. It reads Auth's own members
 * (user(), session, db, countError()) and exists only to keep Auth readable.
 */
trait AuthProof
{
    /** Shown when confirming with the password is locked; the person stays signed in. */
    public const string CONFIRM_LOCKED = 'Too many wrong passwords: confirming is locked for 15 minutes. You stay signed in.';

    /**
     * Confirms the signed-in person's current password for a sensitive step (3.3.3, N56). A wrong one counts like a wrong
     * sign-in: ten in a row lock the account for 15 minutes, while the person stays signed in. A locked account is not
     * checked at all, so the right password is refused too. The password is taken as typed (N61).
     *
     * @param string $wrong the (untranslated) text for a wrong password
     * @return string|null null = confirmed, else the untranslated English reason
     */
    public function confirmPassword(string $typed, string $wrong = 'The current password is not correct.'): ?string
    {
        $user = $this->user();
        if ($user === null) {
            return $wrong;
        }
        if (self::isLocked($user)) {
            return self::CONFIRM_LOCKED;
        }
        if (self::matchingPassword($typed, (string) $user['password']) === null) {
            $this->countError($user);
            $this->countDeviceFailure($user);

            return $wrong;
        }
        // "ten in a row": a right password ends the series
        $this->db->update('users', ['failed_logins' => 0], ['user_id' => $user['user_id']]);
        $this->user['failed_logins'] = 0;

        return null;
    }

    /** How long a proof of identity in the session counts for a sensitive change (seconds). */
    public const int FRESH_SECONDS = 600;

    /** Proof kinds a person who signed in with a credential recently has: any of them counts for a change to other people or the site. */
    private const array PROOF_KINDS = ['password', 'code', 'backup', 'passkey', 'handoff'];

    /**
     * Is $proof (the session's record: ['at' => unix time, 'kind' => ...]) of one of $kinds and younger than FRESH_SECONDS at $now?
     *
     * @param list<string> $kinds
     */
    public static function freshProof(mixed $proof, array $kinds, int $now): bool
    {
        return is_array($proof) && isset($proof['at'], $proof['kind']) && is_int($proof['at']) && is_string($proof['kind'])
            && in_array($proof['kind'], $kinds, true) && $now - $proof['at'] < self::FRESH_SECONDS;
    }

    /**
     * The session remembers that the person proved who they are: a sign-in with a credential (password, app code, backup code,
     * passkey) or a passkey/backup code typed for a confirmation. "Stay signed in" and a typed password confirmation record nothing.
     */
    private function recordProof(string $kind, int $idu): void
    {
        $this->session->set('proof', ['at' => time(), 'kind' => $kind, 'user' => $idu]);
    }

    /** The signed-in person has a proof of one of $kinds younger than FRESH_SECONDS. @param list<string> $kinds */
    private function hasFreshProof(array $kinds): bool
    {
        $user = $this->user();
        $proof = $this->session->get('proof');

        return $user !== null && is_array($proof) && ($proof['user'] ?? 0) === (int) $user['user_id'] && self::freshProof($proof, $kinds, time());
    }

    /**
     * A sensitive change to other people or to the site (another person's password, e-mail or two-factor reset, a new administrator,
     * every section, weaker sign-in rules): a credential sign-in in the last ten minutes, a passkey confirmation (admin.php?action=proof)
     * or the person's own password typed now (confirmPassword).
     *
     * @return string|null null = proven, else the untranslated reason
     */
    public function requireFresh(Request $request): ?string
    {
        if ($this->hasFreshProof(self::PROOF_KINDS)) {
            return null;
        }
        $typed = $request->postRaw('current_password');

        return $typed === '' ? 'Confirm the change with your password or your passkey.' : $this->confirmPassword($typed);
    }

    /**
     * A change to the person's own lasting access (a Claude connection): a passkey or backup code confirmed in the last ten minutes, or
     * the password typed now. A sign-in with the password alone does not count: the password is asked every time.
     *
     * @return string|null null = proven, else the untranslated reason
     */
    public function confirmOwn(Request $request): ?string
    {
        if ($this->hasFreshProof(['passkey', 'backup'])) {
            return null;
        }
        $typed = $request->postRaw('current_password');

        return $typed === '' ? 'The current password is not correct.' : $this->confirmPassword($typed);
    }

    /**
     * "Confirm with your passkey", part 1: a challenge for a key of the signed-in account (the device must check its owner).
     *
     * @return array<string, mixed>|null options for navigator.credentials.get(), null = the account has no passkey
     */
    public function proofChallenge(string $siteUrl): ?array
    {
        $user = $this->user();
        $keys = $user === null ? [] : $this->workingKeys((int) $user['user_id']);
        if ($keys === []) {
            return null;
        }
        $challenge = Passkey::challenge();
        $this->session->set('proof_challenge', $challenge);

        return Passkey::signInOptions($challenge, Passkey::rpId($siteUrl), array_map(static fn (array $k): string => (string) $k['credential_id'], $keys), 'required');
    }

    /**
     * "Confirm with your passkey", part 2: the signature of a key of the signed-in account, made by a device that checked its owner.
     * A wrong one counts like a wrong password. Success is a proof of kind passkey in the session.
     *
     * @param array<string, mixed> $response
     * @return string|null null = confirmed, else the untranslated reason
     */
    public function verifyProof(array $response, string $siteUrl): ?string
    {
        $user = $this->user();
        $challenge = (string) $this->session->get('proof_challenge', '');
        $this->session->remove('proof_challenge'); // valid for one attempt
        if ($user === null) {
            return 'The sign-in has expired, please start again.';
        }
        if (self::isLocked($user)) {
            return self::CONFIRM_LOCKED;
        }
        $key = $this->db->one('SELECT * FROM {user_passkeys} WHERE credential_hash = ? AND user_id = ?', [hash('sha256', Passkey::fromB64((string) ($response['id'] ?? ''))), $user['user_id']]);
        try {
            if ($key === null) {
                throw new \RuntimeException('This key does not belong to the account.');
            }
            $this->assertKey($key, $response, $challenge, $siteUrl, true);
        } catch (\RuntimeException $e) {
            $this->countError($user);
            $this->countDeviceFailure($user);

            return $e->getMessage();
        }
        $this->recordProof('passkey', (int) $user['user_id']);

        return null;
    }
}
