<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The site address (setting site_url) and the server's say over it. TALEA_SITE_URL, when set, wins: the container applies it on
 * every start (`php system/docker.php site-url` in docker/entrypoint.sh, never on a page request); while it is set the admin field
 * is read-only and MCP refuses to change the address.
 */
final class SiteUrl
{
    /** The address the server sets (TALEA_SITE_URL without a trailing slash); '' = none, the stored setting rules. */
    public static function fromEnvironment(): string
    {
        return self::normalize(Config::env('SITE_URL'));
    }

    /**
     * The address in one shape: scheme and host in lower case, no trailing slash, no trailing dot of the host, no port that is the
     * scheme's default. An address that is not just scheme, host and port (a path, a query, a user) is returned as it is, without a
     * trailing slash – the validator refuses it.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return rtrim($url, '/');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;

        return $scheme . '://' . rtrim(strtolower($parts['host']), '.') . ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? ':' . $port : '');
    }

    /**
     * How many accounts would lose every passkey that works on the site when its address changes from $old to $new: passkeys belong to
     * the host (user_passkeys.rp_id), so with another host they sign in no more and the account falls back to password plus second step.
     * 0 when the host stays the same (or there was none).
     */
    public static function passkeyFallbacks(Db $db, string $old, string $new): int
    {
        $host = Passkey::rpId($old);
        if ($host === '' || $host === Passkey::rpId($new)) {
            return 0;
        }

        return (int) $db->value('SELECT COUNT(DISTINCT k.user_id) FROM {user_passkeys} k WHERE k.rp_id = ? AND NOT EXISTS (SELECT 1 FROM {user_passkeys} n WHERE n.user_id = k.user_id AND n.rp_id = ?)', [$host, Passkey::rpId($new)]);
    }

    /**
     * Whether the server's address rules this site: TALEA_SITE_URL is the address of the install, that is of the first owner's site 1 (the install prefix).
     * A further site has its own address (its domains), which its administrator edits like any setting.
     */
    public static function locked(Db $db): bool
    {
        return self::fromEnvironment() !== '' && $db->prefix === $db->installPrefix;
    }

    /**
     * Stores a new address with a change log row (old → new) and empties the page cache, since cached pages carry the canonical
     * addresses. false = it already was the address.
     */
    public static function change(App $app, string $url, string $reason = ''): bool
    {
        $old = $app->settings()->get('site_url');
        if ($old === $url) {
            return false;
        }
        $app->settings()->set('site_url', $url);
        \Talea\Admin\ChangeLog::write($app, 'settings', 'site_url', ($old !== '' ? $old : '–') . ' → ' . $url, $reason);
        \Talea\Front\Cache::clear();

        return true;
    }
}
