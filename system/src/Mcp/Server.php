<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\ChangeLog;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;

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
        \Kaleta\Extension\Registry::boot($this->app); // add-ons may add tools (3.0)
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
                'WWW-Authenticate' => 'Bearer resource_metadata="' . (new \Kaleta\Front\OAuth($this->app))->metadataUrl() . '"']);
        }
        $this->app->auth()->signInAs($user);
        $this->app->auth()->useConnection((string) $user['connection_name'], (string) $user['connection_access']);
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
        $responses = array_values(array_filter(array_map($this->process(...), $batch)));
        if ($responses === []) {
            return new Response('', 202);
        }

        return Response::json(array_is_list($message) ? $responses : $responses[0]);
    }

    /** @param array<string, mixed> $z @return array<string, mixed>|null null = notification without a response */
    private function process(array $z): ?array
    {
        $id = $z['id'] ?? null;
        $method = (string) ($z['method'] ?? '');
        if ($id === null) {
            return null;
        }
        $ok = fn (array $result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        $tools = new Tools($this->app);

        return match ($method) {
            'initialize' => $ok([
                'protocolVersion' => self::protocol($z['params']['protocolVersion'] ?? null),
                'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass(), 'prompts' => new \stdClass()],
                'serverInfo' => ['name' => 'Kaleta – ' . $this->app->settings()->get('site_name'), 'version' => KALETA_VERSION],
                'instructions' => Prompts::serverInstructions($this->app),
            ]),
            // the site owner's instructions and an overview; ready-made tasks (2.2)
            'resources/list' => $ok(['resources' => Prompts::resources()]),
            'resources/read' => $this->guarded($id, fn (): array => Prompts::read($this->app, (string) ($z['params']['uri'] ?? ''))),
            'prompts/list' => $ok(['prompts' => Prompts::listAll()]),
            'prompts/get' => $this->guarded($id, fn (): array => Prompts::get((string) ($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            'ping' => $ok([]),
            // only the tools this connection may use (a connection limited to drafts or to reading, 2.2)
            'tools/list' => $ok(['tools' => array_values(array_filter(array_map(fn (array $t): array => self::withReason($t) + ['annotations' => $tools->annotations($t['name'])],
                $tools->listAll()), fn (array $t): bool => Catalog::allows($this->access(), $t['name'])))]),
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
        if (Catalog::exists($name) && !Catalog::allows($this->access(), $name)) {
            return ['content' => [['type' => 'text', 'text' => $this->access() === 'read'
                ? 'This connection can only read the site. Changes need a connection with more access – the user sets it when connecting Claude, or under My account.'
                : 'This connection can only save drafts: builds, hidden pages and collection items, news drafts, the draft look, proposed exceptions to the opening hours, enquiry triage and notebook notes. This tool changes the live site – the user can do it in the admin, or connect Claude with full access.']], 'isError' => true];
        }
        try {
            $items = $tools->listAll();
            $arguments = self::extractJson($items, $name, $arguments);
            // why Claude makes the change (2.15): any write tool takes it; it goes to the change log, never to the tool
            $reason = \Kaleta\Core\Guardrails::reason($arguments['reason'] ?? null);
            unset($arguments['reason']);
            $unknownParams = self::unknownParams($items, $name, $arguments);
            // the site owner's guardrails (2.15) hold for every connection, on top of its access
            $refusal = \Kaleta\Core\Guardrails::refusal($this->app, $name, Catalog::access($name), $arguments, (string) ($this->app->auth()->connection()['name'] ?? ''));
            if ($refusal !== null) {
                return ['content' => [['type' => 'text', 'text' => $refusal]], 'isError' => true];
            }
            // every content row a change touches is journaled, so the whole Claude session can be undone (2.17, Core\AgentJournal)
            $db = $this->app->db();
            $db->journal = $tools->isWriteTool($name) && $name !== 'undo_agent_session'
                ? \Kaleta\Core\AgentJournal::start($db, (string) ($this->app->auth()->connection()['name'] ?? 'Claude'), $name) : null;
            try {
                $result = $tools->call($name, $arguments);
            } finally {
                $db->journal = null;
            }
            if ($tools->isWriteTool($name)) {
                ChangeLog::write($this->app, 'claude', $name, mb_substr((string) ($arguments['title'] ?? $arguments['name'] ?? $arguments['template'] ?? $arguments['id'] ?? ''), 0, 200), $reason);
                \Kaleta\Front\Cache::clear();
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
        $ip = \Kaleta\Core\Antispam::hash(\Kaleta\Core\Firewall::visitorKey($this->app->request, $this->app->settings()));
        // a personal token from "Můj účet" (kaleta_…) or the access token of an application connected via OAuth
        // (kaleta_oa_…, valid for an hour)
        if (!preg_match('/^Bearer\s+(kaleta_(?:oa_)?[a-f0-9]{48})$/', $header, $m)) {
            return null;
        }
        // Wrong tokens are recorded per address, but only up to 20 rows per 15 minutes: the cap keeps a flood from filling the
        // table, it never refuses a request (3.3.3, N62). A request is not refused on purpose: a valid token must always work,
        // so nobody can lock out the site's Claude connections by sending wrong tokens from a shared address (a proxy in
        // front of Docker, Claude's own servers – N5), and guessing a 192-bit token gains nothing from more tries anyway.
        $limited = (int) $db->value("SELECT COUNT(*) FROM {ip_checks} WHERE type = 'mcp' AND ip = ? AND checked_at > NOW() - INTERVAL 15 MINUTE", [$ip]) >= 20;
        $token = $db->one("SELECT t.token_id, t.name AS connection_name, t.access AS connection_access, u.* FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id WHERE t.token_hash = ? AND u.blocked = 0 AND t.kind <> 'refresh' AND (t.expires_at IS NULL OR t.expires_at > ?)",
            [hash('sha256', $m[1]), date('Y-m-d H:i:s')]);
        if ($token === null) {
            if (!$limited) {
                $db->insert('ip_checks', ['ip' => $ip, 'type' => 'mcp', 'checked_at' => date('Y-m-d H:i:s')]);
            }

            return null;
        }
        $db->run('UPDATE {api_tokens} SET used_at = NOW() WHERE token_id = ?', [$token['token_id']]);

        return $token;
    }
}
