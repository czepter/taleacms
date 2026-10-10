<?php

declare(strict_types=1);

namespace Talea\Extension;

use Talea\Core\App;
use Talea\Core\Events;
use Talea\Core\Response;
use Talea\Core\Settings;

/**
 * Add-ons (3.0): code from other developers in extensions/<slug>/, switched on by an administrator in Add-ons. Official add-ons
 * (BUNDLED) are shipped in the release in the same folder, off by default, and are replaced by updates; every other folder is the
 * site's own and updates never touch it.
 *
 * Trust: an add-on is PHP that runs with the same rights as Talea, like a WordPress plug-in – Add-ons says so before it
 * is switched on. Talea never uploads code from the administration and never fetches it from the internet: an add-on is
 * copied into extensions/ by whoever manages the hosting. What Talea does guarantee:
 *  - an add-on that throws while loading or registering is switched off at once and the error is shown in Add-ons
 *    (so a broken add-on never takes the site down twice);
 *  - an add-on needs the API version it was written for (extension.json → requires.api) and a Talea version that
 *    satisfies requires.talea; otherwise it is not loaded;
 *  - its tools for Claude go through the connection's access and the guardrails like Talea's own tools;
 *  - nothing is loaded in the public demo or when config.php says 'addons' => false (a safe mode for the hosting admin).
 */
final class Registry
{
    public const string DIR = TALEA_ROOT . '/extensions';

    /** Official add-ons that ship in extensions/ inside the release (slug = folder): off by default, switched on in Add-ons, updated with Talea. */
    public const array BUNDLED = ['domain_watch', 'firewall'];

    /** Seconds an early request hook (Api::earlyRequest) may take before it counts as failed and is switched off. */
    public const float EARLY_BUDGET = 0.25;

    private static ?self $instance = null;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var array<string, list<callable>> */
    private array $filters = [];

    /** @var array<string, callable> "slug.name" => render */
    private array $tokens = [];

    /** @var array<string, array{slug: string, name: string, title: string, render: callable}> "slug.name" => page */
    private array $pages = [];

    /** @var array<string, array{name: string, description: string, inputSchema: array<string, mixed>, access: string, handler: callable, requires: string}> */
    private array $tools = [];

    /** @var array<string, array{0: int, 1: string, 2: string, 3: callable}> name => [interval, runner, label, run] */
    private array $jobs = [];

    /** @var array<string, list<callable>> slug => hooks that run first in a public request (API 2) */
    private array $early = [];

    /** @var array<string, list<callable>> hooks for requests that end in 404 (Api::notFound), by slug */
    private array $notFound = [];

    /** @var array<string, callable> slug => rows for System status (API 2) */
    private array $healthRows = [];

    /** @var array<string, callable> slug => hand-over findings (API 2) */
    private array $handover = [];

    /** @var array<string, string> type => description of the event types of add-ons (API 2) */
    private array $eventTypes = [];

    /** @var list<string> types of add-ons whose warnings are worth an alert e-mail (API 2) */
    private array $alertTypes = [];

    /** @var array<string, array<string, array{label: string, type: string, default: string, help: string}>> slug => declared settings (API 2) */
    private array $schemas = [];

    private bool $dispatching = false;

    /** The add-ons of this request (empty until boot()). */
    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** Loads the switched-on add-ons once per request. */
    public static function boot(App $app): self
    {
        $registry = self::get();
        static $booted = false;
        if ($booted) {
            return $registry;
        }
        $booted = true;
        if (\Talea\Core\Demo::active() || ($app->config['addons'] ?? true) === false) {
            return $registry;
        }
        $manifests = self::discover();
        foreach (self::enabled($app->settings()) as $slug) {
            $manifest = $manifests[$slug] ?? null;
            if ($manifest === null || $manifest['problem'] !== '') {
                continue;
            }
            try {
                require_once $manifest['path'] . '/' . $manifest['entry'];
                $class = $manifest['class'];
                if (!class_exists($class) || !is_subclass_of($class, ExtensionInterface::class)) {
                    throw new \RuntimeException('The class ' . $class . ' does not implement Talea\Extension\ExtensionInterface.');
                }
                (new $class())->register(new Api($registry, $slug, $app, $manifest['requires_api'], $manifest['capabilities']));
            } catch (\Throwable $e) {
                self::fail($app, $slug, $e);
            }
        }

        return $registry;
    }

    /**
     * Add-ons found in extensions/: slug => manifest with 'problem' ('' = can be switched on).
     *
     * @return array<string, array{slug: string, name: string, version: string, description: string, author: string, url: string, class: string, entry: string, path: string, requires_talea: string, requires_api: int, capabilities: list<string>, table_prefix: string, has_tables: bool, bundled: bool, problem: string}>
     */
    public static function discover(): array
    {
        $found = [];
        $prefixes = [];
        foreach (glob(self::DIR . '/*/extension.json') ?: [] as $file) {
            $path = dirname($file);
            $slug = basename($path);
            $json = json_decode((string) file_get_contents($file), true);
            $json = is_array($json) ? $json : [];
            $text = fn (string $key, int $max): string => is_scalar($json[$key] ?? null) ? mb_substr(trim((string) $json[$key]), 0, $max) : '';
            $declared = is_array($json['capabilities'] ?? null) ? array_values(array_intersect(array_map('strval', $json['capabilities']), array_keys(Api::CAPABILITIES))) : [];
            $manifest = ['slug' => $slug, 'name' => $text('name', 80) ?: $slug, 'version' => $text('version', 20), 'description' => $text('description', 300),
                'author' => $text('author', 100), 'url' => $text('url', 200), 'class' => $text('class', 150), 'entry' => $text('entry', 100) ?: 'Extension.php', 'path' => $path,
                'requires_talea' => is_scalar($json['requires']['talea'] ?? null) ? (string) $json['requires']['talea'] : '', 'requires_api' => (int) ($json['requires']['api'] ?? $json['api'] ?? 0),
                'capabilities' => $declared, 'table_prefix' => $text('table_prefix', 30) ?: $slug, 'bundled' => in_array($slug, self::BUNDLED, true), 'problem' => '',
                'has_tables' => is_file($path . '/install.sql') || (glob($path . '/migrations/*.sql') ?: []) !== []];
            $manifest['problem'] = match (true) {
                preg_match('/^[a-z][a-z0-9_]{1,30}$/', $slug) !== 1 => 'The folder name must be lowercase letters, digits and _ (it is the add-on\'s slug).',
                $json === [] => 'extension.json is not valid JSON.',
                $manifest['class'] === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $manifest['class']) !== 1 => 'extension.json has no valid "class".',
                preg_match('/^[A-Za-z0-9_\/.-]+\.php$/', $manifest['entry']) !== 1 || str_contains($manifest['entry'], '..') || !is_file($path . '/' . $manifest['entry']) => 'The entry file is missing or not a .php file inside the add-on.',
                !in_array($manifest['requires_api'], Api::SUPPORTED, true) => 'It is written for extension API ' . $manifest['requires_api'] . ', this Talea has API ' . Api::VERSION . '.',
                !self::satisfies(TALEA_VERSION, $manifest['requires_talea']) => 'It needs Talea ' . $manifest['requires_talea'] . '.',
                preg_match('/^[a-z][a-z0-9_]{1,30}$/', $manifest['table_prefix']) !== 1 => 'extension.json has no valid "table_prefix".',
                $manifest['requires_api'] >= 2 && $manifest['has_tables'] && !in_array('tables', $declared, true) => 'It has database tables but does not declare "tables" in its capabilities.',
                default => '',
            };
            if ($manifest['problem'] === '' && $manifest['requires_api'] >= 2) {
                // two add-ons may not share a table prefix (the first one in the alphabet keeps it)
                if (isset($prefixes[$manifest['table_prefix']])) {
                    $manifest['problem'] = 'The table prefix ' . $manifest['table_prefix'] . ' is already used by the add-on ' . $prefixes[$manifest['table_prefix']] . '.';
                } else {
                    $prefixes[$manifest['table_prefix']] = $slug;
                }
            }
            $found[$slug] = $manifest;
        }
        ksort($found);

        return $found;
    }

    /** "3.0", ">=3.0", ">=3.0 <4.0" – an empty requirement is satisfied. */
    public static function satisfies(string $version, string $requirement): bool
    {
        foreach (preg_split('/\s+/', trim($requirement)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=|\^)?(\d+(?:\.\d+){0,2})$/', $part, $m) !== 1) {
                return false;
            }
            $operator = $m[1] !== '' ? $m[1] : '>=';
            $ok = $operator === '^' ? version_compare($version, $m[2], '>=') && (int) $version === (int) $m[2] : version_compare($version, $m[2], $operator === '=' ? '==' : $operator);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        return array_values(array_filter(explode(',', $settings->get('addons_enabled')), fn (string $s): bool => $s !== ''));
    }

    /**
     * Switches an add-on on: its install.sql (CREATE TABLE IF NOT EXISTS {ext_<prefix>_…}) and, for API 2, its migrations run, its
     * onEnable() hook (LifecycleInterface) runs, then it loads from the next request.
     */
    public static function enable(App $app, string $slug): void
    {
        $manifest = self::discover()[$slug] ?? throw new \InvalidArgumentException('The add-on is not in extensions/.');
        if ($manifest['problem'] !== '') {
            throw new \DomainException($manifest['problem']);
        }
        $own = 'ext_' . self::tablePrefix($manifest) . '_';
        $sql = $manifest['path'] . '/install.sql';
        if (is_file($sql)) {
            foreach (\Talea\Core\SqlScript::statements((string) file_get_contents($sql), $app->db()->prefix) as $statement) {
                if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?' . preg_quote($app->db()->prefix . $own, '/') . '[a-z0-9_]+`?\s*\(/i', $statement) !== 1) {
                    throw new \DomainException('install.sql may only CREATE TABLE IF NOT EXISTS {' . $own . '…} tables.');
                }
                $app->db()->pdo()->exec($statement);
            }
        }
        if ($manifest['requires_api'] >= 2) {
            self::migrate($app, $manifest);
            $class = self::load($manifest);
            if (is_subclass_of($class, LifecycleInterface::class)) {
                try {
                    (new $class())->onEnable(new Api(self::get(), $slug, $app, $manifest['requires_api'], $manifest['capabilities']));
                } catch (\Throwable $e) {
                    throw new \DomainException(t('The add-on could not be switched on: %s', $e->getMessage()));
                }
            }
        }
        $app->settings()->set('addons_enabled', implode(',', array_unique([...self::enabled($app->settings()), $slug])));
        $app->settings()->set('addons_error.' . $slug, '');
        Events::record($app->db(), 'addon.enabled', 'info', t('The add-on %s was switched on.', $manifest['name']), ['addon' => $slug, 'version' => $manifest['version']]);
    }

    public static function disable(App $app, string $slug): void
    {
        $app->settings()->set('addons_enabled', implode(',', array_diff(self::enabled($app->settings()), [$slug])));
    }

    /** The table prefix of an add-on: "ext_<this>_<name>" are its tables. API 1 add-ons always use the slug. @param array<string, mixed> $manifest */
    public static function tablePrefix(array $manifest): string
    {
        return $manifest['requires_api'] >= 2 ? (string) $manifest['table_prefix'] : (string) $manifest['slug'];
    }

    /**
     * Runs the migrations of an API 2 add-on that did not run yet: migrations/NNNN-name.sql in order, each statement a CREATE TABLE,
     * CREATE INDEX, ALTER TABLE, DROP TABLE, INSERT, UPDATE or DELETE on the add-on's own tables ({ext_<prefix>_<name>}; the add-on's
     * indexes are named ext_<prefix>_…). Portable SQL: {pk} stands for the auto-numbered primary key column type of the engine. The number
     * of the last migration that ran is kept (addons_migrated.<slug>). Returns how many ran.
     *
     * @param array<string, mixed> $manifest
     */
    public static function migrate(App $app, array $manifest): int
    {
        $db = $app->db();
        $slug = (string) $manifest['slug'];
        $own = preg_quote('ext_' . self::tablePrefix($manifest) . '_', '/');
        $pk = $db->dialect()->autoKeyColumn();
        $done = (int) $app->settings()->get('addons_migrated.' . $slug);
        $ran = 0;
        $files = glob($manifest['path'] . '/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            if (preg_match('/^(\d{4})-[a-z0-9_-]+\.sql$/', basename($file), $m) !== 1) {
                throw new \DomainException('A migration is named NNNN-name.sql: ' . basename($file));
            }
            if ((int) $m[1] <= $done) {
                continue;
            }
            foreach (\Talea\Core\SqlScript::statements((string) file_get_contents($file), $db->prefix) as $statement) {
                $statement = str_replace('{pk}', $pk, trim((string) preg_replace('/^\s*--.*$/m', '', $statement))); // comment lines are not SQL
                if (preg_match('/^(CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE|DROP\s+TABLE(\s+IF\s+EXISTS)?|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+\{' . $own . '[a-z0-9_]+\}/i', $statement) !== 1
                    && preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?' . $own . '[a-z0-9_]+\s+ON\s+\{' . $own . '[a-z0-9_]+\}/i', $statement) !== 1) {
                    throw new \DomainException(basename($file) . ' may only change tables named {ext_' . self::tablePrefix($manifest) . '_…}.');
                }
                try {
                    $db->run($statement);
                } catch (\PDOException $e) {
                    throw new \DomainException(basename($file) . ': ' . $e->getMessage());
                }
            }
            $done = (int) $m[1];
            $app->settings()->set('addons_migrated.' . $slug, (string) $done);
            $ran++;
        }

        return $ran;
    }

    /** Is there a migration of an enabled add-on that did not run yet (a newer version of the add-on was copied in)? @param array<string, mixed> $manifest */
    public static function pendingMigrations(Settings $settings, array $manifest): int
    {
        $done = (int) $settings->get('addons_migrated.' . $manifest['slug']);

        return count(array_filter(glob($manifest['path'] . '/migrations/*.sql') ?: [], fn (string $f): bool => (int) basename($f) > $done));
    }

    /**
     * Uninstalls a switched-off add-on: its onUninstall() hook runs; with $deleteData its tables (the ones its migrations and install.sql
     * create), settings (ext.<slug>.*), job records and migration state are deleted too – without it everything stays for a later switch-on.
     * The folder stays: whoever manages the hosting removes the code.
     */
    public static function uninstall(App $app, string $slug, bool $deleteData): void
    {
        $manifest = self::discover()[$slug] ?? throw new \InvalidArgumentException('The add-on is not in extensions/.');
        if (in_array($slug, self::enabled($app->settings()), true)) {
            throw new \DomainException('Switch the add-on off first.');
        }
        if ($manifest['problem'] === '' && $manifest['requires_api'] >= 2) {
            $class = self::load($manifest);
            if (is_subclass_of($class, LifecycleInterface::class)) {
                try {
                    (new $class())->onUninstall(new Api(self::get(), $slug, $app, $manifest['requires_api'], $manifest['capabilities']), $deleteData);
                } catch (\Throwable $e) {
                    throw new \DomainException(t('The add-on could not be uninstalled: %s', $e->getMessage()));
                }
            }
        }
        if ($deleteData) {
            $db = $app->db();
            $sql = '';
            foreach ([...(glob($manifest['path'] . '/migrations/*.sql') ?: []), $manifest['path'] . '/install.sql'] as $file) {
                $sql .= is_file($file) ? (string) file_get_contents($file) . "\n" : '';
            }
            $own = preg_quote('ext_' . self::tablePrefix($manifest) . '_', '/');
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:\{|tl_)(' . $own . '[a-z0-9_]+)\}?/i', $sql, $m);
            foreach (array_unique($m[1]) as $table) {
                $db->run('DROP TABLE IF EXISTS {' . strtolower($table) . '}');
            }
            foreach ($db->all('SELECT name FROM {settings} WHERE name LIKE ?', ['ext.%']) as $row) {
                if (str_starts_with((string) $row['name'], 'ext.' . $slug . '.')) {
                    $db->run('DELETE FROM {settings} WHERE name = ?', [$row['name']]);
                }
            }
            $db->run('DELETE FROM {settings} WHERE name IN (?, ?)', ['addons_migrated.' . $slug, 'addons_error.' . $slug]);
            foreach ($db->all('SELECT name FROM {jobs} WHERE name LIKE ?', ['ext_%']) as $row) {
                if (str_starts_with((string) $row['name'], 'ext_' . $slug . '_')) {
                    $db->run('DELETE FROM {jobs} WHERE name = ?', [$row['name']]);
                }
            }
        }
        Events::record($app->db(), 'addon.uninstalled', 'info', t('The add-on %s was uninstalled.', $manifest['name']), ['addon' => $slug, 'data_deleted' => $deleteData]);
    }

    /** Loads the add-on's entry file and returns its class (checked to be an ExtensionInterface). @param array<string, mixed> $manifest */
    private static function load(array $manifest): string
    {
        require_once $manifest['path'] . '/' . $manifest['entry'];
        $class = (string) $manifest['class'];
        if (!class_exists($class) || !is_subclass_of($class, ExtensionInterface::class)) {
            throw new \DomainException('The class ' . $class . ' does not implement Talea\Extension\ExtensionInterface.');
        }

        return $class;
    }

    /** A broken add-on is switched off at once and the error kept for the Add-ons screen. */
    private static function fail(App $app, string $slug, \Throwable $e): void
    {
        $message = mb_substr(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 0, 500);
        try {
            self::disable($app, $slug);
            $app->settings()->set('addons_error.' . $slug, $message);
            Events::record($app->db(), 'addon.failed', 'error', t('The add-on %s failed and was switched off: %s', $slug, $message), ['addon' => $slug]);
        } catch (\Throwable) {
            // the site keeps running even when the error cannot be written down
        }
    }

    /**
     * The early request hooks (Api::earlyRequest) of a public request: the first Response wins, null lets the request go on. Fail-open: a hook
     * that throws or runs over EARLY_BUDGET is switched off (error in Add-ons, event addon.failed), its answer is not used, and the request
     * continues with the next hook. The time is measured after the call – PHP cannot interrupt a running function (a hook stuck in
     * a loop would hang the request; the capability text says so), but a slow one is gone from the next request on.
     */
    public static function runEarly(App $app): ?Response
    {
        return self::runHooks($app, 'early', [$app->request]);
    }

    /** The hooks of Api::notFound for an address that ends in 404: the first Response replaces the 404 page. Fail-open like runEarly(). */
    public static function runNotFound(App $app, string $path): ?Response
    {
        return self::runHooks($app, 'notFound', [$app->request, $path]);
    }

    /** @param 'early'|'notFound' $list @param list<mixed> $arguments */
    private static function runHooks(App $app, string $list, array $arguments): ?Response
    {
        $self = self::$instance;
        if ($self === null || $self->$list === []) {
            return null;
        }
        foreach ($self->$list as $slug => $hooks) {
            foreach ($hooks as $hook) {
                $start = microtime(true);
                try {
                    $answer = $hook(...$arguments);
                    if (microtime(true) - $start > self::EARLY_BUDGET) {
                        throw new \RuntimeException('The early request hook took longer than ' . (int) (self::EARLY_BUDGET * 1000) . ' ms.');
                    }
                } catch (\Throwable $e) {
                    self::fail($app, $slug, $e);
                    unset($self->$list[$slug]);
                    continue 2;
                }
                if ($answer instanceof Response) {
                    return $answer;
                }
            }
        }

        return null;
    }

    /**
     * Rows of the add-ons for System status (Core\Health::checks); a failing provider is skipped.
     *
     * @return list<array{group: string, name: string, status: string, info: string}>
     */
    public static function healthRows(App $app): array
    {
        $rows = [];
        foreach (self::boot($app)->healthRows as $provider) {
            try {
                foreach ($provider($app) as $row) {
                    if (is_array($row) && isset($row['name'], $row['info'])) {
                        $rows[] = ['group' => (string) ($row['group'] ?? ''), 'name' => (string) $row['name'], 'status' => in_array($row['status'] ?? '', ['ok', 'warning', 'error'], true) ? $row['status'] : 'ok', 'info' => (string) $row['info']]
                            + (isset($row['links']) && is_array($row['links']) ? ['links' => $row['links']] : []);
                    }
                }
            } catch (\Throwable) {
                // an add-on's mistake stays in the add-on
            }
        }

        return $rows;
    }

    /**
     * Findings of the add-ons for "Before handing over" (Core\Audit): handover key = <slug>.<key>.
     *
     * @return list<array{key: string, message: string, edit: string}>
     */
    public static function handoverFindings(App $app): array
    {
        $out = [];
        foreach (self::boot($app)->handover as $slug => $provider) {
            try {
                foreach ($provider($app) as $finding) {
                    if (is_array($finding) && isset($finding['key'], $finding['message'])) {
                        $out[] = ['key' => $slug . '.' . preg_replace('/[^a-z0-9_.]/', '', strtolower((string) $finding['key'])), 'message' => mb_substr((string) $finding['message'], 0, 500),
                            'edit' => preg_match('/^admin\.php\?[A-Za-z0-9_=&.%-]*$/', (string) ($finding['edit'] ?? '')) === 1 ? (string) $finding['edit'] : 'admin.php?module=addons'];
                    }
                }
            } catch (\Throwable) {
                // an add-on's mistake stays in the add-on
            }
        }

        return $out;
    }

    /** The event types of add-ons: type => description (Core\Events::types() adds them to Talea's own). @return array<string, string> */
    public static function eventTypes(): array
    {
        return self::$instance->eventTypes ?? [];
    }

    /** Types of add-ons whose warnings are worth an alert e-mail (Core\Alerts::warnings()). @return list<string> */
    public static function alertTypes(): array
    {
        return self::$instance->alertTypes ?? [];
    }

    /* ---------- what add-ons registered ---------- */

    public function addListener(string $type, callable $listener): void
    {
        $this->listeners[$type][] = $listener;
    }

    public function addFilter(string $name, callable $filter): void
    {
        $this->filters[$name][] = $filter;
    }

    public function addToken(string $slug, string $name, callable $render): void
    {
        $this->tokens[$slug . '.' . $name] = $render;
    }

    public function addAdminPage(string $slug, string $name, string $title, callable $render): void
    {
        $this->pages[$slug . '.' . $name] = ['slug' => $slug, 'name' => $name, 'title' => mb_substr($title, 0, 80), 'render' => $render];
    }

    /** @param array<string, mixed> $schema */
    public function addTool(string $name, string $description, array $schema, string $access, callable $handler, string $requires = 'admin'): void
    {
        $this->tools[$name] = ['name' => $name, 'description' => mb_substr($description, 0, 2000), 'inputSchema' => $schema, 'access' => $access, 'handler' => $handler, 'requires' => $requires];
    }

    public function addJob(string $name, int $interval, string $label, callable $run, string $runner = 'any'): void
    {
        $this->jobs[$name] = [$interval, $runner, mb_substr($label, 0, 100), $run];
    }

    public function addEarlyHook(string $slug, callable $hook): void
    {
        $this->early[$slug][] = $hook;
    }

    public function addNotFoundHook(string $slug, callable $hook): void
    {
        $this->notFound[$slug][] = $hook;
    }

    public function addHealthRows(string $slug, callable $rows): void
    {
        $this->healthRows[$slug] = $rows;
    }

    public function addHandoverFindings(string $slug, callable $findings): void
    {
        $this->handover[$slug] = $findings;
    }

    public function addEventType(string $type, string $description, bool $alert): void
    {
        if (isset(Events::TYPES[$type])) {
            throw new \InvalidArgumentException('The event type belongs to Talea: ' . $type);
        }
        $this->eventTypes[$type] = mb_substr($description, 0, 300);
        if ($alert && !in_array($type, $this->alertTypes, true)) {
            $this->alertTypes[] = $type;
        }
    }

    /** @param array<string, array{label: string, type: string, default: string, help: string}> $schema */
    public function setSchema(string $slug, array $schema): void
    {
        $this->schemas[$slug] = $schema;
    }

    /** @return array<string, array{label: string, type: string, default: string, help: string}> */
    public function schema(string $slug): array
    {
        return $this->schemas[$slug] ?? [];
    }

    /** An event recorded by Talea goes to the listeners of its type; a failing listener never breaks the caller. */
    public static function dispatch(string $type, array $data): void
    {
        $self = self::$instance;
        if ($self === null || $self->listeners === [] || $self->dispatching) {
            return;
        }
        $self->dispatching = true;
        try {
            foreach ($self->listeners[$type] ?? [] as $listener) {
                try {
                    $listener($type, $data);
                } catch (\Throwable) {
                    // an add-on's mistake stays in the add-on
                }
            }
        } finally {
            $self->dispatching = false;
        }
    }

    /** A filtered value: each filter in turn; a failing filter is skipped. */
    public static function applyFilter(string $name, string $value): string
    {
        foreach (self::$instance->filters[$name] ?? [] as $filter) {
            try {
                $result = $filter($value);
                $value = is_string($result) ? $result : $value;
            } catch (\Throwable) {
                // keep the value as it was
            }
        }

        return $value;
    }

    /**
     * {{ext.<slug>.<name> key="value"}} tokens in what editors wrote (builds, page and news text). Front\Kernel calls it before
     * a template adds anything a visitor sent, never over a whole page (3.3.2, N38). Only real quotes delimit a value: the
     * escaped form (&quot;) is how a visitor's text such as the search query looks on the page, so it never runs a token.
     */
    public static function fillTokens(string $html): string
    {
        $tokens = self::$instance->tokens ?? [];
        if ($tokens === [] || !str_contains($html, '{{ext.')) {
            return $html;
        }

        return (string) preg_replace_callback('/\{\{ext\.([a-z0-9_]+\.[a-z0-9_]+)((?:\s+[a-z_]+="[^"]*")*)\s*\}\}/', function (array $m) use ($tokens): string {
            if (!isset($tokens[$m[1]])) {
                return $m[0];
            }
            preg_match_all('/([a-z_]+)="([^"]*)"/', $m[2], $a, PREG_SET_ORDER);
            $attributes = [];
            foreach ($a as $pair) {
                $attributes[$pair[1]] = html_entity_decode($pair[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            try {
                $out = ($tokens[$m[1]])($attributes);

                return is_string($out) ? $out : '';
            } catch (\Throwable) {
                return '';
            }
        }, $html);
    }

    /** @return array<string, array{slug: string, name: string, title: string, render: callable}> */
    public function adminPages(): array
    {
        return $this->pages;
    }

    /** MCP definitions of the add-ons' tools (tools/list). @return list<array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function toolDefinitions(): array
    {
        return array_values(array_map(fn (array $t): array => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']], $this->tools));
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>, access: string, handler: callable, requires: string}|null */
    public function tool(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    /** Jobs in the shape of Core\Scheduler::jobs(): name => [interval, 'any'|'cron', label, fn (App, string): string]. @return array<string, array{0: int, 1: string, 2: string, 3: callable}> */
    public function jobs(): array
    {
        return array_map(fn (array $j): array => [$j[0], $j[1], $j[2], function () use ($j): string {
            $result = ($j[3])();

            return is_string($result) ? mb_substr($result, 0, 200) : 'ok';
        }], $this->jobs);
    }
}
