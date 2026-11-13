<?php

declare(strict_types=1);

namespace Talea\Fleet;

/**
 * Signed JSON between a site and its console (2.9). The body is signed as it is sent (header X-Talea-Signature), so the
 * other side checks exactly the bytes it received.
 *
 * Since 3.3.2 (N37) every request of the fleet – pairing, heartbeats, the kit and the console's uptime checks – goes only
 * to a public address: the host is resolved once, every address it resolves to must be public (Core\ImageDownloader's
 * rules), and the connection is pinned to it; redirects are never followed and an answer is cut at MAX_BYTES. The signed
 * requests use https only; plain http and local addresses are allowed just in the automated tests (TALEA_FLEET_LOCAL=1).
 */
final class Http
{
    public const string HEADER = 'X-Talea-Signature';

    /** The largest answer read from the other side. */
    public const int MAX_BYTES = 2_000_000;

    /** For tests: replaces the network – gets the URL, the body and the headers, returns [status, body, signature]. */
    public static ?\Closure $transport = null;

    /** The automated tests run a console and its sites on 127.0.0.1 over plain http; never set on a real site. */
    private static function localTests(): bool
    {
        return getenv('TALEA_FLEET_LOCAL') === '1';
    }

    /** Only https (plain http too when $plainHttp, for the uptime check of a site), no user name; tests also local http. */
    public static function allowedUrl(string $url, bool $plainHttp = false): bool
    {
        // Outbound::url: http(s), no user name, and a host curl reads exactly as it is checked – no percent sign (3.3.3, N52)
        $target = \Talea\Core\Outbound::url($url);
        if ($target === null || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }

        return $target['scheme'] === 'https' || $plainHttp || self::localTests();
    }

    /**
     * The address to connect to for a URL: [host, port, ip, url] with the normalized host (Outbound::host), a public IP of
     * it and the URL rewritten with that host – the one to request (3.3.3, N52); or null (every address the host resolves
     * to must be public – one internal address is enough to refuse it).
     *
     * @return array{0: string, 1: int, 2: string, 3: string}|null
     */
    public static function pin(string $url): ?array
    {
        $target = \Talea\Core\Outbound::url($url);
        if ($target === null) {
            return null;
        }
        [$host, $port] = [$target['host'], $target['port']];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return \Talea\Core\ImageDownloader::isPublicIp($host) || self::localTests() ? [$host, $port, $host, $target['url']] : null;
        }
        $addresses = \Talea\Core\Outbound::addresses($host);
        foreach ($addresses as $ip) {
            if (!\Talea\Core\ImageDownloader::isPublicIp($ip) && !self::localTests()) {
                return null;
            }
        }

        return isset($addresses[0]) ? [$host, $port, $addresses[0], $target['url']] : null;
    }

    /**
     * POSTs the payload as JSON, signed by $sign (null = unsigned). Returns the status (0 = no answer), the body, the decoded
     * JSON and the signature header of the answer.
     *
     * @param array<string, mixed> $payload
     * @param (\Closure(string): string)|null $sign
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: ?array<string, mixed>, signature: string, error: string}
     */
    public static function post(string $url, array $payload, ?\Closure $sign, int $timeout = 15, array $headers = []): array
    {
        if (\Talea\Core\Demo::active()) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => \Talea\Core\Demo::refusal()]; // 3.3.2 (N24): no requests from the public demo
        }
        if (!self::allowedUrl($url)) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'Only https addresses are allowed.'];
        }
        $pin = self::$transport === null ? self::pin($url) : ['', 0, '', $url];
        if ($pin === null) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'The address does not lead to a public server.'];
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Talea/' . TALEA_VERSION] + $headers;
        if ($sign !== null) {
            $headers[self::HEADER] = $sign($body);
        }
        [$status, $answer, $signature, $error] = self::$transport !== null ? [...(self::$transport)($url, $body, $headers), ''] : self::send($url, $pin, $body, $headers, $timeout);
        $json = json_decode((string) $answer, true);

        return ['status' => (int) $status, 'body' => (string) $answer, 'json' => is_array($json) ? $json : null, 'signature' => (string) $signature, 'error' => (string) $error];
    }

    /**
     * One POST pinned to the verified address, no redirects, the answer cut at MAX_BYTES.
     *
     * @param array{0: string, 1: int, 2: string, 3: string} $pin
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    private static function send(string $url, array $pin, string $body, array $headers, int $timeout): array
    {
        [$host, $port, $ip, $url] = $pin; // the URL with the normalized host (3.3.3, N52)
        $lines = array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers);
        $signature = '';
        if (function_exists('curl_init')) {
            $answer = '';
            $ch = curl_init($url);
            \Talea\Core\Outbound::pin($ch, $host, $port, $ip);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$signature): int {
                    if (stripos($line, self::HEADER . ':') === 0) {
                        $signature = trim(substr($line, strlen(self::HEADER) + 1));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$answer): int {
                    $answer .= $chunk;

                    return strlen($answer) > self::MAX_BYTES ? -1 : strlen($chunk); // another value than the length stops curl
                }]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $ok === false ? (strlen($answer) > self::MAX_BYTES || curl_errno($ch) === CURLE_FILESIZE_EXCEEDED ? 'The answer is too large.' : curl_error($ch)) : '';

            return [$error === '' ? $status : 0, $error === '' ? $answer : '', $signature, $error];
        }
        // without curl: straight to the verified address, the host name in the Host header and in the certificate check
        $parts = parse_url($url);
        $target = $parts['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $answer = @file_get_contents($target, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0, 'header' => implode("\r\n", [...$lines, 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '')]) . "\r\n", 'content' => $body],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]), 0, self::MAX_BYTES);
        $status = 0;
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, self::HEADER . ':') === 0) {
                $signature = trim(substr($line, strlen(self::HEADER) + 1));
            }
        }

        return [$status, $answer === false ? '' : $answer, $signature, $answer === false ? 'No answer.' : ''];
    }

    /**
     * The HTTP status of a public address (0 = no answer or not allowed) and, for https, the end of its certificate – the
     * console's uptime check (Fleet\Console): pinned like post(), no redirects (any answer below 500 counts as up), at most 1 kB
     * read. The end date is information only, read from the handshake that verification already accepted: verification stays on,
     * and a certificate that fails it fails the request (no date then – the console keeps the one it knows).
     *
     * @param list<string> $urls
     * @return list<array{status: int, certificate_ends: ?int}>
     */
    public static function probe(array $urls): array
    {
        $pins = array_map(fn (string $url): ?array => self::allowedUrl($url, true) ? self::pin($url) : null, $urls);
        if (!function_exists('curl_multi_init')) {
            return array_map(function (string $url, ?array $pin): array {
                if ($pin === null) {
                    return ['status' => 0, 'certificate_ends' => null];
                }
                [$host, $port, $ip, $url] = $pin;
                $parts = parse_url($url);
                $target = $parts['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port . ($parts['path'] ?? '/');
                $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                    'header' => 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '') . "\r\nUser-Agent: Talea-console/" . TALEA_VERSION . "\r\n"],
                    'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'capture_peer_cert' => true]]);
                @file_get_contents($target, false, $context, 0, 1024);
                $status = 0;
                foreach (http_get_last_response_headers() ?? [] as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                    }
                }
                $certificate = stream_context_get_params($context)['options']['ssl']['peer_certificate'] ?? null;
                $ends = null;
                if ($certificate instanceof \OpenSSLCertificate && function_exists('openssl_x509_parse')) {
                    $ends = (int) (openssl_x509_parse($certificate)['validTo_time_t'] ?? 0) ?: null;
                }

                return ['status' => $status, 'certificate_ends' => $ends];
            }, $urls, $pins);
        }
        $multi = curl_multi_init();
        $handles = [];
        $out = array_fill(0, count($urls), ['status' => 0, 'certificate_ends' => null]);
        foreach ($urls as $i => $url) {
            if ($pins[$i] === null) {
                continue;
            }
            [$host, $port, $ip, $target] = $pins[$i];
            $ch = curl_init($target); // the URL with the normalized host (3.3.3, N52)
            \Talea\Core\Outbound::pin($ch, $host, $port, $ip);
            curl_setopt_array($ch, [CURLOPT_RANGE => '0-1023',
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'Talea-console/' . TALEA_VERSION,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_WRITEFUNCTION => fn ($ch, string $chunk): int => 0]); // the status is enough: stop at the first byte of the body
            if (str_starts_with($target, 'https://')) {
                curl_setopt($ch, CURLOPT_CERTINFO, true); // only reports the chain of the verified handshake; CURLOPT_SSL_VERIFYPEER and VERIFYHOST are never touched
            }
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        foreach ($handles as $i => $ch) {
            $chain = curl_getinfo($ch, CURLINFO_CERTINFO);
            $out[$i] = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'certificate_ends' => is_array($chain) ? self::certificateEnd($chain) : null];
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return $out;
    }

    /**
     * The end of the leaf certificate (the first of the chain curl reports) as a timestamp; null when there is none or it cannot be read. Pure.
     *
     * @param list<array<string, mixed>> $chain curl's CURLINFO_CERTINFO: per certificate "Cert" (PEM) and "Expire date"
     */
    public static function certificateEnd(array $chain): ?int
    {
        $leaf = $chain[0] ?? null;
        if (!is_array($leaf)) {
            return null;
        }
        if (is_string($leaf['Cert'] ?? null) && function_exists('openssl_x509_parse')) {
            $parsed = @openssl_x509_parse($leaf['Cert']);
            if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
                return (int) $parsed['validTo_time_t'];
            }
        }
        $time = is_string($leaf['Expire date'] ?? null) ? strtotime($leaf['Expire date']) : false; // "Jan 14 23:59:59 2027 GMT" or "2027-01-14 23:59:59 GMT"

        return $time === false ? null : $time;
    }
}
