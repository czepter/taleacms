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

    /** What a sealed setting or seed starts with: a plain value from before is told from a sealed one by it. */
    public const string PREFIX = 'sealed:';

    /**
     * The settings that hold a password or key of an outside service: sealed while the key comes from TALEA_SECRET_KEY (Settings::get opens
     * them, Settings::set seals them). Not a password of the site's own (tasks_token and the like are addresses): they stay plain.
     */
    public const array SETTINGS = ['smtp_password', 'backup_password', 'captcha_secret', 'ai_key', 'webhook_secret', 'newsletter_key'];

    /**
     * The key for settings and seeds: only the one derived from TALEA_SECRET_KEY. With the key in the database, sealing would protect nothing
     * (a dump holds both), so the values stay plain and System status says to set the variable. null = no install key.
     */
    public static function installKey(Settings $settings): ?string
    {
        $secret = Antispam::environmentSecret($settings);

        return $secret === null ? null : self::derive($secret);
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * The value as it is stored: sealed when there is an install key; '' stays as it is. A value is always sealed, whatever it starts
     * with: a typed secret that begins with "sealed:" is a secret like any other (stored plain it would read back empty). Callers pass
     * plain text only; sealStored skips what is sealed already.
     */
    public static function sealValue(Settings $settings, string $plain): string
    {
        if ($plain === '' || ($key = self::installKey($settings)) === null) {
            return $plain;
        }

        return self::PREFIX . self::seal($key, $plain);
    }

    /** The plain value of a stored one: a value that was never sealed as it is; null when it is sealed and cannot be opened (no install key, another key). */
    public static function openValue(Settings $settings, string $stored): ?string
    {
        if (!self::isSealed($stored)) {
            return $stored;
        }
        $key = self::installKey($settings);

        return $key === null ? null : self::open($key, substr($stored, strlen(self::PREFIX)));
    }

    /**
     * Seals what is still stored in plain text on this site: the secret settings, and the seeds of the authenticator app of the owner's
     * accounts. Only with an install key. Idempotent. It runs under a lock, and every write only replaces the plain value it read: a
     * password saved at the same moment (or a seed enrolled) is not overwritten with the old one.
     *
     * @return int how many values were sealed
     */
    public static function sealStored(Db $db, ?Settings $settings = null): int
    {
        $settings ??= new Settings($db);
        if (self::installKey($settings) === null) {
            return 0;
        }
        $lock = 'vault-seal-' . $db->prefix;
        if (!$db->lock($lock)) {
            return 0; // another process is sealing: it does the same
        }
        try {
            $sealed = 0;
            foreach ($db->pairs('SELECT name, value FROM {settings} WHERE name IN (' . implode(', ', array_fill(0, count(self::SETTINGS), '?')) . ')', self::SETTINGS) as $name => $value) {
                if ((string) $value !== '' && !self::isSealed((string) $value)) {
                    $sealed += $db->update('settings', ['value' => self::sealValue($settings, (string) $value)], ['name' => (string) $name, 'value' => (string) $value]);
                }
            }
            foreach ($db->all("SELECT user_id, totp_secret FROM {users} WHERE totp_secret <> '' AND totp_secret NOT LIKE 'sealed:%'") as $user) {
                $sealed += $db->update('users', ['totp_secret' => self::sealValue($settings, (string) $user['totp_secret'])], ['user_id' => $user['user_id'], 'totp_secret' => $user['totp_secret']]);
            }
        } finally {
            $db->unlock($lock);
        }
        if ($sealed > 0) {
            $settings->reload(); // what this object holds is the plain text of before
        }

        return $sealed;
    }

    /** sealStored for every site of the install (multisite) or the one site; the daily security job. @return int how many values were sealed */
    public static function sealAll(Db $db): int
    {
        if (!Sites::enabled()) {
            return self::sealStored($db);
        }
        $sealed = 0;
        foreach (Sites::prefixes($db) as $prefix) {
            $site = $db->forSite($prefix);
            $sealed += $site->tableExists('settings') ? self::sealStored($site) : 0;
        }

        return $sealed;
    }

    /**
     * Where the key is and whether what it protects can be read, for System status (Encryption key): [ok | warning | error, the text].
     * An error when a stored secret – a connector credential, a sealed setting – cannot be read with the key in use (a database restored
     * without its TALEA_SECRET_KEY, or the variable changed).
     *
     * @return array{0: string, 1: string}
     */
    public static function status(Settings $settings): array
    {
        $db = $settings->db();
        $unreadable = 0;
        foreach ($db->all('SELECT ' . implode(', ', Connectors::SEALED) . ' FROM {connectors}') as $row) {
            foreach ($row as $sealed) {
                $unreadable += (int) ($sealed !== null && $sealed !== '' && Connectors::decrypt($settings, (string) $sealed) === null);
            }
        }
        foreach ($db->all("SELECT totp_secret FROM {users} WHERE totp_secret LIKE 'sealed:%'") as $user) {
            $unreadable += (int) (self::openValue($settings, (string) $user['totp_secret']) === null);
        }
        $plain = 0;
        foreach (self::SETTINGS as $name) {
            $unreadable += (int) $settings->unreadable($name);
            $plain += (int) (($stored = (string) $settings->raw($name)) !== '' && !self::isSealed($stored));
        }
        $variable = Config::env('SECRET_KEY');

        return match (true) {
            $unreadable > 0 => ['error', t('%d stored secret(s) cannot be read with the current key – a database restored without its TALEA_SECRET_KEY, or the variable changed. Set the variable they were stored with, or enter the passwords and keys again and connect the services again.', $unreadable)],
            $variable !== '' && strlen($variable) < Antispam::SECRET_MIN_LENGTH => ['error', t('TALEA_SECRET_KEY is shorter than %d characters and is not used – the key stays in the database', Antispam::SECRET_MIN_LENGTH)],
            $variable === '' => ['warning', t('kept in the database – a database dump or backup alone decrypts the connector credentials, and the passwords and keys in the settings are stored readable; set TALEA_SECRET_KEY (docs/DEPLOYMENT.md)')],
            self::installKey($settings) === null => ['warning', t('TALEA_SECRET_KEY is set, but the stored credentials could not be moved to it yet – see the error log')],
            $plain > 0 => ['warning', t('%d stored password(s) or key(s) are not encrypted yet – they are on the next admin page or by the daily security job.', $plain)],
            default => ['ok', t('from TALEA_SECRET_KEY – stored passwords, keys and connector credentials are encrypted, the database holds no key')],
        };
    }
}
