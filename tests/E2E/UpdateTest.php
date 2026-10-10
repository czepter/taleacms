<?php

declare(strict_types=1);

namespace Talea\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HF-13 (docs/specs/update-and-deployment.md): a real update. Builds the production image from this checkout (version N), installs a site
 * in a throw-away compose project and writes a page; then builds N+1 (N plus one extra migration), restarts only the web service on it,
 * and asserts the page survived, the migration ran before /health turned 200 and the pre-update dump exists. Skipped without Docker (and without buildx unless TALEA_E2E_IMAGE_N names a prebuilt image N).
 * Run: composer test:e2e (first build takes minutes, later ones use the layer cache). Cleans up its project, volumes and images.
 */
#[Group('e2e')]
final class UpdateTest extends TestCase
{
    private const PASSWORD = 'e2e-secret-pw';
    private const MIGRATION = '29990101000000';

    private string $dir;
    private string $project;
    private string $imageN;
    private string $imageN1;
    private int $port;
    private string $prebuilt = '';

    protected function setUp(): void
    {
        [, , $code] = $this->exec(['docker', 'info', '--format', '{{.ServerVersion}}']);
        if ($code !== 0) {
            $this->markTestSkipped('Docker is not available.');
        }
        $this->prebuilt = (string) getenv('TALEA_E2E_IMAGE_N');
        if ($this->prebuilt === '' && $this->exec(['docker', 'buildx', 'version'])[2] !== 0) {
            $this->markTestSkipped('The Dockerfile needs BuildKit (docker buildx); or set TALEA_E2E_IMAGE_N to an already built image.');
        }
        $id = bin2hex(random_bytes(4));
        $this->project = 'talea-e2e-' . $id;
        $this->imageN = $this->prebuilt !== '' ? $this->prebuilt : 'talea-e2e-' . $id . ':n';
        $this->imageN1 = 'talea-e2e-' . $id . ':n1';
        $this->dir = sys_get_temp_dir() . '/' . $this->project;
        mkdir($this->dir, 0775, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $p = self::PASSWORD;
        file_put_contents($this->dir . '/compose.yaml', <<<YAML
            services:
              web:
                image: \${TALEA_E2E_IMAGE}
                ports: ["127.0.0.1:{$this->port}:8080"]
                environment:
                  TALEA_DB_HOST: db
                  TALEA_DB_NAME: talea
                  TALEA_DB_USER: talea
                  TALEA_DB_PASSWORD: {$p}
                  TALEA_SITE_URL: http://127.0.0.1:{$this->port}
                  TALEA_CRON: "0"
                volumes: [storage:/app/storage, media:/app/media, extensions:/app/extensions]
                depends_on:
                  db: {condition: service_healthy}
              db:
                image: mysql:8.4
                command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_0900_ai_ci"]
                environment:
                  MYSQL_DATABASE: talea
                  MYSQL_USER: talea
                  MYSQL_PASSWORD: {$p}
                  MYSQL_RANDOM_ROOT_PASSWORD: "1"
                volumes: [db:/var/lib/mysql]
                healthcheck:
                  test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -utalea -p{$p}"]
                  interval: 3s
                  retries: 40
            volumes:
              db:
              storage:
              media:
              extensions:
            YAML);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) {
            return; // skipped before setUp finished
        }
        $this->exec(['docker', 'compose', '-p', $this->project, '-f', $this->dir . '/compose.yaml', 'down', '-v', '--remove-orphans'], ['TALEA_E2E_IMAGE' => $this->imageN]);
        putenv('TALEA_E2E_IMAGE');
        $this->exec(['docker', 'rmi', $this->imageN1, ...($this->prebuilt === '' ? [$this->imageN] : [])]);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testUpdateKeepsDataAppliesMigrationsAndBecomesHealthy(): void
    {
        $root = dirname(__DIR__, 2);

        // version N = this checkout; N+1 = N plus one later migration (a stand-in for "the next release")
        $this->prebuilt === '' && $this->mustRun(['docker', 'build', '-q', '-t', $this->imageN, $root], timeout: 1800);
        file_put_contents($this->dir . '/e2e_marker.php', "<?php\ndeclare(strict_types=1);\nuse Phinx\\Migration\\AbstractMigration;\nfinal class E2eMarker extends AbstractMigration\n{\n    public function change(): void\n    {\n        \$this->table('e2e_marker')->addColumn('note', 'string', ['limit' => 20, 'null' => false])->create();\n    }\n}\n");
        file_put_contents($this->dir . '/Dockerfile', "FROM {$this->imageN}\nCOPY e2e_marker.php /app/system/database/migrations/" . self::MIGRATION . "_e2e_marker.php\n");
        $this->mustRun(['docker', 'build', '-q', '-t', $this->imageN1, $this->dir], timeout: 600);

        // boot N, install through the real web installer, write data
        putenv('TALEA_E2E_IMAGE=' . $this->imageN);
        $this->mustRun($this->compose('up', '-d'), [], 600);
        $this->waitFor(fn () => $this->http('/install.php')[0] === 200, 'the installer to answer');
        [$status, $body] = $this->http('/install.php', [
            'site_name' => 'E2E Ltd', 'starter' => 'business', 'username' => 'admin', 'name' => 'Tester', 'email' => '',
            'password' => 'e2e-Admin-pw-123', 'password2' => 'e2e-Admin-pw-123', 'language' => 'en', 'extensions' => ['news'],
        ]);
        $this->assertStringContainsString('Done, your website is running', $body, "installer failed ($status): " . mb_substr(strip_tags($body), 0, 600));
        $this->sql("INSERT INTO tl_pages (slug, title, text, in_menu) VALUES ('e2e-page', 'Survives the update', 'kept', 0)");
        $this->assertSame('ok', trim($this->http('/health')[1]));
        $before = (int) $this->sql('SELECT COUNT(*) FROM tl_migrations');
        $this->assertGreaterThan(0, $before);
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'tl_e2e_marker'"));

        // update: only the web container is replaced, volumes and database stay
        putenv('TALEA_E2E_IMAGE=' . $this->imageN1);
        $this->mustRun($this->compose('up', '-d', '--no-deps', 'web'), [], 600);
        $this->waitFor(fn () => $this->http('/health')[0] === 200, 'N+1 to report /health 200');

        $this->assertSame('ok', trim($this->http('/health')[1]));
        $this->assertSame((string) ($before + 1), $this->sql('SELECT COUNT(*) FROM tl_migrations'), 'exactly the new migration was applied');
        $this->assertSame('1', $this->sql("SELECT COUNT(*) FROM tl_migrations WHERE version = '" . self::MIGRATION . "'"));
        $this->assertSame('1', $this->sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'tl_e2e_marker'"));
        $this->assertSame('Survives the update', $this->sql("SELECT title FROM tl_pages WHERE slug = 'e2e-page'"), 'data written on N is still there');
        $this->assertStringContainsString('Survives the update', $this->http('/e2e-page')[1], 'the page is served by N+1');
        $dumps = $this->mustRun($this->compose('exec', '-T', 'web', 'sh', '-c', 'ls /app/storage/backups'))[0];
        $this->assertNotSame('', trim($dumps), 'the before-update dump exists in storage/backups');
    }

    // ---- helpers

    /** @return list<string> */
    private function compose(string ...$args): array
    {
        return ['docker', 'compose', '-p', $this->project, '-f', $this->dir . '/compose.yaml', ...$args];
    }

    /** @return string one value of a query in the db container */
    private function sql(string $query): string
    {
        [$out] = $this->mustRun($this->compose('exec', '-T', '-e', 'MYSQL_PWD=' . self::PASSWORD, 'db', 'mysql', '-utalea', 'talea', '-N', '-B', '-e', $query));

        return trim($out);
    }

    /** @return array{0: int, 1: string} status and body ('' / 0 when nothing answers) */
    private function http(string $path, ?array $post = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0,
            'header' => $post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post === null ? '' : http_build_query($post),
        ]]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}$path", false, $context);
        if ($body === false) {
            return [0, ''];
        }

        return [(int) (preg_match('#HTTP/\S+ (\d+)#', (string) ($http_response_header[0] ?? ''), $m) ? $m[1] : 0), $body];
    }

    private function waitFor(callable $condition, string $what, int $seconds = 180): void
    {
        for ($end = time() + $seconds; time() < $end; sleep(2)) {
            if ($condition()) {
                return;
            }
        }
        $this->fail("Timed out waiting for $what. Web log:\n" . $this->exec($this->compose('logs', '--tail', '40', 'web'))[0]);
    }

    /** @param list<string> $command @param array<string, string> $env @return array{0: string, 1: string, 2: int} stdout, stderr, exit code */
    private function exec(array $command, array $env = [], int $timeout = 120): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            return ['', 'cannot start ' . $command[0], 127];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        for ($end = time() + $timeout; ; usleep(50000)) {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running'] || time() > $end) {
                break;
            }
        }
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $running = proc_get_status($process)['running'];
        $running && proc_terminate($process);
        $code = proc_close($process);

        return [$out, $err, $running ? 124 : $code];
    }

    /** @param list<string> $command @param array<string, string> $env @return array{0: string, 1: string} */
    private function mustRun(array $command, array $env = [], int $timeout = 120): array
    {
        [$out, $err, $code] = $this->exec($command, $env, $timeout);
        $this->assertSame(0, $code, implode(' ', array_slice($command, 0, 6)) . " failed ($code):\n" . $out . $err);

        return [$out, $err];
    }
}
