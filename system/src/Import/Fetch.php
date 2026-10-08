<?php

declare(strict_types=1);

namespace Kaleta\Import;

use Kaleta\Core\Demo;
use Kaleta\Core\ImageDownloader;

/**
 * The fetch step of the importers that have no export file (Import\Remote: Joomla, Drupal): the old site's API is read
 * page by page and written into a local JSON file in storage/import/sources/<key>-<domain>.json, which the Source then
 * reads like an uploaded export. The addresses come from the Remote class and from the "next page" links the API
 * answers, so neither the administrator nor the API is trusted:
 *  - HTTP GET only, http(s), the old site's domain only, standard ports, no user name in the address, and the host must
 *    resolve to public addresses – the same rules as Core\ImageDownloader (whose checks are reused), the connection is
 *    pinned to the verified address, redirects are followed by hand (at most MAX_REDIRECTS, each checked again);
 *  - JSON only (the answer must decode to an object), at most PAGE_BYTES per answer and TOTAL_BYTES per fetch, a timeout
 *    per request, at most PAGES pages per browser request – the progress page submits itself and the fetch continues
 *    where it stopped, like Import\Batch; a "next page" link that leaves the old site is not followed;
 *  - the API token the administrator typed is only sent as a header of these requests. The caller keeps it in the PHP
 *    session under sessionKey() while the fetch runs and removes it when the fetch ends (done or failed); it is never put
 *    into the state file, the fetched file, the database, a log or an error message, and never shown back.
 */
final class Fetch
{
    public const int PAGE_BYTES = 8 * 1024 * 1024;
    public const int TOTAL_BYTES = 48 * 1024 * 1024;
    public const int PAGES = 5;
    public const int MAX_PAGES = 5000;
    public const int MAX_REDIRECTS = 3;
    public const int CONNECT_TIMEOUT = 5;
    public const int TIMEOUT = 25;
    private const string USER_AGENT = 'Kaleta-import';

    /** The session key of the token for a fetched file (one fetch per file at a time). */
    public static function sessionKey(string $file): string
    {
        return 'import_fetch_' . substr(sha1($file), 0, 16);
    }

    /** The file a fetch from this site is written to: <key>-<domain>.json. */
    public static function fileName(string $key, string $siteUrl): string
    {
        return $key . '-' . slugify(ImageDownloader::domainFromUrl($siteUrl), 70) . '.json';
    }

    /**
     * A fresh fetch state for the import state's 'stahovani' key.
     *
     * @param class-string<Remote> $class
     * @param list<string> $steps the steps the administrator ticked; unknown ones are dropped, the first step is always in
     * @return array<string, mixed>
     */
    public static function state(string $class, string $siteUrl, array $steps): array
    {
        $known = array_keys($class::steps());
        $steps = array_values(array_unique([$known[0], ...array_intersect($known, $steps)]));

        return ['web' => rtrim($siteUrl, '/'), 'kroky' => $steps, 'krok' => 0, 'adresa' => '', 'strana' => 0, 'polozek' => 0, 'bajtu' => 0, 'vynechano' => []];
    }

    /**
     * The skeleton of the fetched file; the steps fill in as pages arrive, 'done' says the fetch finished (a Source refuses
     * a half-fetched file).
     *
     * @return array<string, mixed>
     */
    public static function skeleton(string $key, string $siteUrl): array
    {
        return ['kaleta_fetch' => ['system' => $key, 'site' => rtrim($siteUrl, '/'), 'fetched' => date('c'), 'done' => false, 'skipped' => []], 'steps' => []];
    }

    /**
     * Fetches the next pages (at most $pages) into the file; when the last step is finished, the import state moves on to
     * the analysis ('analyza') and the file is marked done. An optional step (any but the first) whose endpoint answers
     * 403, 404 or 405 is skipped and noted – a Joomla without the tags component, a Drupal without that vocabulary.
     *
     * @param array<string, mixed> $state the import state (Import\Batch::newState with 'stahovani' from state())
     * @param string $token the API token from the session ('' = none)
     * @throws \RuntimeException with an English message for the user; the caller shows it and forgets the token
     */
    public static function step(array &$state, string $token, int $pages = self::PAGES): void
    {
        $class = Sources::byKey((string) $state['zdroj']);
        if ($class === null || !is_subclass_of($class, Remote::class)) {
            throw new \RuntimeException('This system has no fetch step.');
        }
        $path = Batch::path((string) $state['soubor']) ?? throw new \RuntimeException('The file does not exist.');
        $f = &$state['stahovani'];
        $site = (string) $f['web'];
        $document = json_decode((string) file_get_contents($path), true);
        $document = is_array($document) && isset($document['kaleta_fetch']) ? $document : self::skeleton($class::key(), $site);
        try {
            for ($n = 0; $n < $pages; $n++) {
                if ($f['krok'] >= count($f['kroky'])) {
                    $document['kaleta_fetch']['done'] = true;
                    $document['kaleta_fetch']['skipped'] = $f['vynechano'];
                    $state['faze'] = 'analyza';
                    $state['pozice'] = 0;

                    return;
                }
                $step = (string) $f['kroky'][$f['krok']];
                $url = $f['adresa'] !== '' ? (string) $f['adresa'] : $class::firstPage($site, $step);
                try {
                    [$json, $bytes] = self::get($url, $class::headers($token), $site, self::TOTAL_BYTES - (int) $f['bajtu']);
                } catch (\RuntimeException $e) {
                    if ($f['krok'] > 0 && $f['adresa'] === '' && in_array($e->getCode(), [403, 404, 405], true)) {
                        $f['vynechano'][] = $step; // an optional step the site does not offer
                        $f['krok']++;
                        continue;
                    }
                    throw $e;
                }
                $f['bajtu'] += $bytes;
                [$items, $included, $next] = $class::page($json, $step);
                $document['steps'][$step]['data'] = [...($document['steps'][$step]['data'] ?? []), ...$items];
                if ($included !== []) {
                    $document['steps'][$step]['included'] = [...($document['steps'][$step]['included'] ?? []), ...$included];
                }
                $f['polozek'] += count($items);
                if (++$f['strana'] > self::MAX_PAGES) {
                    throw new \RuntimeException('The site answers more than 5000 pages – the fetch was stopped.');
                }
                if ($next === '' || $items === [] || $next === $url) {
                    $f['krok']++;
                    $f['adresa'] = '';
                } elseif (!self::allowedUrl($next, $site)) {
                    throw new \RuntimeException('The site sent a "next page" link that leads outside the site – the fetch was stopped.');
                } else {
                    $f['adresa'] = $next;
                }
            }
        } finally {
            // whatever happened, the pages fetched so far are on disk; an interrupted write must not leave a half-written file
            file_put_contents($path . '.tmp', (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            rename($path . '.tmp', $path);
        }
    }

    /** Whether the address may be requested at all: http(s), the old site's domain, standard port, no user name – a pure check. */
    public static function allowedUrl(string $url, string $siteUrl): bool
    {
        return (new ImageDownloader($siteUrl))->isAllowedUrl($url);
    }

    /**
     * Whether the site address the administrator typed can be fetched from: allowedUrl() and the host resolves to public
     * addresses only (or does not resolve at all – then the first request fails with a clear message).
     */
    public static function allowedSite(string $siteUrl): bool
    {
        $target = \Kaleta\Core\Outbound::url($siteUrl);

        return $target !== null && self::allowedUrl($siteUrl, $siteUrl) && (new ImageDownloader($siteUrl))->verifiedIp($target['host']) !== null;
    }

    /**
     * One GET of a JSON document, redirects by hand, every hop under the same rules.
     *
     * @param list<string> $headers
     * @param int $budget how many bytes this fetch may still receive in total
     * @return array{0: array<string, mixed>, 1: int} the decoded document and the size of the answer
     * @throws \RuntimeException the message for the user, the HTTP status as the code when the site answered one
     */
    public static function get(string $url, array $headers, string $siteUrl, int $budget = self::TOTAL_BYTES): array
    {
        if (Demo::active()) {
            throw new \RuntimeException('Fetching from other sites is switched off in the public demo.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP extension curl is missing on the server – the site’s API cannot be read without it.');
        }
        $downloader = new ImageDownloader($siteUrl);
        $limit = min(self::PAGE_BYTES, max(0, $budget));
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = \Kaleta\Core\Outbound::url($url);
            if ($target === null || !$downloader->isAllowedUrl($url)) {
                throw new \RuntimeException('The address does not belong to the old site, or it is not a public http(s) address on the standard port.');
            }
            $ip = $downloader->verifiedIp($target['host']);
            if ($ip === null) {
                throw new \RuntimeException('The domain of the old site does not exist or points to an internal network.');
            }
            $response = self::request($target, $ip, $headers, $limit); // the URL with the host that was resolved and is pinned (3.3.3, N52)
            if (in_array($response['kod'], [301, 302, 303, 307, 308], true) && $response['location'] !== '') {
                $next = ImageDownloader::redirectTarget($url, $response['location']);
                if (self::downgradesCredentials($url, $next, $headers)) {
                    throw new \RuntimeException('The site redirected the API request from https to plain http – the token would travel unencrypted, so the fetch was stopped. Use the https address of the site.');
                }
                $url = $next;
                continue;
            }
            if ($response['kod'] === 401 || $response['kod'] === 403) {
                throw new \RuntimeException('The site refused the request – the API token is wrong or missing, or it has no permission. Response:', $response['kod']);
            }
            if ($response['kod'] === 404) {
                throw new \RuntimeException('The API is not available at this address (the site answered 404). Check the address and that the API is switched on.', 404);
            }
            if ($response['kod'] !== 200) {
                throw new \RuntimeException('The site did not answer the API request, it responded with error', $response['kod']);
            }
            $json = json_decode($response['data'], true, 64);
            if (!is_array($json) || !str_contains(strtolower($response['typ']), 'json')) {
                throw new \RuntimeException('The site did not answer with JSON – this is not the address of the API.');
            }

            return [$json, strlen($response['data'])];
        }
        throw new \RuntimeException('Too many redirects.');
    }

    /**
     * A redirect from https to plain http on a request that carries credentials (3.3.2, N43): an Authorization-like header
     * (anything but Accept) or a key or token in the address. The token would go over the network unencrypted.
     *
     * @param list<string> $headers
     */
    public static function downgradesCredentials(string $from, string $to, array $headers): bool
    {
        if (strtolower((string) parse_url($from, PHP_URL_SCHEME)) !== 'https' || strtolower((string) parse_url($to, PHP_URL_SCHEME)) === 'https') {
            return false;
        }
        foreach ($headers as $header) {
            if (!preg_match('/^\s*accept\s*:/i', $header)) {
                return true;
            }
        }

        return preg_match('/(^|&)[^=&]*(key|token|secret|auth|password)[^=&]*=/i', (string) parse_url($to, PHP_URL_QUERY)) === 1;
    }

    /**
     * A single request via curl pinned to the verified IP address (Outbound::pin), the body cut at $limit bytes.
     *
     * @param array{url: string, host: string, port: int, scheme: string} $target Outbound::url()
     * @param list<string> $headers
     * @return array{kod: int, typ: string, location: string, data: string}
     */
    private static function request(array $target, string $ip, array $headers, int $limit): array
    {
        $data = '';
        $found = ['content-type' => '', 'location' => ''];
        $ch = curl_init($target['url']);
        \Kaleta\Core\Outbound::pin($ch, $target['host'], $target['port'], $ip); // another port only in the tests
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_MAXFILESIZE => $limit,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => array_values(array_filter($headers, fn (string $h): bool => !preg_match('/[\r\n]/', $h))),
            CURLOPT_HEADERFUNCTION => function ($ch, string $row) use (&$found): int {
                $parts = explode(':', $row, 2);
                if (count($parts) === 2 && isset($found[strtolower(trim($parts[0]))])) {
                    $found[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($row);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$data, $limit): int {
                $data .= $chunk;

                return strlen($data) > $limit ? -1 : strlen($chunk); // a value other than the length = curl stops the download
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        if (strlen($data) > $limit || $error === CURLE_FILESIZE_EXCEEDED) {
            throw new \RuntimeException('The answer is larger than the fetch allows (8 MB per page, 48 MB in total).');
        }
        if ($error !== 0) {
            throw new \RuntimeException('The old site is not responding.');
        }

        return ['kod' => $code, 'typ' => $found['content-type'], 'location' => $found['location'], 'data' => $data];
    }
}
