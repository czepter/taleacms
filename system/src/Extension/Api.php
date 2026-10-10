<?php

declare(strict_types=1);

namespace Talea\Extension;

use Talea\Core\App;

/**
 * The extension API (3.0): everything an add-on may hook into, versioned by VERSION. Methods and hook names listed in
 * tools/contracts/extension-api.json are a public contract – they keep working through 3.x; a change that breaks them
 * needs 4.0. Anything else in Talea (classes, tables, columns) is internal and may change in any release.
 *
 * One Api object belongs to one add-on: its slug prefixes its settings, its MCP tools (ext_<slug>_<name>), its jobs and
 * its admin pages, so add-ons cannot overwrite each other or Talea's own names.
 */
final class Api
{
    public const int VERSION = 1;

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

    /** Who may call an add-on tool that does not say: by its access. */
    private const array DEFAULT_ROLE = ['read' => 'author', 'draft' => 'author', 'write' => 'editor', 'destructive' => 'admin'];

    public function __construct(private readonly Registry $registry, public readonly string $slug, private readonly App $app)
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

    /** A background job: run every $interval seconds (at least 60) by cron or visits; fn (): string returns a short result. */
    public function job(string $name, int $interval, string $label, callable $run): void
    {
        $this->registry->addJob('ext_' . $this->slug . '_' . self::name($name), max(60, $interval), $label, $run);
    }

    /** A setting of this add-on (its own namespace). */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->app->settings()->get('ext.' . $this->slug . '.' . self::name($key));

        return $value !== '' ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->app->settings()->set('ext.' . $this->slug . '.' . self::name($key), $value);
    }

    /** The site, for what the API does not cover yet – internal classes may change in any release. */
    public function app(): App
    {
        return $this->app;
    }

    private static function name(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) !== 1) {
            throw new \InvalidArgumentException('A name of lowercase letters, digits and _, starting with a letter: ' . $name);
        }

        return $name;
    }
}
