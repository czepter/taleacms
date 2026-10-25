<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Downloading images from the old site during an import from WordPress.
 *
 * Image URLs come from the imported file, that is from untrusted input. If the server downloaded anything the file says,
 * it could be abused to probe the hosting's internal network (an SSRF attack). Therefore, without exception:
 *  1. only http and https, only the old site's domain (or its variant with „www.“), only the default port, no user name and password in the URL;
 *  2. the domain is resolved to IP addresses and ALL of them must be public (not 10.x, 192.168.x, 127.x, 169.254.x, 100.64.x, ::1, fc00::/7…);
 *     the connection is then pinned to the verified address, so that it cannot be swapped between the check and the download (DNS rebinding);
 *  3. at most 3 redirects, never automatic – every step goes through points 1 and 2 again;
 *  4. connection within 5 s, the whole download within 20 s, at most 15 MB (checked already while reading);
 *  5. only JPEG, PNG, GIF and WebP are accepted – by the response header AND by the actual content. Never SVG;
 *  6. no cookies, credentials or headers from the import are sent; the client identifies itself as „Talea-import“.
 * downloadMany() fetches several at once (curl_multi) under the same rules: each request and each redirect step is checked and pinned on its own.
 * The downloaded data goes on only through Core\Images, which re-encodes the image.
 */
final class ImageDownloader
{
    public const int MAX_BYTES = 15 * 1024 * 1024;
    public const int MAX_REDIRECTS = 3;
    public const int CONNECT_TIMEOUT = 5;
    public const int TOTAL_TIMEOUT = 20;
    /** downloadMany(): at most this many connections to one host at a time. */
    public const int PARALLEL = 6;
    private const string USER_AGENT = 'Talea-import';
    private const array TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Ranges the server never connects to: internal networks, loopback, link-local, CGNAT, multicast, reserved and documentation addresses
     * and also IPv6 transition ranges that can wrap an internal IPv4 address (::a.b.c.d, SIIT ::ffff:0:a.b.c.d, NAT64 with
     * the well-known and the local-use prefix, Teredo, 6to4) – 3.3.3, N22.
     */
    private const array BLOCKED_NETWORKS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '::ffff:0:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** Domain of the old site in lowercase and without „www.“. */
    private readonly string $domain;

    /**
     * @param string $siteUrl URL of the old site from the imported file (<channel><link>)
     * @param bool $anyDomain images may come from any public host (2.6, import from a website: Wix, Squarespace and others
     *        keep images on their CDN); everything else in the rules above still applies
     */
    public function __construct(string $siteUrl, private readonly bool $anyDomain = false)
    {
        $this->domain = self::domainFromUrl($siteUrl);
    }

    /** The automated tests download from their own server on 127.0.0.1 (TALEA_IMPORT_LOCAL=1); never set on a real site. */
    private static function localTests(): bool
    {
        return getenv('TALEA_IMPORT_LOCAL') === '1';
    }

    /** Can the server download at all? Without both curl and allow_url_fopen the images have to be moved manually. */
    public static function isAvailable(): bool
    {
        return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Domain from a URL: normalized like every outbound host (Outbound::host – lowercase, an internationalized name in
     * punycode), without „www.“ and without a trailing dot; empty string = the URL is not http(s) or its host is refused.
     */
    public static function domainFromUrl(string $url): string
    {
        $host = rtrim((string) (Outbound::url(trim($url))['host'] ?? ''), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /** Point 1: can this URL be tried at all? A pure function – it neither resolves nor downloads anything. */
    public function isAllowedUrl(string $url): bool
    {
        if ($this->domain === '' || preg_match('/[\x00-\x20\\\\]/', $url) || Outbound::url($url) === null) {
            return false; // Outbound::url: a host with a percent sign or other characters curl would read on its own (3.3.3, N52)
        }
        $c = parse_url($url);
        if (!is_array($c) || isset($c['username']) || isset($c['pass'])) {
            return false;
        }
        $schema = strtolower($c['scheme'] ?? '');
        if (!in_array($schema, ['http', 'https'], true) || (isset($c['port']) && $c['port'] !== ($schema === 'https' ? 443 : 80) && !self::localTests())) {
            return false;
        }

        return self::domainFromUrl($url) === $this->domain || ($this->anyDomain && self::domainFromUrl($url) !== '');
    }

    /** Point 2: is the IP address public? IPv4 wrapped in IPv6 (::ffff:10.0.0.1) is judged as IPv4. */
    public static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton(trim($ip, '[]'));
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $binary = substr($binary, 12); // ::ffff:a.b.c.d
        }
        foreach (self::BLOCKED_NETWORKS as $network) {
            [$url, $mask] = explode('/', $network);
            $networkBinary = (string) inet_pton($url);
            if (strlen($networkBinary) === strlen($binary) && self::isInNetwork($binary, $networkBinary, (int) $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Point 5: image type by the Content-Type header AND AT THE SAME TIME by the actual bytes; null = rejected.
     * Both must be on the allowed list (neither SVG, HTML nor anything else gets through, whatever it pretends to be).
     */
    public static function imageType(string $contentTypeHeader, string $data): ?string
    {
        $fromHeader = strtolower(trim(explode(';', $contentTypeHeader)[0]));
        $info = $data === '' ? false : @getimagesizefromstring($data);
        $fromContent = $info === false ? '' : (string) $info['mime'];

        return in_array($fromHeader, self::TYPES, true) && in_array($fromContent, self::TYPES, true) ? $fromContent : null;
    }

    /** Redirect target: the Location header can also be relative (/jinam/foto.jpg). */
    public static function redirectTarget(string $fromUrl, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $c = parse_url($fromUrl);
        $root = ($c['scheme'] ?? 'http') . '://' . ($c['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($c['scheme'] ?? 'http') . ':' . $location;
        }

        return str_starts_with($location, '/') ? $root . $location : $root . rtrim(dirname(($c['path'] ?? '/') . 'x'), '/') . '/' . $location;
    }

    /**
     * Resolves the domain and returns the IP address that may be connected to; null = the domain does not exist or one of the addresses is not public.
     */
    public function verifiedIp(string $host): ?string
    {
        $host = Outbound::host($host); // the same host curl is given (3.3.3, N52)
        if ($host === null) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) || ($host === '127.0.0.1' && self::localTests()) ? $host : null;
        }

        return Outbound::publicAddress($host); // a single internal address is enough for the whole domain to be suspicious
    }

    /**
     * Downloads an image and returns its content. With $imagesOnly = false also another file (a font, a PDF for MCP) – its type
     * is then verified only on saving (Core\Files: allowed extensions and actual content, SVG is sanitized by Core\Svg); the other rules apply the same.
     *
     * @throws \RuntimeException with the reason why the image cannot be downloaded
     */
    public function download(string $url, bool $imagesOnly = true): string
    {
        $result = $this->downloadMany([$url], $imagesOnly)[$url];
        if ($result instanceof \RuntimeException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Downloads several files at once (curl_multi, at most PARALLEL connections): every address and every redirect step goes
     * through points 1 to 6 like a single download – each request is checked, resolved and pinned on its own. Without curl
     * one after another.
     *
     * @param list<string> $urls
     * @return array<string, string|\RuntimeException> address => content, or why it could not be downloaded
     */
    public function downloadMany(array $urls, bool $imagesOnly = true): array
    {
        if (Demo::active()) {
            throw new \RuntimeException('Downloading from other sites is switched off in the public demo.');
        }
        $results = [];
        $pending = [];
        foreach ($urls as $url) {
            $pending[$url] = $url; // the address asked for => the address of the current redirect step
        }
        for ($step = 0; $pending !== []; $step++) {
            $requests = [];
            foreach ($pending as $url => $current) {
                $target = Outbound::url($current);
                $ip = $target !== null && $this->isAllowedUrl($current) ? $this->verifiedIp($target['host']) : null;
                $results[$url] = match (true) {
                    $step > self::MAX_REDIRECTS => new \RuntimeException('Too many redirects.'),
                    $target === null || !$this->isAllowedUrl($current) => new \RuntimeException('The address does not belong to the old site.'),
                    $ip === null => new \RuntimeException('The domain of the old site does not exist or points to an internal network.'),
                    default => '',
                };
                if ($results[$url] === '') {
                    $requests[$url] = [$target, $ip]; // the request goes to the URL with the normalized host – the one that was resolved and is pinned (3.3.3, N52)
                }
            }
            $next = [];
            foreach ($this->requests($requests) as $url => $response) {
                if (!$response instanceof \RuntimeException && in_array($response['code'], [301, 302, 303, 307, 308], true) && $response['location'] !== '') {
                    $next[$url] = self::redirectTarget($pending[$url], $response['location']);
                    continue;
                }
                $results[$url] = match (true) {
                    $response instanceof \RuntimeException => $response,
                    $response['code'] !== 200 => new \RuntimeException('The old site did not return the image, it responded with error', $response['code']), // getCode() carries the response code
                    $imagesOnly && self::imageType($response['type'], $response['data']) === null => new \RuntimeException('The file is not a JPG, PNG, GIF or WebP image.'),
                    default => $response['data'],
                };
            }
            $pending = $next;
        }

        return $results;
    }

    /**
     * One round of requests to verified addresses: in parallel through curl (Outbound::pin), else one after another.
     *
     * @param array<string, array{0: array{url: string, host: string, port: int, scheme: string}, 1: string}> $requests address => [Outbound::url(), verified IP]
     * @return array<string, array{code:int, type:string, location:string, data:string}|\RuntimeException>
     */
    private function requests(array $requests): array
    {
        $out = [];
        if (!function_exists('curl_multi_init')) {
            foreach ($requests as $url => [$target, $ip]) {
                try {
                    $out[$url] = $this->streamRequest($target['url'], $ip);
                } catch (\RuntimeException $e) {
                    $out[$url] = $e;
                }
            }

            return $out;
        }
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, self::PARALLEL);
        $handles = [];
        $responses = [];
        foreach ($requests as $url => [$target, $ip]) {
            $responses[$url] = new \stdClass();
            $handles[$url] = $this->curlHandle($target, $ip, $responses[$url]);
            curl_multi_add_handle($multi, $handles[$url]);
        }
        $errors = [];
        do {
            $status = curl_multi_exec($multi, $running);
            while (($info = curl_multi_info_read($multi)) !== false) {
                $errors[spl_object_id($info['handle'])] = (int) $info['result']; // curl_errno() says nothing about a handle in curl_multi
            }
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        foreach ($handles as $url => $ch) {
            $response = $responses[$url];
            $error = $errors[spl_object_id($ch)] ?? CURLE_RECV_ERROR;
            $out[$url] = match (true) {
                strlen($response->data) > self::MAX_BYTES || $error === CURLE_FILESIZE_EXCEEDED => new \RuntimeException('The image is larger than 15 MB.'),
                $error !== CURLE_OK => new \RuntimeException('The old site is not responding.'),
                default => ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'type' => $response->type, 'location' => $response->location, 'data' => $response->data],
            };
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return $out;
    }

    /**
     * A curl handle for one request; the connection is pinned to the verified IP address (Outbound::pin), the answer is
     * collected into $response (data, type, location) and cut off above MAX_BYTES.
     *
     * @param array{url: string, host: string, port: int, scheme: string} $target Outbound::url()
     */
    private function curlHandle(array $target, string $ip, \stdClass $response): \CurlHandle
    {
        $response->data = '';
        $response->type = '';
        $response->location = '';
        $ch = curl_init($target['url']);
        Outbound::pin($ch, $target['host'], $target['port'], $ip); // another port only in the tests
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, // we handle redirects ourselves, step by step
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HEADERFUNCTION => function ($ch, string $row) use ($response): int {
                $parts = explode(':', $row, 2);
                $name = strtolower(trim($parts[0]));
                if (count($parts) === 2 && ($name === 'content-type' || $name === 'location')) {
                    $response->{$name === 'location' ? 'location' : 'type'} = trim($parts[1]);
                }

                return strlen($row);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($response): int {
                $response->data .= $chunk;

                return strlen($response->data) > self::MAX_BYTES ? -1 : strlen($chunk); // a value other than the length = curl stops the download
            },
        ]);

        return $ch;
    }

    /**
     * The same without curl (allow_url_fopen). Connects directly to the verified IP address; the domain goes in the Host header and into certificate verification.
     *
     * @return array{code:int, type:string, location:string, data:string}
     */
    private function streamRequest(string $url, string $ip): array
    {
        $c = parse_url($url);
        $host = (string) $c['host'];
        $target = $c['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ($c['path'] ?? '/') . (isset($c['query']) ? '?' . $c['query'] : '');
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => self::CONNECT_TIMEOUT, 'ignore_errors' => true,
                'user_agent' => self::USER_AGENT, 'header' => 'Host: ' . $host . "\r\nConnection: close\r\n"],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($target, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('The old site is not responding.');
        }
        $end = microtime(true) + self::TOTAL_TIMEOUT;
        $data = '';
        while (!feof($stream)) {
            $data .= (string) fread($stream, 65536);
            if (strlen($data) > self::MAX_BYTES) {
                fclose($stream);
                throw new \RuntimeException('The image is larger than 15 MB.');
            }
            if (microtime(true) > $end) {
                fclose($stream);
                throw new \RuntimeException('The old site responds too slowly.');
            }
        }
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);
        $response = ['code' => 0, 'type' => '', 'location' => '', 'data' => $data];
        foreach ($headers as $row) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $row, $m)) {
                $response['code'] = (int) $m[1];
            } elseif (preg_match('#^(content-type|location):\s*(.*)$#i', (string) $row, $m)) {
                $response[strtolower($m[1]) === 'location' ? 'location' : 'type'] = trim($m[2]);
            }
        }

        return $response;
    }

    /** Does the address lie in the network? The first $mask bits are compared. */
    private static function isInNetwork(string $ip, string $network, int $mask): bool
    {
        $byteCount = intdiv($mask, 8);
        $bitCount = $mask % 8;
        if (substr($ip, 0, $byteCount) !== substr($network, 0, $byteCount)) {
            return false;
        }

        return $bitCount === 0 || ((ord($ip[$byteCount]) ^ ord($network[$byteCount])) & (0xFF << (8 - $bitCount) & 0xFF)) === 0;
    }
}
