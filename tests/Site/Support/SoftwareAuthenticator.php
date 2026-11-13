<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

use Talea\Core\Passkey;

/** A software authenticator for the site tests: an ES256 key pair that registers and signs in like a device. Needs site(). */
trait SoftwareAuthenticator
{
    /**
     * Registers a new key for the account straight in the database; $rpId is the host it belongs to (default: the site's).
     *
     * @return array{\OpenSSLAsymmetricKey, string} the private key and the credential id
     */
    protected function registerKey(string $username, string $publicId, ?string $rpId = null): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $id = random_bytes(20);
        $this->site()->exec("INSERT INTO tl_user_passkeys (public_id, user_id, name, credential_hash, credential_id, public_key, alg, rp_id, sign_count, created_at) SELECT ?, user_id, 'test', ?, ?, ?, -7, ?, 0, NOW() FROM tl_users WHERE username = ?",
            [$publicId, hash('sha256', $id), Passkey::b64($id), (string) openssl_pkey_get_details($key)['key'], $rpId ?? Passkey::rpId($this->site()->base), $username]);

        return [$key, $id];
    }

    /** The answer of the authenticator to a sign-in challenge ($flags: 0x01 present, 0x04 verified, 0x05 both). */
    protected function keyAnswer(\OpenSSLAsymmetricKey $key, string $id, string $challenge, int $flags): string
    {
        $origin = Passkey::origin($this->site()->base);
        $client = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $data = hash('sha256', Passkey::rpId($origin), true) . chr($flags) . pack('N', 0);
        openssl_sign($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256);

        return (string) json_encode(['id' => Passkey::b64($id), 'clientDataJSON' => Passkey::b64($client), 'authenticatorData' => Passkey::b64($data), 'signature' => Passkey::b64((string) $signature)]);
    }

    /** Signs in on the first step with the key alone (the device checked its owner when $flags is 0x05). */
    protected function signInWithKey(Http $browser, \OpenSSLAsymmetricKey $key, string $id, bool $remember = false, int $flags = 0x05): Response
    {
        $csrf = $browser->get('/admin.php')->csrf();
        $options = $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'passkey_options'])->json();

        return $browser->post('/admin.php', ['_csrf' => $csrf, 'step' => 'key', 'answer' => $this->keyAnswer($key, $id, (string) $options['challenge'], $flags)] + ($remember ? ['remember' => '1'] : []));
    }

    /**
     * The answer of a new key to the registration options of My account.
     *
     * @param array<string, mixed> $options the JSON of passkey_options
     * @return array{answer: string, key: \OpenSSLAsymmetricKey, id: string}
     */
    protected function registrationAnswer(array $options, ?string $id = null): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);
        $id ??= random_bytes(20);
        $origin = Passkey::origin($this->site()->base);
        $client = (string) json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $data = hash('sha256', Passkey::rpId($origin), true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id
            . str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s/', '', $details['key']));

        return ['key' => $key, 'id' => $id, 'answer' => (string) json_encode([
            'id' => Passkey::b64($id), 'clientDataJSON' => Passkey::b64($client), 'authenticatorData' => Passkey::b64($data),
            'publicKey' => Passkey::b64($der), 'publicKeyAlgorithm' => -7,
        ])];
    }
}
