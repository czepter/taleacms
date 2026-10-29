<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Error reports to Sentry or GlitchTip (docs/specs/error-tracking.md). Off until the operator sets a DSN (TALEA_ERROR_DSN, or
 * error_dsn in config.php) – never a setting, so neither the admin nor an MCP connection can point the reports elsewhere.
 * A small client of its own instead of the Sentry SDK: one event type, sent as an envelope (POST /api/<project>/envelope/),
 * which Sentry and GlitchTip both accept.
 *
 * What leaves the site is technical only: the exception, a stack trace without arguments (paths relative to the install, five
 * lines of source around each frame), the release, the area and route, the role of the signed-in person. Never an IP address,
 * a cookie, a request body, a name or an e-mail address; messages are scrubbed (scrub()), the query string keeps only the
 * English parameter names of QUERY_KEYS.
 *
 * Events are sent after the response (flush() from a shutdown function, after fastcgi_finish_request()) with short timeouts; a
 * send that fails leaves the envelope in storage/log/outbox/ (files: the database may be what failed), retried by the background
 * jobs (retryOutbox()) for a day. The same fault is sent once a minute at most (a crawler on a broken page must not use up the
 * project's quota).
 */
final class ErrorReport
{
    /** Levels of the protocol, in the order of severity. */
    public const array LEVELS = ['debug', 'info', 'warning', 'error', 'fatal'];

    /** Query parameters whose values say how a page was asked for, never who asked: the others are sent as [filtered]. */
    public const array QUERY_KEYS = ['module', 'action', 'tab', 'page', 'build', 'editor', 'status', 'type', 'view', 'sort', 'language', 'part', 'variant'];

    /** Envelopes waiting in the outbox at most (the oldest are kept, newer ones dropped) and how long they wait. */
    public const int OUTBOX_LIMIT = 100;
    public const int OUTBOX_HOURS = 24;

    /** The same fingerprint is sent once per this many seconds (per container). */
    public const int FLOOD_SECONDS = 60;

    /** Lines of source before and after the line of a frame; frames per exception at most. */
    private const int CONTEXT_LINES = 5;
    private const int MAX_FRAMES = 50;

    /** @var array{dsn: string, key: string, endpoint: string, host: string}|null the parsed DSN; null = off */
    private static ?array $dsn = null;
    private static bool $configured = false;
    private static string $environment = 'production';
    private static float $sampleRate = 1.0;
    private static bool $browser = false;
    /** @var array<string, string> tags of this request: area, route, module, role, db_driver … */
    private static array $tags = [];
    /** @var list<array{id: string, envelope: string}> events of this request waiting for flush() */
    private static array $queue = [];
    private static bool $flushRegistered = false;
    /** @var array<string, list<string>> source files read for the context lines */
    private static array $sources = [];
    /** Part of the flood counter's key: only the tests change it (reset()), so one run does not count another's events. */
    private static string $floodScope = '';

    /**
     * Reads the configuration: the environment wins over config.php. A malformed DSN leaves reporting off and says so once in
     * the PHP log (never an exception: the error handler itself must not fail).
     *
     * @param array<string, mixed> $config contents of config.php, or Config::fromEnvironment()
     */
    public static function configure(array $config = []): void
    {
        self::$configured = true;
        $dsn = Config::env('ERROR_DSN', (string) ($config['error_dsn'] ?? ''));
        self::$dsn = $dsn === '' ? null : self::parseDsn($dsn);
        if ($dsn !== '' && self::$dsn === null) {
            error_log('Talea: TALEA_ERROR_DSN is not a DSN like https://<key>@<host>/<project>; error reports are off');
        }
        self::$environment = Config::env('ERROR_ENVIRONMENT', (string) ($config['error_environment'] ?? '')) ?: 'production';
        $rate = Config::env('ERROR_SAMPLE_RATE', (string) ($config['error_sample_rate'] ?? '1'));
        self::$sampleRate = is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 1.0;
        self::$browser = Config::flag('ERROR_BROWSER', (bool) ($config['error_browser'] ?? false));
        if (is_string($config['db']['driver'] ?? null)) {
            self::$tags['db_driver'] = $config['db']['driver'];
        }
    }

    public static function enabled(): bool
    {
        if (!self::$configured) {
            self::configure(); // a CLI script or the installer, before any configuration was read: the environment alone
        }

        return self::$dsn !== null;
    }

    /** Whether the public site sends its browser errors too (TALEA_ERROR_BROWSER=1); the admin and the builder always do when enabled. */
    public static function browserOnSite(): bool
    {
        return self::enabled() && self::$browser;
    }

    /** The host the reports go to, for System status ('' = off). */
    public static function host(): string
    {
        return self::enabled() ? (string) self::$dsn['host'] : '';
    }

    /** A tag for every event of this request (area, route, module, role …); '' removes it. */
    public static function tag(string $name, string $value): void
    {
        if ($value === '') {
            unset(self::$tags[$name]);
        } else {
            self::$tags[$name] = mb_substr($value, 0, 200);
        }
    }

    /**
     * https://<key>@<host>[:port][/<path>]/<project> → the envelope endpoint and the key; null when it is not a DSN.
     *
     * @return array{dsn: string, key: string, endpoint: string, host: string}|null
     */
    public static function parseDsn(string $dsn): ?array
    {
        $p = parse_url(trim($dsn));
        if (!is_array($p) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '' || ($p['user'] ?? '') === '') {
            return null;
        }
        $path = trim((string) ($p['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $project = (string) array_pop($segments);
        if ($project === '' || preg_match('/^[A-Za-z0-9_-]+$/', $project) !== 1) {
            return null;
        }
        $prefix = $segments === [] ? '' : '/' . implode('/', $segments);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        return ['dsn' => trim($dsn), 'key' => rawurldecode((string) $p['user']), 'endpoint' => $origin . $prefix . '/api/' . $project . '/envelope/', 'host' => (string) $p['host']];
    }

    /**
     * Reports an exception; returns the event id, or null when nothing is sent (off, sampled out, the same fault a moment ago).
     *
     * @param array{tags?: array<string, string>, extra?: array<string, scalar>, fingerprint?: list<string>, handled?: bool, force?: bool} $context
     */
    public static function capture(\Throwable $e, string $level = 'error', array $context = []): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        try {
            $event = self::event($level, $context) + ['exception' => ['values' => self::exceptions($e, $context['handled'] ?? true)]];
            $culprit = self::culprit($e);
            if ($culprit !== '') {
                $event['tags']['addon'] ??= $culprit;
            }

            return self::queue($event, $context, $e::class . '|' . self::relative($e->getFile()) . ':' . $e->getLine());
        } catch (\Throwable $inner) {
            error_log('Talea: the error report could not be built: ' . $inner->getMessage());

            return null;
        }
    }

    /**
     * Reports a fault without an exception (an e-mail given up, a test event).
     *
     * @param array{tags?: array<string, string>, extra?: array<string, scalar>, fingerprint?: list<string>, force?: bool} $context
     */
    public static function message(string $text, string $level = 'warning', array $context = []): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        try {
            $text = self::scrub($text);
            $event = self::event($level, $context) + ['message' => ['formatted' => mb_substr($text, 0, 2000)]];

            return self::queue($event, $context, 'message|' . $text);
        } catch (\Throwable $inner) {
            error_log('Talea: the error report could not be built: ' . $inner->getMessage());

            return null;
        }
    }

    /**
     * Reports an error from the browser (POST /js-error, image/errors.js): the message and the stack the script sent. The
     * caller has checked who may send it; the stack becomes frames with the address path only (no host, no query).
     *
     * @param array{message: string, stack: string, source: string, line: int, column: int, path: string} $report
     */
    public static function browser(array $report, string $area, string $browser): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $message = self::scrub(mb_substr($report['message'], 0, 1000));
        // "Uncaught TypeError: x is undefined" → TypeError and its text
        [$type, $value] = preg_match('/^(?:Uncaught )?([A-Z][A-Za-z]*Error):\s*(.+)$/s', $message, $m) === 1 ? [$m[1], $m[2]] : ['Error', $message];
        $frames = self::browserFrames($report['stack']);
        if ($frames === [] && $report['source'] !== '') {
            $frames = [['filename' => self::urlPath($report['source']), 'lineno' => $report['line'], 'colno' => $report['column'], 'in_app' => true]];
        }
        $context = ['tags' => ['area' => $area, 'browser' => $browser]];
        $event = ['platform' => 'javascript'] + self::event('error', $context);
        $event['request'] = ['url' => self::requestUrl(self::urlPath($report['path'])), 'method' => 'GET'];
        $event['transaction'] = self::urlPath($report['path']);
        unset($event['tags']['route'], $event['contexts']['runtime']);
        $event['exception'] = ['values' => [['type' => $type, 'value' => $value, 'mechanism' => ['type' => 'onerror', 'handled' => false]]
            + ($frames !== [] ? ['stacktrace' => ['frames' => $frames]] : [])]];
        $first = end($frames) ?: [];

        return self::queue($event, $context, 'js|' . $type . '|' . ($first['filename'] ?? '') . ':' . ($first['lineno'] ?? 0) . '|' . mb_substr($value, 0, 100));
    }

    /**
     * POST /js-error: a report of image/errors.js. The admin and the builder report for signed-in people; the public site only
     * when the operator switched it on (TALEA_ERROR_BROWSER=1). Kept: errors of the site's own scripts (image/…), at most 20 a
     * minute per address, never from bots. The address and the full browser string are not passed on, only the browser's name
     * and major version.
     */
    public static function receiveBrowser(App $app, string $body): void
    {
        $data = strlen($body) <= 8192 ? json_decode($body, true) : null;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!is_array($data) || !self::enabled() || $ua === '' || \Talea\Front\Stats::isBot($ua)) {
            return;
        }
        $area = in_array($data['area'] ?? '', ['admin', 'builder'], true) ? (string) $data['area'] : 'site';
        if ($area === 'site' ? !self::$browser : $app->auth()->user() === null) {
            return;
        }
        $report = ['message' => (string) ($data['message'] ?? ''), 'stack' => mb_substr((string) ($data['stack'] ?? ''), 0, 4000), 'source' => (string) ($data['source'] ?? ''),
            'line' => (int) ($data['line'] ?? 0), 'column' => (int) ($data['column'] ?? 0), 'path' => (string) ($data['path'] ?? '/')];
        $own = $app->request->basePath() . '/image/';
        $ours = str_starts_with(self::urlPath($report['source']), $own) || array_filter(self::browserFrames($report['stack']), fn (array $f): bool => str_starts_with((string) $f['filename'], $own)) !== [];
        if ($report['message'] === '' || !$ours || Antispam::tally($app->request->ip(), 'js-error', 60) > 20) {
            return;
        }
        self::browser($report, $area, self::browserName($ua));
    }

    /** "Chrome 131", "Safari 18" … from a browser string; Edge and Opera say Chrome too, so they are asked first. */
    public static function browserName(string $ua): string
    {
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Version' => 'Safari'] as $token => $name) {
            if (preg_match('#\b' . $token . '/(\d+)#', $ua, $m) === 1) {
                return $name . ' ' . $m[1];
            }
        }

        return 'other';
    }

    /**
     * The common part of every event.
     *
     * @param array{tags?: array<string, string>, extra?: array<string, scalar>, fingerprint?: list<string>} $context
     * @return array<string, mixed>
     */
    private static function event(string $level, array $context): array
    {
        $tags = array_map(fn ($v): string => mb_substr((string) $v, 0, 200), ($context['tags'] ?? []) + self::$tags);
        try {
            $tags['language'] ??= Language::code();
        } catch (\Throwable) {
        }
        $server = $_SERVER;
        $event = [
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => round(microtime(true), 3),
            'platform' => 'php',
            'level' => in_array($level, self::LEVELS, true) ? $level : 'error',
            'logger' => 'talea',
            'release' => 'talea@' . TALEA_VERSION,
            'environment' => self::$environment,
            'server_name' => (string) (gethostname() ?: 'talea'),
            'tags' => $tags,
            'contexts' => ['runtime' => ['name' => 'php', 'version' => PHP_VERSION], 'os' => ['name' => PHP_OS_FAMILY]],
        ];
        if (isset($tags['route'])) {
            $event['transaction'] = $tags['route'];
        }
        if (isset($server['REQUEST_URI'])) { // a web request (the CLI has none)
            $headers = array_filter(['User-Agent' => (string) ($server['HTTP_USER_AGENT'] ?? ''), 'Accept-Language' => (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? '')], fn (string $v): bool => $v !== '');
            $event['request'] = ['url' => self::requestUrl((string) $server['REQUEST_URI']), 'method' => (string) ($server['REQUEST_METHOD'] ?? 'GET'),
                'headers' => array_map(fn (string $v): string => mb_substr($v, 0, 300), $headers)];
        }
        if (($context['extra'] ?? []) !== []) {
            $event['extra'] = $context['extra'];
        }
        if (($context['fingerprint'] ?? []) !== []) {
            $event['fingerprint'] = array_map(fn ($v): string => self::scrub((string) $v), $context['fingerprint']); // grouping keys leave the site too
        }

        return $event;
    }

    /**
     * Sampling and flood protection, then the event waits for flush(). Returns the event id or null.
     *
     * @param array<string, mixed> $event @param array{force?: bool} $context
     */
    private static function queue(array $event, array $context, string $fingerprint): ?string
    {
        $force = (bool) ($context['force'] ?? false);
        if (!$force && self::$sampleRate < 1.0 && mt_rand() / mt_getrandmax() >= self::$sampleRate) {
            return null;
        }
        if (!$force && Antispam::tally(self::$floodScope . implode('|', (array) ($event['fingerprint'] ?? [])) . $fingerprint, 'error-report', self::FLOOD_SECONDS) > 1) {
            return null; // the same fault within the minute: once is enough
        }
        $id = (string) $event['event_id'];
        $json = (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $header = (string) json_encode(['event_id' => $id, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => self::$dsn['dsn'] ?? ''], JSON_UNESCAPED_SLASHES);
        self::$queue[] = ['id' => $id, 'envelope' => $header . "\n" . json_encode(['type' => 'event', 'length' => strlen($json)]) . "\n" . $json . "\n"];
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function(static function (): void {
                if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // the visitor has the page already; the report does not keep the connection open
                }
                self::flush();
            });
        }

        return $id;
    }

    /**
     * Sends the events of this request. What cannot be sent goes to the outbox. Returns per event whether it arrived.
     *
     * @return list<array{id: string, ok: bool, error: string}>
     */
    public static function flush(): array
    {
        $results = [];
        while (($item = array_shift(self::$queue)) !== null) {
            $error = self::send($item['envelope']);
            if ($error !== '' && $error !== 'rate limited') {
                self::toOutbox($item['id'], $item['envelope']);
            }
            $results[] = ['id' => $item['id'], 'ok' => $error === '', 'error' => $error];
        }

        return $results;
    }

    /** Envelopes the tracker did not take earlier: sent now, oldest first; dropped after OUTBOX_HOURS. Returns how many arrived. */
    public static function retryOutbox(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $sent = 0;
        foreach (self::outbox() as $file) {
            if (filemtime($file) < time() - self::OUTBOX_HOURS * 3600) {
                @unlink($file);
                continue;
            }
            $error = self::send((string) file_get_contents($file));
            if ($error !== '' && $error !== 'rate limited') {
                break; // the tracker is still away: the rest waits for the next run
            }
            @unlink($file);
            $sent += (int) ($error === '');
        }

        return $sent;
    }

    /** @return list<string> the outbox files, oldest first */
    private static function outbox(): array
    {
        $files = glob(TALEA_ROOT . '/storage/log/outbox/*.envelope') ?: [];
        sort($files);

        return $files;
    }

    private static function toOutbox(string $id, string $envelope): void
    {
        $folder = TALEA_ROOT . '/storage/log/outbox';
        if ((!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) || count(self::outbox()) >= self::OUTBOX_LIMIT) {
            return;
        }
        @file_put_contents($folder . '/' . sprintf('%012d', time()) . '-' . $id . '.envelope', $envelope, LOCK_EX);
    }

    /** One POST of an envelope; '' = accepted, otherwise the reason (also kept for System status). */
    private static function send(string $envelope): string
    {
        if (self::$dsn === null || !function_exists('curl_init')) {
            return 'curl is missing';
        }
        $curl = curl_init(self::$dsn['endpoint']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $envelope, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => PHP_SAPI === 'cli' ? 3000 : 1000, CURLOPT_TIMEOUT_MS => PHP_SAPI === 'cli' ? 10000 : 2000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-sentry-envelope', 'User-Agent: Talea/' . TALEA_VERSION,
                'X-Sentry-Auth: Sentry sentry_version=7, sentry_client=talea/' . TALEA_VERSION . ', sentry_key=' . self::$dsn['key']],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = match (true) {
            $body === false => 'no connection: ' . curl_error($curl),
            $status === 429 => 'rate limited',
            $status < 200 || $status >= 300 => 'HTTP ' . $status . ($status === 401 || $status === 403 ? ' (check the DSN key)' : ''),
            default => '',
        };
        self::writeState($error);

        return $error;
    }

    /** The last outcome for System status: storage/log/error-report.json. */
    private static function writeState(string $error): void
    {
        $file = TALEA_ROOT . '/storage/log/error-report.json';
        $state = self::state();
        $state = $error === '' ? ['sent_at' => time()] + $state : ['failed_at' => time(), 'error' => mb_substr($error, 0, 200)] + $state;
        if ($error === '') {
            unset($state['failed_at'], $state['error']);
        }
        @file_put_contents($file, (string) json_encode($state), LOCK_EX);
    }

    /**
     * For System status and the fleet: on, where to, the last send, the last failure and what waits in the outbox.
     *
     * @return array{enabled: bool, host: string, environment: string, browser: bool, sent_at: int, failed_at: int, error: string, outbox: int}
     */
    public static function status(): array
    {
        $state = self::state();

        return ['enabled' => self::enabled(), 'host' => self::host(), 'environment' => self::$environment, 'browser' => self::browserOnSite(),
            'sent_at' => (int) ($state['sent_at'] ?? 0), 'failed_at' => (int) ($state['failed_at'] ?? 0), 'error' => (string) ($state['error'] ?? ''), 'outbox' => count(self::outbox())];
    }

    /** @return array<string, mixed> */
    private static function state(): array
    {
        $raw = @file_get_contents(TALEA_ROOT . '/storage/log/error-report.json');

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Writes an uncaught throwable to storage/log/errors.log: the first line as System status counts it ([date] class: message
     * in file:line), the event id when it was reported, then the trace indented. The log is rotated at 5 MB (one .1 file).
     */
    public static function log(\Throwable $e, ?string $eventId = null): void
    {
        $file = TALEA_ROOT . '/storage/log/errors.log';
        if (@filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        $line = sprintf("[%s] %s: %s in %s:%d%s\n", date('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $eventId !== null ? ' [event ' . $eventId . ']' : '');
        $trace = $e->getTraceAsString();
        $line .= $trace === '' ? '' : '  ' . str_replace("\n", "\n  ", $trace) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * The exception and the ones before it (getPrevious()), the oldest first as the protocol wants it.
     *
     * @return list<array<string, mixed>>
     */
    private static function exceptions(\Throwable $e, bool $handled): array
    {
        $values = [];
        for ($current = $e, $depth = 0; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            $values[] = ['type' => $current::class, 'value' => self::scrub(mb_substr($current->getMessage(), 0, 2000)),
                'stacktrace' => ['frames' => self::frames($current)], 'mechanism' => ['type' => 'generic', 'handled' => $handled]];
        }

        return array_reverse($values);
    }

    /**
     * The stack of a throwable as frames, the oldest call first and the place of the throw last: paths relative to the install,
     * in_app for the application and add-ons (not vendor/), five lines of source around the line; never the arguments.
     *
     * @return list<array<string, mixed>>
     */
    public static function frames(\Throwable $e): array
    {
        $trace = $e->getTrace();
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();
        foreach ([...$trace, ['function' => '{main}']] as $call) {
            if ($file !== '') {
                $function = isset($call['class']) ? $call['class'] . ($call['type'] ?? '::') . $call['function'] : $call['function'];
                $frames[] = self::frame($file, $line, $function);
            }
            $file = (string) ($call['file'] ?? '');
            $line = (int) ($call['line'] ?? 0);
        }

        return array_reverse(array_slice($frames, 0, self::MAX_FRAMES));
    }

    /** @return array<string, mixed> */
    private static function frame(string $file, int $line, string $function): array
    {
        $relative = self::relative($file);
        $frame = ['filename' => $relative, 'abs_path' => $relative, 'lineno' => $line, 'function' => $function,
            'in_app' => $relative !== $file && !str_starts_with($relative, 'vendor/')];
        $lines = self::$sources[$file] ??= is_readable($file) && filesize($file) < 2_000_000 && $relative !== 'config.php' ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        if (isset($lines[$line - 1])) {
            $cut = fn (string $s): string => mb_substr($s, 0, 200);
            $frame['pre_context'] = array_map($cut, array_slice($lines, max(0, $line - 1 - self::CONTEXT_LINES), min(self::CONTEXT_LINES, $line - 1)));
            $frame['context_line'] = $cut($lines[$line - 1]);
            $frame['post_context'] = array_map($cut, array_slice($lines, $line, self::CONTEXT_LINES));
        }

        return $frame;
    }

    /** The add-on a throwable came from: the newest frame inside extensions/<slug>/ ('' = none). */
    private static function culprit(\Throwable $e): string
    {
        foreach ([$e->getFile(), ...array_column($e->getTrace(), 'file')] as $file) {
            if (preg_match('#^extensions/([a-z0-9_-]+)/#', self::relative((string) $file), $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    private static function relative(string $file): string
    {
        $root = rtrim(str_replace('\\', '/', TALEA_ROOT), '/') . '/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    /**
     * A browser's stack (Chrome "at fn (url:1:2)", Firefox and Safari "fn@url:1:2") as frames, the oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function browserFrames(string $stack): array
    {
        $frames = [];
        foreach (array_slice(preg_split('/\R/u', $stack) ?: [], 0, self::MAX_FRAMES) as $row) {
            if (preg_match('/^\s*at (?:(.+?) \()?(\S+?):(\d+):(\d+)\)?\s*$/', $row, $m) === 1 || preg_match('/^\s*(.*?)@(\S+?):(\d+):(\d+)\s*$/', $row, $m) === 1) {
                $path = self::urlPath($m[2]);
                $frames[] = ['filename' => $path, 'function' => mb_substr($m[1] !== '' ? $m[1] : '?', 0, 200), 'lineno' => (int) $m[3], 'colno' => (int) $m[4],
                    'in_app' => preg_match('#(^|/)image/#', $path) === 1];
            }
        }

        return array_reverse($frames);
    }

    /**
     * The path of an address without the scheme, host and query: https://example.com/image/web.js?v=4 → /image/web.js; scrubbed,
     * since a browser sends the page path as it is (/_booking/cancel/<token>).
     */
    private static function urlPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return mb_substr(self::scrub($path === '' ? '/' : $path), 0, 300);
    }

    /** The address of the request: the host (not secret) and the path, the query values of QUERY_KEYS only. */
    private static function requestUrl(string $uri): string
    {
        $server = $_SERVER;
        $https = ($server['HTTPS'] ?? '') !== '' && ($server['HTTPS'] ?? '') !== 'off' || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($server['HTTP_HOST'] ?? 'localhost'));
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        $kept = [];
        foreach ($query as $key => $value) {
            $kept[$key] = in_array($key, self::QUERY_KEYS, true) && is_string($value) ? mb_substr($value, 0, 60) : '[filtered]';
        }

        return ($https ? 'https' : 'http') . '://' . $host . self::scrub($path) . ($kept !== [] ? '?' . http_build_query($kept) : '');
    }

    /**
     * A message without the personal data and secrets it may carry: e-mail addresses, bearer and Talea tokens, password, token or
     * key values, long hex strings (hashes, signed links) and the quoted values in database messages.
     */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [filtered]', $text);
        $text = (string) preg_replace('/\btalea_[A-Za-z0-9]{16,}/', '[filtered]', $text);
        $text = (string) preg_replace('/\b(pass(?:word|wd)?|secret|token|api[_-]?key|key|signature|sig)(["\']?\s*[=:]\s*["\']?)[^\s"\'&,;]+/i', '$1$2[filtered]', $text);
        $text = (string) preg_replace('/\b[a-f0-9]{32,}\b/i', '[filtered]', $text);
        if (str_contains($text, 'SQLSTATE')) {
            $text = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'[filtered]'", $text);
        }

        return $text;
    }

    /** For the tests: forget the configuration and what is queued. */
    public static function reset(): void
    {
        self::$configured = false;
        self::$dsn = null;
        self::$tags = [];
        self::$queue = [];
        self::$sources = [];
        self::$floodScope = bin2hex(random_bytes(4));
    }

    /**
     * For the tests: the envelopes waiting for flush(), taken out of the queue.
     *
     * @return list<string>
     */
    public static function takeQueued(): array
    {
        $envelopes = array_column(self::$queue, 'envelope');
        self::$queue = [];

        return $envelopes;
    }
}
