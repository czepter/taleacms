<?php

declare(strict_types=1);

namespace Talea\Mcp;

use Talea\Admin\ChangeLog;
use Talea\Core\App;
use Talea\Core\Response;
use Talea\Core\Extensions;

/**
 * MCP server (Model Context Protocol, "Streamable HTTP" transport) at /mcp.
 * Through it Claude can work with the site: read and write pages and news, manage categories, collections, site parts
 * and appearance.
 *
 * Sign-in: the header "Authorization: Bearer <token>" – a personal token from the "My account" menu, or the
 * token of an application connected via OAuth (connector in Claude, Front\OAuth).
 * Claude then acts with this user's permissions (author / editor / administrator), limited by the access of the connection
 * (full, drafts or read, 2.2). New installations have the extension switched on.
 */
final class Server
{
    /** Protocol versions the server speaks, newest first (2.2: the client's version is answered when it is one of them). */
    public const array PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        \Talea\Extension\Registry::boot($this->app); // add-ons may add tools (3.0)
        if (!Extensions::isEnabled($this->app->settings(), 'claude')) {
            return Response::json(['error' => 'The Claude connection is switched off (Extensions menu).'], 404);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['error' => 'MCP is available over HTTPS only.'], 403);
        }
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $user = $this->user();
        if ($user === null) {
            // link to the OAuth metadata: using them the Claude connector registers itself and asks the user for consent
            return new Response(json_encode(['error' => 'The token is invalid or missing.']), 401, ['Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . (new \Talea\Front\OAuth($this->app))->metadataUrl() . '"']);
        }
        $this->app->auth()->signInAs($user);
        $this->app->auth()->useConnection((string) $user['connection_name'], (string) $user['connection_access'], (string) $user['connection_tool_set']);
        if ($this->app->auth()->isMissingRequired2fa($this->app->settings())) {
            return Response::json(['error' => 'The site requires two-factor sign-in. Turn it on in the admin under My account – the connection does not work until then.'], 403);
        }

        $message = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($message)) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Invalid JSON.']], 400);
        }
        // a batch of messages as well as a single message
        $batch = array_is_list($message) ? $message : [$message];
        if (count($batch) > 50) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'A batch has at most 50 messages.']], 400);
        }
        // only a call that is served counts as one (#154): a call refused above leaves no trace of use, so a connection whose
        // every call is refused never shows as connected or used
        $this->served($user);
        // a message that is not an object is an invalid request, not an error page
        $responses = array_values(array_filter(array_map(fn (mixed $z): ?array => is_array($z) ? $this->process($z)
            : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'A message must be a JSON object.']], $batch)));
        if ($responses === []) {
            return new Response('', 202);
        }

        return Response::json(array_is_list($message) ? $responses : $responses[0]);
    }

    /** @param array<string, mixed> $z @return array<string, mixed>|null null = notification without a response */
    private function process(array $z): ?array
    {
        $id = $z['id'] ?? null;
        $method = is_string($z['method'] ?? null) ? $z['method'] : '';
        if ($id === null) {
            return null;
        }
        // a name, address or arguments of the wrong type is invalid params – (string) on an array would warn and the error
        // handler would answer with an error page
        $params = $z['params'] ?? [];
        foreach (['name' => 'is_string', 'uri' => 'is_string', 'arguments' => 'is_array'] as $key => $isValid) {
            if (!is_array($params) || (isset($params[$key]) && !$isValid($params[$key]))) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => is_array($params) ? 'params.' . $key . ' has the wrong type.' : 'params must be an object.']];
            }
        }
        $ok = fn (array $result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        $tools = new Tools($this->app);

        return match ($method) {
            'initialize' => $ok([
                'protocolVersion' => self::protocol($z['params']['protocolVersion'] ?? null),
                'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass(), 'prompts' => new \stdClass()],
                'serverInfo' => ['name' => 'Talea – ' . $this->app->settings()->get('site_name'), 'version' => TALEA_VERSION],
                'instructions' => Prompts::serverInstructions($this->app),
            ]),
            // the site owner's instructions and an overview; ready-made tasks (2.2)
            'resources/list' => $ok(['resources' => Prompts::resources()]),
            'resources/read' => $this->guarded($id, fn (): array => Prompts::read($this->app, (string) ($z['params']['uri'] ?? ''))),
            'prompts/list' => $ok(['prompts' => Prompts::listAll()]),
            'prompts/get' => $this->guarded($id, fn (): array => Prompts::get((string) ($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            'ping' => $ok([]),
            // only the tools this connection may use (a connection limited to drafts or to reading, 2.2); an essentials connection
            // lists the core tools, find_tools and the runners it needs (Mcp\Discovery)
            'tools/list' => $ok(['tools' => $this->toolSet() === 'essentials' ? Discovery::essentials($this->available($tools), $this->access()) : $this->available($tools)]),
            'tools/call' => $ok($this->call($tools, (string) ($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Unknown method: ' . $method]],
        };
    }

    /**
     * Every tool that changes the site takes an optional reason (2.15): one line on why – the request it answers, what the
     * user asked for. It is kept in the change log next to the change.
     *
     * @param array<string, mixed> $tool a tools/list item
     * @return array<string, mixed>
     */
    public static function withReason(array $tool): array
    {
        if (Catalog::access((string) $tool['name']) === 'read' || !is_array($tool['inputSchema'] ?? null)) {
            return $tool;
        }
        $tool['inputSchema']['properties'] = (array) ($tool['inputSchema']['properties'] ?? []) + ['reason' => ['type' => 'string',
            'description' => 'Why you make this change, in one short line – the user’s request or the site request it answers. Kept in the change log.']];

        return $tool;
    }

    /**
     * Tool call.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(Tools $tools, string $name, array $arguments): array
    {
        // the meta tools: callable on any connection, they only find tools and run them through this same method
        if ($name === 'find_tools') {
            $available = $this->available($tools);
            $essentials = $this->toolSet() === 'essentials';
            try {
                $found = Discovery::find($available, $essentials ? Catalog::ESSENTIALS : array_column($available, 'name'), $arguments, $essentials ? Discovery::runners($available, $this->access()) : []);
            } catch (\InvalidArgumentException $e) {
                return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
            }

            return ['content' => [['type' => 'text', 'text' => json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        }
        if (isset(Discovery::RUNNERS[$name])) {
            return $this->runTool($tools, $arguments, $name);
        }
        if (Catalog::exists($name) && !Catalog::allows($this->access(), $name)) {
            return ['content' => [['type' => 'text', 'text' => $this->access() === 'read'
                ? 'This connection can only read the site. Changes need a connection with more access – the user sets it when connecting Claude, or under My account.'
                : 'This connection can only save drafts: builds, hidden pages and collection items, news drafts, the draft look, proposed exceptions to the opening hours, enquiry triage and notebook notes. This tool changes the live site – the user can do it in the admin, or connect Claude with full access.']], 'isError' => true];
        }
        try {
            $items = $tools->listAll();
            $arguments = self::extractJson($items, $name, $arguments);
            // why Claude makes the change (2.15): any write tool takes it; it goes to the change log, never to the tool
            $reason = \Talea\Core\Guardrails::reason($arguments['reason'] ?? null);
            unset($arguments['reason']);
            $unknownParams = self::unknownParams($items, $name, $arguments);
            // rows are named by their public id (HF-16): the tools below work with the numbers, so the guardrails and the journal see them
            $given = $arguments;
            $ownTool = \Talea\Extension\Registry::get()->tool($name) === null;
            if ($ownTool) {
                $arguments = \Talea\Mcp\PublicIds::in($this->app->db(), $name, $arguments, false);
            }
            // the site owner's guardrails (2.15) hold for every connection, on top of its access
            $refusal = \Talea\Core\Guardrails::refusal($this->app, $name, Catalog::access($name), $arguments, (string) ($this->app->auth()->connection()['name'] ?? ''));
            if ($refusal !== null) {
                return ['content' => [['type' => 'text', 'text' => $refusal]], 'isError' => true];
            }
            if ($ownTool) {
                $arguments = \Talea\Mcp\PublicIds::in($this->app->db(), $name, $given); // now an id that names no row is refused
            }
            // every content row a change touches is journaled, so the whole Claude session can be undone (2.17, Core\AgentJournal)
            $db = $this->app->db();
            $db->journal = $tools->isWriteTool($name) && $name !== 'undo_agent_session'
                ? \Talea\Core\AgentJournal::start($db, (string) ($this->app->auth()->connection()['name'] ?? 'Claude'), $name) : null;
            try {
                $result = $tools->call($name, $arguments);
                $result = \Talea\Mcp\PublicIds::out($this->app->db(), $name, $result, $arguments);
            } finally {
                $db->journal = null;
            }
            if ($tools->isWriteTool($name)) {
                ChangeLog::write($this->app, 'claude', $name, mb_substr((string) ($given['title'] ?? $given['name'] ?? $given['template'] ?? $given['id'] ?? ''), 0, 200), $reason);
                \Talea\Front\Cache::clear();
                if (!$this->app->auth()->draftsOnly()) {
                    \Talea\Core\MediaStaging::promotePublished($db); // files waiting for approval that published content now uses (#150)
                }
            }
            if ($name === 'list_enquiries') {
                // enquiries hold personal data: every read by Claude is in the change log, with how many it saw
                ChangeLog::write($this->app, 'claude', 'list_enquiries', t('%d enquiries read', is_array($result) ? count($result) : 0));
            }
            if ($unknownParams !== [] && is_array($result) && !array_is_list($result)) {
                // a typo in a parameter name would otherwise get lost without a trace (the tool does not know it, so it skips it)
                $result['unknown_parameters'] = $unknownParams;
            }

            return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        } catch (\InvalidArgumentException | \DomainException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    /**
     * The runners: the inner tool goes through call() like a direct tools/call – the connection's access, the guardrails and
     * the reason, the journal for undo, unknown parameters, the change log and the cache. Each runner takes only its own class
     * of tools (Discovery::runner), so its annotations hold for whatever it runs; none runs a meta tool, so a call cannot nest.
     *
     * @param array<string, mixed> $arguments
     * @param string $runner run_read_tool | run_tool | run_destructive_tool
     * @return array<string, mixed>
     */
    private function runTool(Tools $tools, array $arguments, string $runner): array
    {
        $error = fn (string $text): array => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        $name = trim(is_scalar($arguments['name'] ?? null) ? (string) $arguments['name'] : '');
        if ($name === '') {
            return $error($runner . ' needs name – the tool to run (find_tools lists them) – and its arguments as an object.');
        }
        if (isset(Catalog::META[$name])) {
            return $error($runner . ' runs the site\'s tools, not ' . $name . ' – call ' . ($name === 'find_tools' ? 'find_tools directly.' : 'the tool you need directly in ' . $runner . '.'));
        }
        $inner = $arguments['arguments'] ?? [];
        if (is_string($inner)) {
            // a client without the schema may send the object as JSON text
            $inner = trim($inner) === '' ? [] : json_decode($inner, true);
        }
        if (!is_array($inner) || ($inner !== [] && array_is_list($inner))) {
            return $error($runner . ': arguments must be an object with the tool\'s parameters, e.g. {"id": "…"}. find_tools with names gives them.');
        }
        if (!isset($inner['reason']) && is_string($arguments['reason'] ?? null)) {
            $inner['reason'] = $arguments['reason']; // the reason may come with the runner itself
        }
        if (!Catalog::exists($name)) {
            return $error('Unknown tool: ' . mb_substr($name, 0, 100) . '. Call find_tools for the tools of this site.');
        }
        if (Discovery::runner($name) !== $runner) {
            // a client may "always allow" one runner: it must never reach a tool of a more dangerous class
            return $error(Discovery::refusal($runner, $name));
        }

        return $this->call($tools, $name, $inner);
    }

    /**
     * One tools/list item: the definition with the optional reason of a changing tool and its annotations.
     *
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    public static function listed(array $tool): array
    {
        return self::withReason($tool) + ['annotations' => Tools::annotations((string) $tool['name'])];
    }

    /**
     * Every tool this connection may use, as tools/list items (the whole list of an "all" connection, and what find_tools searches).
     *
     * @return list<array<string, mixed>>
     */
    private function available(Tools $tools): array
    {
        return array_values(array_filter(array_map(self::listed(...), $tools->listAll()), fn (array $t): bool => Catalog::allows($this->access(), $t['name'])));
    }

    /** The tool set of this connection: all | essentials. */
    private function toolSet(): string
    {
        return $this->app->auth()->connection()['tool_set'] ?? 'all';
    }

    /**
     * An object or array sent as JSON text (a client without the tool schema, some proxies) is decoded by the parameter
     * type in the schema – otherwise the tool would not recognize it and would silently skip the values.
     *
     * @param list<array<string, mixed>> $items tool definitions (tools/list)
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function extractJson(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') !== $name) {
                continue;
            }
            $properties = (array) ($tool['inputSchema']['properties'] ?? []);
            foreach ($arguments as $key => $value) {
                // the type can also be a list, e.g. ["array", "null"] for menu items
                $types = (array) ($properties[$key]['type'] ?? []);
                // a boolean sent as text: „false“ would be true in PHP (a hidden page would get published)
                if (is_string($value) && in_array('boolean', $types, true) && in_array(strtolower(trim($value)), ['true', 'false', '1', '0', ''], true)) {
                    $arguments[$key] = in_array(strtolower(trim($value)), ['true', '1'], true);
                    continue;
                }
                if (!is_string($value) || !array_intersect($types, ['object', 'array']) || !preg_match('/^\s*[\[{]/', $value)) {
                    continue;
                }
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $kind = $decoded === [] ? null : (array_is_list($decoded) ? 'array' : 'object'); // both [] and {} match either type
                if ($kind === null || in_array($kind, $types, true)) {
                    $arguments[$key] = $decoded;
                }
            }
            break;
        }

        return $arguments;
    }

    /**
     * Parameters the tool does not have in its schema – returned in the result so the caller knows they were not used.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function unknownParams(array $items, string $name, array $arguments): array
    {
        foreach ($items as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return array_values(array_diff(array_map('strval', array_keys($arguments)), array_keys((array) ($tool['inputSchema']['properties'] ?? []))));
            }
        }

        return [];
    }

    /** The protocol version to answer with: the client's when the server speaks it, otherwise the newest (2.2). */
    public static function protocol(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::PROTOCOLS, true) ? $requested : self::PROTOCOLS[0];
    }

    /**
     * A method whose parameters may be wrong (an unknown resource or prompt): the error goes back as a JSON-RPC error.
     *
     * @param callable(): array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function guarded(mixed $id, callable $result): array
    {
        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result()];
        } catch (\InvalidArgumentException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => $e->getMessage()]];
        }
    }

    /** What the connection of this request may do: full | drafts | read (2.2). */
    private function access(): string
    {
        return $this->app->auth()->connection()['access'] ?? 'full';
    }

    /** @return array<string, mixed>|null user by token */
    private function user(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $db = $this->app->db();
        // the visitor's address behind the configured proxy, an IPv6 address by its /64 (3.3.3, N54)
        $ip = \Talea\Core\Antispam::hash(\Talea\Core\Antispam::visitorKey($this->app->request, $this->app->settings()));
        // a personal token from "My account" (talea_…) or the access token of an application connected via OAuth
        // (talea_oa_…, valid for an hour)
        if (!preg_match('/^Bearer\s+(talea_(?:oa_)?[a-f0-9]{48})$/', $header, $m)) {
            return null;
        }
        // Wrong tokens are recorded per address, but only up to 20 rows per 15 minutes: the cap keeps a flood from filling the
        // table, it never refuses a request (3.3.3, N62). A request is not refused on purpose: a valid token must always work,
        // so nobody can lock out the site's Claude connections by sending wrong tokens from a shared address (a proxy in
        // front of Docker, Claude's own servers – N5), and guessing a 192-bit token gains nothing from more tries anyway.
        $limited = (int) $db->value("SELECT COUNT(*) FROM {ip_checks} WHERE type = 'mcp' AND ip = ? AND checked_at > NOW() - INTERVAL 15 MINUTE", [$ip]) >= 20;
        $token = $db->one("SELECT t.token_id, t.client_id AS connection_client, t.name AS connection_name, t.access AS connection_access, t.tool_set AS connection_tool_set, u.* FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id WHERE t.token_hash = ? AND u.blocked = FALSE AND t.kind <> 'refresh' AND (t.expires_at IS NULL OR t.expires_at > ?)",
            [hash('sha256', $m[1]), date('Y-m-d H:i:s')]);
        if ($token === null) {
            if (!$limited) {
                $db->insert('ip_checks', ['ip' => $ip, 'type' => 'mcp', 'checked_at' => date('Y-m-d H:i:s')]);
            }

            return null;
        }

        return $token;
    }

    /**
     * Records a served call: the token's last use and, for an OAuth connection, its refresh row – the access row goes an
     * hour after it expires (Core\ClaudeConnection).
     *
     * @param array<string, mixed> $token the row user() found
     */
    private function served(array $token): void
    {
        $db = $this->app->db();
        $db->run('UPDATE {api_tokens} SET used_at = ? WHERE token_id = ?', [date('Y-m-d H:i:s'), $token['token_id']]);
        \Talea\Core\ClaudeConnection::stamp($db, (int) $token['user_id'], $token['connection_client'] !== null ? (string) $token['connection_client'] : null);
    }
}
