<?php

declare(strict_types=1);

namespace Talea\Tests\Site\JobsFleetFacts;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Error tracking (Core\ErrorReport, docs/specs/error-tracking.md) against the fake tracker of tools/fake/sentry.php: off without a
 * DSN; with one, an uncaught error, an MCP tool, a failing job and a browser error each arrive as one event without personal data;
 * a tracker that is away gets the report later from the outbox. A small add-on (boomer) provides the faults.
 */
#[Group('site')]
final class ErrorTrackingTest extends SiteTestCase
{
    use Helpers;

    /** Sets config.php keys of the site (the router reads config.php on every request). @param array<string, mixed> $keys */
    private function configure(array $keys): void
    {
        $file = $this->site()->path('config.php');
        $config = array_replace(require $file, $keys);
        file_put_contents($file, "<?php\n\nreturn " . var_export($config, true) . ";\n");
        static $mtime = 0;
        $mtime = max($mtime + 2, time() + 2);
        touch($file, $mtime); // opcache sees a new file even within the same second
    }

    /** @return list<array<string, mixed>> the events the fake tracker received, waiting up to $seconds for at least $count */
    private function events(int $count = 1, float $seconds = 5.0): array
    {
        $until = microtime(true) + $seconds;
        do {
            $events = array_map(fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", $this->site()->fakeLog('sentry')))));
            if (count($events) >= $count) {
                return $events;
            }
            usleep(100_000);
        } while (microtime(true) < $until);

        return $events;
    }

    /** @return array<string, mixed> the newest event whose tag $tag is $value */
    private function eventTagged(string $tag, string $value, int $count): array
    {
        foreach (array_reverse($this->events($count)) as $entry) {
            if (($entry['event']['tags'][$tag] ?? null) === $value) {
                return $entry;
            }
        }
        $this->fail('no event with ' . $tag . ' = ' . $value);
    }

    public function testWithoutADsnNothingIsSent(): void
    {
        $site = $this->site();
        $this->assertPageLacks('/admin.php', 'image/errors.js', message: 'no DSN: the admin loads no error script');
        $this->assertPageLacks('/', 'image/errors.js', $site->client(), 'no DSN: the site neither');
        $this->assertSame(204, $site->client()->post('/js-error', '{"message":"x","source":"/image/web.js","area":"site"}')->status, 'the endpoint answers quietly');
        $this->assertStringContainsString('Error reporting is off', $site->cli(['system/docker.php', 'error-test']), 'the test command says what to set');
        $this->assertSame([], $this->events(0, 0.0), 'nothing reached the tracker');
        $this->assertPage('/admin.php?module=status', 200, ['Error tracking', 'set TALEA_ERROR_DSN'], message: 'System status: off');
    }

    public function testAnUncaughtErrorArrivesWithoutPersonalData(): void
    {
        $site = $this->site();
        $dir = $site->path('extensions/boomer');
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/extension.json', (string) json_encode(['name' => 'Boomer', 'version' => '1.0.0', 'description' => 'Fails on purpose for the error tracking test.',
            'author' => 'Talea tests', 'class' => 'TaleaTest\\Boomer\\Extension', 'entry' => 'Extension.php', 'requires' => ['talea' => '>=3.0', 'api' => 2], 'capabilities' => ['routes']]));
        file_put_contents($dir . '/Extension.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TaleaTest\Boomer;

            use Talea\Extension\Api;
            use Talea\Extension\ExtensionInterface;

            final class Extension implements ExtensionInterface
            {
                public function register(Api $api): void
                {
                    $api->route('boom', function ($request, string $rest): never {
                        if ($rest === 'while-away') {
                            throw new \LogicException('A second fault, another place'); // the same place again would be held back for a minute
                        }
                        throw new \RuntimeException('Boom from the add-on at ' . $rest . ' for jana@example.com');
                    });
                    $api->mcpTool('boom', 'Fails on purpose.', ['properties' => new \stdClass()], 'read', fn (array $args): int => strlen(null));
                    $api->job('fail', 60, 'Fails on purpose', function (): string {
                        throw new \RuntimeException('The job fails on purpose');
                    });
                }
            }
            PHP);
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'boomer', 'on' => 1, 'trust' => 1]);
        $this->assertSame('boomer', (string) $site->value("SELECT value FROM tl_settings WHERE name = 'addons_enabled'"), 'the add-on is on');
        $this->configure(['error_dsn' => 'http://test-key@127.0.0.1:' . $site->port('fake') . '/sentry/42', 'error_environment' => 'test']);

        $this->assertPage('/admin.php', 200, ['image/errors.js', 'data-errors-area="admin"'], message: 'with a DSN the admin loads the error script');
        $this->assertPageLacks('/', 'image/errors.js', $site->client(), 'the public site only with TALEA_ERROR_BROWSER');

        $visitor = $site->client('visitor');
        $page = $visitor->get('/boom/first?page=2&email=jana@example.com', ['Cookie: PHPSESSID=visitor-secret']);
        $this->assertSame(500, $page->status, 'the error page answers');
        $this->assertStringContainsString('Sorry, something went wrong', $page->body, 'the visitor sees the plain error page');
        $entry = $this->eventTagged('addon', 'boomer', 1);
        $event = $entry['event'];
        $this->assertSame(['42', 'test-key', 'event', 'error', 'talea@' . TALEA_VERSION, 'test', 'front', '/{prefix:[a-z][a-z0-9_-]{1,30}}{rest:.*}'],
            [$entry['project'], $entry['key'], $entry['type'], $event['level'], $event['release'], $event['environment'], $event['tags']['area'], $event['tags']['route']],
            'the project, the key, the release, the environment, the area and the route pattern (not the address)');
        $exception = end($event['exception']['values']);
        $this->assertSame(['RuntimeException', 'Boom from the add-on at first for [email]', 'extensions/boomer/Extension.php', false],
            [$exception['type'], $exception['value'], end($exception['stacktrace']['frames'])['filename'], $exception['mechanism']['handled']], 'the exception, scrubbed, thrown in the add-on');
        // without the frames: their source lines are the add-on's code, which names the address it throws with
        $sent = (string) json_encode(array_diff_key($event, ['exception' => 0]) + ['values' => array_column($event['exception']['values'], 'value')]);
        $this->assertSame([false, false, false, true], [str_contains($entry['raw'], 'visitor-secret'), str_contains($sent, 'jana@example.com'), isset($event['user']),
            str_ends_with($event['request']['url'], '/boom/first?page=2&email=%5Bfiltered%5D')], 'no cookie, no e-mail address, no user; the query value of email filtered');
        $this->assertStringContainsString('[event ' . $event['event_id'] . ']', (string) file_get_contents($site->path('storage/log/errors.log')), 'errors.log names the event');
        $this->assertPage('/admin.php?module=status', 200, ['Error tracking', 'to 127.0.0.1 (test)', 'last report'], message: 'System status: on, with the last report');
    }

    public function testAnMcpToolErrorIsAToolErrorWithTheEventId(): void
    {
        $count = count($this->events(0, 0.0));
        $text = $this->site()->mcpText('ext_boomer_boom');
        $this->assertStringContainsString('internal error of the site (TypeError)', $text, 'the client gets a tool error, not an HTML page');
        $entry = $this->eventTagged('tool', 'ext_boomer_boom', $count + 1);
        $this->assertSame(['mcp', 'TypeError'], [$entry['event']['tags']['area'], end($entry['event']['exception']['values'])['type']], 'the event names the area and the tool');
        $this->assertStringContainsString($entry['event']['event_id'], $text, 'the answer carries the event id');
    }

    public function testAFailingJobIsOneGroupedEvent(): void
    {
        $count = count($this->events(0, 0.0));
        $this->site()->runTasks();
        $entry = $this->eventTagged('job', 'ext_boomer_fail', $count + 1);
        $this->assertSame(['job', 'ext_boomer_fail', 'RuntimeException'], $entry['event']['fingerprint'], 'every run of the job is the same issue');
    }

    public function testBrowserErrors(): void
    {
        $site = $this->site();
        $count = count($this->events(0, 0.0));
        $report = fn (string $area, string $source): array => ['message' => 'TypeError: x is undefined', 'stack' => 'TypeError: x is undefined' . "\n    at go (" . $source . ':3:7)',
            'source' => $source, 'line' => 3, 'column' => 7, 'area' => $area, 'path' => '/admin/pages'];
        $this->assertSame(204, $site->admin()->post('/js-error', (string) json_encode($report('admin', $site->base . '/image/admin.js?v=1')))->status, 'the admin reports');
        $entry = $this->eventTagged('area', 'admin', $count + 1);
        $this->assertSame(['javascript', 'TypeError', '/image/admin.js'], [$entry['event']['platform'], $entry['event']['exception']['values'][0]['type'],
            end($entry['event']['exception']['values'][0]['stacktrace']['frames'])['filename']], 'a browser error with its frames');

        $visitor = $site->client('browser');
        $visitor->post('/js-error', (string) json_encode($report('site', $site->base . '/image/web.js')));
        $visitor->post('/js-error', (string) json_encode($report('admin', $site->base . '/image/admin.js')));
        $this->assertCount($count + 1, $this->events($count + 2, 1.0), 'a visitor reports nothing while TALEA_ERROR_BROWSER is off, and cannot pose as the admin');

        $this->configure(['error_browser' => true]);
        $site->clearPageCache();
        $this->assertPage('/', 200, ['image/errors.js', 'data-errors='], $visitor, 'TALEA_ERROR_BROWSER: the public pages load the script');
        $visitor->post('/js-error', (string) json_encode($report('site', 'https://ads.example/tracker.js')));
        $visitor->post('/js-error', (string) json_encode($report('site', $site->base . '/image/web.js')));
        $entry = $this->eventTagged('area', 'site', $count + 2);
        $this->assertSame(1, count(array_filter($this->events($count + 2), fn (array $e): bool => ($e['event']['tags']['area'] ?? '') === 'site')), 'the foreign script is not ours, the site\'s own is');
        $this->assertSame('/image/web.js', end($entry['event']['exception']['values'][0]['stacktrace']['frames'])['filename'], 'the visitor\'s report of the site\'s script');
    }

    public function testAReportWaitsInTheOutboxWhileTheTrackerIsAway(): void
    {
        $site = $this->site();
        $down = sys_get_temp_dir() . '/talea-fake-' . $site->port('fake') . '-sentry-down';
        touch($down);
        $count = count($this->events(0, 0.0));
        $this->assertSame(500, $site->client()->get('/boom/while-away')->status, 'another fault');
        $until = microtime(true) + 5;
        while ((glob($site->path('storage/log/outbox/*.envelope')) ?: []) === [] && microtime(true) < $until) {
            usleep(100_000);
        }
        $this->assertCount(1, glob($site->path('storage/log/outbox/*.envelope')) ?: [], 'the report waits in the outbox');
        unlink($down);
        $site->runTasks();
        $this->assertSame([[], true], [glob($site->path('storage/log/outbox/*.envelope')) ?: [], str_contains(json_encode($this->events($count + 1)) ?: '', 'while-away')],
            'the background jobs delivered it');

        $output = $site->cli(['system/docker.php', 'error-test']);
        $this->assertMatchesRegularExpression('/Test event [a-f0-9]{32} sent to 127\.0\.0\.1/', $output, 'the test command sends one event and says so');
    }
}
