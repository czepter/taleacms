<?php

declare(strict_types=1);

namespace TaleaAddon\Firewall;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Events;
use Talea\Core\Response;
use Talea\Extension\Api;

/**
 * Firewall of the public site (was Core\Firewall until issue #29): blocked addresses and networks, blocked countries, a limit of requests
 * per minute, and a 24-hour block for addresses that probe for other systems (/wp-login.php, /.env, /xmlrpc.php…).
 *
 *  - It guards the public site, /mcp and OAuth – never admin.php, so the owner cannot lock themselves out; sign-in has its own lockout
 *    (Core\Auth), and so do password reset, the Claude connection and the page lock: those limits are core and never depend on this add-on.
 *    Addresses of the local network are never blocked.
 *  - The visitor's address is Core\Antispam::visitorIp() (the core setting trusted_proxy). The country is known only behind Cloudflare
 *    (CF-IPCountry, believed only from a Cloudflare address) or when the hosting sends GEOIP_COUNTRY_CODE; without it country blocking does nothing.
 *  - Probing is counted only for addresses that end in 404 (Api::notFound), never for pages that exist.
 *  - The request limit counts in small files (Antispam::tally, no database write per request).
 *  - Every refused request is logged (ext_firewall_log, 30 days, at most LOG_PER_HOUR rows an hour).
 */
final class Firewall
{
    /** Probes for other systems from one address in an hour before it is blocked for a day. */
    public const int PROBES = 5;
    public const int PROBE_BLOCK_HOURS = 24;
    public const int LOG_DAYS = 30;
    private const int LOG_PER_HOUR = 2000;

    /**
     * Addresses of other systems that only probing bots ask for. Narrower than NotFound::BOTS on purpose: a missing image,
     * a stylesheet or an old /wp-content/uploads/ link of a moved site never counts – a real visitor would be blocked.
     */
    public const string PROBE_PATHS = '#(^|/)\.(env|git|svn|hg|aws|ssh|htpasswd|ds_store)|\.(env|sql|bak|old|ini|sh|aspx?|jsp|cgi)$|^(wp-(login|admin|includes|config|signup|cron)|xmlrpc\.php|wordpress/|wp/wp-|cgi-bin/|phpmyadmin|pma/|myadmin|adminer|phpinfo|vendor/|actuator|boaform|hnap1|owa/|autodiscover|telescope|_profiler|server-status)#i';

    /** A refusal for this request, or null when it may go on (the early request hook). */
    public static function check(Api $api, string $path): ?Response
    {
        if (($ip = self::guardedIp($api->app())) === null) {
            return null;
        }
        $app = $api->app();
        $db = $app->db();
        $path = trim($path, '/');
        $server = $app->request->serverValues();
        $rate = (int) $api->get('rate');
        $reason = match (true) {
            $db->value('SELECT 1 FROM {ext_firewall_blocks} WHERE ip = ? AND blocked_until > NOW()', [$ip]) !== null => 'temporary',
            Antispam::inList($ip, self::parseList($api->get('ips'))[0]) => 'list',
            self::countryBlocked(self::country($server, $app->settings()->get('trusted_proxy')), $api->get('countries')) => 'country',
            // the Claude connection signs in on its own and may send many calls at once while it builds; sites report to a console
            $rate > 0 && preg_match('#^(mcp|oauth|\.well-known/|fleet/)#', $path) !== 1 && Antispam::tally($ip, 'rate', 60) > $rate => 'rate',
            default => null,
        };

        return $reason === null ? null : self::refuse($db, $ip, $reason, $path);
    }

    /**
     * Called when an address ends in 404: the fifth probe for another system (PROBE_PATHS) from one address in an hour
     * blocks it for PROBE_BLOCK_HOURS. Returns the refusal for this request, or null for the normal 404 page.
     */
    public static function notFound(Api $api, string $path): ?Response
    {
        if ($api->get('probes') !== '1' || preg_match(self::PROBE_PATHS, $path) !== 1 || ($ip = self::guardedIp($api->app())) === null) {
            return null;
        }
        if (Antispam::tally($ip, 'probe', 3600) < self::PROBES) {
            return null;
        }
        $db = $api->app()->db();
        $db->upsert('ext_firewall_blocks', ['ip' => $ip, 'blocked_until' => date('Y-m-d H:i:s', time() + self::PROBE_BLOCK_HOURS * 3600), 'reason' => 'probe', 'created_at' => date('Y-m-d H:i:s')], ['ip'], ['blocked_until', 'reason']);
        Events::record($db, 'firewall.blocked', 'warning', t('%s was blocked for %d hours after probing for other systems (%s).', $ip, self::PROBE_BLOCK_HOURS, '/' . mb_substr($path, 0, 120)), ['reason' => 'probe']);

        return self::refuse($db, $ip, 'probe', $path);
    }

    /** The visitor's address when the firewall guards this request (not a local address), otherwise null. */
    private static function guardedIp(App $app): ?string
    {
        $ip = Antispam::visitorIp($app->request->serverValues(), $app->settings()->get('trusted_proxy'));
        // the automated tests connect from 127.0.0.1 (TALEA_FIREWALL_LOCAL=1); never set on a real site
        if ($ip === '' || (self::isLocal($ip) && getenv('TALEA_FIREWALL_LOCAL') !== '1')) {
            return null;
        }

        return $ip;
    }

    private static function refuse(Db $db, string $ip, string $reason, string $path): Response
    {
        if ((int) $db->value('SELECT COUNT(*) FROM {ext_firewall_log} WHERE created_at > NOW() - ' . $db->dialect()->interval(1, 'HOUR')) < self::LOG_PER_HOUR) {
            $db->run('INSERT INTO {ext_firewall_log} (created_at, ip, reason, path) VALUES (?, ?, ?, ?)', [date('Y-m-d H:i:s'), $ip, $reason, '/' . mb_substr($path, 0, 254)]);
        }

        return $reason === 'rate'
            ? new Response("Too many requests.\n", 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60', 'Cache-Control' => 'no-store'])
            : new Response("Access denied.\n", 403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $server */
    public static function country(array $server, string $proxy): string
    {
        $code = $proxy === 'cloudflare' && Antispam::inList((string) ($server['REMOTE_ADDR'] ?? ''), Antispam::CLOUDFLARE)
            ? (string) ($server['HTTP_CF_IPCOUNTRY'] ?? '') : (string) ($server['GEOIP_COUNTRY_CODE'] ?? '');

        return preg_match('/^[A-Z]{2}$/', strtoupper($code)) === 1 && !in_array(strtoupper($code), ['XX', 'T1'], true) ? strtoupper($code) : '';
    }

    public static function countryBlocked(string $country, string $setting): bool
    {
        return $country !== '' && in_array($country, self::countries($setting), true);
    }

    /** @return list<string> ISO country codes from the setting ("RU, CN" or one per line) */
    public static function countries(string $setting): array
    {
        preg_match_all('/\b[A-Za-z]{2}\b/', $setting, $m);

        return array_values(array_unique(array_map('strtoupper', $m[0])));
    }

    /**
     * The manual list: one address or network per line, a comment after #. Returns [valid entries, invalid lines].
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function parseList(string $text): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $entry = trim(explode('#', $line, 2)[0]);
            if ($entry === '') {
                continue;
            }
            self::isValidEntry($entry) ? $valid[] = $entry : $invalid[] = $entry;
        }

        return [$valid, $invalid];
    }

    public static function isValidEntry(string $entry): bool
    {
        [$address, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits >= (strlen($packed) === 4 ? 8 : 16) && (int) $bits <= strlen($packed) * 8);
    }

    public static function isLocal(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** The clean-up job: old log rows and expired blocks (the request counters are cleaned by core). */
    public static function cleanUp(Db $db): void
    {
        $db->run('DELETE FROM {ext_firewall_log} WHERE created_at < NOW() - ' . $db->dialect()->interval(self::LOG_DAYS, 'DAY'));
        $db->run('DELETE FROM {ext_firewall_blocks} WHERE blocked_until < NOW()');
    }

    /** @return list<array<string, mixed>> */
    public static function blocks(Db $db): array
    {
        return $db->all('SELECT ip, blocked_until, reason FROM {ext_firewall_blocks} WHERE blocked_until > NOW() ORDER BY blocked_until DESC LIMIT 100');
    }

    /** @return list<array<string, mixed>> */
    public static function log(Db $db, int $limit = 50): array
    {
        return $db->all('SELECT created_at, ip, reason, path FROM {ext_firewall_log} ORDER BY id DESC LIMIT ' . $limit);
    }

    /** Why a request was refused, for people. @return array<string, string> */
    public static function reasons(): array
    {
        return ['list' => t('blocked address'), 'country' => t('blocked country'), 'rate' => t('too many requests'), 'probe' => t('probing for other systems'), 'temporary' => t('blocked for a while')];
    }
}
