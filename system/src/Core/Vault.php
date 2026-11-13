<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Sealing of stored secrets: one secretbox (libsodium) for everything the site keeps that must not sit in the database as readable text –
 * the credentials of connectors (Core\Connectors, always sealed), and, while the key comes from TALEA_SECRET_KEY, the passwords and
 * keys in the settings and the seeds of the authenticator app (#335).
 *
 * The key is derived from TALEA_SECRET_KEY when it is set (a database dump alone then opens nothing), otherwise from secret_key in the
 * database. The context of the derivation stays as it was in Connectors, so credentials sealed before still open.
 */
final class Vault
{
    /** The key sealed values are made with: derived from TALEA_SECRET_KEY, or from the database's secret_key while the variable is not set. */
    public static function key(Settings $settings): string
    {
        return self::derive(Antispam::environmentSecret($settings) ?? (new Antispam($settings->db(), $settings))->key());
    }

    /** The secretbox key of a secret (32 bytes). */
    public static function derive(string $secret): string
    {
        return sodium_crypto_generichash('talea-connectors|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** $plain sealed with $key: base64 of the nonce and the box. */
    public static function seal(string $key, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /** The plain text of a sealed value, null when it cannot be read (another key, damaged, empty). */
    public static function open(string $key, ?string $sealed): ?string
    {
        $raw = $sealed !== null ? base64_decode($sealed, true) : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return $plain === false ? null : $plain;
    }
}
