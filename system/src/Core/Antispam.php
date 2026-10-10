<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Spam protection for visitors' forms without cookies and without CAPTCHA:
 *  - a signed timestamp (a form cannot be sent sooner than a few seconds, nor after hours),
 *  - a hidden field that a human does not see and a bot fills in (honeypot),
 *  - a limit on the number of actions from one IP address.
 */
final class Antispam
{
    /** A form sent sooner is rejected (bot); image/web.js delays sending by the remainder (attribute data-wait). */
    public const int MIN_SECONDS = 4;

    /** The minimum age; the automated tests shorten it (TALEA_ANTISPAM_MIN, like the other TALEA_* test switches) so they do not wait four seconds per form. */
    public static function minSeconds(): int
    {
        $test = getenv('TALEA_ANTISPAM_MIN');

        return $test !== false && ctype_digit($test) ? (int) $test : self::MIN_SECONDS;
    }
    private const int MAX_SECONDS = 4 * 3600;

    public function __construct(private readonly Db $db, private readonly Settings $settings)
    {
    }

    /** Secret key of the installation; created on first use. */
    public function key(): string
    {
        $key = $this->settings->get('secret_key');
        if ($key === '') {
            $key = bin2hex(random_bytes(32));
            $this->settings->set('secret_key', $key);
        }

        return $key;
    }

    /** Hidden fields for a form: the signed time of issue and a bot trap. */
    public function fields(string $purpose): string
    {
        $time = (string) time();

        return '<input type="hidden" name="as_time" value="' . $time . '" data-wait="' . self::minSeconds() . '"><input type="hidden" name="as_signature" value="' . hash_hmac('sha256', $purpose . '|' . $time, $this->key()) . '">'
            . '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>' . e(t('Leave this field empty')) . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** @return string|null reason for rejection (already translated to the site language; 'robot' is a marker, not text), null = OK */
    public function verify(Request $request, string $purpose): ?string
    {
        return match ($this->reason($request, $purpose)) {
            null => null,
            'robot' => 'robot',
            'too_fast' => t('That was too fast. Please try again in a few seconds.'),
            'expired' => t('The form has expired. Reload the page and try again.'),
            default => t('The form could not be verified. Reload the page and try again.'),
        };
    }

    /** @return 'robot'|'signature'|'too_fast'|'expired'|null code of the rejection reason (builder forms choose their message by it), null = OK */
    public function reason(Request $request, string $purpose): ?string
    {
        if ($request->post('website') !== '') {
            return 'robot';
        }
        $time = $request->postInt('as_time');
        if (!hash_equals(hash_hmac('sha256', $purpose . '|' . $time, $this->key()), $request->post('as_signature'))) {
            return 'signature';
        }
        $age = time() - $time;
        if ($age < self::minSeconds()) {
            return 'too_fast';
        }

        return $age > self::MAX_SECONDS ? 'expired' : null;
    }

    /** How many times the IP address has already performed the given action in the last $minutes. */
    public function count(string $ip, string $type, int $target, int $minutes): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {ip_checks} WHERE type = ? AND target = ? AND ip = ? AND checked_at > NOW() - INTERVAL ? MINUTE',
            [$type, $target, self::hash($ip), $minutes],
        );
    }

    public function write(string $ip, string $type, int $target): void
    {
        $this->db->insert('ip_checks', ['ip' => self::hash($ip), 'type' => $type, 'target' => $target, 'checked_at' => date('Y-m-d H:i:s')]);
        if (random_int(1, 50) === 1) {
            $this->db->run("DELETE FROM {ip_checks} WHERE checked_at < NOW() - INTERVAL 40 DAY");
        }
    }

    /** Cloudflare's published address ranges (https://www.cloudflare.com/ips/): the only senders whose CF-Connecting-IP header is believed. */
    public const array CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
        '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The visitor's address, decided in one place (issue #29): REMOTE_ADDR, or behind Cloudflare (setting trusted_proxy = cloudflare)
     * the address it passes on – but only when the request really comes from a Cloudflare address, otherwise anyone could choose theirs.
     *
     * @param array<string, mixed> $server
     */
    public static function visitorIp(array $server, string $proxy): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($proxy === 'cloudflare' && isset($server['HTTP_CF_CONNECTING_IP']) && self::inList($remote, self::CLOUDFLARE)) {
            $ip = trim((string) $server['HTTP_CF_CONNECTING_IP']);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $remote;
        }

        return $remote;
    }

    /**
     * What the sign-in, reset, MCP and page-lock limits count by: the visitor's address behind the configured proxy, an IPv6 address
     * by its /64 (network()) – so visitors behind Cloudflare do not share one counter, and one IPv6 network does not get a fresh one
     * for every address it owns.
     */
    public static function visitorKey(Request $request, Settings $settings): string
    {
        return self::network(self::visitorIp($request->serverValues(), $settings->get('trusted_proxy')));
    }

    /** @param list<string> $entries addresses and networks (CIDR) */
    public static function inList(string $ip, array $entries): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($entries as $entry) {
            [$address, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
            $network = @inet_pton($address);
            if ($network === false || strlen($network) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts a request of a key in the current window and returns the count: one small file per key and window, a byte appended per
     * request (no database write). $add false only reads the count. Used by the page lock and the firewall add-on.
     */
    public static function tally(string $key, string $kind, int $window, bool $add = true): int
    {
        $folder = TALEA_ROOT . '/storage/cache/limits';
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            return 0;
        }
        $file = $folder . '/' . $kind . '-' . intdiv(time(), $window) . '-' . substr(hash('sha256', $key), 0, 24);
        if ($add) {
            @file_put_contents($file, '.', FILE_APPEND | LOCK_EX);
        }
        clearstatcache(true, $file);

        return (int) @filesize($file);
    }

    /** The clean-up job: counter files older than two hours. */
    public static function cleanUpCounters(): void
    {
        foreach (glob(TALEA_ROOT . '/storage/cache/limits/*') ?: [] as $file) {
            if (filemtime($file) < time() - 7200) {
                @unlink($file);
            }
        }
    }

    /**
     * The network an address stands for when counting tries (3.3.2): an IPv4 address as it is, an IPv6 address by its
     * /64 prefix – one connection usually gets a whole /64, so counting single IPv6 addresses would count nothing.
     */
    public static function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12)); // an IPv4 address written as IPv6 (::ffff:1.2.3.4)
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** The table does not store the IP address, only its hash. */
    public static function hash(string $ip): string
    {
        return substr(hash('sha256', 'talea|' . $ip), 0, 40);
    }
}
