<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Copy of the database backup off the server: FTP/FTPS (another hosting, NAS) or S3-compatible storage
 * (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2…). A backup on the same disk as the site does not protect against
 * losing the hosting. Since 1.8 the media go to the same place, incrementally (syncMedia). No libraries: FTPS and S3
 * through cURL (the FTPS certificate is verified; S3 requests carry an AWS Signature V4 signature).
 */
final class RemoteBackup
{
    /** Which media files are already copied (path => "size:mtime") and to which target. */
    public const string MEDIA_MANIFEST = Backup::FOLDER . '/media-copy.json';

    /** The longest failure text kept in a status, an event or a message on screen. */
    public const int FAILURE_LENGTH = 200;

    /** Seconds between background runs of the media copy when nothing is waiting. */
    private const int MEDIA_INTERVAL = 3600;

    /**
     * A failure of the off-site copy as one short line that is safe to show and to store: the passwords and the user name
     * of the target, credentials in an address, e-mail addresses and the install folder are gone. Pure and idempotent, so a
     * status saved earlier can be cleaned again when it is read.
     */
    public static function failureText(string $error, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if (mb_strlen($secret) < 4) {
                continue;
            }
            foreach ([$secret, rawurlencode($secret), (string) preg_replace('/[\s|]+/u', ' ', $secret)] as $form) {
                $error = str_ireplace($form, '…', $error);
            }
        }
        $error = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1', $error);
        $error = ErrorReport::scrub($error);
        $error = str_replace([TALEA_ROOT . '/', TALEA_ROOT], '', $error);
        $error = trim((string) preg_replace('/[\s|]+/u', ' ', $error));
        if (mb_strlen($error) > self::FAILURE_LENGTH) {
            $error = rtrim(mb_substr($error, 0, self::FAILURE_LENGTH - 1)) . '…';
        }

        return $error;
    }

    private static function clean(Settings $s, string $error): string
    {
        return self::failureText($error, $s->get('backup_password'), $s->get('backup_user'));
    }

    /** A stored status ("Y-m-d H:i|ok or error|…") with its failure text cleaned again: it may have been saved before the text was masked. */
    public static function cleanStatus(Settings $s, string $status): string
    {
        $parts = explode('|', $status, 3);
        if (!isset($parts[1]) || in_array($parts[1], ['', 'ok'], true)) {
            return $status;
        }
        $parts[1] = self::clean($s, $parts[1]);

        return implode('|', $parts);
    }

    /** @return string|null error text, null = uploaded (or remote backup is turned off) */
    public static function upload(Settings $s, string $path): ?string
    {
        if (!self::isOn($s)) {
            return null;
        }
        try {
            [$put, $close] = self::open($s);
            try {
                $put(basename($path), $path);
            } finally {
                $close();
            }
            $error = null;
        } catch (\Throwable $e) {
            $error = self::clean($s, $e->getMessage());
        }
        $s->set('remote_backup_status', date('Y-m-d H:i') . '|' . ($error ?? 'ok'));
        if ($error !== null) {
            // the automatic and the manual backup both end here: a failed copy is a failed backup (#336)
            Events::record($s->db(), 'backup.failed', 'error', t('The off-site copy of the backup failed: %s', $error), ['file' => basename($path), 'offsite' => true]);
        }

        return $error;
    }

    public static function isOn(Settings $s): bool
    {
        if (Demo::active()) {
            return false;
        }
        return in_array($s->get('remote_backup'), ['ftp', 's3'], true) && $s->get('backup_host') !== '';
    }

    /**
     * Incremental copy of media/ to the same target as the database backups (1.8), into its folder media/. Only new and
     * changed files go (a manifest remembers what is there); a file deleted on the site stays in the copy. It runs for
     * at most $seconds and continues next time. Status: remote_media_status = "Y-m-d H:i|ok or error|files waiting".
     *
     * @return string|null error text, null = done or nothing to do
     */
    public static function syncMedia(Settings $s, int $seconds = 20): ?string
    {
        if (!self::isOn($s) || !$s->bool('backup_media')) {
            return null;
        }
        $target = sha1($s->get('remote_backup') . '|' . $s->get('backup_host') . '|' . $s->get('backup_folder'));
        $manifest = is_file(self::MEDIA_MANIFEST) ? json_decode((string) file_get_contents(self::MEDIA_MANIFEST), true) : null;
        if (!is_array($manifest) || ($manifest['target'] ?? '') !== $target) {
            $manifest = ['target' => $target, 'files' => []]; // a new target gets everything
        }
        $pending = [];
        foreach (SiteExport::mediaFiles() as $path => $size) {
            $signature = $size . ':' . (int) @filemtime(TALEA_ROOT . '/' . $path);
            if (($manifest['files'][$path] ?? '') !== $signature) {
                $pending[$path] = $signature;
            }
        }
        $error = null;
        $done = 0;
        if ($pending !== []) {
            $start = microtime(true);
            try {
                [$put, $close] = self::open($s);
                try {
                    foreach ($pending as $path => $signature) {
                        if (microtime(true) - $start > $seconds) {
                            break;
                        }
                        $put($path, TALEA_ROOT . '/' . $path);
                        $manifest['files'][$path] = $signature;
                        $done++;
                    }
                } finally {
                    $close();
                }
            } catch (\Throwable $e) {
                $error = self::clean($s, $e->getMessage());
            }
            if (!is_dir(Backup::FOLDER)) {
                @mkdir(Backup::FOLDER, 0775, true);
            }
            file_put_contents(self::MEDIA_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $failing = !in_array(explode('|', $s->get('remote_media_status'))[1] ?? '', ['', 'ok'], true);
        $s->set('remote_media_status', date('Y-m-d H:i') . '|' . ($error ?? 'ok') . '|' . (count($pending) - $done));
        if ($error !== null && !$failing) {
            // once when the copy starts failing, not on every hourly retry
            Events::record($s->db(), 'backup.failed', 'error', t('The off-site copy of the media failed: %s', $error), ['offsite' => true, 'media' => true]);
        }

        return $error;
    }

    /** From background tasks: at most once an hour, or right away while files are still waiting. */
    public static function syncMediaInBackground(Settings $s, int $seconds): void
    {
        if (!self::isOn($s) || !$s->bool('backup_media')) {
            return;
        }
        $waiting = (int) (explode('|', $s->get('remote_media_status'))[2] ?? 0);
        if ($waiting === 0 && time() - $s->int('media_sync_check') < self::MEDIA_INTERVAL) {
            return;
        }
        $s->set('media_sync_check', (string) time());
        self::syncMedia($s, $seconds);
    }

    /**
     * A connection to the target: a function that uploads one file under a path relative to the backup folder, and one
     * that closes the connection.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void}
     */
    private static function open(Settings $s): array
    {
        return $s->get('remote_backup') === 'ftp' ? self::ftp($s) : self::s3($s);
    }

    /**
     * Explicit FTPS through cURL: unlike `ftp_ssl_connect()` (which accepts any certificate) cURL checks the server
     * certificate and its name, so a man in the middle cannot read the backup or the FTP password.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void}
     */
    private static function ftp(Settings $s): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL extension is missing on the server.');
        }
        $host = $s->get('backup_host');
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new \RuntimeException('Enter the FTP server address without ftp:// and without a path.');
        }
        $folder = trim($s->get('backup_folder'), '/');
        $put = function (string $remote, string $local) use ($s, $host, $folder): void {
            $f = fopen($local, 'rb');
            $ch = curl_init(self::ftpUrl($host, $folder, $remote));
            curl_setopt_array($ch, self::ftpOptions($s->get('backup_user'), $s->get('backup_password'), $f, (int) filesize($local)));
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            fclose($f);
            if ($ok === false) {
                throw new \RuntimeException('The file could not be uploaded to the FTP server ' . $host . ($error !== '' ? ': ' . $error : '') . '.');
            }
        };

        return [$put, fn () => null];
    }

    public static function ftpUrl(string $host, string $folder, string $remote): string
    {
        $path = implode('/', array_map(rawurlencode(...), array_filter(explode('/', trim($folder . '/' . $remote, '/')), fn (string $p): bool => $p !== '')));

        return 'ftp://' . $host . '/' . $path;
    }

    /**
     * cURL options of one FTPS upload: TLS is required (never plain FTP) and the certificate and host name are verified.
     *
     * @param resource $file
     * @return array<int, mixed>
     */
    public static function ftpOptions(string $user, string $password, $file, int $size): array
    {
        return [
            CURLOPT_USERPWD => $user . ':' . $password,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $file, CURLOPT_INFILESIZE => $size,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 300,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }

    /** @return array{0: callable(string, string): void, 1: callable(): void} */
    private static function s3(Settings $s): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL extension is missing on the server.');
        }
        $host = preg_replace('#^https?://|/.*$#', '', $s->get('backup_host')) ?? '';
        $bucket = trim($s->get('backup_folder'), '/');
        if ($bucket === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('Enter the storage address (e.g. s3.eu-central-1.amazonaws.com) and the bucket name.');
        }
        // tests: the storage can be a local fake server (only through the database, it is not in the admin)
        $base = 'https://' . $host;
        $test = $s->get('backup_test_url');
        if ($test !== '' && preg_match('#^http://(127\.0\.0\.1:\d+)$#', $test, $m)) {
            [$base, $host] = [$test, $m[1]];
        }
        $put = function (string $remote, string $local) use ($s, $host, $bucket, $base): void {
            $uri = '/' . implode('/', array_map(rawurlencode(...), explode('/', $bucket . '/' . $remote)));
            $headers = self::signS3('PUT', $host, $uri, (string) hash_file('sha256', $local), $s->get('backup_region') ?: 'us-east-1', $s->get('backup_user'), $s->get('backup_password'), time());
            $f = fopen($local, 'rb');
            $ch = curl_init($base . $uri);
            curl_setopt_array($ch, [
                CURLOPT_PUT => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($local), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
                CURLOPT_PROTOCOLS => str_starts_with($base, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            ]);
            $response = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            fclose($f);
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('The storage rejected the upload (code ' . $code . ')' . (preg_match('#<Message>([^<]+)#', $response, $m) ? ': ' . $m[1] : ($code === 0 ? ': could not connect' : '')) . '.');
            }
        };

        return [$put, fn () => null];
    }

    /**
     * Request headers signed with AWS Signature Version 4 (service s3).
     *
     * @return array<string, string>
     */
    public static function signS3(string $method, string $host, string $uri, string $bodyHash, string $region, string $key, string $secret, int $time): array
    {
        $date = gmdate('Ymd\THis\Z', $time);
        $day = substr($date, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date];
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $uri . "\n\n" . implode('', array_map(fn (string $k, string $v): string => $k . ':' . $v . "\n", array_keys($headers), $headers)) . "\n" . $signedHeaders . "\n" . $bodyHash;
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);

        return [
            'Host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . hash_hmac('sha256', $stringToSign, $k),
        ];
    }
}
