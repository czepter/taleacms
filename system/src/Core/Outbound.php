<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The host of a request the server makes to another site, decided in one place (3.3.3, N52). Every pinned request –
 * images and pages of an import (ImageDownloader, Import\Fetch), the fleet (Fleet\Http) and the link check (Links) –
 * goes through it:
 *  - the host is normalized once: an internationalized name becomes punycode, letters become lowercase, and anything
 *    that is neither an IP address nor a plain ASCII name (a-z, 0-9, dot, hyphen) is refused – above all a percent sign,
 *    which curl would decode on its own and so look up a name nobody checked;
 *  - the request goes to the URL rewritten with that host, so the name that was resolved and checked, the
 *    CURLOPT_RESOLVE entry and the name curl connects to are one and the same string;
 *  - before sending anything curl compares the address it connected to with the pinned one (CURLOPT_PREREQFUNCTION)
 *    and gives up on a mismatch.
 */
final class Outbound
{
    /**
     * The host as the server looks it up and pins it: an IP address (IPv6 without brackets), or an ASCII name in lowercase
     * (an internationalized name in punycode). null = refused: a percent sign, a space or another character outside
     * a-z 0-9 . -, an empty label, or a name whose last label is a number (0x7f.1, 2130706433 – another way of writing
     * an IPv4 address, which curl and browsers read as one).
     */
    public static function host(string $host): ?string
    {
        if ($host === '' || str_contains($host, '%')) {
            return null;
        }
        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            $packed = @inet_pton($bare);

            return $packed === false ? null : (string) inet_ntop($packed);
        }
        if ($bare !== $host) {
            return null; // brackets around something that is not an IPv6 address
        }
        if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null; // without the intl extension an internationalized name cannot be checked
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                return null;
            }
            $host = $ascii;
        }
        $host = strtolower($host);
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.?$/', $host) !== 1) {
            return null;
        }
        $labels = explode('.', rtrim($host, '.'));

        return preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1 ? null : $host;
    }

    /**
     * An http(s) URL with its host normalized (host()) and written back into it; null = another scheme, a user name or
     * password, a refused host, or a URL whose host parse_url() reads differently. The authority is read as curl reads
     * it – from "//" to the first / ? or # – and not with parse_url() alone, which mangles the bytes of an
     * internationalized name.
     *
     * @return array{url: string, host: string, port: int, scheme: string}|null
     */
    public static function url(string $url): ?array
    {
        if (preg_match('#^(https?)://#i', $url, $m) !== 1) {
            return null;
        }
        $scheme = strtolower($m[1]);
        $start = strlen($m[0]);
        $authority = substr($url, $start, strcspn($url, '/?#', $start));
        if (preg_match('/^(\[[^\]]*\]|[^:@\[\]]*)(:\d{1,5})?$/', $authority, $a) !== 1) {
            return null; // a user name or password, or a port that is not a number
        }
        $host = self::host($a[1]);
        $parsed = parse_url($url, PHP_URL_HOST);
        if ($host === null || !is_string($parsed) || (preg_match('/[^\x21-\x7e]/', $a[1]) !== 1 && $parsed !== $a[1])) {
            return null;
        }
        $port = $a[2] ?? '';

        return ['url' => substr($url, 0, $start) . (str_contains($host, ':') ? '[' . $host . ']' : $host) . $port . substr($url, $start + strlen($authority)), 'host' => $host,
            'port' => $port !== '' ? (int) substr($port, 1) : ($scheme === 'https' ? 443 : 80), 'scheme' => $scheme];
    }

    /**
     * Every address a normalized host resolves to, IPv4 and IPv6 (an IP address is its own address); [] = it does not
     * resolve.
     *
     * @return list<string>
     */
    public static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $addresses[] = (string) ($record['ipv6'] ?? '');
        }

        return array_values(array_unique(array_filter($addresses)));
    }

    /**
     * The address to pin a normalized host to: the first one, when every address it resolves to is public
     * (ImageDownloader::isPublicIp); null = it does not resolve, or one address is internal – one is enough to refuse it.
     */
    public static function publicAddress(string $host): ?string
    {
        $addresses = self::addresses($host);
        foreach ($addresses as $ip) {
            if (!ImageDownloader::isPublicIp($ip)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * Pins a curl handle to the verified address: the CURLOPT_RESOLVE entry for the normalized host (the handle must be
     * opened with url()'s URL) and, where curl supports it, a check of the address it actually connected to before the
     * request is sent. Behind a proxy set in the environment curl connects to the proxy, so only the entry applies.
     */
    public static function pin(\CurlHandle $ch, string $host, int $port, string $ip): void
    {
        curl_setopt($ch, CURLOPT_RESOLVE, [(str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]);
        if (defined('CURLOPT_PREREQFUNCTION') && !self::viaProxy()) {
            curl_setopt($ch, CURLOPT_PREREQFUNCTION, fn (\CurlHandle $handle, string $connected): int => self::sameAddress($connected, $ip) ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT);
        }
    }

    /** Are the two the same IP address, whatever their notation (::1 and 0:0::1, an IPv4-mapped IPv6 address)? */
    public static function sameAddress(string $a, string $b): bool
    {
        $pa = @inet_pton(trim($a, '[]'));
        $pb = @inet_pton(trim($b, '[]'));
        if ($pa === false || $pb === false) {
            return false;
        }
        $mapped = str_repeat("\0", 10) . "\xff\xff";
        $pa = strlen($pa) === 16 && str_starts_with($pa, $mapped) ? substr($pa, 12) : $pa;
        $pb = strlen($pb) === 16 && str_starts_with($pb, $mapped) ? substr($pb, 12) : $pb;

        return $pa === $pb;
    }

    private static function viaProxy(): bool
    {
        foreach (['http_proxy', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
            if ((string) getenv($name) !== '') {
                return true;
            }
        }

        return false;
    }
}
