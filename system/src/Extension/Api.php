<?php

declare(strict_types=1);

namespace Talea\Extension;

use Talea\Core\App;

/**
 * The extension API: everything an add-on may hook into, versioned by VERSION. Methods and hook names listed in
 * tools/contracts/extension-api.json are a public contract – they keep working through 3.x; a change that breaks them
 * needs 4.0. Anything else in Talea (classes, tables, columns) is internal and may change in any release.
 *
 * Version 2 is strictly additive: an add-on written for version 1 (extension.json requires.api = 1) runs unchanged, and the
 * methods in V2_METHODS (and the runner option of job()) refuse to work for it. An add-on says which version it was written
 * for in its manifest; Talea loads versions in SUPPORTED.
 *
 * One Api object belongs to one add-on: its slug prefixes its settings, its MCP tools (ext_<slug>_<name>), its jobs and
 * its admin pages, so add-ons cannot overwrite each other or Talea's own names.
 */
final class Api
{
    public const int VERSION = 2;

    /** API versions Talea loads add-ons for. */
    public const array SUPPORTED = [1, 2];

    /** Filters an add-on may change, with what they get: name => description (the contract lists them). */
    public const array FILTERS = [
        'head' => 'HTML added to <head> of every public page (string in, string out).',
        'footer' => 'HTML added before </body> of every public page (string in, string out).',
        'page.html' => 'The whole HTML of a public page before it is sent and cached (string in, string out). Keep it fast.',
    ];

    /** The access an MCP tool declares, as Mcp\Catalog knows it: read | draft | write | destructive. */
    public const array TOOL_ACCESS = ['read', 'draft', 'write', 'destructive'];

    /** Roles an add-on tool may require (3.3.2; Core\Auth: author 0, editor 1, administrator 2). */
    public const array TOOL_ROLES = ['author', 'editor', 'admin'];

    /** Methods that exist since version 2: an add-on written for version 1 may not call them. */
    public const array V2_METHODS = ['earlyRequest', 'notFound', 'healthRows', 'handoverFindings', 'eventType', 'settings', 'httpGet'];

    /** Where a job runs: with every visit and cron, or only from cron (heavy work). */
    public const array RUNNERS = ['any', 'cron'];

    /** What an add-on declares in extension.json ("capabilities") and the Add-ons screen shows before it is switched on. Disclosure, not enforcement: PHP cannot sandbox code. */
    public const array CAPABILITIES = [
        'early_request' => 'Runs on every public request before anything else and may refuse it',
        'tables' => 'Creates and keeps its own database tables',
        'outgoing_requests' => 'Makes requests to other servers',
        'mail' => 'Sends e-mail',
    ];

    /** Who may call an add-on tool that does not say: by its access. */
    private const array DEFAULT_ROLE = ['read' => 'author', 'draft' => 'author', 'write' => 'editor', 'destructive' => 'admin'];

    /**
     * @param int $level the API version the add-on was written for (its manifest)
     * @param list<string> $capabilities what the manifest declares
     */
    public function __construct(private readonly Registry $registry, public readonly string $slug, private readonly App $app,
        private readonly int $level = self::VERSION, private readonly array $capabilities = ['early_request', 'tables', 'outgoing_requests', 'mail'])
    {
    }

    /** Called after Talea records an event (Core\Events::TYPES, e.g. enquiry.received): fn (string $type, array $data). */
    public function on(string $eventType, callable $listener): void
    {
        $this->registry->addListener($eventType, $listener);
    }

    /** Changes a filtered value (Api::FILTERS): fn (string $value): string. */
    public function filter(string $name, callable $filter): void
    {
        if (!isset(self::FILTERS[$name])) {
            throw new \InvalidArgumentException('Unknown filter: ' . $name);
        }
        $this->registry->addFilter($name, $filter);
    }

    /**
     * A token for texts and builds: {{ext.<slug>.<name>}} or {{ext.<slug>.<name> key="value"}} on a public page is
     * replaced by fn (array $attributes): string – HTML the add-on must escape itself.
     */
    public function token(string $name, callable $render): void
    {
        $this->registry->addToken($this->slug, self::name($name), $render);
    }

    /**
     * A page in the administration (Add-ons, administrators only): fn (\Talea\Core\Request $request): string returns the
     * HTML of the page body; a POST is CSRF-checked by Talea before the callable runs.
     */
    public function adminPage(string $name, string $title, callable $render): void
    {
        $this->registry->addAdminPage($this->slug, self::name($name), $title, $render);
    }

    /**
     * A tool for Claude: ext_<slug>_<name>. $schema is a JSON schema of the arguments (type object); $access tells Claude's
     * connections and the guardrails what it does (read | draft | write | destructive); fn (array $arguments): mixed.
     *
     * $requires (3.3.2) is who may call it, like the built-in tools check the user: author | editor | admin (the lowest
     * role), or the ident of an admin section the user must have (pages, news, enquiries…). Left empty, a read or draft
     * tool is open to every user, a write tool needs an editor and a destructive one an administrator.
     *
     * @param array<string, mixed> $schema
     */
    public function mcpTool(string $name, string $description, array $schema, string $access, callable $handler, string $requires = ''): void
    {
        if (!in_array($access, self::TOOL_ACCESS, true)) {
            throw new \InvalidArgumentException('Unknown access: ' . $access);
        }
        if ($requires !== '' && !in_array($requires, self::TOOL_ROLES, true) && preg_match('/^[a-z][a-z_]{1,40}$/', $requires) !== 1) {
            throw new \InvalidArgumentException('Unknown role or section: ' . $requires);
        }
        $this->registry->addTool('ext_' . $this->slug . '_' . self::name($name), $description, $schema + ['type' => 'object'], $access, $handler,
            $requires !== '' ? $requires : self::DEFAULT_ROLE[$access]);
    }

    /** May the signed-in user call a tool that requires $requires (a role of TOOL_ROLES or a section ident)? */
    public static function userMay(\Talea\Core\Auth $auth, string $requires): bool
    {
        return match ($requires) {
            'author' => $auth->user() !== null,
            'editor' => $auth->isAdmin() || $auth->isEditor(),
            'admin' => $auth->isAdmin(),
            default => $auth->hasModule($requires),
        };
    }

    /**
     * A background job: run every $interval seconds (at least 60); fn (): string returns a short result.
     * $runner (API 2): 'any' = by cron and by visits (the default), 'cron' = only when cron calls /tasks – for heavy work that must not slow a visit.
     */
    public function job(string $name, int $interval, string $label, callable $run, string $runner = 'any'): void
    {
        if (!in_array($runner, self::RUNNERS, true)) {
            throw new \InvalidArgumentException('Unknown runner: ' . $runner);
        }
        if ($runner !== 'any') {
            $this->needsVersion2('job() with a runner');
        }
        $this->registry->addJob('ext_' . $this->slug . '_' . self::name($name), max(60, $interval), $label, $run, $runner);
    }

    /* ---------- version 2 ---------- */

    /**
     * (API 2, capability early_request) Runs at the very start of every public request – before routing, before sessions, before the
     * page cache; not for the administration. fn (\Talea\Core\Request $request): ?\Talea\Core\Response – null lets the request go on, a
     * Response answers it (a refusal). Fail-open: a hook that throws, or takes longer than Registry::EARLY_BUDGET seconds, is
     * switched off (the error is kept in Add-ons and recorded as an event) and the request continues. Keep it to memory, files and
     * the database – never a request to another server.
     */
    public function earlyRequest(callable $hook): void
    {
        $this->needsVersion2('earlyRequest()');
        $this->needsCapability('early_request');
        $this->registry->addEarlyHook($this->slug, $hook);
    }

    /**
     * (API 2, capability early_request) Runs when an address of the public site ends in 404 (no page, no redirect), before the 404 page:
     * fn (\Talea\Core\Request $request, string $path): ?\Talea\Core\Response – null shows the 404 page, a Response answers instead. $path is
     * the address without the leading slash. Fail-open and time-limited like earlyRequest().
     */
    public function notFound(callable $hook): void
    {
        $this->needsVersion2('notFound()');
        $this->needsCapability('early_request');
        $this->registry->addNotFoundHook($this->slug, $hook);
    }

    /**
     * (API 2) Rows for System status (Settings → System status, get_health): fn (\Talea\Core\App $app): list of
     * ['group' => string, 'name' => string, 'status' => 'ok'|'warning'|'error', 'info' => string]. Only while the add-on is on.
     */
    public function healthRows(callable $rows): void
    {
        $this->needsVersion2('healthRows()');
        $this->registry->addHealthRows($this->slug, $rows);
    }

    /**
     * (API 2) Findings for "Before handing over" (site_audit kind handover): fn (\Talea\Core\App $app): list of
     * ['key' => string, 'message' => string, 'edit' => 'admin.php?module=…' (optional)]. The key is kept as handover id, prefixed by the slug.
     */
    public function handoverFindings(callable $findings): void
    {
        $this->needsVersion2('handoverFindings()');
        $this->registry->addHandoverFindings($this->slug, $findings);
    }

    /**
     * (API 2) An event type of this add-on: "<slug>.<name>", e.g. domain_watch.expiring. Recorded with Core\Events::record() like Talea's
     * own and listed in Events::types() (list_events, get_health). $alert = true: an event of this type with severity warning is
     * worth an alert e-mail (errors always are).
     */
    public function eventType(string $type, string $description, bool $alert = false): void
    {
        $this->needsVersion2('eventType()');
        if (preg_match('/^' . preg_quote($this->slug, '/') . '\.[a-z][a-z0-9_.]{0,30}$/', $type) !== 1) {
            throw new \InvalidArgumentException('An event type of this add-on is written <slug>.<name>: ' . $type);
        }
        $this->registry->addEventType($type, $description, $alert);
    }

    /**
     * (API 2) The add-on's settings, declared: name => ['label' => …, 'type' => …, 'default' => …, 'help' => …] with the types text,
     * lines, flag, number:min:max, choice:a|b, email and url. Values are validated (set() refuses a bad one) and stored as
     * ext.<slug>.<name>. Registers the administration page "settings" (Add-ons → <title>) with a form for them; $extra: fn (Request): string,
     * HTML printed below the form (it also sees the POSTs the form does not handle).
     *
     * @param array<string, array{label: string, type: string, default?: string, help?: string}> $schema
     */
    public function settings(array $schema, string $title, ?callable $extra = null): void
    {
        $this->needsVersion2('settings()');
        $schema = SettingsSchema::normalize($schema);
        $this->registry->setSchema($this->slug, $schema);
        $this->adminPage('settings', $title, fn (\Talea\Core\Request $request): string => SettingsSchema::page($this, $schema, $request, $extra));
    }

    /**
     * (API 2, capability outgoing_requests) One GET to another server, through the same pinned-address path as Talea's own requests
     * (the host must resolve to public addresses only; the connection is pinned to the checked address). No redirects are followed – read
     * "location" and ask again, so every hop is checked. Throws \RuntimeException.
     *
     * @return array{status: int, location: string, body: string}
     */
    public function httpGet(string $url, int $timeout = 5, int $maxBytes = 262144): array
    {
        $this->needsVersion2('httpGet()');
        $this->needsCapability('outgoing_requests');
        $target = \Talea\Core\Outbound::url($url);
        if ($target === null) {
            throw new \RuntimeException('the address is refused');
        }
        $ip = \Talea\Core\Outbound::publicAddress($target['host']);
        if ($ip === null) {
            throw new \RuntimeException('the host does not resolve to a public address');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('the curl extension is missing on the server');
        }
        $location = '';
        $ch = curl_init($target['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout * 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HTTPHEADER => ['Accept: application/json, */*', 'User-Agent: Talea/' . TALEA_VERSION],
            CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ]);
        \Talea\Core\Outbound::pin($ch, $target['host'], $target['port'], $ip);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!is_string($body)) {
            throw new \RuntimeException($error !== '' ? $error : 'connection failed');
        }
        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('the response is unexpectedly large');
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }

    /* ---------- settings of this add-on ---------- */

    /** A setting of this add-on (its own namespace); a declared default (settings()) applies when the value is empty. */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->app->settings()->get('ext.' . $this->slug . '.' . self::name($key));
        if ($value !== '') {
            return $value;
        }

        return $default !== '' ? $default : (string) ($this->registry->schema($this->slug)[$key]['default'] ?? '');
    }

    public function set(string $key, string $value): void
    {
        $schema = $this->registry->schema($this->slug);
        if (isset($schema[$key])) {
            $value = SettingsSchema::sanitize($schema[$key]['type'], $value) ?? throw new \InvalidArgumentException('The value is not valid for the setting ' . $key . '.');
        }
        $this->app->settings()->set('ext.' . $this->slug . '.' . self::name($key), $value);
    }

    /** The site, for what the API does not cover yet – internal classes may change in any release. */
    public function app(): App
    {
        return $this->app;
    }

    private function needsVersion2(string $what): void
    {
        if ($this->level < 2) {
            throw new \LogicException($what . ' needs extension API 2; this add-on was written for API ' . $this->level . ' (extension.json requires.api).');
        }
    }

    private function needsCapability(string $capability): void
    {
        if (!in_array($capability, $this->capabilities, true)) {
            throw new \LogicException('The add-on uses ' . $capability . ' but extension.json does not declare it in "capabilities".');
        }
    }

    private static function name(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) !== 1) {
            throw new \InvalidArgumentException('A name of lowercase letters, digits and _, starting with a letter: ' . $name);
        }

        return $name;
    }
}
