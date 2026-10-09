<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Verification of publisher signatures (Ed25519). The file system/update.pub may hold SEVERAL public keys - one per line
 * (base64, an optional description after a space, lines with # are comments). A signature is valid when it matches any of them.
 *
 * Why several keys: besides the operational key there is a backup key that is kept offline and not used. If the operational
 * key is lost, it signs a release with a new operational key; if it leaks, a release that removes the compromised key from the file.
 * The procedure is in docs/RELEASING.md.
 */
final class Signature
{
    /** @return array<string, string> key identifier => public key (binary) */
    public static function keys(string $file): array
    {
        $keys = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#') {
                continue;
            }
            $key = base64_decode((string) strtok($row, " \t"), true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[self::id($key)] = $key;
            }
        }

        return $keys;
    }

    /** Short key identifier (the first 8 characters of the fingerprint) - for the manifest and the docs, so it is clear what signed it. */
    public static function id(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 8);
    }

    /** Is the signature (base64) of the message valid against any of the keys in the file? */
    public static function isValid(string $message, string $signatureBase64, string $file): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        foreach (self::keys($file) as $key) {
            if (sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                return true;
            }
        }

        return false;
    }

    /** What exactly is signed for a package: the version, the ZIP fingerprint and the security-release flag (that one installs itself). */
    public static function packageMessage(string $version, string $sha256, bool $securityRelease): string
    {
        return $version . '|' . strtolower($sha256) . '|' . ($securityRelease ? 'security' : 'regular');
    }
}
