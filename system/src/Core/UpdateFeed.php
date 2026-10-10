<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The "new version available" notice (HF-13). A site asks the release feed for the newest version at most once a day – no
 * identifier, no query string – and only shows a notice; it never installs anything (an update is a new image, see
 * docs/DEPLOYMENT.md). The feed is signed with the publisher's Ed25519 key (system/update.pub); an unsigned or badly signed
 * feed is ignored. Without a feed address (KALETA_UPDATE_FEED) or with KALETA_UPDATE_CHECK=0 or the setting update_check off
 * nothing is requested at all.
 *
 * Feed (update.json): {version, released, security, changes[], image, digest, signature}; the signature covers
 * Signature::packageMessage(version, digest without "sha256:", security).
 */
final class UpdateFeed
{
    public const int TTL = 86400;
    public const int ERROR_TTL = 3600;

    /** @param ?\Closure(string): string $fetch tests replace the HTTP request: gets the address, returns the body */
    public function __construct(
        private readonly Settings $settings,
        private readonly string $keyFile = KALETA_SYSTEM . '/update.pub',
        private readonly ?\Closure $fetch = null,
    ) {
    }

    /** The feed address, or '' when the check is off. */
    public function url(): string
    {
        if (Config::env('UPDATE_CHECK') === '0' || !$this->settings->bool('update_check') || Demo::active()) {
            return '';
        }

        return Config::env('UPDATE_FEED');
    }

    /**
     * The newest version when it is newer than the running one and the feed is valid; null otherwise (also when the check is off).
     *
     * @return array{version: string, released: string, security: bool, changes: list<string>, image: string, digest: string}|null
     */
    public function available(bool $force = false): ?array
    {
        $url = $this->url();
        if ($url === '') {
            return null;
        }
        $cache = json_decode($this->settings->get('update_feed_cache'), true);
        $ttl = is_array($cache) && ($cache['error'] ?? null) !== null ? self::ERROR_TTL : self::TTL;
        if (!$force && is_array($cache) && ($cache['url'] ?? '') === $url && time() - (int) ($cache['checked'] ?? 0) < $ttl) {
            $manifest = $cache['manifest'] ?? null;
        } else {
            $error = null;
            try {
                $manifest = $this->read($url);
            } catch (\RuntimeException $e) {
                $manifest = null;
                $error = $e->getMessage();
            }
            $this->settings->set('update_feed_cache', (string) json_encode(['url' => $url, 'checked' => time(), 'manifest' => $manifest, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return is_array($manifest) && version_compare((string) $manifest['version'], KALETA_VERSION, '>') ? $manifest : null;
    }

    /** The error of the last check ('' when it worked or nothing was checked). */
    public function error(): string
    {
        $cache = json_decode($this->settings->get('update_feed_cache'), true);

        return is_array($cache) ? (string) ($cache['error'] ?? '') : '';
    }

    /** @return array<string, mixed> a verified feed */
    private function read(string $url): array
    {
        $body = $this->fetch !== null ? ($this->fetch)($url) : $this->http($url);
        $m = json_decode($body, true);
        $digest = is_array($m) ? strtolower((string) preg_replace('/^sha256:/', '', (string) ($m['digest'] ?? ''))) : '';
        if (!is_array($m) || !isset($m['version'], $m['signature']) || preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) $m['version']) !== 1 || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new \RuntimeException('The release feed is not in a valid format.');
        }
        $security = !empty($m['security']);
        if (!Signature::isValid(Signature::packageMessage((string) $m['version'], $digest, $security), (string) $m['signature'], $this->keyFile)) {
            throw new \RuntimeException('The release feed is not signed by the publisher.');
        }

        return [
            'version' => (string) $m['version'],
            'released' => mb_substr((string) ($m['released'] ?? ''), 0, 40),
            'security' => $security,
            'changes' => array_values(array_filter(array_map(fn ($c): string => mb_substr(strip_tags((string) $c), 0, 300), array_slice((array) ($m['changes'] ?? []), 0, 20)))),
            'image' => mb_substr(preg_replace('/[^A-Za-z0-9._:\/@-]/', '', (string) ($m['image'] ?? '')) ?? '', 0, 200),
            'digest' => 'sha256:' . $digest,
        ];
    }

    private function http(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if (preg_match('#^https://#i', $url) !== 1 && !($local && preg_match('#^http://#i', $url) === 1)) {
            throw new \RuntimeException('The release feed must use an https:// address.');
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta-update-check\r\nAccept: application/json\r\n"]]));
        if ($data === false || $data === '' || strlen($data) > 100 * 1024) {
            throw new \RuntimeException('The release feed is not reachable.');
        }

        return $data;
    }
}
