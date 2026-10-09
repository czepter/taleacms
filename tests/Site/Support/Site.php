<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Support;

use PDO;

/**
 * One complete, isolated installation for a test class: a copy of the code in a temporary folder, its own database, its own PHP
 * server on a free port, the web installer run once, an administrator signed in and an MCP token. Nothing is shared with other
 * classes (or with other test processes), so classes can run in parallel.
 *
 * Replaces the shell walk tools/test.sh: what it did with curl and mysql, a test now does with this object.
 */
final class Site
{
    public readonly string $root;
    public readonly string $base;
    public readonly string $database;
    public readonly string $password;
    public readonly string $mcpToken;
    public readonly PDO $pdo;
    public readonly int $port;
    /** The installer's answer ("Hotovo, web běží …" with the cron line, or the export hand-over). */
    public readonly Response $installerResponse;

    /** @var list<resource> */
    private array $processes = [];
    /** @var array<string, int> named extra ports (captcha, fake services…) */
    private array $ports = [];
    private Http $admin;
    private string $work;
    private bool $closed = false;

    /** @param array<string, mixed> $options web (starter site), extensions (installer checkboxes), prefix, siteName */
    public static function boot(array $options = []): self
    {
        return new self($options);
    }

    /** @param array<string, mixed> $options */
    private function __construct(private readonly array $options)
    {
        // whatever the constructor has started is stopped even when it fails halfway (a failed installer must not leak servers or databases)
        register_shutdown_function(fn () => $this->close());
        $project = dirname(__DIR__, 3);
        $server = ['host' => (string) getenv('KALETA_TEST_DB_HOST'), 'port' => (int) getenv('KALETA_TEST_DB_PORT'), 'username' => (string) getenv('KALETA_TEST_DB_USER'), 'password' => (string) getenv('KALETA_TEST_DB_PASSWORD')];
        $admin = new PDO(sprintf('mysql:host=%s;port=%d', $server['host'], $server['port']), $server['username'], $server['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->work = sys_get_temp_dir() . '/kaleta-site-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->work . '/jars', 0775, true);
        $this->root = $this->work . '/web';
        $this->database = 'kaleta_site_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $server['host'], $server['port'], $this->database), $server['username'], $server['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->copyProject($project);
        $this->ports = ['captcha' => $this->freePort(), 'fake' => $this->freePort()];
        $fake = $this->startPhp($project . '/tools', 'fake-services.php', [], $this->ports['fake']);
        $this->port = $this->freePort();
        $this->base = 'http://127.0.0.1:' . $this->port;
        $this->startPhp($this->root, 'system/dev-router.php', [
            'KALETA_CAPTCHA_VERIFY' => 'http://127.0.0.1:' . $this->ports['captcha'] . '/', 'KALETA_CONNECTORS_FAKE' => 'http://127.0.0.1:' . $fake,
            'KALETA_IMPORT_LOCAL' => '1', 'KALETA_FIREWALL_LOCAL' => '1', 'KALETA_LINKS_LOCAL' => '1', 'KALETA_FLEET_LOCAL' => '1',
        ], $this->port);

        $this->password = 'Test-' . bin2hex(random_bytes(6)) . '-pw';
        $this->installerResponse = $this->install($server);
        $this->admin = $this->client('admin');
        if (($this->options['login'] ?? true) !== false) {
            $this->signIn($this->admin);
        }
        $this->mcpToken = 'kaleta_' . bin2hex(random_bytes(24));
        $this->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM ka_users WHERE username = 'admin'", [hash('sha256', $this->mcpToken)]);
        $this->setting('tasks_token', $this->tasksToken());
        $this->exec("INSERT INTO ka_settings VALUES ('extensions', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$this->options['enabledExtensions'] ?? 'novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,claude']);
    }

    // ---- background jobs

    public const string TASKS_TOKEN = 'testtoken123';

    /** Runs the due background jobs the way web cron does (GET /ulohy?token=…); returns the response body. */
    public function runTasks(string $query = ''): string
    {
        return $this->client('cron')->get('/ulohy?token=' . $this->tasksToken() . $query)->body;
    }

    /** The cron token of this site (option tasksToken, default TASKS_TOKEN); a test that changes the setting itself should use settingValue('tasks_token'). */
    public function tasksToken(): string
    {
        return $this->options['tasksToken'] ?? self::TASKS_TOKEN;
    }

    // ---- browsers and requests

    /** A new browser with its own cookie jar (a second signed-in person, an anonymous visitor…). */
    public function client(string $name = 'visitor'): Http
    {
        return new Http($this->base, $this->work . '/jars', $name);
    }

    /** The administrator's browser, signed in. */
    public function admin(): Http
    {
        return $this->admin;
    }

    public function signIn(Http $client, string $user = 'admin', ?string $password = null): Response
    {
        $csrf = $client->get('/admin.php')->csrf();

        return $client->post('/admin.php', ['_csrf' => $csrf, 'username' => $user, 'password' => $password ?? $this->password]);
    }

    /** A fresh anti-forgery token of the signed-in administrator (taken from a page every admin screen shares). */
    public function csrf(?Http $as = null, string $path = '/admin.php'): string
    {
        return ($as ?? $this->admin)->get($path)->csrf();
    }

    /**
     * Calls an MCP tool and returns the decoded JSON-RPC answer.
     *
     * @param array<string, mixed>|object $arguments
     * @return array<string, mixed>
     */
    public function mcp(string $tool, array|object $arguments = [], ?string $token = null): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) (array) $arguments]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->mcpRaw($body, $token);
    }

    /** @return array<string, mixed> */
    public function mcpRaw(string $jsonRpc, ?string $token = null): array
    {
        $response = $this->client('mcp')->post('/mcp', $jsonRpc, ['Authorization: Bearer ' . ($token ?? $this->mcpToken), 'Content-Type: application/json']);

        return (array) $response->json();
    }

    /** The text a tool returned, decoded when it is JSON (arrays) or as it is. */
    public function mcpResult(string $tool, array|object $arguments = [], ?string $token = null): mixed
    {
        $text = $this->mcp($tool, $arguments, $token)['result']['content'][0]['text'] ?? '';
        $decoded = json_decode((string) $text, true);

        return $decoded ?? $text;
    }

    /** The raw text a tool returned (not decoded). */
    public function mcpText(string $tool, array|object $arguments = [], ?string $token = null): string
    {
        return (string) ($this->mcp($tool, $arguments, $token)['result']['content'][0]['text'] ?? '');
    }

    /** The request log the fake outside-services server wrote for $name (oauth, search, sheets …); empty when there is none. */
    public function fakeLog(string $name): string
    {
        $file = sys_get_temp_dir() . '/kaleta-fake-' . $this->port('fake') . '-' . $name . '.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    // ---- database

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** First column of the first row (null when none). @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param list<mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function setting(string $name, string $value): void
    {
        $this->exec('INSERT INTO ka_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    }

    public function settingValue(string $name): string
    {
        return (string) $this->value('SELECT value FROM ka_settings WHERE name = ?', [$name]);
    }

    // ---- the site on disk

    /** The cached pages of anonymous visitors go (the old tests did this before every page check that follows a change). */
    public function clearPageCache(): void
    {
        foreach (glob($this->root . '/storage/cache/stranky/*.html') ?: [] as $file) {
            unlink($file);
        }
    }

    public function path(string $relative = ''): string
    {
        return $this->root . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /** A folder for test files (fake servers' logs, uploads) inside this site's temporary space. */
    public function workDir(string $name): string
    {
        $dir = $this->work . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Runs PHP code inside the site (cwd = its root, bootstrap loaded); returns what it printed. */
    public function php(string $code): string
    {
        $process = proc_open([PHP_BINARY, '-r', 'chdir($argv[1]); require "system/bootstrap.php"; ' . $code, $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }

    // ---- helper servers

    public function port(string $name): int
    {
        return $this->ports[$name] ??= $this->freePort();
    }

    /**
     * Starts `php -S` on a folder with a router script and waits until it answers. Stopped by close().
     *
     * @param array<string, string> $env
     */
    public function startPhp(string $dir, string $router, array $env = [], ?int $port = null): int
    {
        $port ??= $this->freePort();
        $this->ports['php:' . $port] = $port;
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->work . '/server-' . $port . '.log', 'w'], 2 => ['file', $this->work . '/server-' . $port . '.log', 'a']], $pipes, $dir, array_merge(getenv(), $env));
        $this->processes[] = $process;
        for ($i = 0; $i < 100; $i++) {
            if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2) !== false) {
                return $port;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("The PHP server on port $port did not start ($router in $dir).");
    }

    /**
     * Starts any command (a CLI fake such as tools/fake-smtp.php) and returns a handle for stopProcess(); stopped by close() at the latest.
     *
     * @param list<string> $command @param array<string, string> $env
     */
    public function startProcess(array $command, array $env = [], ?string $cwd = null): int
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->work . '/process-' . count($this->processes) . '.log', 'w'], 2 => ['file', $this->work . '/process-' . count($this->processes) . '.log', 'a']], $pipes, $cwd ?? $this->root, array_merge(getenv(), $env));
        $this->processes[] = $process;

        return count($this->processes) - 1;
    }

    /** Stops a process started with startProcess() (or the server of startPhp(), by the index startPhp() does not return: use startProcess for things you must stop). */
    public function stopProcess(int $handle): void
    {
        if (isset($this->processes[$handle]) && is_resource($this->processes[$handle])) {
            proc_terminate($this->processes[$handle]);
            proc_close($this->processes[$handle]);
        }
    }

    public function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    /** Stops every server, drops the database, deletes the temporary folder. Called after the class (and at shutdown). */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->processes as $process) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        try {
            if (isset($this->pdo, $this->database)) {
                $this->pdo->exec('DROP DATABASE `' . $this->database . '`');
            }
        } catch (\Throwable) {
        }
        if (isset($this->work)) {
            $this->removeDirectory($this->work);
        }
    }

    // ---- internals

    private function copyProject(string $project): void
    {
        $files = (string) shell_exec('cd ' . escapeshellarg($project) . ' && git ls-files -z --cached --others --exclude-standard');
        foreach (array_filter(explode("\0", $files)) as $relative) {
            // the site does not need the project's tools, docs or tests; vendor/ is linked below (git does not carry it anyway)
            if (preg_match('#^(tools|docs|tests|\.github|\.claude)/#', $relative) === 1 || !is_file($project . '/' . $relative)) {
                continue;
            }
            $target = $this->root . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($project . '/' . $relative, $target);
        }
        foreach (['media', 'storage/log', 'storage/cache', 'storage/import', 'extensions'] as $dir) {
            if (!is_dir($this->root . '/' . $dir)) {
                mkdir($this->root . '/' . $dir, 0775, true);
            }
        }
        symlink($project . '/vendor', $this->root . '/vendor');
    }

    /** @param array<string, mixed> $server */
    private function install(array $server): Response
    {
        $visitor = $this->client('installer');
        $fields = [
            'db_host' => $server['host'], 'db_port' => $server['port'], 'db_name' => $this->database, 'db_user' => $server['username'], 'db_password' => $server['password'],
            'db_prefix' => $this->options['prefix'] ?? 'ka_', 'nazev_webu' => $this->options['siteName'] ?? 'Testovací firma', 'web' => $this->options['web'] ?? 'firemni',
            'username' => 'admin', 'jmeno' => 'Tester', 'email' => '', 'password' => $this->password, 'password2' => $this->password,
            'rozsireni' => $this->options['extensions'] ?? ['novinky', 'poptavky', 'statistika', 'presmerovani'],
        ];
        $answer = $visitor->post('/install.php', $fields);
        if (!$answer->contains('Hotovo, web běží')) {
            throw new \RuntimeException('The installer failed: ' . mb_substr($answer->text(), 0, 1100));
        }

        return $answer;
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir($dir . '/' . $entry) && !is_link($dir . '/' . $entry) ? $this->removeDirectory($dir . '/' . $entry) : @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
