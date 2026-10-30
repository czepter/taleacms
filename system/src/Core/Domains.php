<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Multisite, domains (docs/specs/multisite.md, "Domains and certificates"): a host routes only once it was shown to point at this install
 * (`site_domains.verified_at`), by an HTTP token or a DNS TXT record; the install's own preview subdomains (TALEA_PREVIEW_DOMAIN) are
 * verified from the start and never indexed. Two requests are answered before any routing, on every host: the token file of a pending
 * host and the proxy's on-demand TLS question (/_talea/tls-allowed).
 */
final class Domains
{
    /** Questions per minute that /_talea/tls-allowed (per name, and per network unless it comes from the trusted proxy) and the token path (per network) answer; more get 429. */
    private const int ASKS_PER_MINUTE = 300;

    /** Tests replace the two network lookups: fn (array $target): ?string (the body of the answer), fn (string $name): list<string> (TXT values). */
    public static ?\Closure $fetch = null;
    public static ?\Closure $txt = null;

    /** The preview domain of the install (TALEA_PREVIEW_DOMAIN), lower-case, or '' when previews are off. */
    public static function previewDomain(): string
    {
        return Sites::normalizeHost(ltrim(Config::env('PREVIEW_DOMAIN'), '*.')) ?? '';
    }

    /** Is the host the preview domain or below it? Those names resolve to this install by design, so only the system makes them (kind = preview). */
    public static function inPreviewZone(string $host): bool
    {
        $preview = self::previewDomain();

        return $preview !== '' && ($host === $preview || str_ends_with($host, '.' . $preview));
    }

    /** Is the host listed in TALEA_VERIFIED_HOSTS (comma separated)? An operator's way to vouch for hosts without any check. */
    public static function envVerified(string $host): bool
    {
        return $host !== '' && in_array($host, array_map('trim', explode(',', strtolower(Config::env('VERIFIED_HOSTS')))), true);
    }

    /** Must a host that this person adds be verified first? Only with several owners, and not for operators; one owner's install keeps adding hosts at once. @param array<string, mixed>|null $user */
    public static function needsVerification(Db $db, ?array $user): bool
    {
        return Owners::enabled() && !Owners::isOperator($db, $user);
    }

    /** The host of a site's preview subdomain: owner 1 "{slug}.{preview}", owner n "{slug}.{owner letters}.{preview}" ("tlb_" gives "b"). */
    public static function previewHost(Db $db, string $ownerPrefix, string $slug): ?string
    {
        $preview = self::previewDomain();
        if ($preview === '' || $slug === '') {
            return null;
        }
        $letters = $ownerPrefix === $db->installPrefix ? '' : substr(rtrim($ownerPrefix, '_'), strlen(rtrim($db->installPrefix, '_')));

        return Sites::normalizeHost($slug . '.' . ($letters !== '' ? $letters . '.' : '') . $preview);
    }

    /**
     * Gives every set-up site that has none a preview subdomain (verified, never indexed): cron's, so sites that existed before
     * TALEA_PREVIEW_DOMAIN was set get theirs once. Idempotent. Returns how many were added.
     */
    public static function ensurePreviews(Db $db): int
    {
        if (self::previewDomain() === '' || !Sites::enabled()) {
            return 0;
        }
        $added = 0;
        foreach (Owners::enabled() ? Owners::readyPrefixes($db) : [$db->ownerPrefix] as $owner) {
            $o = $db->forOwner($owner);
            $has = array_flip(array_map('intval', array_column($o->all("SELECT site_id FROM {site_domains} WHERE kind = 'preview'"), 'site_id')));
            foreach ($o->all("SELECT site_id, slug FROM {sites} WHERE ready_at IS NOT NULL AND status <> 'trashed'") as $site) {
                if (!isset($has[(int) $site['site_id']])) {
                    $added += self::addPreview($o, (int) $site['site_id'], (string) $site['slug']) ? 1 : 0;
                }
            }
        }

        return $added;
    }

    /** One site's preview host, unless the name is taken; $owner is a connection of the owner (any site of it). */
    public static function addPreview(Db $owner, int $siteId, string $slug): bool
    {
        $host = self::previewHost($owner, $owner->ownerPrefix, $slug);
        if ($host === null || Owners::hostTaken($owner, $host)) {
            return false;
        }
        $owner->insert('site_domains', ['site_id' => $siteId, 'host' => $host, 'is_primary' => false, 'redirect_to_primary' => false, 'kind' => 'preview', 'verified_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** A new secret for the checks of a host. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The value a TXT record `_talea.<host>` must carry. */
    public static function txtValue(string $token): string
    {
        return 'talea-verify=' . $token;
    }

    /**
     * Checks a pending host (the HTTP token file first, then the DNS record) and marks it verified. Returns null when it is verified now,
     * otherwise why not. The host is the owner's input, so the request goes through the safe outbound path (Links::target: no internal
     * address, pinned connection) and the DNS lookup only sees a name Outbound::host accepts.
     *
     * @param array<string, mixed> $domain the row of {site_domains} of $owner
     */
    public static function check(Db $owner, array $domain): ?string
    {
        if ($domain['verified_at'] !== null) {
            return null;
        }
        $host = (string) $domain['host'];
        $token = (string) $domain['token'];
        if ($token === '' || Outbound::host($host) === null) {
            return 'This host name cannot be checked.';
        }
        // the install answers the token file on every name that points here, the preview zone's wildcard included, and any name that
        // already points at the install (an address of the install, a foreign site behind the same proxy) would pass it: for a client owner
        // only the DNS record proves anything. Owner 1 (the install's own) may also use the token file.
        if (self::dnsOnly($owner) || self::inPreviewZone($host)) {
            $failure = self::viaDns($host, $token);
            if ($failure !== null) {
                return self::inPreviewZone($host) ? 'Names under the preview domain are verified with the DNS record only.' : $failure;
            }
        } else {
            $http = self::viaHttp($host, $token);
            if ($http !== null && self::viaDns($host, $token) !== null) {
                return $http;
            }
        }

        return self::verify($owner, (int) $domain['site_id'], (int) $domain['domain_id']);
    }

    /** Is this owner's host verified by the DNS record alone? True for every owner but the install's own (owner 1); false without several owners. */
    public static function dnsOnly(Db $owner): bool
    {
        return Owners::enabled() && $owner->ownerPrefix !== $owner->installPrefix;
    }

    /** The operator vouches for a host (the Owners area, owner 1's administration): verified without a check. Returns an error text, null = verified. */
    public static function markVerified(Db $owner, int $siteId, int $domainId): ?string
    {
        return self::verify($owner, $siteId, $domainId);
    }

    /**
     * Marks a host verified and removes the unverified rows of the same name in the other owners (a name was first claimed by someone
     * who could not prove it). Refused when another owner has the name verified already. Returns an error text, null = done.
     */
    private static function verify(Db $owner, int $siteId, int $domainId): ?string
    {
        $host = (string) $owner->value('SELECT host FROM {site_domains} WHERE domain_id = ? AND site_id = ?', [$domainId, $siteId]);
        if ($host === '') {
            return 'The host name does not exist.';
        }
        foreach (Owners::enabled() ? Owners::readyPrefixes($owner) : [] as $prefix) {
            if ($prefix !== $owner->ownerPrefix && $owner->forOwner($prefix)->value('SELECT 1 FROM {site_domains} WHERE host = ? AND verified_at IS NOT NULL', [$host]) !== null) {
                return 'This host name is already used by a site.';
            }
        }
        $owner->update('site_domains', ['verified_at' => date('Y-m-d H:i:s'), 'token' => null], ['domain_id' => $domainId, 'site_id' => $siteId]);
        self::dropUnverified($owner, $host);

        return null;
    }

    /** Deletes the unverified rows of a host name in every OTHER owner (called when the name becomes verified). */
    public static function dropUnverified(Db $owner, string $host): void
    {
        foreach (Owners::enabled() ? Owners::readyPrefixes($owner) : [] as $prefix) {
            if ($prefix !== $owner->ownerPrefix) {
                $owner->forOwner($prefix)->run('DELETE FROM {site_domains} WHERE host = ? AND verified_at IS NULL', [$host]);
            }
        }
    }

    /** http://<host>/.well-known/talea-domain/<token> must answer with the token. null = it does, otherwise the reason. */
    public static function viaHttp(string $host, string $token): ?string
    {
        $target = Links::target('http://' . $host . '/.well-known/talea-domain/' . $token);
        if ($target === null) {
            return 'This host name leads to an internal address and is not checked.';
        }
        if ($target === false) {
            return 'This host name does not resolve yet: point it to this installation in the DNS first.';
        }
        $body = (self::$fetch ?? self::get(...))($target);

        return $body !== null && hash_equals($token, trim($body)) ? null : 'The address did not answer with the token yet. Is the host name pointed to this installation?';
    }

    /** TXT _talea.<host> must hold "talea-verify=<token>". null = it does, otherwise the reason. */
    public static function viaDns(string $host, string $token): ?string
    {
        $values = (self::$txt ?? static fn (string $name): array => array_map(fn (array $r): string => (string) ($r['txt'] ?? ''), @dns_get_record($name, DNS_TXT) ?: []))('_talea.' . $host);

        return in_array(self::txtValue($token), array_map('trim', $values), true) ? null : 'The DNS record was not found yet.';
    }

    /**
     * One GET on the pinned address, no redirects, a few hundred bytes at most.
     *
     * @param array{url: string, host: string, port: int, ip: string} $target from Links::target
     */
    private static function get(array $target): ?string
    {
        $ch = curl_init($target['url']);
        Outbound::pin($ch, $target['host'], $target['port'], $target['ip']);
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Talea domain check',
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $h, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($body) > 256 ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);

        return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $body : null;
    }

    /* ---------- the requests answered before routing, on any host ---------- */

    /**
     * /_talea/tls-allowed?domain=<host> (the proxy's on-demand TLS question: 200 = get a certificate) and
     * /.well-known/talea-domain/<token> (the token file of a pending host). Sends the answer and returns true when the path is one of them.
     * The answers never say which owner a host belongs to.
     */
    public static function answerEarly(Request $request, Db $db): bool
    {
        $path = $request->path();
        $token = preg_match('#^/\.well-known/talea-domain/([a-f0-9]{32})$#D', $path, $m) === 1 ? $m[1] : null;
        if ($path !== '/_talea/tls-allowed' && $token === null) {
            return false;
        }
        $answer = static function (int $status, string $body): never {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
            exit($body);
        };
        $server = $request->serverValues();
        if ($token !== null) {
            // the token path is asked by whoever guesses: counted per network (behind TALEA_TRUSTED_PROXIES the visitor's, not the proxy's)
            if (Antispam::tally(Antispam::network(Antispam::visitorIp($server, '')), 'domain-token', 60) > self::ASKS_PER_MINUTE) {
                $answer(429, "Too many requests\n");
            }
            $host = Sites::requestHost($request);
            foreach (Owners::enabled() ? Owners::readyPrefixes($db) : [$db->ownerPrefix] as $owner) {
                if ($db->forOwner($owner)->value('SELECT 1 FROM {site_domains} WHERE host = ? AND token = ? AND verified_at IS NULL', [$host, $token]) !== null) {
                    $answer(200, $token);
                }
            }
            $answer(404, "Not found\n");
        }
        // the proxy asks for every name it meets, a flood of random names included: counted per NAME, so one name's questions never lock out
        // another; the question of the trusted proxy itself (its address is the same for all names) is not counted per network
        $host = Sites::normalizeHost($request->get('domain')) ?? '';
        $fromProxy = Antispam::inList((string) ($server['REMOTE_ADDR'] ?? ''), array_filter(array_map('trim', explode(',', Config::env('TRUSTED_PROXIES')))));
        if (Antispam::tally('name:' . $host, 'tls-ask-name', 60) > self::ASKS_PER_MINUTE
            || (!$fromProxy && Antispam::tally(Antispam::network((string) ($server['REMOTE_ADDR'] ?? '')), 'tls-ask', 60) > self::ASKS_PER_MINUTE)) {
            $answer(429, "Too many requests\n");
        }
        $site = $host === '' ? null : Sites::resolve($db, $host);
        $allowed = $site !== null && $site['verified'] && $site['status'] !== 'trashed' && $site['owner_status'] !== 'archived';
        $answer($allowed ? 200 : 404, $allowed ? "ok\n" : "Not found\n");
    }
}
