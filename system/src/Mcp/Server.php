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
    /**
     * A usable connection with its user (the first parameter is now): not blocked, not a refresh token, not expired. Narrowed by
     * the token (user()) or by the connection an upload link belongs to (UploadLinks).
     */
    public const string CONNECTION_SQL = "SELECT t.token_id, t.client_id AS connection_client, t.name AS connection_name, t.access AS connection_access, t.tool_set AS connection_tool_set, u.* FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id WHERE u.blocked = FALSE AND u.operator_user_id IS NULL AND t.kind <> 'refresh' AND (t.expires_at IS NULL OR t.expires_at > ?)";

    /** Protocol versions the server speaks, newest first (2.2: the client's version is answered when it is one of them). */
    public const array PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var array<string, mixed>|null the user row of the connection (Server::user), to sign in on a sibling site */
    private ?array $user = null;

    /** The public id of the site this server instance was built for by onSite(); null = the site the connection called. */
    private ?string $siteId = null;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * What every Claude request must pass before its token is looked at – /mcp and the upload links (UploadLinks): the
     * extension is on and the request comes over HTTPS (or to a local address). null = it passes.
     */
    public static function gate(App $app): ?Response
    {
        $r = $app->request;
        \Talea\Extension\Registry::boot($app); // add-ons may add tools (3.0)
        if (($gate = self::siteGate($app, false)) !== null) {
            return Response::json(['error' => $gate[1]], $gate[0]);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['error' => 'MCP is available over HTTPS only.'], 403);
        }

        return null;
    }

    /**
     * Signs the request in as a connection (a row of CONNECTION_SQL); the answer refuses it when the site requires two-factor
     * sign-in its user has not turned on. null = signed in.
     *
     * @param array<string, mixed> $user
     */
    public static function signIn(App $app, array $user): ?Response
    {
        $app->auth()->signInAs($user);
        $app->auth()->useConnection((string) $user['connection_name'], (string) $user['connection_access'], (string) $user['connection_tool_set']);
        if (($gate = self::siteGate($app, true)) !== null) {
            return Response::json(['error' => $gate[1]], $gate[0]);
        }

        return null;
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        if (($refused = self::gate($this->app)) !== null) {
            return $refused;
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
        $this->user = $user;
        if (($refused = self::signIn($this->app, $user)) !== null) {
            return $refused;
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

    /**
     * What a site requires of a connection, from that site's own settings: the Claude extension must be on (before the sign-in, $user false)
     * and the two-factor sign-in it requires must be set up for the signed-in user ($user true). One place for handle(), onSite() and the
     * upload links (gate(), signIn()), so neither the `site` argument nor an upload link skips a gate of the site it runs on.
     *
     * @return array{0: int, 1: string}|null HTTP status and message of the refusal
     */
    private static function siteGate(App $app, bool $user): ?array
    {
        if (!$user) {
            return Extensions::isEnabled($app->settings(), 'claude') ? null : [404, 'The Claude connection is switched off (Extensions menu).'];
        }

        return $app->auth()->isMissingRequired2fa($app->settings())
            ? [403, 'The site requires two-factor sign-in. Turn it on in the admin under My account – the connection does not work until then.'] : null;
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
        if (!is_array($tool['inputSchema'] ?? null)) {
            return $tool;
        }
        // multisite: every tool of a site takes the optional site (its public id) to work on a sibling of the site the connection calls
        if (\Talea\Core\Sites::enabled() && !in_array((string) $tool['name'], Catalog::SITE_TOOLS, true) && (!isset(Catalog::META[(string) $tool['name']]) || isset(Discovery::RUNNERS[(string) $tool['name']]))) {
            $tool['inputSchema']['properties'] = (array) ($tool['inputSchema']['properties'] ?? []) + ['site' => ['type' => 'string',
                'description' => 'Optional: the id of another site of this installation (list_owner_sites) to run this tool on. Without it the tool works on the site this connection calls.']];
        }
        if (Catalog::access((string) $tool['name']) === 'read') {
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
        // an error report names the tool and the connection's access, never the arguments (they hold content and enquiries)
        \Talea\Core\ErrorReport::tag('tool', $name);
        \Talea\Core\ErrorReport::tag('access', $this->access());
        \Talea\Core\ErrorReport::tag('tool_set', $this->toolSet());
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
        // multisite: `site` runs the tool on a sibling (added to the schema like `reason`, see withReason)
        if (\Talea\Core\Sites::enabled() && array_key_exists('site', $arguments) && !in_array($name, Catalog::SITE_TOOLS, true)) {
            $site = $arguments['site'];
            unset($arguments['site']);
            if ($site !== null && $site !== '') {
                try {
                    return $this->onSite($name, $arguments, $site);
                } catch (\InvalidArgumentException | \DomainException $e) {
                    return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                }
            }
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
            if ($this->siteId !== null && is_array($result) && !array_is_list($result)) {
                $result['on_site'] = $this->siteId; // the result names the site the tool ran on (site_info has a `site` of its own)
            }
            if ($unknownParams !== [] && is_array($result) && !array_is_list($result)) {
                // a typo in a parameter name would otherwise get lost without a trace (the tool does not know it, so it skips it)
                $result['unknown_parameters'] = $unknownParams;
            }

            return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
        } catch (\InvalidArgumentException | \DomainException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (\Throwable $e) {
            // a fault of the site, not of the call: the client gets a tool error with the event id instead of an HTML error page
            $event = \Talea\Core\ErrorReport::capture($e, 'error', ['handled' => false]);
            \Talea\Core\ErrorReport::log($e, $event);

            return ['content' => [['type' => 'text', 'text' => 'The tool failed with an internal error of the site (' . $e::class . ').'
                . ($event !== null ? ' Error report ' . $event . ' went to the site’s error tracking.' : '') . ' The details are in the site’s error log (storage/log/errors.log); tell the site owner.']], 'isError' => true];
        }
    }

    /**
     * Runs one tool call on another site of the owner (`site` = its public id): the owner's ready, non-trashed sites only – an unknown,
     * trashed or not yet set up site is an error, never a silent fallback to the site the connection called. The call goes through call()
     * again on a Server built for an App of that site (same configuration with site_prefix, the user and the connection signed in), so
     * access, guardrails, journal (entries carry the site's prefix, undo writes back there), change log and cache all hold for that site.
     *
     * Static state that belongs to a site is switched for the call and put back afterwards: the page cache folder (Front\Cache), the
     * time zone, the site language and its register (Core\Language), and the memos of the slug and news-slug settings (Core\Slug,
     * Core\Routes). Core\Facts keys its memo by prefix by itself. Add-ons are loaded once for the site the connection called, so an
     * add-on's tool is refused with `site` (it would act on the wrong database).
     *
     * @param array<string, mixed> $arguments without `site`
     * @return array<string, mixed>
     */
    private function onSite(string $name, array $arguments, mixed $site): array
    {
        $db = $this->app->db();
        $row = is_string($site) ? $db->byPublicId('sites', $site) : null;
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown site. list_owner_sites gives the ids of the sites of this installation.');
        }
        if (!\Talea\Core\Sites::allows($db, (array) $this->user, (int) $row['site_id'])) {
            throw new \InvalidArgumentException('Unknown site. list_owner_sites gives the ids of the sites of this installation.'); // roles per site: the same answer as for an id that does not exist
        }
        if ($row['status'] === 'trashed') {
            throw new \InvalidArgumentException('This site is in the trash. Restore it first (restore_site).');
        }
        if (($moving = \Talea\Core\Sites::transferRefusal($db, (int) $row['site_id'])) !== null) {
            throw new \DomainException($moving);
        }
        if ($row['ready_at'] === null) {
            throw new \InvalidArgumentException('This site is still being set up. Try again in a few minutes.');
        }
        if (\Talea\Extension\Registry::get()->tool($name) !== null) {
            throw new \InvalidArgumentException('An add-on\'s tool works on the site the connection calls; it cannot take the site argument.');
        }
        if ((int) $row['site_id'] === \Talea\Core\Sites::currentId($db)) {
            return $this->call(new Tools($this->app), $name, $arguments); // the site this connection calls anyway
        }
        $config = $this->app->config;
        $config['db']['site_prefix'] = (string) $row['prefix'];
        $request = \Talea\Core\Request::fromGlobals();
        $app = new App($config, $request, ['site_id' => (int) $row['site_id'], 'public_id' => (string) $row['public_id'], 'prefix' => (string) $row['prefix'], 'owner_prefix' => $db->ownerPrefix, 'status' => (string) $row['status'],
            'redirect_to_site_id' => null, 'is_primary' => true, 'redirect_to_primary' => false, 'primary_host' => null, 'ready' => true]);
        $app->auth()->signInAs((array) $this->user);
        $conn = (array) $this->app->auth()->connection();
        $app->auth()->useConnection((string) ($conn['name'] ?? ''), (string) ($conn['access'] ?? 'read'), (string) ($conn['tool_set'] ?? 'all'));
        foreach ([false, true] as $signedIn) {
            if (($gate = self::siteGate($app, $signedIn)) !== null) {
                throw new \DomainException('The site ' . $row['public_id'] . ' refuses this connection: ' . $gate[1]);
            }
        }
        $origin = $app->settings()->get('site_url');
        $host = \Talea\Core\Sites::primaryHost($db, (int) $row['site_id']);
        $request->setOrigin($origin !== '' ? $origin : ($host !== null ? \Talea\Core\Sites::originOn($this->app->request, $host) : ''));

        $before = [\Talea\Front\Cache::site(), \Talea\Core\Language::code(), date_default_timezone_get()];
        $owner = (string) $row['prefix'] === $db->installPrefix; // the install's own site 1 keeps the plain cache folder
        \Talea\Front\Cache::useSite($owner ? null : (string) $row['prefix']);
        \Talea\Core\Slug::setPerLanguage(null);
        \Talea\Core\Routes::setNewsSlug(null);
        try {
            $app->applyTimezone();
            \Talea\Core\Language::setSite($app->settings(), \Talea\Core\Language::defaults($app->settings()));
            $server = new self($app);
            $server->user = $this->user;
            $server->siteId = (string) $row['public_id'];

            return $server->call(new Tools($app), $name, $arguments);
        } finally {
            \Talea\Front\Cache::useSite($before[0]);
            date_default_timezone_set($before[2]);
            \Talea\Core\Slug::setPerLanguage(null);
            \Talea\Core\Routes::setNewsSlug(null);
            \Talea\Core\Language::setSite($this->app->settings(), $before[1]);
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
        if (!isset($inner['site']) && is_string($arguments['site'] ?? null) && \Talea\Core\Sites::enabled()) {
            $inner['site'] = $arguments['site']; // the site may come with the runner itself; the inner call applies it
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
        $token = $db->one(self::CONNECTION_SQL . ' AND t.token_hash = ?', [date('Y-m-d H:i:s'), hash('sha256', $m[1])]);
        if ($token === null) {
            if (!$limited) {
                $db->insert('ip_checks', ['ip' => $ip, 'type' => 'mcp', 'checked_at' => date('Y-m-d H:i:s')]);
            }

            return null;
        }
        if (!\Talea\Core\Sites::allowsHere($db, $token)) {
            return null; // roles per site: a person who may not work on the site this connection calls has no connection to it
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
