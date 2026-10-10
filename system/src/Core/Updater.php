<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * System updates from the admin.
 *
 * The source is the file update.json: {"version","released","url","sha256","signature","min_php","security","changes":[...]}.
 * A release marked "security": true can install itself (Settings -> Backups and updates).
 * The package (ZIP) is accepted only when the SHA-256 and the Ed25519 signature (Core\Signature::packageMessage) match,
 * verified by one of the public keys in system/update.pub (operational + backup, see docs/RELEASING.md). Only the
 * publisher has the private key (tools/release.php).
 * config.php, media/, storage/, extensions/ (add-ons), install.php and layouts that are not part of the package are never overwritten.
 */
final class Updater
{
    /** For tests: replaces the HTTP requests of probe() – gets the address, returns [status, body]. */
    public ?\Closure $probeFetch = null;

    /**
     * The in-app updater is switched off (hard fork, HF-12): no request to an update channel, no update UI. The code and the key file stay
     * for the dedicated update mechanism (HF-13); until then a site is updated by image or by uploading a package, and bin/migrate migrates.
     */
    public const bool ENABLED = false;

    /** Default update source – none until HF-13 brings the new release channel. */
    public const string DEFAULT_URL = '';

    private const array PROTECTED_PATHS = ['config.php', 'install.php', 'media/', 'storage/', 'extensions/', 'image/ukazka/', 'tools/', '.git/']; // extensions/: add-ons (3.0)
    private const int MAX_BYTES = 60 * 1024 * 1024;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $root = KALETA_ROOT,
        private readonly string $keyFile = KALETA_SYSTEM . '/update.pub',
    ) {
    }

    public function url(): string
    {
        if (!self::ENABLED) {
            return '';
        }
        if (Demo::active()) {
            return ''; // the public demo is reset every hour and never updates itself
        }
        if (Config::fromEnv()) {
            return ''; // container: the files live in the image, a new version is a new image (docker pull), never an in-place update
        }

        return $this->settings->get('update_url') !== '' ? $this->settings->get('update_url') : self::DEFAULT_URL;
    }

    /**
     * Update status; the result of the request is remembered for 12 hours.
     *
     * @return array{configured:bool, current:string, available:?array<string, mixed>, error:?string, checked:int}
     */
    public function state(bool $force = false): array
    {
        $state = ['configured' => $this->url() !== '', 'current' => KALETA_VERSION, 'available' => null, 'error' => null, 'checked' => 0];
        if (!$state['configured']) {
            return $state;
        }
        $cache = json_decode($this->settings->get('update_cache'), true);
        // a good answer is kept 12 hours, a failure only one – a source that was briefly unreachable is asked again soon
        $keep = is_array($cache) && ($cache['error'] ?? null) !== null ? 3600 : 12 * 3600;
        if (!$force && is_array($cache) && ($cache['url'] ?? '') === $this->url() && time() - (int) ($cache['checked'] ?? 0) < $keep) {
            $manifest = $cache['manifest'] ?? null;
            $state['error'] = $cache['error'] ?? null;
            $state['checked'] = (int) $cache['checked'];
        } else {
            try {
                $manifest = $this->manifest();
            } catch (\RuntimeException $e) {
                $manifest = null;
                $state['error'] = $e->getMessage();
            }
            $state['checked'] = time();
            $this->settings->set('update_cache', (string) json_encode(['url' => $this->url(), 'checked' => time(), 'manifest' => $manifest, 'error' => $state['error']], JSON_UNESCAPED_UNICODE));
        }
        if (is_array($manifest) && version_compare((string) $manifest['version'], KALETA_VERSION, '>')) {
            $state['available'] = $manifest;
        }

        return $state;
    }

    /**
     * The background job "updates" (2.9, Core\Scheduler): once every 12 hours checks for a new version and installs it when
     * that is allowed – a security release with automatic updates on, or the version the fleet console allowed (the site
     * lets the console decide, Fleet\Link). Otherwise a security release is announced to the site e-mail. Each version is
     * tried once (update_attempt); a failure is recorded as an event (update.failed / update.rolled_back), so it reaches
     * the alert e-mail.
     */
    public static function runInBackground(App $app): string
    {
        $s = $app->settings();
        $a = new self($s);
        if ($a->url() === '') {
            return 'no update source';
        }
        $byConsole = $s->bool('fleet_updates') && \Kaleta\Fleet\Link::isPaired($s) ? $s->get('fleet_update_allowed') : '';
        $cache = json_decode($s->get('update_cache'), true);
        $fresh = is_array($cache) && ($cache['url'] ?? '') === $a->url() && time() - (int) ($cache['checked'] ?? 0) < (($cache['error'] ?? null) !== null ? 3600 : 12 * 3600);
        if ($fresh && ($byConsole === '' || !version_compare($byConsole, KALETA_VERSION, '>') || $s->get('update_attempt') === $byConsole)) {
            return 'checked recently';
        }
        $newVersion = $a->state(!$fresh)['available'];
        if ($newVersion === null) {
            return 'up to date';
        }
        $version = (string) $newVersion['version'];
        $allowedByConsole = $byConsole !== '' && $byConsole === $version;
        if ((!$allowedByConsole && empty($newVersion['security'])) || $s->get('update_attempt') === $version) {
            return 'version ' . $version . ' available';
        }
        $s->set('update_attempt', $version); // each version is tried and announced only once
        $install = $allowedByConsole || $s->bool('auto_updates');
        // written to the site e-mail (an address without an account): admin texts in the site's default language. The task also
        // runs from the public site, where the admin dictionary is not loaded – Language::runWith() loads it just for this moment
        // (also for installation error messages).
        [$subject, $text, $result] = Language::runWith(Language::defaults($s), function () use ($app, $a, $s, $newVersion, $version, $install, $allowedByConsole): array {
            $result = 'announced';
            $message = t('Security update %s is available. Install it in the administration: Settings → Backups and updates.', $version);
            if ($install) {
                try {
                    Backup::create($app->db(), 'before_update');
                    // 3.3.2 (N40): the decision came from the cached manifest – install only that version with that flag
                    $a->install($app->db(), $version, $allowedByConsole ? null : !empty($newVersion['security']));
                    $result = 'installed';
                    $message = t('Security update %s was installed automatically. A database backup was created before the installation.', $version);
                } catch (\Throwable $e) {
                    $result = 'failed';
                    $message .= ' ' . t('The automatic installation failed: %s', $e->getMessage());
                }
            }

            return [
                t('Kaleta: security update %s', $version),
                $message . "\n\n" . t('Changes:') . "\n- " . implode("\n- ", (array) $newVersion['changes']) . "\n\n" . $s->get('site_name'),
                $result,
            ];
        }, 'admin-');
        // an update the console decided about is the console owner's business – the client is not e-mailed (a failure still
        // reaches the alert e-mail as an event)
        $recipient = $s->get('site_email');
        if ($recipient !== '' && !empty($newVersion['security']) && !$allowedByConsole) {
            Mail::send($s, $recipient, $subject, $text);
        }

        return $result . ' ' . $version;
    }

    /**
     * @param Db|null $db the site database: the new version's migrations run right after the files are uploaded, and when they
     *                    fail, the files are reverted too (so the site is not left with new code over an unmigrated database)
     * @param string|null $expectedVersion the version the decision was made for (3.3.2): the manifest downloaded now must offer
     *                    exactly it, otherwise nothing is installed
     * @param bool|null $expectedSecurity whether that version was announced as a security release: the signed flag of the
     *                    package must say the same (an automatic installation relies on it)
     * @return string the installed version
     */
    public function install(?Db $db = null, ?string $expectedVersion = null, ?bool $expectedSecurity = null): string
    {
        if (!self::ENABLED) {
            throw new \RuntimeException('The in-app updater is switched off.');
        }
        if (Config::fromEnv()) {
            throw new \RuntimeException('Updates run by pulling a new container image.');
        }
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException(t('The server lacks the zip or sodium extension – update manually by uploading the files over FTP.'));
        }
        // download, writing, migrations and the check after it may take longer than a page view is allowed to (2.10)
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $m = $this->manifest();
        if (!version_compare((string) $m['version'], KALETA_VERSION, '>')) {
            throw new \RuntimeException(t('No newer version is available.'));
        }
        // the manifest is checked again against what was decided on; the signature below covers the version and the flag
        if ($expectedVersion !== null && (string) $m['version'] !== $expectedVersion) {
            throw new \RuntimeException(t('The update source now offers version %s instead of %s – nothing was installed. Check for updates again.', (string) $m['version'], $expectedVersion));
        }
        if ($expectedSecurity !== null && !empty($m['security']) !== $expectedSecurity) {
            throw new \RuntimeException(t('The update source changed whether version %s is a security release – nothing was installed. Check for updates again.', (string) $m['version']));
        }
        if (version_compare(PHP_VERSION, (string) ($m['min_php'] ?? '8.4'), '<')) {
            throw new \RuntimeException(t('The new version requires PHP %s; the server runs %s.', (string) $m['min_php'], PHP_VERSION));
        }
        if (!is_writable($this->root) || !is_writable($this->root . '/system')) {
            throw new \RuntimeException(t('The system files are not writable – update manually over FTP.'));
        }
        if (Signature::keys($this->keyFile) === []) {
            throw new \RuntimeException(t('The publisher\'s public key (system/update.pub) is missing, the package cannot be verified.'));
        }

        // lock: an automatic update from background tasks and an administrator's click (or two visits at once) must not overwrite files simultaneously
        $lock = fopen(KALETA_ROOT . '/storage/cache/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('An update is already running. Try again in a moment.'));
        }
        $workDir = KALETA_ROOT . '/storage/cache/update-' . bin2hex(random_bytes(4));
        $zip = $workDir . '.zip';
        try {
            $this->download((string) $m['url'], $zip);
            $sha = hash_file('sha256', $zip);
            if (!hash_equals(strtolower((string) $m['sha256']), $sha)) {
                throw new \RuntimeException(t('The package checksum does not match.'));
            }
            // the signature also covers the security-release flag: whoever controlled only the site with the manifest must not declare a regular release a security one
            if (!Signature::isValid(Signature::packageMessage((string) $m['version'], $sha, !empty($m['security'])), (string) $m['signature'], $this->keyFile)) {
                throw new \RuntimeException(t('The package signature is not valid – the package does not come from the Kaleta publisher.'));
            }
            $files = $this->extract($zip, $workDir);
            $previous = $this->releaseFiles();
            $releaseHashes = $this->releaseHashes();
            touch(KALETA_ROOT . '/storage/maintenance.lock');
            // every file being overwritten is set aside first: if writing fails halfway, the site returns to its original state (not a mix of versions)
            $setAside = $workDir . '-puvodni';
            $written = [];
            try {
                foreach ($files as $relativePath) {
                    $target = $this->root . '/' . $relativePath;
                    if ($relativePath === '.htaccess' && is_file($target) && isset($releaseHashes['.htaccess']) && !hash_equals($releaseHashes['.htaccess'], (string) hash_file('sha256', $target))) {
                        // custom edits of .htaccess (HTTPS, www, redirects) are not overwritten – the new version is placed next to it for comparison
                        copy($workDir . '/' . $relativePath, $target . '.kaleta-nova');
                        continue;
                    }
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                        throw new \RuntimeException(t('Cannot create the folder %s.', dirname($relativePath)));
                    }
                    if (is_file($target)) {
                        if (!is_dir(dirname($setAside . '/' . $relativePath)) && !mkdir(dirname($setAside . '/' . $relativePath), 0775, true) || !copy($target, $setAside . '/' . $relativePath)) {
                            throw new \RuntimeException(t('Cannot write the file %s.', 'storage/cache'));
                        }
                    }
                    if (!copy($workDir . '/' . $relativePath, $target)) {
                        throw new \RuntimeException(t('Cannot write the file %s.', $relativePath));
                    }
                    $written[] = $relativePath;
                }
                // database migrations are not run here: `php bin/migrate` (or the Docker entrypoint) applies them after an update
            } catch (\Throwable $e) {
                $this->restore($written, $setAside);
                if ($db !== null) {
                    Events::record($db, 'update.failed', 'error', mb_substr(t('Installing version %s failed: %s', (string) $m['version'], $e->getMessage()), 0, 255), ['version' => (string) $m['version']]);
                }
                throw new \RuntimeException($e->getMessage() . ' ' . t('The website files have been returned to their state before the update.'), 0, $e);
            }
            // 2.8: does the site work on the new version? Maintenance ends, the site is asked; when it answers with an error,
            // the previous files come back (the database changes are additive, the old version keeps working on them)
            @unlink(KALETA_ROOT . '/storage/maintenance.lock');
            $answers = [];
            $problem = $db !== null ? $this->probe((string) $m['version'], $answers) : null;
            if ($problem !== null) {
                $this->restore($written, $setAside);
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                Events::record($db, 'update.rolled_back', 'error', mb_substr(t('Version %s did not work (%s), so the site went back to version %s.', (string) $m['version'], $problem, KALETA_VERSION), 0, 255),
                    ['version' => (string) $m['version'], 'answers' => array_map(fn (array $a): array => [$a[0], mb_substr(trim(strip_tags($a[1])), 0, 80)], $answers)]);
                $this->settings->set('update_attempt', (string) $m['version']); // an automatic update does not try the same version again
                throw new \RuntimeException(t('Version %s was installed but the site did not work afterwards (%s). The website files have been returned to their state before the update.', (string) $m['version'], $problem));
            }
            self::deleteFolder($setAside);
            self::cleanUpObsolete($this->root, $previous, $files);
        } finally {
            @unlink(KALETA_ROOT . '/storage/maintenance.lock');
            @unlink($zip);
            self::deleteFolder($workDir);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        // the version just installed is the newest the source knows: the admin page right after the update need not ask again
        // (asking the source in that moment failed on sites that are their own update source and showed a red error, 2.10.2)
        $this->settings->set('update_cache', (string) json_encode(['url' => $this->url(), 'checked' => time(), 'manifest' => $m, 'error' => null], JSON_UNESCAPED_UNICODE));
        if ($db !== null) {
            Events::record($db, 'update.applied', 'info', t('Version %s was installed (from %s).', (string) $m['version'], KALETA_VERSION), ['version' => (string) $m['version'], 'from' => KALETA_VERSION]);
        }

        return (string) $m['version'];
    }

    /** @param list<string> $written files written by the update; each comes back from the set-aside copy, a new one is removed */
    private function restore(array $written, string $setAside): void
    {
        foreach (array_reverse($written) as $relativePath) {
            is_file($setAside . '/' . $relativePath) ? @copy($setAside . '/' . $relativePath, $this->root . '/' . $relativePath) : @unlink($this->root . '/' . $relativePath);
        }
        self::deleteFolder($setAside);
    }

    /**
     * After the files are written: asks the site itself whether the new version runs – the cron address with a one-time
     * probe code must answer with the new version, and the home page and the administration must not end with a server
     * error. Returns the problem, or null when it works – also when the site cannot reach itself (some hostings block
     * that): an update is never undone without a clear sign that it broke the site.
     *
     * @param array<string, array{0: int, 1: string}> $answers what the site answered (for the event when the update is undone)
     * @param-out array<string, array{0: int, 1: string}> $answers
     */
    private function probe(string $version, array &$answers): ?string
    {
        $answers = [];
        $site = rtrim($this->settings->get('site_url'), '/');
        if ($site === '' || getenv('KALETA_UPDATE_PROBE') === 'off') {
            return null;
        }
        $code = bin2hex(random_bytes(12));
        $this->settings->set('update_probe', $code);
        // the web server must run the new files, not the cached old ones (a mix of both can fail where nothing is broken)
        $reset = function_exists('opcache_reset') && @opcache_reset();
        if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            if (filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL)) {
                // other PHP processes (and hostings that restrict opcache_reset) notice changed files after revalidate_freq
                sleep(min(10, max(0, (int) ini_get('opcache.revalidate_freq'))) + 1);
            } elseif (!$reset) {
                $this->settings->set('update_probe', '');

                return null; // files are never checked again and the cache cannot be cleared – the answers would say nothing
            }
        }
        /** @var \Closure(string): array{0: int, 1: string} $fetch */
        $fetch = $this->probeFetch ?? function (string $url): array {
            $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3,
                'header' => "User-Agent: Kaleta-update-check/" . KALETA_VERSION . "\r\n"], 'ssl' => ['verify_peer' => true]]);
            $body = @file_get_contents($url, false, $context, 0, 200_000);
            $status = 0;
            foreach (http_get_last_response_headers() ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $s)) {
                    $status = (int) $s[1]; // the last one wins (after redirects)
                }
            }

            return [$status, $body === false ? '' : $body];
        };
        try {
            $probe = $fetch($site . '/tasks?probe=' . $code);
            if (str_starts_with(trim($probe[1]), 'KALETA-PROBE ') && trim($probe[1]) !== 'KALETA-PROBE ' . $version) {
                // the old version answered: PHP still runs the cached old files (opcache checks them every few seconds)
                sleep(3);
                $probe = $fetch($site . '/tasks?probe=' . $code);
            }
            // no answer at all: the site cannot reach itself (a hosting firewall, one PHP worker) – the rest would only wait
            $unreachable = $probe[0] === 0;
            $answers = [
                'probe' => $probe,
                'home' => $unreachable ? [0, ''] : $fetch($site . '/'),
                'admin' => $unreachable ? [0, ''] : $fetch($site . '/admin.php'),
            ];
        } finally {
            $this->settings->set('update_probe', '');
        }

        return self::probeVerdict($answers, $version);
    }

    /**
     * Whether the answers say the new version is broken: only a server error counts. Nothing reachable, a refusal or an
     * answer of the old version (PHP still runs cached files, or a proxy answers) = unknown, not broken (null) – undoing
     * a working update would be worse than keeping it.
     *
     * @param array{probe: array{0: int, 1: string}, home: array{0: int, 1: string}, admin: array{0: int, 1: string}} $answers [status, body]
     */
    public static function probeVerdict(array $answers, string $version): ?string
    {
        if ($answers['probe'][0] >= 500 && trim($answers['probe'][1]) !== 'KALETA-PROBE ' . $version) {
            return t('the site does not start');
        }
        foreach (['home' => t('the home page'), 'admin' => t('the administration')] as $key => $what) {
            if ($answers[$key][0] >= 500) {
                return t('%s ends with error %d', $what, $answers[$key][0]);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = $this->http($this->url(), 200 * 1024, 6); // short limit: the check runs after the page is sent, but not every server can detach it
        $m = json_decode($json, true);
        if (!is_array($m) || !isset($m['version'], $m['url'], $m['sha256'], $m['signature']) || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) $m['version'])) {
            throw new \RuntimeException(t('The update information file is not in a valid format.'));
        }
        $m['changes'] = array_values(array_filter(array_map(fn ($z): string => mb_substr((string) $z, 0, 300), (array) ($m['changes'] ?? []))));

        return $m;
    }

    private function download(string $url, string $target): void
    {
        file_put_contents($target, $this->http($url, self::MAX_BYTES));
    }

    private function http(string $url, int $maxBytes, int $timeoutSeconds = 30): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
        if (!preg_match('#^https://#i', $url) && !($isLocal && preg_match('#^http://#i', $url))) {
            throw new \RuntimeException(t('The update source must use an https:// address.'));
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]), 0, $maxBytes + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException(t('The update source is not reachable (%s).', $host));
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException(t('The downloaded file is unexpectedly large.'));
        }

        return $data;
    }

    /**
     * Extracts the package into a working folder and returns the list of files to overwrite (without protected paths).
     *
     * @return list<string>
     */
    private function extract(string $zipFile, string $destination): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException(t('The package cannot be opened.'));
        }
        $fileNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileNames[] = (string) $zip->getNameIndex($i);
        }
        // the package may have everything in one top-level folder (as GitHub does) - it is stripped off
        $first = array_unique(array_map(fn (string $j): string => explode('/', $j, 2)[0], $fileNames));
        $prefix = count($first) === 1 && !in_array('index.php', $fileNames, true) ? $first[0] . '/' : '';

        $files = [];
        foreach ($fileNames as $i => $displayName) {
            $relativePath = substr($displayName, strlen($prefix));
            if ($relativePath === '' || str_ends_with($relativePath, '/')) {
                continue;
            }
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
                throw new \RuntimeException(t('The package contains an unsafe path.'));
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $target = $destination . '/' . $relativePath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $zip->getFromIndex($i));
            $files[] = $relativePath;
        }
        $zip->close();
        if (!in_array('system/bootstrap.php', $files, true) || !in_array('index.php', $files, true)) {
            throw new \RuntimeException(t('The package does not contain Kaleta.'));
        }

        return $files;
    }

    /**
     * Files that left the package before the update could clean up after itself (or the site skipped them): path => hashes
     * of all released versions. The core file list no longer knows about them, so they are cleaned up by this list.
     */
    private const array REMOVED_FILES = [
        // 'path/to/file.php' => ['sha256 of a released version', …] – files the new version removed
    ];

    /**
     * One-time cleanup after moving to a new version: deletes known removed files, but only when they are exactly as we
     * released them. A file the administrator edited is left alone.
     *
     * @return int number of deleted files
     */
    public static function cleanUpRemoved(string $root): int
    {
        $deleted = 0;
        foreach (self::REMOVED_FILES as $relativePath => $hashes) {
            $file = $root . '/' . $relativePath;
            if (is_file($file) && in_array(hash_file('sha256', $file), $hashes, true) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }
        // files of the previous release that the package carries only for the update to run: the old code that installs it
        // still loads its classes in the same request (renamed classes would otherwise be missing) – the new version then deletes them
        $legacy = json_decode((string) @file_get_contents($root . '/system/files.json'), true)['legacy'] ?? [];
        foreach (is_array($legacy) ? $legacy : [] as $relativePath => $hash) {
            $file = $root . '/' . $relativePath;
            if ((str_starts_with((string) $relativePath, 'system/src/') || $relativePath === 'system/class-aliases.php') && !str_contains((string) $relativePath, '..') && is_file($file)
                && hash_equals((string) $hash, (string) hash_file('sha256', $file)) && @unlink($file)) {
                $deleted++;
                for ($folder = dirname($file); $folder !== $root . '/system/src' && @rmdir($folder); $folder = dirname($folder));
            }
        }

        return $deleted;
    }

    /** @return array<string, string> hashes of the files of the currently installed release (system/files.json) */
    private function releaseHashes(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/files.json'), true);

        return is_array($data['files'] ?? null) ? array_map('strval', $data['files']) : [];
    }

    /** @return list<string> core files per the list of the currently installed release (system/files.json); empty without the list */
    private function releaseFiles(): array
    {
        $data = json_decode((string) @file_get_contents($this->root . '/system/files.json'), true);

        return is_array($data['files'] ?? null) ? array_map('strval', array_keys($data['files'])) : [];
    }

    /**
     * Deletes files that belonged to the old release and are no longer in the new one (renamed and removed parts of the system).
     * Deletes only what the old release itself brought - it does not touch custom files, templates, media or protected paths.
     *
     * @param list<string> $previous
     * @param list<string> $newItems
     * @return int number of deleted files
     */
    public static function cleanUpObsolete(string $root, array $previous, array $newItems): int
    {
        $deleted = 0;
        foreach (array_diff($previous, $newItems) as $relativePath) {
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
                continue;
            }
            foreach (self::PROTECTED_PATHS as $protectedPath) {
                if ($relativePath === $protectedPath || (str_ends_with($protectedPath, '/') && str_starts_with($relativePath, $protectedPath))) {
                    continue 2;
                }
            }
            $file = $root . '/' . $relativePath;
            if (is_file($file) && @unlink($file)) {
                $deleted++;
                @rmdir(dirname($file)); // the folder disappears only if it was left empty
            }
        }

        return $deleted;
    }

    private static function deleteFolder(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($folder);
    }
}
