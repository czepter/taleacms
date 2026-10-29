<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\ErrorReport;

/** Error reports to Sentry or GlitchTip (Core\ErrorReport, docs/specs/error-tracking.md) */
final class ErrorReportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        ErrorReport::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        putenv('TALEA_ERROR_DSN');
        putenv('TALEA_ERROR_SAMPLE_RATE');
        ErrorReport::reset();
    }

    /** @param array<string, mixed> $config */
    private function on(array $config = []): void
    {
        ErrorReport::configure(['error_dsn' => 'https://public-key@glitchtip.example/7'] + $config);
    }

    /** @return array{header: array<string, mixed>, item: array<string, mixed>, event: array<string, mixed>, raw: string} the one queued envelope */
    private function sent(): array
    {
        $queued = ErrorReport::takeQueued();
        $this->assertCount(1, $queued, 'one event is queued');
        [$header, $item, $event] = explode("\n", $queued[0]);

        return ['header' => json_decode($header, true), 'item' => json_decode($item, true), 'event' => json_decode($event, true), 'raw' => $queued[0]];
    }

    private function failure(): \RuntimeException
    {
        try {
            (function (): void {
                throw new \RuntimeException('Failure for the report of user@example.com', 0, new \LogicException('the cause'));
            })();
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    public function testDsn(): void
    {
        $this->assertSame([
            ['dsn' => 'https://abc123@o1.ingest.sentry.io/4507', 'key' => 'abc123', 'endpoint' => 'https://o1.ingest.sentry.io/api/4507/envelope/', 'host' => 'o1.ingest.sentry.io'],
            ['dsn' => 'http://k@localhost:8000/glitch/5', 'key' => 'k', 'endpoint' => 'http://localhost:8000/glitch/api/5/envelope/', 'host' => 'localhost'],
            null, null, null, null, null,
        ], array_map(ErrorReport::parseDsn(...), ['https://abc123@o1.ingest.sentry.io/4507', 'http://k@localhost:8000/glitch/5',
            'not a dsn', 'https://o1.ingest.sentry.io/4507', 'ftp://k@host/1', 'https://k@host/', 'https://k@host/a b']),
            'Sentry and GlitchTip DSNs (with a port and a sub-path) give the envelope endpoint; a missing key, project or scheme is no DSN');

        $this->assertSame([false, ''], [ErrorReport::enabled(), ErrorReport::host()], 'without a DSN error reporting is off');
        $this->assertNull(ErrorReport::capture(new \RuntimeException('x')), 'off: nothing is captured');
        putenv('TALEA_ERROR_DSN=https://env-key@env.example/3');
        ErrorReport::configure(['error_dsn' => 'https://file-key@file.example/1']);
        $this->assertSame([true, 'env.example'], [ErrorReport::enabled(), ErrorReport::host()], 'the environment wins over config.php');
        putenv('TALEA_ERROR_DSN=oops');
        ErrorReport::configure([]);
        $this->assertFalse(ErrorReport::enabled(), 'a malformed DSN leaves reporting off instead of failing');
    }

    public function testScrub(): void
    {
        $this->assertSame([
            'Mail to [email] failed',
            'Authorization: Bearer [filtered]',
            'token [filtered] refused',
            'password=[filtered]&page=2',
            'api_key: "[filtered]"',
            'the link /_booking/cancel/[filtered] expired',
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '[filtered]' for key '[filtered]'",
            'Undefined array key "title"',
        ], array_map(ErrorReport::scrub(...), [
            'Mail to jana.novakova@example.cz failed',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.e30.abc',
            'token talea_0123456789abcdef0123 refused',
            'password=hunter2&page=2',
            'api_key: "sk-ant-123"',
            'the link /_booking/cancel/0123456789abcdef0123456789abcdef expired',
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jana' for key 'users.username'",
            'Undefined array key "title"',
        ]), 'e-mail addresses, tokens, secrets, long hex strings and the values in database errors go; an ordinary message stays');
    }

    public function testFrames(): void
    {
        $frames = ErrorReport::frames($this->failure());
        $last = end($frames);
        $this->assertSame(['tests/Unit/ErrorReportTest.php', true, true, false, false], [$last['filename'], $last['in_app'], str_contains($last['context_line'], 'throw new'),
            isset($last['vars']) || isset($last['args']), str_starts_with($frames[0]['filename'], '/')],
            'the throw is the last frame, relative to the install, in the app, with its line of source; no arguments, no absolute paths');
        $this->assertCount(5, $last['pre_context'], 'five lines before the line');
        $vendor = array_values(array_filter($frames, fn (array $f): bool => str_starts_with($f['filename'], 'vendor/')));
        $this->assertSame(false, $vendor[0]['in_app'] ?? false, 'vendor/ is not the application');
    }

    public function testAnException(): void
    {
        $this->on(['db' => ['driver' => 'sqlite']]);
        $_SERVER = ['REQUEST_URI' => '/news/a-story?page=2&email=jana@example.cz&token=abc', 'REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'shop.example', 'HTTPS' => 'on',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/131.0', 'HTTP_COOKIE' => 'PHPSESSID=secret-session', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'] + $this->server;
        ErrorReport::tag('area', 'front');
        ErrorReport::tag('route', '/news/{slug}');
        $id = ErrorReport::capture($this->failure(), 'error', ['force' => true, 'tags' => ['job' => 'mail'], 'fingerprint' => ['job', 'mail']]);
        ['header' => $header, 'item' => $item, 'event' => $event] = $this->sent();
        $raw = (string) json_encode(array_diff_key($event, ['exception' => 0])); // the frames carry this file's source, which names the values below

        $this->assertSame([$id, 'https://public-key@glitchtip.example/7', 'event'], [$header['event_id'], $header['dsn'], $item['type']], 'an envelope: header, item header, event');
        $this->assertSame(['error', 'php', 'talea@' . TALEA_VERSION, 'production', '/news/{slug}'], [$event['level'], $event['platform'], $event['release'], $event['environment'], $event['transaction']], 'the release and the route');
        $this->assertSame(['front', '/news/{slug}', 'mail', 'sqlite'], [$event['tags']['area'], $event['tags']['route'], $event['tags']['job'], $event['tags']['db_driver']], 'the tags of the request and of the call');
        $this->assertSame(['job', 'mail'], $event['fingerprint'], 'a fingerprint is passed on');
        $this->assertSame([['LogicException', 'the cause'], ['RuntimeException', 'Failure for the report of [email]']],
            array_map(fn (array $v): array => [$v['type'], $v['value']], $event['exception']['values']), 'the exception after its cause, the message scrubbed');
        $this->assertSame('https://shop.example/news/a-story?page=2&email=%5Bfiltered%5D&token=%5Bfiltered%5D', $event['request']['url'], 'the query keeps only the known parameter names');
        $this->assertSame(['User-Agent' => 'Mozilla/5.0 Firefox/131.0'], $event['request']['headers'], 'only the browser string, no cookie');
        $this->assertSame([false, false, false, false, false], [str_contains($raw, '203.0.113.9'), str_contains($raw, '198.51.100.4'), str_contains($raw, 'secret-session'),
            str_contains($raw, 'jana@example.cz'), isset($event['user'])], 'no IP address, no cookie, no e-mail address, no user');
    }

    public function testMessagesAndLimits(): void
    {
        $this->on();
        ErrorReport::message('E-mail given up after 5 attempts: 550 jana@example.cz unknown', 'warning', ['force' => true]);
        $event = $this->sent()['event'];
        $this->assertSame(['warning', 'E-mail given up after 5 attempts: 550 [email] unknown'], [$event['level'], $event['message']['formatted']], 'a message without an exception, scrubbed');

        $e = new \RuntimeException('Flood ' . bin2hex(random_bytes(4)));
        $this->assertSame([true, null], [is_string(ErrorReport::capture($e)), ErrorReport::capture($e)], 'the same fault twice within the minute is sent once');
        ErrorReport::takeQueued();

        ErrorReport::configure(['error_dsn' => 'https://k@glitchtip.example/7', 'error_sample_rate' => '0']);
        $this->assertSame([null, true], [ErrorReport::capture(new \RuntimeException('sampled ' . bin2hex(random_bytes(4)))), is_string(ErrorReport::capture(new \RuntimeException('forced'), 'error', ['force' => true]))],
            'sample rate 0 sends nothing, a forced event (the test command) still goes');
        ErrorReport::takeQueued();
    }

    public function testBrowserErrors(): void
    {
        $chrome = "TypeError: Cannot read properties of null (reading 'value')\n    at submit (https://shop.example/image/forms.js?v=4.0:120:15)\n    at HTMLFormElement.<anonymous> (https://shop.example/image/forms.js?v=4.0:88:3)";
        $firefox = "submit@https://shop.example/image/forms.js?v=4.0:120:15\n@https://shop.example/image/web.js:10:1";
        $this->assertSame([
            [['/image/forms.js', 'HTMLFormElement.<anonymous>', 88, true], ['/image/forms.js', 'submit', 120, true]],
            [['/image/web.js', '?', 10, true], ['/image/forms.js', 'submit', 120, true]],
        ], array_map(fn (string $stack): array => array_map(fn (array $f): array => [$f['filename'], $f['function'], $f['lineno'], $f['in_app']], ErrorReport::browserFrames($stack)), [$chrome, $firefox]),
            'Chrome and Firefox stacks become frames, the oldest first, the address path only (no host, no version query)');
        $this->assertSame(['Chrome 131', 'Edge 130', 'Firefox 132', 'Safari 18', 'other'], array_map(ErrorReport::browserName(...), [
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.68',
            'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'curl/8.0']),
            'the browser by name and major version, Edge before Chrome');

        $this->on();
        ErrorReport::browser(['message' => "TypeError: Cannot read properties of null (reading 'value')", 'stack' => $chrome, 'source' => 'https://shop.example/image/forms.js?v=4.0',
            'line' => 120, 'column' => 15, 'path' => '/contact'], 'site', 'Chrome 131');
        $event = $this->sent()['event'];
        $this->assertSame(['javascript', 'TypeError', "Cannot read properties of null (reading 'value')", 'site', 'Chrome 131', '/contact', 2],
            [$event['platform'], $event['exception']['values'][0]['type'], $event['exception']['values'][0]['value'], $event['tags']['area'], $event['tags']['browser'], $event['transaction'],
                count($event['exception']['values'][0]['stacktrace']['frames'])], 'a browser error: its type, text, area, browser, page and frames');

        $script = (string) file_get_contents(TALEA_ROOT . '/image/errors.js');
        $this->assertSame([true, true, 0], [str_contains($script, "getAttribute('data-errors')"), str_contains($script, 'navigator.sendBeacon('), preg_match('/document\.cookie|localStorage|sessionStorage/', $script)],
            'the script reads only its own tag, sends with sendBeacon and stores nothing');
        $this->assertTrue(in_array('js-error', \Talea\Front\Kernel::reservedSlugs(), true), '/js-error is a reserved address');
    }

    public function testOutbox(): void
    {
        $outbox = TALEA_ROOT . '/storage/log/outbox';
        $before = glob($outbox . '/*.envelope') ?: [];
        ErrorReport::configure(['error_dsn' => 'http://k@127.0.0.1:9/1']); // nothing listens on port 9
        $id = ErrorReport::capture(new \RuntimeException('Unreachable ' . bin2hex(random_bytes(4))), 'error', ['force' => true]);
        $result = ErrorReport::flush();
        $new = array_values(array_diff(glob($outbox . '/*.envelope') ?: [], $before));
        try {
            $this->assertSame([$id, false, true, 1], [$result[0]['id'], $result[0]['ok'], str_starts_with($result[0]['error'], 'no connection'), count($new)], 'a report the tracker did not take waits in the outbox');
            $this->assertStringContainsString($id, $new[0], 'the file is named after the event');
            $status = ErrorReport::status();
            $this->assertSame([true, '127.0.0.1', true], [$status['enabled'], $status['host'], $status['failed_at'] > 0], 'System status sees the failure');
        } finally {
            array_map('unlink', $new);
            @unlink(TALEA_ROOT . '/storage/log/error-report.json');
        }
    }
}
