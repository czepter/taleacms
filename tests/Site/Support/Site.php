<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

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
    /** The installer's answer ("Done, the site is running …" with the cron line, or the export hand-over). */
    public readonly Response $installerResponse;

    /** @var list<resource> */
    private array $processes = [];
    /** @var array<string, int> named extra ports (captcha, fake services…) */
    private array $ports = [];
    private Http $admin;
    private string $work;
    private bool $closed = false;
    private bool $keepDatabase = false;

    /** @param array<string, mixed> $options web (starter site), extensions (installer checkboxes), prefix, siteName, language (installer language), installerFields (extra/replaced installer POST fields), doneText (text of the finished screen in that language) */
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
        $server = ['host' => (string) getenv('TALEA_TEST_DB_HOST'), 'port' => (int) getenv('TALEA_TEST_DB_PORT'), 'username' => (string) getenv('TALEA_TEST_DB_USER'), 'password' => (string) getenv('TALEA_TEST_DB_PASSWORD')];
        $admin = new PDO(sprintf('mysql:host=%s;port=%d', $server['host'], $server['port']), $server['username'], $server['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->work = sys_get_temp_dir() . '/talea-site-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->work . '/jars', 0775, true);
        $this->root = $this->work . '/web';
        $templateBuild = $this->options['_templateBuild'] ?? null;
        $this->database = $templateBuild ?? 'talea_site_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->keepDatabase = $templateBuild !== null;
        // an installed site is built once per run and set of installer options and cloned for every class; freshInstall runs the real installer
        $template = $templateBuild === null && !($this->options['freshInstall'] ?? false) ? self::template($this->options, $server, $admin) : null;
        $admin->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $server['host'], $server['port'], $this->database), $server['username'], $server['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->copyProject($project);
        $this->ports = ['captcha' => $this->freePort(), 'fake' => $this->freePort()];
        $fake = $this->startPhp($project . '/tools', 'fake-services.php', [], $this->ports['fake']);
        $this->port = $this->freePort();
        $this->base = 'http://127.0.0.1:' . $this->port;
        if ($template !== null) {
            $this->cloneTemplate($template);
        }
        $this->startPhp($this->root, 'system/dev-router.php', [
            'TALEA_CAPTCHA_VERIFY' => 'http://127.0.0.1:' . $this->ports['captcha'] . '/', 'TALEA_CONNECTORS_FAKE' => 'http://127.0.0.1:' . $fake,
            'TALEA_ANTISPAM_MIN' => '1', 'TALEA_IMPORT_LOCAL' => '1', 'TALEA_FIREWALL_LOCAL' => '1', 'TALEA_LINKS_LOCAL' => '1', 'TALEA_FLEET_LOCAL' => '1',
        ], $this->port);

        if ($template !== null) {
            $this->password = $template['password'];
            $this->installerResponse = new Response(200, str_replace($template['base'], $this->base, $template['body']), '', []);
            $this->exec("UPDATE tl_settings SET value = ? WHERE name = 'site_url'", [$this->base]);
        } else {
            $this->password = $templateBuild !== null ? self::TEMPLATE_PASSWORD : 'Test-' . bin2hex(random_bytes(6)) . '-pw';
            $this->installerResponse = $this->install($server);
        }
        if ($templateBuild !== null) {
            return; // the template is only the installed state: no sign-in, token or per-class settings
        }
        $this->admin = $this->client('admin');
        if (($this->options['login'] ?? true) !== false) {
            $this->signIn($this->admin);
        }
        $this->mcpToken = 'talea_' . bin2hex(random_bytes(24));
        $this->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tl_users WHERE username = 'admin'", [hash('sha256', $this->mcpToken)]);
        $this->setting('tasks_token', $this->tasksToken());
        $this->exec("INSERT INTO tl_settings VALUES ('extensions', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$this->options['enabledExtensions'] ?? 'news,enquiries,newsletter_signup,stats,redirects,assistant,languages,claude']);
    }

    // ---- installed-site template (built once per run, cloned per class)

    private const string TEMPLATE_PASSWORD = 'Template-Pw-1';

    /**
     * The installed state for these installer options: a database `talea_tpl_<code>_<options>` plus the installer's answer and config.php.
     * Built under a lock by the first class that needs it (the others wait); templates of older code are dropped.
     *
     * @param array<string, mixed> $options @param array<string, mixed> $server
     * @return array{database: string, password: string, body: string, base: string, config: array<string, mixed>, ddl: array<string, string>}
     */
    private static function template(array $options, array $server, PDO $admin): array
    {
        $project = dirname(__DIR__, 3);
        $fingerprint = '';
        foreach (array_filter(explode("\0", (string) shell_exec('cd ' . escapeshellarg($project) . ' && git ls-files -z --cached --others --exclude-standard -- system image'))) as $file) {
            $fingerprint .= $file . @filemtime($project . '/' . $file) . @filesize($project . '/' . $file);
        }
        $code = substr(md5($fingerprint . md5_file(__FILE__)), 0, 8); // the installer defaults of this harness are part of what a template is
        $key = substr(md5((string) json_encode(array_intersect_key($options, array_flip(['web', 'extensions', 'prefix', 'siteName', 'language', 'installerFields', 'doneText'])))), 0, 8);
        $name = "talea_tpl_{$code}_{$key}";
        $marker = sys_get_temp_dir() . "/$name.json";
        $lock = fopen(sys_get_temp_dir() . "/$name.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            if (!is_file($marker)) {
                foreach ($admin->query("SHOW DATABASES LIKE 'talea\\_tpl\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
                    if (!str_starts_with((string) $old, "talea_tpl_{$code}_")) {
                        $admin->exec('DROP DATABASE IF EXISTS `' . $old . '`');
                        @unlink(sys_get_temp_dir() . '/' . $old . '.json');
                    }
                }
                $admin->exec('DROP DATABASE IF EXISTS `' . $name . '`');
                $built = new self(array_intersect_key($options, array_flip(['web', 'extensions', 'prefix', 'siteName', 'language', 'installerFields', 'doneText'])) + ['_templateBuild' => $name]);
                $config = require $built->root . '/config.php';
                $ddl = [];
                foreach ($built->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    $ddl[(string) $table] = (string) $built->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
                }
                file_put_contents($marker . '.tmp', json_encode(['password' => $built->password, 'body' => $built->installerResponse->body, 'base' => $built->base, 'config' => $config, 'ddl' => $ddl]));
                $built->close();
                rename($marker . '.tmp', $marker);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['database' => $name] + json_decode((string) file_get_contents($marker), true);
    }

    /** Tables (structure with keys and foreign keys, then rows) and config.php of the template into this site's database and folder. @param array<string, mixed> $template */
    private function cloneTemplate(array $template): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($template['ddl'] as $table => $create) {
            $this->pdo->exec($create);
            $this->pdo->exec('INSERT INTO `' . $table . '` SELECT * FROM `' . $template['database'] . '`.`' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $config = $template['config'];
        $config['db']['name'] = $this->database;
        file_put_contents($this->root . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
        @unlink($this->root . '/install.php'); // the installer deletes itself when it is done
    }

    // ---- background jobs

    public const string TASKS_TOKEN = 'testtoken123';

    /** Runs the due background jobs the way web cron does (GET /tasks?token=…); returns the response body. */
    public function runTasks(string $query = ''): string
    {
        return $this->client('cron')->get('/tasks?token=' . $this->tasksToken() . $query)->body;
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
        $file = sys_get_temp_dir() . '/talea-fake-' . $this->port('fake') . '-' . $name . '.log';

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

    /** The public id (UUID v4) of a row, as a link or an MCP argument must carry it: tests look rows up by their integer key and address them by this. */
    public function publicId(string $table, int $id): string
    {
        $pk = \Talea\Core\Db::PRIMARY_KEYS[$table] ?? throw new \InvalidArgumentException("No public ids for $table");

        return (string) $this->value("SELECT public_id FROM tl_$table WHERE $pk = ?", [$id]);
    }

    /** The integer key (internal, only for tests that query the database) of the row with this public id. */
    public function internalId(string $table, string $uuid): int
    {
        $pk = \Talea\Core\Db::PRIMARY_KEYS[$table] ?? throw new \InvalidArgumentException("No public ids for $table");

        return (int) $this->value("SELECT $pk FROM tl_$table WHERE public_id = ?", [$uuid]);
    }

    /** The integer key of the row with this public id in whichever table holds it – for a test that reads an id out of a tool answer and queries the database with it. */
    public function rowId(string $uuid): int
    {
        foreach (\Talea\Core\Db::PRIMARY_KEYS as $table => $pk) {
            $id = $this->value("SELECT $pk FROM tl_$table WHERE public_id = ?", [$uuid]);
            if ($id !== null) {
                return (int) $id;
            }
        }

        return 0;
    }

    public function setting(string $name, string $value): void
    {
        $this->exec('INSERT INTO tl_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    }

    public function settingValue(string $name): string
    {
        return (string) $this->value('SELECT value FROM tl_settings WHERE name = ?', [$name]);
    }

    // ---- the site on disk

    /** The cached pages of anonymous visitors go (the old tests did this before every page check that follows a change). */
    public function clearPageCache(): void
    {
        foreach (glob($this->root . '/storage/cache/pages/*.html') ?: [] as $file) {
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
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=0', '-d', 'opcache.memory_consumption=128', '-S', '127.0.0.1:' . $port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->work . '/server-' . $port . '.log', 'w'], 2 => ['file', $this->work . '/server-' . $port . '.log', 'a']], $pipes, $dir, array_merge(getenv(), $env));
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
        // TALEA_TEST_ERRLOG=<file>: the application error logs of all test sites are collected there (to see the causes of a failed run at once)
        $collect = getenv('TALEA_TEST_ERRLOG');
        if ($collect !== false && $collect !== '' && isset($this->root) && is_file($this->root . '/storage/log/errors.log')) {
            file_put_contents($collect, (string) file_get_contents($this->root . '/storage/log/errors.log'), FILE_APPEND | LOCK_EX);
        }
        foreach ($this->processes as $process) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        try {
            if (isset($this->pdo, $this->database) && !$this->keepDatabase) {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
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
            'db_prefix' => $this->options['prefix'] ?? 'tl_', 'site_name' => $this->options['siteName'] ?? 'Test Company', 'starter' => $this->options['web'] ?? 'business',
            'username' => 'admin', 'name' => 'Tester', 'email' => '', 'password' => $this->password, 'password2' => $this->password,
            'extensions' => $this->options['extensions'] ?? ['news', 'enquiries', 'stats', 'redirects'],
        ];
        $fields['language'] = $this->options['language'] ?? 'en';
        // extra or replaced installer fields (German register, site language, e-mail …); a null value drops the field
        $fields = array_filter(array_replace($fields, (array) ($this->options['installerFields'] ?? [])), static fn ($v): bool => $v !== null);
        $answer = $visitor->post('/install.php', $fields);
        if (!$answer->contains($this->options['doneText'] ?? 'Done, your website is running')) {
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
