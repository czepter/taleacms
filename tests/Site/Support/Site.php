<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

use PDO;
use Talea\Tests\Support\TestDatabase;

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

    /** @param array<string, mixed> $options web ('export' = the empty site for a move), blueprint (installer's business type), extensions (installer checkboxes), prefix, siteName, language (installer language), installerFields (extra/replaced installer POST fields), doneText (text of the finished screen in that language) */
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
        $server = TestDatabase::server();
        $admin = TestDatabase::admin();

        $this->work = sys_get_temp_dir() . '/talea-site-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->work . '/jars', 0775, true);
        $this->root = $this->work . '/web';
        $templateBuild = $this->options['_templateBuild'] ?? null;
        $this->database = $templateBuild ?? 'talea_site_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->keepDatabase = $templateBuild !== null;
        // an installed site is built once per run and set of installer options and cloned for every class; freshInstall runs the real installer
        $template = $templateBuild === null && !($this->options['freshInstall'] ?? false) ? self::template($this->options, $server, $admin) : null;
        // PostgreSQL clones the template database natively, SQLite copies the file; MySQL gets an empty one and cloneTemplate() copies the tables
        $this->cloneLocked($admin, $template);
        $this->pdo = TestDatabase::connect($this->database);

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
            'TALEA_AI_URL' => 'http://127.0.0.1:' . $fake . '/ai/v1/messages', 'TALEA_ANTISPAM_MIN' => '1', 'TALEA_IMPORT_LOCAL' => '1', 'TALEA_FIREWALL_LOCAL' => '1', 'TALEA_LINKS_LOCAL' => '1', 'TALEA_FLEET_LOCAL' => '1',
        ] + ($this->options['env'] ?? []), $this->port);

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
        $this->setting('extensions', $this->options['enabledExtensions'] ?? 'news,enquiries,newsletter_signup,stats,redirects,page_check,assistant,languages,claude');
    }

    // ---- installed-site template (built once per run, cloned per class)

    private const string TEMPLATE_PASSWORD = 'Template-Pw-1';

    /** A template of another code version is dropped only when its marker is older than this: a whole run takes well under it. */
    private const int STALE_TEMPLATE_SECONDS = 4 * 3600;

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
        $code = substr(md5($fingerprint . md5_file(__FILE__) . TestDatabase::driver()), 0, 8); // the engine too: the marker file is shared by both servers // the installer defaults of this harness are part of what a template is
        $key = substr(md5((string) json_encode(array_intersect_key($options, array_flip(['web', 'blueprint', 'extensions', 'prefix', 'siteName', 'language', 'installerFields', 'doneText'])))), 0, 8);
        $name = "talea_tpl_{$code}_{$key}";
        $marker = sys_get_temp_dir() . "/$name.json";
        $lock = fopen(sys_get_temp_dir() . "/$name.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            // The marker lives in the host's temp dir, the database on the server. A self-hosted runner keeps the temp dir between
            // jobs while every job gets a fresh database service; an unchanged fingerprint (same files, same mtimes) then found a
            // marker whose database was gone and every clone failed with "Unknown database 'talea_tpl_…'". The marker only counts
            // when the database is there too.
            if (!is_file($marker) || TestDatabase::find($admin, str_replace('_', '\\_', $name)) === []) {
                foreach (TestDatabase::find($admin, 'talea\\_tpl\\_%') as $old) {
                    // Only templates of an earlier run go. The fingerprint above changes when a file under system/ or image/ is
                    // created or touched while the suite runs; a worker that then finds no marker for its new fingerprint used to
                    // drop the template the other workers were cloning from ("Unknown database 'talea_tpl_…'", 100+ errors).
                    // A template whose marker is younger than STALE_TEMPLATE_SECONDS may still be in use.
                    $oldMarker = sys_get_temp_dir() . '/' . $old . '.json';
                    if (!str_starts_with($old, "talea_tpl_{$code}_") && (!is_file($oldMarker) || filemtime($oldMarker) < time() - self::STALE_TEMPLATE_SECONDS)) {
                        TestDatabase::drop($admin, $old);
                        @unlink($oldMarker);
                    }
                }
                TestDatabase::drop($admin, $name);
                $built = new self(array_intersect_key($options, array_flip(['web', 'blueprint', 'extensions', 'prefix', 'siteName', 'language', 'installerFields', 'doneText'])) + ['_templateBuild' => $name]);
                $config = require $built->root . '/config.php';
                $ddl = [];
                foreach (TestDatabase::isMySql() ? $built->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : [] as $table) { // PostgreSQL and SQLite clone the whole database instead
                    $ddl[(string) $table] = (string) $built->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
                }
                file_put_contents($marker . '.tmp', json_encode(['password' => $built->password, 'body' => $built->installerResponse->body, 'base' => $built->base, 'config' => $config, 'ddl' => $ddl]));
                $built->close();
                TestDatabase::disconnectOthers($admin, $name); // a template database must have no sessions
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
        if (TestDatabase::isMySql()) {
            $this->copyTables($template);
        }
        $config = $template['config'];
        $config['db']['name'] = TestDatabase::isSqlite() ? 'storage/database/' . $this->database . '.sqlite' : $this->database;
        file_put_contents($this->root . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
        @unlink($this->root . '/install.php'); // the installer deletes itself when it is done
    }

    /** @param array<string, mixed> $template */
    private function copyTables(array $template): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($template['ddl'] as $table => $create) {
            $this->pdo->exec($create);
            $this->pdo->exec('INSERT INTO `' . $table . '` SELECT * FROM `' . $template['database'] . '`.`' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Creates this site's database: empty, or (PostgreSQL, SQLite, with a template) a clone of it. Clones of one template are made one at a time: the source must be quiet while it is copied. @param array<string, mixed>|null $template */
    private function cloneLocked(PDO $admin, ?array $template): void
    {
        if ($template === null || TestDatabase::isMySql()) {
            TestDatabase::create($admin, $this->database);

            return;
        }
        $lock = fopen(sys_get_temp_dir() . '/' . $template['database'] . '.clone.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            TestDatabase::create($admin, $this->database, $template['database']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
        $statement = $this->pdo->prepare(TestSql::forEngine($sql, $this->pdo));
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** First column of the first row (null when none). @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare(TestSql::forEngine($sql, $this->pdo));
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param list<mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare(TestSql::forEngine($sql, $this->pdo));
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
        $this->exec(strtr(TestDatabase::dialect()->upsert('settings', ['name', 'value'], ['name'], ['value']), ['{settings}' => TestDatabase::dialect()->quote('tl_settings')]), [$name, $value]);
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

    /**
     * Runs a PHP script of the site as the container would (cwd = its root), with extra environment variables; returns what it printed.
     *
     * @param list<string> $args @param array<string, string> $env
     */
    public function cli(array $args, array $env = []): string
    {
        $process = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, array_merge(getenv(), $env));
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
     * Starts `php -S` on a folder with a router script and waits until it runs. Stopped by close().
     *
     * A free port can be taken by another server before php -S binds it (parallel workers and CI jobs share one network): that
     * php -S then exits, and a check that only connects would reach the other server, so the test talks to the wrong site. Ready
     * therefore means: our process still runs and its log says it started. A port the caller did not choose is retried.
     *
     * @param array<string, string> $env
     */
    public function startPhp(string $dir, string $router, array $env = [], ?int $port = null): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $chosen = $port ?? $this->freePort();
            $log = $this->work . '/server-' . $chosen . '.log';
            $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=0', '-d', 'opcache.memory_consumption=128', '-S', '127.0.0.1:' . $chosen, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $dir, array_merge(getenv(), $env));
            $this->processes[] = $process;
            for ($i = 0; $i < 200 && proc_get_status($process)['running']; $i++) {
                if (str_contains((string) @file_get_contents($log), ') started')) {
                    $this->ports['php:' . $chosen] = $chosen;

                    return $chosen;
                }
                usleep(50_000);
            }
            if ($port !== null) {
                break;
            }
        }
        throw new \RuntimeException("The PHP server on port $chosen did not start ($router in $dir): " . trim((string) @file_get_contents($log)));
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
                TestDatabase::drop(TestDatabase::admin(), $this->database);
            }
            // MySQL keeps the connection of a dropped database open as long as this object lives (a test class may hold its site
            // in a static property): ended here, so a worker's connections do not pile up past max_connections (PostgreSQL's
            // DROP … WITH (FORCE) ends them already)
            if (isset($this->pdo) && TestDatabase::isMySql()) {
                TestDatabase::admin()->exec('KILL ' . (int) $this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
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
        if (TestDatabase::isSqlite()) {
            symlink(TestDatabase::sqliteFolder(), $this->root . '/storage/database'); // the installer keeps the file there; the harness reaches it by its name
        }
    }

    /** @param array<string, mixed> $server */
    private function install(array $server): Response
    {
        $visitor = $this->client('installer');
        // on a busy CI host the migrations took 85 seconds once: the default 60 cut the request off while the server went on installing
        $visitor->timeout = 300;
        $fields = [
            'db_driver' => $server['driver'], 'db_host' => $server['host'], 'db_port' => $server['port'], 'db_name' => $this->database . (TestDatabase::isSqlite() ? '.sqlite' : ''), 'db_user' => $server['username'], 'db_password' => $server['password'],
            'db_prefix' => $this->options['prefix'] ?? 'tl_', 'site_name' => $this->options['siteName'] ?? 'Test Company', 'blueprint' => $this->options['blueprint'] ?? (($this->options['web'] ?? '') === 'export' ? 'export' : ''),
            'username' => 'admin', 'name' => 'Tester', 'email' => '', 'password' => $this->password, 'password2' => $this->password,
            'extensions' => $this->options['extensions'] ?? ['news', 'enquiries', 'stats', 'redirects', 'page_check'],
        ];
        $fields['language'] = $this->options['language'] ?? 'en';
        // extra or replaced installer fields (German register, site language, e-mail …); a null value drops the field
        $fields = array_filter(array_replace($fields, (array) ($this->options['installerFields'] ?? [])), static fn ($v): bool => $v !== null);
        $answer = $visitor->post('/install.php', $fields);
        for ($try = 1; $answer->status === 0 && $visitor->lastErrno !== CURLE_OPERATION_TIMEDOUT && $try < 3; $try++) {
            // no answer at all (a connection refused or reset on a busy runner), so nothing was installed: ask again. Not after a
            // timeout: the server may still be installing, and a second request would meet a finished site (404)
            sleep(2);
            $answer = $visitor->post('/install.php', $fields);
        }
        if (!$answer->contains($this->options['doneText'] ?? 'Done, your website is running')) {
            $log = @file_get_contents($this->work . '/server-' . $this->port . '.log');
            throw new \RuntimeException('The installer failed (HTTP ' . $answer->status . ', ' . strlen($answer->text()) . ' bytes' . ($visitor->lastError !== null ? ', ' . $visitor->lastError : '')
                . ($answer->text() === '' ? ', an empty answer: a PHP fatal error, a time-out or a killed worker – see the PHP log of the runner' : '') . '): ' . mb_substr($answer->text(), 0, 1100)
                . ($log !== false && $log !== '' ? "\nServer log: " . mb_substr($log, -1500) : ''));
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
