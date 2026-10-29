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
        return rtrim(Config::env('SITE_URL'), '/');
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
